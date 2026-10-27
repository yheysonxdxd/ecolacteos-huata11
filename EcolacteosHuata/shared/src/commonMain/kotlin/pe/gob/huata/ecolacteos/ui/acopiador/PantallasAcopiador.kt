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
    }.deMiRuta() // solo los de su carro, en el orden de su recorrido
    when (ruta) {
        "acopiador/hoy" -> PantallaHoyAcopiador(filas, estado, irA)
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
internal fun TarjetaCambiosRuta(filas: List<FilaProveedor>) {
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
    val tieneRuta = miVehiculo?.ordenRuta?.isNotEmpty() == true
    var elegida by remember { mutableStateOf<Long?>(null) }
    var abierta by remember { mutableStateOf<FilaProveedor?>(null) }
    var verTodo by remember { mutableStateOf(false) }

    val porComunidad = filas.groupBy { it.proveedor.comunidadId }
    val pines = porComunidad.mapNotNull { (id, fs) ->
        Demo.comunidades.firstOrNull { it.id == id }?.let { c ->
            PinComunidad(c, estadoComunidad(fs.map { f -> estadoPin(f, estado) }), fs.count { it.entrega != null }, fs.size)
        }
    }
    val pinDe = pines.associateBy { it.comunidad.id }

    // comunidades de mi ruta en orden; sin vehículo elegido, todas las que tienen proveedores
    val orden = miVehiculo?.ordenRuta?.ifEmpty { null }
        ?: porComunidad.keys.sortedBy { id -> Demo.comunidades.firstOrNull { it.id == id }?.nombre }
    val lista = (orden + porComunidad.keys.filter { it !in orden }).filter { it in porComunidad }

    Column(
        Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(Medidas.Pantalla),
        verticalArrangement = Arrangement.spacedBy(Medidas.Hueco),
    ) {
        Surface(shape = RoundedCornerShape(18.dp), color = Altiplano.Superficie, border = BorderStroke(1.dp, Altiplano.Borde)) {
            Column {
                Row(Modifier.padding(start = 16.dp, end = 12.dp, top = 14.dp, bottom = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(if (miVehiculo != null) "TU RUTA" else "DISTRITO DE HUATA", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
                        Text(
                            miVehiculo?.let { "${it.codigo} · ${pines.filter { p -> p.comunidad.id in orden }.sumOf { p -> p.hechos }} de ${pines.filter { p -> p.comunidad.id in orden }.sumOf { p -> p.total }} registrados" }
                                ?: "Elige tu vehículo en «Hoy»",
                            style = Texto.Fila, color = Altiplano.Texto,
                        )
                    }
                    if (tieneRuta) Surface(
                        onClick = { verTodo = !verTodo },
                        shape = RoundedCornerShape(50), color = Altiplano.Fondo,
                        border = BorderStroke(1.dp, Altiplano.Borde),
                    ) {
                        Text(
                            if (verTodo) "Ver mi ruta" else "Ver todo Huata",
                            style = Texto.Apoyo, color = Altiplano.TextoSecundario,
                            modifier = Modifier.padding(horizontal = 12.dp, vertical = 7.dp),
                        )
                    }
                }
                MapaRuta(
                    Demo.comunidades, Demo.vehiculos, pines,
                    resaltado = mio, acercar = tieneRuta && !verTodo, elegida = elegida,
                    onToque = { c -> elegida = if (elegida == c.id) null else c.id },
                )
                Row(Modifier.padding(horizontal = 16.dp, vertical = 12.dp), horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                    Leyenda("Enviado", colorPin(EstadoPin.REGISTRADO))
                    Leyenda("Sin enviar", colorPin(EstadoPin.SIN_ENVIAR))
                    Leyenda("Falta", colorPin(EstadoPin.FALTA))
                }
            }
        }

        // proveedores de la comunidad tocada, justo debajo del mapa
        elegida?.let { id -> pinDe[id] }?.let { p ->
            val paso = orden.indexOf(p.comunidad.id).takeIf { miVehiculo != null && it >= 0 }
            Tarjeta(borde = colorPin(p.estado)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text((paso?.let { "${it + 1} · " } ?: "") + p.comunidad.nombre, style = Texto.Fila, color = Altiplano.Texto)
                        Text("${p.hechos} de ${p.total} registrados · toca un nombre para anotar", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                    }
                    TextButton(onClick = { elegida = null }) { Text("Cerrar", color = Altiplano.TextoSecundario) }
                }
                porComunidad.getValue(p.comunidad.id).forEach { f ->
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
                            f.entrega?.takeIf { !it.ausente }?.let {
                                Text("${formatear(it.litros)} L", style = Texto.Fila, color = Altiplano.Texto)
                                Spacer(Modifier.width(8.dp))
                            }
                            ChipEstado(estadoDe(f.entrega))
                        }
                    }
                }
            }
        }

        Text(if (miVehiculo != null) "EN ORDEN DE RUTA" else "COMUNIDADES", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
        lista.forEach { id ->
            val p = pinDe[id] ?: return@forEach
            val paso = orden.indexOf(id).takeIf { miVehiculo != null && it >= 0 }
            Surface(
                onClick = { elegida = if (elegida == id) null else id },
                shape = RoundedCornerShape(14.dp), color = Altiplano.Superficie,
                border = BorderStroke(1.dp, if (elegida == id) Altiplano.VerdeMarca else Altiplano.Borde),
            ) {
                Row(Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(28.dp).background(colorPin(p.estado).copy(alpha = 0.16f), CircleShape), contentAlignment = Alignment.Center) {
                        Text(paso?.let { "${it + 1}" } ?: "•", style = Texto.Apoyo, color = colorPin(p.estado))
                    }
                    Spacer(Modifier.width(12.dp))
                    Text(p.comunidad.nombre, style = Texto.Fila, color = Altiplano.Texto, modifier = Modifier.weight(1f))
                    Text("${p.hechos}/${p.total}", style = Texto.Fila, color = colorPin(p.estado))
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
