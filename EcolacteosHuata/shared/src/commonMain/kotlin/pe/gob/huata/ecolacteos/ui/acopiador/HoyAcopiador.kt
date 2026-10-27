package pe.gob.huata.ecolacteos.ui.acopiador

import androidx.compose.animation.core.*
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import pe.gob.huata.ecolacteos.datos.ParametrosCalidad
import pe.gob.huata.ecolacteos.datos.Sesion
import pe.gob.huata.ecolacteos.datos.TipoVehiculo
import pe.gob.huata.ecolacteos.datos.Vehiculo
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*
import pe.gob.huata.ecolacteos.ui.roles.Aparece
import pe.gob.huata.ecolacteos.ui.roles.Tocable
import pe.gob.huata.ecolacteos.ui.roles.saludo

/** Solo los proveedores del carro elegido, en el orden de su recorrido (sin carro elegido: todos). */
fun List<FilaProveedor>.deMiRuta(): List<FilaProveedor> {
    val v = Demo.vehiculos.firstOrNull { it.id == VehiculoElegido.id } ?: return this
    val orden = v.ordenRuta
    return filter { it.proveedor.vehiculoId == v.id }
        .sortedWith(compareBy<FilaProveedor>({ orden.indexOf(it.proveedor.comunidadId).let { i -> if (i < 0) Int.MAX_VALUE else i } }, { it.proveedor.nombre }))
}

/**
 * "Hoy" del acopiador: su carro y lo de hoy en grande, un botón grande para registrar,
 * a dónde ir ahora y su ruta en línea de tiempo (tocar a alguien anota sus litros ahí mismo).
 */
@Composable
internal fun PantallaHoyAcopiador(filas: List<FilaProveedor>, estado: EstadoAcopio, irA: (Int) -> Unit) {
    val v = Demo.vehiculos.firstOrNull { it.id == VehiculoElegido.id }
    var abierta by remember { mutableStateOf<FilaProveedor?>(null) }
    PantallaScroll {
        Aparece(0) {
            Column {
                Text(saludo() + (Sesion.nombre.takeIf { it.isNotBlank() }?.let { ", ${it.substringBefore(' ')}" } ?: "") + " 👋",
                    style = Texto.TituloPantalla.copy(fontSize = 24.sp), color = Altiplano.Texto)
                Text(if (v == null) "Primero elige con qué vas a recoger la leche hoy." else "Esta es tu ruta de hoy.",
                    style = Texto.Cuerpo, color = Altiplano.TextoTerciario)
            }
        }
        if (v == null) {
            Aparece(1) { TarjetaMiVehiculo(filas) { irA(1) } }
            return@PantallaScroll
        }
        Aparece(1) { HeroRuta(v, filas, estado) }
        if (filas.any { it.entrega == null }) Aparece(2) { BotonRegistrar { irA(1) } }
        Aparece(3) { SiguienteParada(v, filas) }
        Aparece(4) { LineaRuta(v, filas) { abierta = it } }
        Aparece(5) { TarjetaCambiosRuta(filas) }
    }
    abierta?.let { fila ->
        HojaRegistro(
            fila = fila, parametros = ParametrosCalidad(), onCerrar = { abierta = null },
            onGuardar = { litros -> estado.guardar(fila.proveedor, litros); abierta = null },
            onNoEntrego = { estado.guardar(fila.proveedor, 0.0, ausente = true); abierta = null },
        )
    }
}

/** El carro con su color, los litros de hoy que cuentan y cuántos faltan. */
@Composable
private fun HeroRuta(v: Vehiculo, filas: List<FilaProveedor>, estado: EstadoAcopio) {
    val hechos = filas.count { it.entrega != null }
    val total = filas.size.coerceAtLeast(1)
    val litros by animateFloatAsState(estado.litrosHoy.toFloat(), tween(800, easing = FastOutSlowInEasing), label = "litros")
    val avance by animateFloatAsState(hechos / total.toFloat(), tween(800, easing = FastOutSlowInEasing), label = "avance")
    val esMotocar = v.tipo == TipoVehiculo.MOTOCAR
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp))
            .background(Brush.linearGradient(listOf(v.color.copy(alpha = 0.55f), Color(0xFF1E6E3E))))
            .padding(18.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(if (esMotocar) "🛵" else "🚚", fontSize = 26.sp)
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text("${v.codigo} · ${if (esMotocar) "Motocar" else "Carro"}", style = Texto.Fila.copy(fontWeight = FontWeight.Bold), color = Color.White)
                Text(v.ordenRuta.joinToString(" → ") { Demo.comunidad(it).nombre } + if (v.rutaCircular) " ↺" else "",
                    style = Texto.Apoyo, color = Color(0xFFE3F1EA), maxLines = 2)
            }
            TextButton(onClick = { VehiculoElegido.id = null }) { Text("Cambiar", color = Color(0xFFE3F1EA)) }
        }
        Spacer(Modifier.height(6.dp))
        Row(verticalAlignment = Alignment.Bottom) {
            Text(formatear(litros.toDouble()), style = Texto.Dato.copy(fontSize = 46.sp, fontWeight = FontWeight.Bold), color = Color.White)
            Text("  litros hoy", style = Texto.Fila, color = Color(0xFFE3F1EA), modifier = Modifier.padding(bottom = 10.dp))
        }
        Box(Modifier.fillMaxWidth().height(12.dp).clip(RoundedCornerShape(99.dp)).background(Color(0x33FFFFFF))) {
            Box(Modifier.fillMaxWidth(avance).fillMaxHeight().clip(RoundedCornerShape(99.dp)).background(Color(0xFF7BE3A1)))
        }
        Spacer(Modifier.height(8.dp))
        Row {
            Text("$hechos de ${filas.size} proveedores", style = Texto.Cuerpo, color = Color.White, modifier = Modifier.weight(1f))
            if (estado.pendientes > 0) Text("📤 ${estado.pendientes} por enviar", style = Texto.Cuerpo, color = Color(0xFFFFE3A3))
            else if (hechos > 0) Text("✓ todo enviado", style = Texto.Cuerpo, color = Color(0xFFBFF5D2))
        }
    }
}

/** Botón grande que late suave: lo que más se hace en el día. */
@Composable
private fun BotonRegistrar(onClick: () -> Unit) {
    val latido = rememberInfiniteTransition(label = "registrar")
    val escala by latido.animateFloat(1f, 1.03f, infiniteRepeatable(tween(900), RepeatMode.Reverse), label = "escala")
    Box(Modifier.graphicsLayer { scaleX = escala; scaleY = escala }) {
        Tocable(Color(0xFF4FC47A), Modifier.fillMaxWidth(), onClick) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("🥛", fontSize = 34.sp)
                Spacer(Modifier.width(14.dp))
                Column(Modifier.weight(1f)) {
                    Text("Registrar leche", style = Texto.Fila.copy(fontSize = 19.sp, fontWeight = FontWeight.Bold), color = Altiplano.Texto)
                    Text("Toca a cada proveedor y anota sus litros", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                }
                Text("›", fontSize = 30.sp, color = Color(0xFF4FC47A))
            }
        }
    }
}

/** A dónde ir ahora: la primera comunidad de la ruta donde todavía falta alguien. */
@Composable
private fun SiguienteParada(v: Vehiculo, filas: List<FilaProveedor>) {
    val siguiente = v.ordenRuta.firstOrNull { cid -> filas.any { it.proveedor.comunidadId == cid && it.entrega == null } }
    val color = if (siguiente != null) Color(0xFF6FC3E8) else Color(0xFF4FC47A)
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(color.copy(alpha = 0.13f))
            .border(1.dp, color.copy(alpha = 0.45f), RoundedCornerShape(18.dp)).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(if (siguiente != null) "📍" else "🏁", fontSize = 28.sp)
        Spacer(Modifier.width(12.dp))
        Column {
            if (siguiente != null) {
                val faltan = filas.count { it.proveedor.comunidadId == siguiente && it.entrega == null }
                Text("VE A ACOPIAR EN", style = Texto.Eyebrow, color = color)
                Text(Demo.comunidad(siguiente).nombre, style = Texto.Fila.copy(fontSize = 18.sp, fontWeight = FontWeight.Bold), color = Altiplano.Texto)
                Text("$faltan ${if (faltan == 1) "proveedor te espera" else "proveedores te esperan"}", style = Texto.Apoyo, color = Altiplano.TextoSecundario)
            } else {
                Text("RUTA COMPLETA 🎉", style = Texto.Eyebrow, color = color)
                Text(if (v.rutaCircular) "Cierra el circuito y regresa a la planta" else "Regresa a la planta", style = Texto.Fila, color = Altiplano.Texto)
            }
        }
    }
}

/** La ruta en orden: cada parada con sus proveedores; la que toca ahora late. Tocar un nombre anota sus litros. */
@Composable
private fun LineaRuta(v: Vehiculo, filas: List<FilaProveedor>, abrir: (FilaProveedor) -> Unit) {
    val paradas = v.ordenRuta.map { cid -> cid to filas.filter { it.proveedor.comunidadId == cid } }.filter { it.second.isNotEmpty() }
    val siguiente = paradas.firstOrNull { (_, ps) -> ps.any { it.entrega == null } }?.first
    val latido = rememberInfiniteTransition(label = "parada")
    val halo by latido.animateFloat(1f, 1.35f, infiniteRepeatable(tween(800), RepeatMode.Reverse), label = "halo")
    Tarjeta {
        Text("TU RUTA", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
        Spacer(Modifier.height(10.dp))
        paradas.forEachIndexed { i, (cid, ps) ->
            val hechos = ps.count { it.entrega != null }
            val completa = hechos == ps.size
            val ahora = cid == siguiente
            Row {
                // el punto de la parada y la línea que baja a la siguiente
                Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.width(34.dp)) {
                    Box(contentAlignment = Alignment.Center) {
                        if (ahora) Box(Modifier.size(30.dp).graphicsLayer { scaleX = halo; scaleY = halo; alpha = 2f - halo }.background(v.color.copy(alpha = 0.35f), CircleShape))
                        Box(
                            Modifier.size(28.dp).background(if (completa) Color(0xFF4FC47A) else if (ahora) v.color else Altiplano.Fondo, CircleShape)
                                .border(1.dp, if (completa || ahora) Color.Transparent else Altiplano.Borde, CircleShape),
                            contentAlignment = Alignment.Center,
                        ) { Text(if (completa) "✓" else "${i + 1}", style = Texto.Apoyo, color = if (completa || ahora) Altiplano.Fondo else Altiplano.TextoTerciario) }
                    }
                    if (i < paradas.lastIndex) Box(Modifier.width(3.dp).height((ps.size * 44 + 20).dp)
                        .background(if (completa) Color(0xFF4FC47A).copy(alpha = 0.6f) else Altiplano.Borde))
                }
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f).padding(bottom = 12.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(Demo.comunidad(cid).nombre, style = Texto.Fila.copy(fontWeight = if (ahora) FontWeight.Bold else FontWeight.Normal),
                            color = if (completa) Altiplano.TextoTerciario else Altiplano.Texto, modifier = Modifier.weight(1f))
                        Text("$hechos/${ps.size}", style = Texto.Apoyo, color = if (completa) Color(0xFF4FC47A) else Altiplano.TextoSecundario)
                    }
                    ps.forEach { f ->
                        val e = f.entrega
                        Row(
                            Modifier.fillMaxWidth().padding(top = 6.dp).clip(RoundedCornerShape(10.dp))
                                .background(if (e == null) Altiplano.Superficie else Color.Transparent)
                                .clickable { abrir(f) }.padding(horizontal = 10.dp, vertical = 8.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Text(
                                when { e == null -> "○"; e.ausente -> "–"; else -> "✓" },
                                color = when { e == null -> Altiplano.TextoTerciario; e.ausente -> Altiplano.Aviso; else -> Color(0xFF4FC47A) },
                                modifier = Modifier.width(18.dp),
                            )
                            Text(f.proveedor.nombre, style = Texto.Cuerpo, color = if (e == null) Altiplano.Texto else Altiplano.TextoSecundario,
                                modifier = Modifier.weight(1f), maxLines = 1)
                            Text(
                                when { e == null -> "anotar ›"; e.ausente -> "no entregó"; else -> "${formatear(e.litros)} L" },
                                style = Texto.Apoyo, color = if (e == null) Color(0xFF6FC3E8) else Altiplano.TextoSecundario,
                            )
                        }
                    }
                }
            }
        }
        if (paradas.isEmpty()) Text("Tu carro todavía no tiene proveedores asignados.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
    }
}
