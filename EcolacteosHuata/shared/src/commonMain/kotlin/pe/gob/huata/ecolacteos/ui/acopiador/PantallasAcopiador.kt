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

@Composable
private fun PantallaHoy(filas: List<FilaProveedor>, estado: EstadoAcopio, irA: (Int) -> Unit) {
    val registrados = filas.count { it.entrega != null }
    val faltan = filas.filter { it.entrega == null }
    Column(
        Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(Medidas.Pantalla),
        verticalArrangement = Arrangement.spacedBy(Medidas.Hueco),
    ) {
        TarjetaMiVehiculo(filas) { irA(1) }
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

@Composable
private fun PantallaMapa(filas: List<FilaProveedor>, estado: EstadoAcopio) {
    val pines = filas.map { f ->
        Demo.comunidad(f.proveedor.comunidadId) to when {
            f.entrega == null -> EstadoPin.FALTA
            f.entrega.ausente -> EstadoPin.NO_ENTREGO
            f.proveedor.id in estado.enviados -> EstadoPin.REGISTRADO
            else -> EstadoPin.SIN_ENVIAR
        }
    }
    Column(
        Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(Medidas.Pantalla),
        verticalArrangement = Arrangement.spacedBy(Medidas.Hueco),
    ) {
        Surface(shape = RoundedCornerShape(16.dp), color = Altiplano.Superficie, border = BorderStroke(1.dp, Altiplano.Borde)) {
            Column(Modifier.padding(14.dp)) {
                Text("CROQUIS · DISTRITO DE HUATA", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
                Spacer(Modifier.height(10.dp))
                CroquisConNombres(Demo.comunidades, Demo.vehiculos, pines)
                Spacer(Modifier.height(12.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                    Leyenda("Registrado", Altiplano.Exito)
                    Leyenda("Sin enviar", Altiplano.Aviso)
                    Leyenda("Falta", Color(0xFF4E6577))
                }
            }
        }
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
