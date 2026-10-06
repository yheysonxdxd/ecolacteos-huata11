package pe.gob.huata.ecolacteos.ui.acopiador

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import pe.gob.huata.ecolacteos.datos.*
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

/** Datos de muestra mientras no esté conectado el backend. */
object Demo {
    var comunidades = listOf(
        Comunidad(1, "Huatta", 170f, 250f),
        Comunidad(2, "Yanamocco", 80f, 170f),
        Comunidad(3, "Buena Vista", 250f, 160f),
        Comunidad(4, "Santa Bárbara de Moro", 120f, 90f),
        Comunidad(5, "Canchi", 225f, 80f),
        Comunidad(6, "Carata", 60f, 270f),
        Comunidad(7, "Lluco", 292f, 238f),
        Comunidad(8, "Collana Primero", 205f, 298f),
    )
    var vehiculos = listOf(
        Vehiculo(1, "C-01", TipoVehiculo.CARRO, ordenRuta = listOf(6, 2, 4)),
        Vehiculo(2, "C-02", TipoVehiculo.CARRO, ordenRuta = listOf(1, 3, 5)),
        Vehiculo(3, "C-03", TipoVehiculo.CARRO, ordenRuta = listOf(8, 7, 3)),
        Vehiculo(4, "M-01", TipoVehiculo.MOTOCAR, rutaCircular = true, ordenRuta = listOf(1, 8, 6)),
    )
    var proveedores = listOf(
        Proveedor(1, "p1", "Rosa Quispe Mamani", "40112233", 1, 4, promedioLitros = 18.0),
        Proveedor(2, "p2", "Julián Apaza Condori", "40223344", 2, 1, promedioLitros = 12.0),
        Proveedor(3, "p3", "Elena Choque Tito", "40334455", 3, 2, promedioLitros = 16.0),
        Proveedor(4, "p4", "Mario Huanca Paredes", "40445566", 5, 2),
        Proveedor(5, "p5", "Feliciana Cutipa Luque", "40556677", 6, 1, promedioLitros = 10.0),
        Proveedor(6, "p6", "Gregorio Yana Ccama", "40667788", 8, 3, promedioLitros = 9.0),
    )
    var seFueron = emptyList<SeFue>()
    fun comunidad(id: Long) = comunidades.first { it.id == id }
    fun vehiculo(id: Long) = vehiculos.first { it.id == id }
}

/** Estado en memoria del día. Luego se reemplaza por SQLDelight + Outbox. */
class EstadoAcopio {
    val entregas = mutableStateMapOf<Long, Entrega>()
    val enviados = mutableStateListOf<Long>()
    var sincronizando by mutableStateOf(false)
    val pendientes: Int get() = entregas.keys.count { it !in enviados }
    private var n = 0

    fun guardar(p: Proveedor, litros: Double, ausente: Boolean = false) {
        entregas[p.id] = Entrega(
            // único de verdad: "e-{id}-{n}" se repetía si se recreaba el estado (girar el celular)
            uuid = nuevoUuid(), proveedorId = p.id, fecha = "hoy",
            litros = if (ausente) 0.0 else litros, ausente = ausente, registradoEn = "ahora",
        )
        enviados.remove(p.id)
    }
    fun sincronizar() { enviados.clear(); enviados.addAll(entregas.keys) }
    val litrosHoy: Double get() = entregas.values.sumOf { it.litros }
}

@Composable
fun ContenidoAcopiador(ruta: String, estado: EstadoAcopio, irA: (Int) -> Unit) {
    RecordarAcopio(estado)
    AltaProveedor(estado)
    val filas = Demo.proveedores.map {
        FilaProveedor(it, Demo.comunidad(it.comunidadId).nombre, Demo.vehiculo(it.vehiculoId).codigo, estado.entregaParaMostrar(it.id))
    }.cercaDeMiRuta()
    when (ruta) {
        "acopiador/hoy" -> PantallaHoy(filas, estado, irA)
        "acopiador/registrar" -> PantallaRegistrar(
            filas = filas,
            parametros = ParametrosCalidad(),
            onGuardar = { p, l -> estado.guardar(p, l) },
            onNoEntrego = { p -> estado.guardar(p, 0.0, ausente = true) },
            onNuevoProveedor = { ProveedoresNuevos.abrir() },
        )
        "acopiador/mapa" -> PantallaMapa(filas, estado)
        else -> if (Sesion.desdeServidor) PantallaSemanaReal() else PantallaSemana()
    }
}

/** "🆕 Nuevo en tu ruta · antes en Carata" (primeros 15 días tras un cambio de comunidad). */
@Composable
fun EtiquetaNuevoEnRuta(p: Proveedor) {
    Text("🆕 Nuevo en tu ruta · antes en ${p.cambioAntes}", style = Texto.Apoyo, color = Altiplano.CelesteAcento)
}

/**
 * Quién llegó a mi ruta y quién se fue (cambios de comunidad de los últimos
 * 15 días): para pasar a recoger al nuevo y no esperar al que ya no está.
 */
@Composable
private fun TarjetaCambiosRuta(filas: List<FilaProveedor>) {
    val mio = VehiculoElegido.id ?: return
    val llegaron = filas.map { it.proveedor }.filter { it.nuevoEnRuta(mio) }
    val seFueron = Demo.seFueron.filter { it.deVehiculoId == mio }
    if (llegaron.isEmpty() && seFueron.isEmpty()) return

    Tarjeta(borde = Altiplano.CelesteAcento) {
        Text("CAMBIOS EN TU RUTA", style = Texto.Eyebrow, color = Altiplano.CelesteAcento)
        llegaron.forEach { p ->
            Spacer(Modifier.height(8.dp))
            Text("🆕 ${p.nombre}", style = Texto.Fila, color = Altiplano.Texto)
            Text("Ahora está en ${Demo.comunidad(p.comunidadId).nombre} (antes en ${p.cambioAntes}) · desde el ${fechaCorta(p.cambioDesde ?: "")}. Pasa a recoger su leche.",
                style = Texto.Apoyo, color = Altiplano.TextoSecundario)
        }
        seFueron.forEach { s ->
            Spacer(Modifier.height(8.dp))
            Text("👋 ${s.nombre}", style = Texto.Fila, color = Altiplano.Texto)
            Text("Ya no está en tu ruta: se cambió de ${s.antes} a ${s.ahora} desde el ${fechaCorta(s.desde)}. No lo esperes.",
                style = Texto.Apoyo, color = Altiplano.TextoSecundario)
        }
    }
}

@Composable
private fun PantallaHoy(filas: List<FilaProveedor>, estado: EstadoAcopio, irA: (Int) -> Unit) {
    val registrados = filas.count { it.entrega != null }
    val faltan = filas.filter { it.entrega == null }
    Column(
        Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(Medidas.Pantalla),
        verticalArrangement = Arrangement.spacedBy(Medidas.Hueco),
    ) {
        TarjetaMiVehiculo(filas) { irA(1) }
        TarjetaCambiosRuta(filas)
        TarjetaDato(
            eyebrow = "LITROS ACOPIADOS HOY",
            valor = formatear(estado.litrosHoy), unidad = "L",
            apoyo = "$registrados de ${filas.size} proveedores registrados",
            destacada = true,
        ) {
            Spacer(Modifier.height(12.dp))
            LinearProgressIndicator(
                progress = { registrados / filas.size.toFloat() },
                modifier = Modifier.fillMaxWidth().height(7.dp),
                color = Altiplano.Exito, trackColor = Altiplano.Fondo,
            )
        }
        Row(horizontalArrangement = Arrangement.spacedBy(Medidas.Hueco)) {
            MiniDato("Valor del día", "S/ ${formatear(estado.litrosHoy * 1.8, 2)}", Modifier.weight(1f))
            MiniDato("Sin enviar", "${estado.pendientes}", Modifier.weight(1f), Altiplano.Aviso)
        }
        if (faltan.isNotEmpty()) {
            Text("PENDIENTES DE REGISTRAR", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
            faltan.forEach { f ->
                Surface(
                    onClick = { irA(1) },
                    modifier = Modifier.fillMaxWidth().heightIn(min = Medidas.FilaProveedor),
                    shape = RoundedCornerShape(14.dp), color = Altiplano.Superficie,
                    border = BorderStroke(1.dp, Altiplano.VerdeBorde),
                ) {
                    Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(f.proveedor.nombre, style = Texto.Fila, color = Altiplano.Texto)
                            Text("${f.comunidad} · ${f.vehiculo}", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                            if (f.proveedor.nuevoEnRuta(VehiculoElegido.id)) EtiquetaNuevoEnRuta(f.proveedor)
                        }
                        ChipEstado(Estado.FALTA)
                    }
                }
            }
        }
        TarjetaAcopiadores(filas)
    }
}

@Composable
private fun MiniDato(etiqueta: String, valor: String, modifier: Modifier, color: Color = Altiplano.Texto) {
    Surface(modifier, shape = RoundedCornerShape(14.dp), color = Altiplano.Superficie, border = BorderStroke(1.dp, Altiplano.Borde)) {
        Column(Modifier.padding(14.dp)) {
            Text(etiqueta, style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            Spacer(Modifier.height(5.dp))
            Text(valor, style = Texto.DatoMedio.copy(fontSize = Texto.DatoMedio.fontSize * 0.75f), color = color)
        }
    }
}

private fun estadoPin(f: FilaProveedor, estado: EstadoAcopio): EstadoPin = when {
    f.entrega == null -> EstadoPin.FALTA
    f.entrega.ausente -> EstadoPin.NO_ENTREGO
    f.proveedor.id in estado.enviados -> EstadoPin.REGISTRADO
    else -> EstadoPin.SIN_ENVIAR
}

/**
 * Un pin por comunidad: gris si falta alguien, amarillo si ya están todos pero
 * algo no se envió, verde si todo salió, rojo si nadie entregó.
 */
internal fun estadoComunidad(estados: List<EstadoPin>): EstadoPin = when {
    EstadoPin.FALTA in estados -> EstadoPin.FALTA
    EstadoPin.SIN_ENVIAR in estados -> EstadoPin.SIN_ENVIAR
    estados.isNotEmpty() && estados.all { it == EstadoPin.NO_ENTREGO } -> EstadoPin.NO_ENTREGO
    else -> EstadoPin.REGISTRADO
}

/**
 * Mapa de la ruta. Funciona sin señal: el croquis, las comunidades y los
 * proveedores salen de la copia guardada en el teléfono, y lo que se anota
 * aquí queda en el teléfono hasta que se pueda enviar.
 */
@Composable
private fun PantallaMapa(filas: List<FilaProveedor>, estado: EstadoAcopio) {
    val mio = VehiculoElegido.id
    val miVehiculo = Demo.vehiculos.firstOrNull { it.id == mio }
    var elegida by remember { mutableStateOf<Long?>(null) }
    var abierta by remember { mutableStateOf<FilaProveedor?>(null) }

    val porComunidad = filas.groupBy { it.proveedor.comunidadId }
    val pines = porComunidad.mapNotNull { (id, fs) ->
        Demo.comunidades.firstOrNull { it.id == id }?.let { it to estadoComunidad(fs.map { f -> estadoPin(f, estado) }) }
    }
    fun avance(id: Long) = porComunidad[id].orEmpty().let { fs -> "${fs.count { it.entrega != null }}/${fs.size}" }

    // comunidades de mi ruta en orden; sin vehículo elegido, todas las que tienen proveedores
    val orden = miVehiculo?.ordenRuta?.ifEmpty { null }
        ?: porComunidad.keys.sortedBy { id -> Demo.comunidades.firstOrNull { it.id == id }?.nombre }
    val otras = porComunidad.keys.filter { it !in orden }

    Column(
        Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(Medidas.Pantalla),
        verticalArrangement = Arrangement.spacedBy(Medidas.Hueco),
    ) {
        Surface(shape = RoundedCornerShape(16.dp), color = Altiplano.Superficie, border = BorderStroke(1.dp, Altiplano.Borde)) {
            Column(Modifier.padding(14.dp)) {
                Text(
                    if (miVehiculo != null) "TU RUTA · ${miVehiculo.codigo}" else "CROQUIS · DISTRITO DE HUATA",
                    style = Texto.Eyebrow, color = Altiplano.TextoTerciario,
                )
                Spacer(Modifier.height(10.dp))
                CroquisConNombres(
                    Demo.comunidades, Demo.vehiculos, pines,
                    resaltado = mio,
                    etiquetaDe = { c -> if (c.id in porComunidad) "${c.nombre} ${avance(c.id)}" else c.nombre },
                    onToque = { c -> if (c.id in porComunidad) elegida = if (elegida == c.id) null else c.id },
                )
                Spacer(Modifier.height(12.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                    Leyenda("Registrado", Altiplano.Exito)
                    Leyenda("Sin enviar", Altiplano.Aviso)
                    Leyenda("Falta", Color(0xFF4E6577))
                }
                Spacer(Modifier.height(8.dp))
                Text(
                    if (miVehiculo == null) "Elige tu vehículo en «Hoy» para ver tu ruta resaltada. Toca una comunidad para anotar litros."
                    else "Toca una comunidad para anotar litros. Funciona sin señal: se envía cuando vuelva.",
                    style = Texto.Apoyo, color = Altiplano.TextoTerciario,
                )
            }
        }

        Text(if (miVehiculo != null) "COMUNIDADES EN ORDEN DE RUTA" else "COMUNIDADES", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
        (orden + otras).filter { it in porComunidad }.forEach { id ->
            val c = Demo.comunidades.firstOrNull { it.id == id } ?: return@forEach
            val fs = porComunidad.getValue(id)
            val enRuta = miVehiculo != null && id in orden
            val pin = estadoComunidad(fs.map { estadoPin(it, estado) })
            Surface(
                onClick = { elegida = if (elegida == id) null else id },
                shape = RoundedCornerShape(14.dp), color = Altiplano.Superficie,
                border = BorderStroke(1.dp, if (elegida == id) Altiplano.VerdeMarca else Altiplano.Borde),
            ) {
                Column(Modifier.fillMaxWidth().padding(14.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(10.dp).background(colorPin(pin), CircleShape))
                        Spacer(Modifier.width(10.dp))
                        Text(
                            (if (enRuta) "${orden.indexOf(id) + 1}. " else "") + c.nombre,
                            style = Texto.Fila, color = Altiplano.Texto, modifier = Modifier.weight(1f),
                        )
                        Text("${avance(id)} registrados", style = Texto.Apoyo, color = Altiplano.TextoSecundario)
                    }
                    if (elegida == id) fs.forEach { f ->
                        Spacer(Modifier.height(8.dp))
                        Surface(
                            onClick = { abierta = f },
                            shape = RoundedCornerShape(12.dp), color = Altiplano.Fondo,
                            border = BorderStroke(1.dp, if (f.entrega == null) Altiplano.VerdeBorde else Altiplano.Borde),
                        ) {
                            Row(Modifier.fillMaxWidth().heightIn(min = 56.dp).padding(horizontal = 12.dp, vertical = 8.dp),
                                verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.weight(1f)) {
                                    Text(f.proveedor.nombre, style = Texto.Cuerpo, color = Altiplano.Texto)
                                    Text(f.vehiculo, style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                                }
                                Text(
                                    f.entrega?.let { if (it.ausente) "—" else "${formatear(it.litros)} L" } ?: "",
                                    style = Texto.Fila, color = Altiplano.Texto,
                                )
                                Spacer(Modifier.width(8.dp))
                                ChipEstado(estadoDe(f.entrega))
                            }
                        }
                    }
                }
            }
        }
        Text("VEHÍCULOS", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
        Demo.vehiculos.forEach { v ->
            val suyos = filas.filter { it.proveedor.vehiculoId == v.id }
            Surface(shape = RoundedCornerShape(14.dp), color = Altiplano.Superficie, border = BorderStroke(1.dp, Altiplano.Borde)) {
                Row(Modifier.fillMaxWidth().padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(10.dp).background(v.color, CircleShape))
                    Spacer(Modifier.width(10.dp))
                    Column(Modifier.weight(1f)) {
                        Text("${v.codigo} · ${if (v.tipo == TipoVehiculo.MOTOCAR) "Motocar" else "Furgón"}", style = Texto.Fila, color = Altiplano.Texto)
                        Text(if (v.rutaCircular) "Circuito cerrado" else "Ruta lineal", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                    }
                    Text("${suyos.count { it.entrega != null }}/${suyos.size}", style = Texto.Fila, color = Altiplano.TextoSecundario)
                }
            }
        }
    }

    abierta?.let { fila ->
        HojaRegistro(
            fila = fila,
            parametros = ParametrosCalidad(),
            onCerrar = { abierta = null },
            onGuardar = { litros -> estado.guardar(fila.proveedor, litros); abierta = null },
            onNoEntrego = { estado.guardar(fila.proveedor, 0.0, ausente = true); abierta = null },
        )
    }
}

private fun colorPin(p: EstadoPin): Color = when (p) {
    EstadoPin.REGISTRADO -> Altiplano.Exito
    EstadoPin.SIN_ENVIAR -> Altiplano.Aviso
    EstadoPin.NO_ENTREGO -> Altiplano.Alerta
    EstadoPin.FALTA -> Color(0xFF4E6577)
}

@Composable
private fun Leyenda(t: String, c: Color) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(9.dp).background(c, CircleShape))
        Spacer(Modifier.width(6.dp))
        Text(t, style = Texto.Apoyo, color = Altiplano.TextoSecundario)
    }
}

@Composable
private fun PantallaSemana() {
    val dias = listOf("Lu" to 1180.0, "Ma" to 1240.0, "Mi" to 1160.0, "Ju" to 1290.0, "Vi" to 1310.0, "Sá" to 1066.0, "Do" to 214.0)
    Column(
        Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(Medidas.Pantalla),
        verticalArrangement = Arrangement.spacedBy(Medidas.Hueco),
    ) {
        TarjetaDato(eyebrow = "ACUMULADO DE LA SEMANA", valor = formatear(dias.sumOf { it.second }), unidad = "L", destacada = true) {
            Spacer(Modifier.height(16.dp))
            Row(Modifier.fillMaxWidth().height(110.dp), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.Bottom) {
                dias.forEach { (d, v) ->
                    Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
                        Box(Modifier.fillMaxWidth().height((v / 1350 * 86).dp).background(Altiplano.VerdeMarca, RoundedCornerShape(6.dp)))
                        Spacer(Modifier.height(6.dp))
                        Text(d, style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                    }
                }
            }
        }
    }
}
