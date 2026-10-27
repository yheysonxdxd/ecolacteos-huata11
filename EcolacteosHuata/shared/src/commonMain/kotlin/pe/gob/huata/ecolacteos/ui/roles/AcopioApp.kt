package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.*
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.serialization.json.JsonObject
import pe.gob.huata.ecolacteos.datos.Sesion
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

/**
 * Acopio del admin en una sola vista, sin pestañas: el día grande arriba, los últimos 7 días
 * como barras que se tocan, el mes completo si se pide, cada carro con su color y la
 * conciliación campo vs. planta como una tarjeta más.
 */
@Composable
internal fun AcopioAdmin() {
    val cal = AcopioCalendario
    AnimatedContent(cal.conciliar, transitionSpec = { transicionMenu(targetState) }, label = "acopio") { conciliar ->
        if (conciliar) DentroDe("Acopio", "Campo vs. planta", { cal.conciliar = false }) { ConciliacionReal() }
        else AcopioDelDia()
    }
}

private val DIAS = listOf("lunes", "martes", "miércoles", "jueves", "viernes", "sábado", "domingo")
private val COLORES_RUTA = listOf(Color(0xFF6FC3E8), Color(0xFFE8B44C), Color(0xFFB79CF2), Color(0xFF4FC47A), Color(0xFFEF8F8B), Color(0xFFD9C6A0))

/** Día de la semana de una fecha ISO (lunes = 0), con la fórmula de Zeller. */
internal fun diaSemana(iso: String): Int {
    val (anio, mes, dia) = iso.take(10).split("-").map { it.toInt() }
    val (a, m) = if (mes < 3) (anio - 1) to (mes + 12) else anio to mes
    val h = (dia + 13 * (m + 1) / 5 + a % 100 + (a % 100) / 4 + (a / 100) / 4 + 5 * (a / 100)) % 7 // 0 = sábado
    return (h + 5) % 7
}

@Composable
private fun AcopioDelDia() {
    val cal = AcopioCalendario
    // sin día elegido: el último con entregas
    val ruta = cal.fecha?.let { "/api/v1/admin/totales?fecha=$it" } ?: "/api/v1/admin/totales?periodo=dia"
    CargaServidor(ruta) { d, _ ->
        val dia = d.txt("hasta")
        PantallaScroll {
            Aparece(0) { HeroDia(d) }
            Aparece(1) { TiraDias(d.lista("recientes"), dia) }
            Aparece(2) {
                Tocable(CELESTE, Modifier.fillMaxWidth(), { cal.activo = !cal.activo; if (cal.activo) cal.mes = dia.take(7) }) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text("📅", fontSize = 22.sp)
                        Spacer(Modifier.width(12.dp))
                        Text(if (cal.activo) "Ocultar el mes" else "Ver todo el mes", style = Texto.Fila, color = Altiplano.Texto, modifier = Modifier.weight(1f))
                        Text(if (cal.activo) "▴" else "▾", fontSize = 20.sp, color = CELESTE)
                    }
                }
            }
            AnimatedVisibility(cal.activo, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) { MesCompleto(dia) }

            val vehiculos = d.lista("vehiculos")
            Aparece(3) { Text("🚚  CADA CARRO", style = Texto.Eyebrow, color = Altiplano.TextoTerciario, modifier = Modifier.padding(top = 6.dp)) }
            val maximo = maxOf(vehiculos.maxOfOrNull { it.num("litros") } ?: 0.0, 1.0)
            vehiculos.forEachIndexed { k, v -> Aparece(4 + k) { TarjetaCarro(v, COLORES_RUTA[k % COLORES_RUTA.size], (v.num("litros") / maximo).toFloat()) } }

            Aparece(5 + vehiculos.size) {
                Tocable(ARENA, Modifier.fillMaxWidth(), { cal.conciliar = true }) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text("⚖️", fontSize = 26.sp)
                        Spacer(Modifier.width(12.dp))
                        Column(Modifier.weight(1f)) {
                            Text("Campo vs. planta", style = Texto.Fila, color = Altiplano.Texto)
                            Text("¿Llegó a la planta lo que anotaron los acopiadores?", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                        }
                        Text("›", fontSize = 26.sp, color = ARENA)
                    }
                }
            }
        }
    }
}

/** El día elegido, grande y de colores: litros que cuentan, calidad en una barra y lo que se paga. */
@Composable
private fun HeroDia(d: JsonObject) {
    val dia = d.txt("hasta")
    var meta by remember { mutableStateOf(0f) }
    LaunchedEffect(d) { meta = d.num("litros").toFloat() }
    val litros by animateFloatAsState(meta, tween(900, easing = FastOutSlowInEasing), label = "litros")
    val acep = d.num("aceptados_pct").toFloat() / 100f
    val rech = d.num("rechazados_pct").toFloat() / 100f
    val adul = d.num("adulterada_pct").toFloat() / 100f
    var crecer by remember { mutableStateOf(0f) }
    LaunchedEffect(d) { crecer = 1f }
    val avance by animateFloatAsState(crecer, tween(1000, 200, FastOutSlowInEasing), label = "barra")

    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp))
            .background(Brush.linearGradient(listOf(Color(0xFF1C4F6E), Color(0xFF1E6E3E))))
            .padding(18.dp),
    ) {
        Text("📅  ${DIAS[diaSemana(dia)].replaceFirstChar { it.uppercase() }} ${fechaCorta(dia)}", style = Texto.Cuerpo, color = Color(0xFFD7EEF6))
        Row(verticalAlignment = Alignment.Bottom) {
            Text(formatear(litros.toDouble(), 0), style = Texto.Dato.copy(fontSize = 46.sp, fontWeight = FontWeight.Bold), color = Color.White)
            Text("  litros de leche", style = Texto.Fila, color = Color(0xFFD7EEF6), modifier = Modifier.padding(bottom = 10.dp))
        }
        Text("${d.num("entregas").toInt()} entregas · ${soles(d.num("a_pagar"))} a pagar", style = Texto.Cuerpo, color = Color(0xFFD7EEF6))
        Spacer(Modifier.height(14.dp))
        // la calidad del día en una sola barra que crece
        Row(Modifier.fillMaxWidth().height(12.dp).clip(RoundedCornerShape(99.dp)).background(Color(0x33FFFFFF))) {
            if (acep > 0) Box(Modifier.weight(acep * avance + 0.0001f).fillMaxHeight().background(Color(0xFF7BE3A1)))
            if (rech > 0) Box(Modifier.weight(rech * avance + 0.0001f).fillMaxHeight().background(Color(0xFFFF9C97)))
            if (adul > 0) Box(Modifier.weight(adul * avance + 0.0001f).fillMaxHeight().background(Color(0xFFB0453F)))
            Spacer(Modifier.weight((1f - (acep + rech + adul) * avance).coerceAtLeast(0.0001f)))
        }
        Spacer(Modifier.height(8.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
            Text("✅ ${formatear(d.num("aceptados_pct"), 0)} % aceptada", style = Texto.Apoyo, color = Color.White)
            if (rech > 0) Text("❌ ${formatear(d.num("rechazados_pct"), 0)} %", style = Texto.Apoyo, color = Color.White)
            if (adul > 0) Text("💧 ${formatear(d.num("adulterada_pct"), 0)} % con agua", style = Texto.Apoyo, color = Color.White)
        }
        val pend = d.num("pendientes").toInt()
        if (pend > 0) Text("🔬 $pend sin analizar todavía", style = Texto.Apoyo, color = Color(0xFFFFE3A3), modifier = Modifier.padding(top = 4.dp))
    }
}

/** Los últimos 7 días como barras que suben; tocar una muestra ese día. */
@Composable
private fun TiraDias(dias: List<JsonObject>, elegido: String) {
    val max = maxOf(dias.maxOfOrNull { it.num("litros") } ?: 0.0, 1.0)
    var crecer by remember { mutableStateOf(0f) }
    LaunchedEffect(Unit) { crecer = 1f }
    val avance by animateFloatAsState(crecer, tween(900, 150, FastOutSlowInEasing), label = "tira")
    Tarjeta {
        Text("ÚLTIMOS 7 DÍAS · toca uno", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
        Spacer(Modifier.height(10.dp))
        Row(Modifier.fillMaxWidth().height(130.dp), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.Bottom) {
            dias.forEach { x ->
                val f = x.txt("fecha")
                val sel = f == elegido
                Column(
                    Modifier.weight(1f).fillMaxHeight().clip(RoundedCornerShape(10.dp)).clickable { AcopioCalendario.fecha = f },
                    horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Bottom,
                ) {
                    Text(if (x.num("litros") > 0) formatear(x.num("litros"), 0) else "—", style = Texto.Apoyo.copy(fontSize = 11.sp),
                        color = if (sel) Altiplano.Texto else Altiplano.TextoTerciario, maxLines = 1)
                    Box(
                        Modifier.padding(top = 3.dp).fillMaxWidth(0.72f).height((78 * (x.num("litros") / max) * avance).dp.coerceAtLeast(3.dp))
                            .clip(RoundedCornerShape(topStart = 7.dp, topEnd = 7.dp, bottomStart = 3.dp, bottomEnd = 3.dp))
                            .background(if (sel) Brush.verticalGradient(listOf(Color(0xFF7BE3A1), Color(0xFF2E8B57))) else Brush.verticalGradient(listOf(Color(0xFF3D6C88), Color(0xFF27475C)))),
                    )
                    Text(DIAS[diaSemana(f)].take(2), style = Texto.Apoyo.copy(fontSize = 11.sp, fontWeight = if (sel) FontWeight.Bold else FontWeight.Normal),
                        color = if (sel) VERDE else Altiplano.TextoTerciario, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 4.dp))
                    Text(f.takeLast(2).trimStart('0'), style = Texto.Apoyo.copy(fontSize = 11.sp), color = if (sel) VERDE else Altiplano.TextoApagado)
                }
            }
        }
    }
}

/** El mes del día elegido (o el que se pasó con las flechitas) como calendario. */
@Composable
private fun MesCompleto(dia: String) {
    val cal = AcopioCalendario
    val mes = cal.mes ?: dia.take(7)
    var datos by remember { mutableStateOf<JsonObject?>(null) }
    LaunchedEffect(mes) { datos = runCatching { Sesion.consultar("/api/v1/admin/acopio/calendario?mes=$mes") }.getOrNull() }
    datos?.let { CalendarioMes(it) } ?: Text("Cargando el mes…", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
}

/** Un carro con el color de su ruta: litros que se llenan, su parte del día y la calidad. */
@Composable
private fun TarjetaCarro(v: JsonObject, color: Color, fraccion: Float) {
    var crecer by remember { mutableStateOf(0f) }
    LaunchedEffect(fraccion) { crecer = fraccion }
    val avance by animateFloatAsState(crecer, tween(900, 100, FastOutSlowInEasing), label = "carro")
    val rechazo = v.num("rechazo_pct")
    val adult = v.num("adulteradas").toInt()
    Row(
        Modifier.fillMaxWidth().height(IntrinsicSize.Min).clip(RoundedCornerShape(18.dp)).background(color.copy(alpha = 0.10f)),
    ) {
        Box(Modifier.width(7.dp).fillMaxHeight().background(color))
        Column(Modifier.weight(1f).padding(14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(if (v.txt("tipo").contains("MOTO")) "🛵" else "🚚", fontSize = 26.sp)
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(v.txt("codigo"), style = Texto.Fila.copy(fontWeight = FontWeight.Bold), color = color)
                    Text(v.txt("acopiador").ifEmpty { "sin acopiador" }, style = Texto.Apoyo, color = Altiplano.TextoSecundario)
                }
                Text("${formatear(v.num("litros"), 0)} L", style = Texto.Dato.copy(fontSize = 22.sp), color = Altiplano.Texto)
            }
            Spacer(Modifier.height(10.dp))
            Box(Modifier.fillMaxWidth().height(9.dp).clip(RoundedCornerShape(99.dp)).background(Color(0x22FFFFFF))) {
                Box(Modifier.fillMaxWidth(avance.coerceIn(0f, 1f)).fillMaxHeight().clip(RoundedCornerShape(99.dp)).background(color))
            }
            Spacer(Modifier.height(8.dp))
            Text(v.txt("ruta"), style = Texto.Apoyo, color = Altiplano.TextoTerciario, maxLines = 2)
            if (v.num("analisis") > 0) Text(
                "Grasa ${formatear(v.num("grasa"), 2)} % · ${formatear(v.num("temperatura"))} °C · rechazo ${formatear(rechazo)} %" +
                    if (adult > 0) " · 💧 $adult con agua" else "",
                style = Texto.Apoyo, color = if (rechazo > 8 || adult > 0) ROSA else Altiplano.TextoSecundario, modifier = Modifier.padding(top = 2.dp),
            )
        }
    }
}
