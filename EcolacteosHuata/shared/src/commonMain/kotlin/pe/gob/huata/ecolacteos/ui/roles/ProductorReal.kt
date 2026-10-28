package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.animation.core.*
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
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
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import pe.gob.huata.ecolacteos.datos.Sesion
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*
import kotlin.time.Clock

/**
 * Productor con datos de la base (GET /productor/resumen), con el estilo de Planta: su leche de hoy
 * en grande con el camino que sigue hasta el pago, sus 7 días como barras de colores y cada pago
 * con su franja (verde pagado, ámbar por pagar). La pestaña Comunidad sigue con la pantalla de siempre.
 */
@Composable
fun ContenidoProductorReal(ruta: String, e: EstadoProductor) {
    if (ruta == "productor/comunidad") { ComunidadProductorReal(); return }

    CargaServidor("/api/v1/productor/resumen") { d, recargar ->
        val prov = d.obj("proveedor") ?: JsonObject(emptyMap())
        when (ruta) {
            "productor/hoy" -> HoyReal(prov, d.obj("hoy"), d.lista("pagos").firstOrNull(), d.lista("ultimos"), recargar)
            "productor/semana" -> SemanaProductorReal(prov, d.lista("ultimos"))
            else -> PagosReal(d.lista("pagos"))
        }
    }
}

private fun estadoEntrega(x: JsonObject): Estado = when {
    x.txt("ausente") == "true" -> Estado.NO_ENTREGO
    x.txt("estado") == "ACEPTADO" -> Estado.ACEPTADO
    x.txt("estado") == "RECHAZADO" -> Estado.RECHAZADO
    else -> Estado.PENDIENTE
}

/** Color de la barra de un día: verde aceptada, rosa rechazada, ámbar sin analizar, gris si no entregó. */
private fun colorEntrega(x: JsonObject?): Color = when (x?.let { estadoEntrega(it) }) {
    Estado.ACEPTADO -> VERDE
    Estado.RECHAZADO -> ROSA
    Estado.PENDIENTE -> AMBAR
    else -> Color(0xFF5B6B79)
}

/** Fecha ISO de hoy menos [atras] días, en hora de Puno (UTC−5). */
private fun fechaIso(atras: Int = 0): String {
    val z = (Clock.System.now().epochSeconds - 5 * 3600).floorDiv(86400) - atras + 719468
    val era = z.floorDiv(146097)
    val doe = z - era * 146097
    val yoe = (doe - doe / 1460 + doe / 36524 - doe / 146096) / 365
    val doy = doe - (365 * yoe + yoe / 4 - yoe / 100)
    val mp = (5 * doy + 2) / 153
    val dia = doy - (153 * mp + 2) / 5 + 1
    val mes = if (mp < 10) mp + 3 else mp - 9
    val anio = yoe + era * 400 + if (mes <= 2) 1 else 0
    return "$anio-${mes.toString().padStart(2, '0')}-${dia.toString().padStart(2, '0')}"
}

private val DIAS_CORTOS = listOf("Lu", "Ma", "Mi", "Ju", "Vi", "Sá", "Do")

@Composable
private fun HoyReal(prov: JsonObject, hoy: JsonObject?, enCurso: JsonObject?, ultimos: List<JsonObject>, recargar: () -> Unit) {
    val scope = rememberCoroutineScope()
    var enviando by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    PantallaScroll {
        Aparece(0) {
            Column {
                Text(saludo() + ", ${prov.txt("nombre").substringBefore(' ')} 👋", style = Texto.TituloPantalla.copy(fontSize = 24.sp), color = Altiplano.Texto)
                Text(
                    listOf(prov.txt("comunidad"), prov.txt("vehiculo").takeIf { it.isNotEmpty() }?.let { "carro $it" }, prov.txt("acopiador"))
                        .filter { !it.isNullOrEmpty() }.joinToString(" · "),
                    style = Texto.Cuerpo, color = Altiplano.TextoTerciario,
                )
            }
        }
        Aparece(1) { HeroLecheHoy(hoy, prov) }
        if (hoy != null && hoy.txt("ausente") != "true") Aparece(2) { CaminoLeche(hoy) }

        if (hoy != null && hoy.txt("ausente") != "true") Aparece(3) {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                if (hoy.txt("confirmada") == "true") AvisoCampo("✔ Confirmaste que la cantidad es correcta.")
                else {
                    BotonPrimario(if (enviando) "Enviando…" else "✔ Sí, entregué ${formatear(hoy.num("litros"))} L", habilitado = !enviando) {
                        scope.launch {
                            enviando = true; error = null
                            try { Sesion.confirmarEntrega(hoy.num("id").toLong()); recargar() }
                            catch (t: Throwable) { error = t.message }
                            enviando = false
                        }
                    }
                    error?.let { AvisoCampo("No se pudo confirmar: $it", esAlerta = true) }
                    Text("Si no coincide con lo que entregaste, avisa hoy al acopiador. Así se evitan reclamos el viernes.",
                        style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                }
            }
        }
        enCurso?.let { Aparece(4) { PagoSemana(it) } }
        if (ultimos.isNotEmpty()) Aparece(5) { TiraSemana(ultimos, conTitulo = true) }
    }
}

/** Lo de hoy en grande sobre el degradado del lago: los litros que cuentan desde 0, o que el carro todavía no pasa. */
@Composable
private fun HeroLecheHoy(hoy: JsonObject?, prov: JsonObject) {
    var meta by remember { mutableStateOf(0f) }
    LaunchedEffect(hoy) { meta = hoy?.num("litros")?.toFloat() ?: 0f }
    val litros by animateFloatAsState(meta, tween(900, easing = FastOutSlowInEasing), label = "litros")
    val promedio = prov.num("promedio_litros")
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp))
            .background(Brush.linearGradient(listOf(Color(0xFF1C4F6E), Color(0xFF1E6E3E))))
            .padding(18.dp),
    ) {
        Text("🥛  TU LECHE DE HOY", style = Texto.Eyebrow, color = Color(0xFFD7EEF6))
        when {
            hoy == null -> {
                Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(top = 8.dp)) {
                    Text("🚚", fontSize = 40.sp)
                    Spacer(Modifier.width(12.dp))
                    Column {
                        Text("El carro todavía no pasa", style = Texto.Fila.copy(fontWeight = FontWeight.Bold), color = Color.White)
                        Text("Cuando anoten tu leche sale aquí al momento.", style = Texto.Apoyo, color = Color(0xFFD7EEF6))
                    }
                }
            }
            hoy.txt("ausente") == "true" -> {
                Text("No entregaste", style = Texto.Dato.copy(fontSize = 34.sp, fontWeight = FontWeight.Bold), color = Color.White,
                    modifier = Modifier.padding(top = 6.dp))
                Text("El acopiador anotó que hoy no hubo leche · ${hoy.txt("hora")}", style = Texto.Apoyo, color = Color(0xFFD7EEF6))
            }
            else -> {
                Row(verticalAlignment = Alignment.Bottom) {
                    Text(formatear(litros.toDouble()), style = Texto.Dato.copy(fontSize = 46.sp, fontWeight = FontWeight.Bold), color = Color.White)
                    Text("  litros", style = Texto.Fila, color = Color(0xFFD7EEF6), modifier = Modifier.padding(bottom = 10.dp))
                }
                Text("Anotado a las ${hoy.txt("hora")} por ${prov.txt("acopiador").ifEmpty { "el acopiador" }}", style = Texto.Cuerpo, color = Color(0xFFD7EEF6))
                if (promedio > 0) {
                    val dif = hoy.num("litros") - promedio
                    Text(
                        when {
                            dif > 0.5 -> "▲ ${formatear(dif)} L más que tu promedio (${formatear(promedio)} L)"
                            dif < -0.5 -> "▼ ${formatear(-dif)} L menos que tu promedio (${formatear(promedio)} L)"
                            else -> "● Igual que tu promedio (${formatear(promedio)} L)"
                        },
                        style = Texto.Apoyo, color = if (dif < -0.5) Color(0xFFFFE3A3) else Color(0xFF7BE3A1), modifier = Modifier.padding(top = 4.dp),
                    )
                }
            }
        }
    }
}

/** El camino de la leche de hoy: anotada → analizada → aceptada → se paga el viernes. */
@Composable
private fun CaminoLeche(hoy: JsonObject) {
    val estado = estadoEntrega(hoy)
    val rechazada = estado == Estado.RECHAZADO
    val pasos = listOf(
        Triple("🚚", "Anotada", true),
        Triple("🔬", if (estado == Estado.PENDIENTE) "Por analizar" else "Analizada", estado != Estado.PENDIENTE),
        Triple(if (rechazada) "❌" else "✅", if (rechazada) "Rechazada" else "Aceptada", estado == Estado.ACEPTADO || rechazada),
        Triple("💰", if (rechazada) "No se paga" else "Viernes", false),
    )
    Tarjeta {
        Text("EL CAMINO DE TU LECHE", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
        Spacer(Modifier.height(12.dp))
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
            pasos.forEachIndexed { k, (emoji, texto, hecho) ->
                val color = when {
                    rechazada && k == 2 -> ROSA
                    hecho -> VERDE
                    else -> Color(0xFF5B6B79)
                }
                Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
                    Box(
                        Modifier.size(44.dp).clip(CircleShape).background(color.copy(alpha = if (hecho) 0.22f else 0.10f))
                            .border(2.dp, color.copy(alpha = if (hecho) 0.9f else 0.35f), CircleShape),
                        contentAlignment = Alignment.Center,
                    ) { Text(emoji, fontSize = 20.sp) }
                    Text(texto, style = Texto.Apoyo.copy(fontSize = 11.sp, fontWeight = if (hecho) FontWeight.Bold else FontWeight.Normal),
                        color = if (hecho) Altiplano.Texto else Altiplano.TextoTerciario, textAlign = TextAlign.Center,
                        modifier = Modifier.padding(top = 6.dp))
                }
                if (k < pasos.lastIndex) Box(
                    Modifier.padding(top = 21.dp).weight(0.35f).height(2.dp)
                        .background(if (pasos[k + 1].third) VERDE else Color(0xFF3A4856)),
                )
            }
        }
        if (rechazada) Text("Pregunta al analista de calidad por qué; te ayuda a que mañana pase.",
            style = Texto.Apoyo, color = ROSA, modifier = Modifier.padding(top = 10.dp))
    }
}

/** Lo que va ganando esta semana y cuántos días faltan para el viernes, con la semana como barra que se llena. */
@Composable
private fun PagoSemana(p: JsonObject) {
    val hoy = diaSemana(fechaIso())
    val faltan = (4 - hoy + 7) % 7
    var crecer by remember { mutableStateOf(0f) }
    LaunchedEffect(p) { crecer = 1f }
    val avance by animateFloatAsState(crecer, tween(900, 150, FastOutSlowInEasing), label = "semana")
    Row(Modifier.fillMaxWidth().height(IntrinsicSize.Min).clip(RoundedCornerShape(18.dp)).background(VERDE.copy(alpha = 0.10f))) {
        Box(Modifier.width(7.dp).fillMaxHeight().background(VERDE))
        Column(Modifier.weight(1f).padding(14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("💰", fontSize = 26.sp)
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text("Tu pago de esta semana", style = Texto.Fila.copy(fontWeight = FontWeight.Bold), color = VERDE)
                    Text("${formatear(p.num("litros"))} L aceptados × ${soles(p.num("precio"))}", style = Texto.Apoyo, color = Altiplano.TextoSecundario)
                }
                Text(soles(p.num("monto")), style = Texto.Dato.copy(fontSize = 22.sp), color = Altiplano.Texto)
            }
            Spacer(Modifier.height(10.dp))
            // lunes a domingo; lo pintado es lo que ya pasó de la semana
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                DIAS_CORTOS.forEachIndexed { k, dia ->
                    Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
                        Box(Modifier.fillMaxWidth().height(7.dp).clip(RoundedCornerShape(99.dp))
                            .background(if (k <= hoy) VERDE.copy(alpha = 0.35f + 0.65f * avance) else Color(0x22FFFFFF)))
                        Text(if (k == 4) "💰" else dia, style = Texto.Apoyo.copy(fontSize = 10.sp),
                            color = if (k == hoy) Altiplano.Texto else Altiplano.TextoTerciario, modifier = Modifier.padding(top = 3.dp))
                    }
                }
            }
            Text(
                when (faltan) { 0 -> "Hoy es día de pago"; 1 -> "Mañana es día de pago"; else -> "Faltan $faltan días para el viernes" },
                style = Texto.Apoyo, color = Altiplano.TextoTerciario, modifier = Modifier.padding(top = 4.dp),
            )
        }
    }
}

/** Los últimos 7 días como barras de colores (hoy a la derecha), aunque algún día no haya entrega. */
@Composable
private fun TiraSemana(ultimos: List<JsonObject>, conTitulo: Boolean) {
    val porFecha = ultimos.associateBy { it.txt("fecha").take(10) }
    val dias = (6 downTo 0).map { fechaIso(it) }
    val max = maxOf(ultimos.maxOfOrNull { it.num("litros") } ?: 0.0, 1.0)
    var crecer by remember { mutableStateOf(0f) }
    LaunchedEffect(Unit) { crecer = 1f }
    val avance by animateFloatAsState(crecer, tween(900, 150, FastOutSlowInEasing), label = "tira")
    Tarjeta {
        if (conTitulo) {
            Text("TUS ÚLTIMOS 7 DÍAS", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
            Spacer(Modifier.height(10.dp))
        }
        Row(Modifier.fillMaxWidth().height(120.dp), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.Bottom) {
            dias.forEachIndexed { k, f ->
                val x = porFecha[f]
                val litros = if (x?.txt("ausente") == "true") 0.0 else x?.num("litros") ?: 0.0
                val esHoy = k == dias.lastIndex
                val color = colorEntrega(x)
                Column(Modifier.weight(1f).fillMaxHeight(), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Bottom) {
                    Text(if (litros > 0) formatear(litros, 0) else "—", style = Texto.Apoyo.copy(fontSize = 11.sp),
                        color = if (esHoy) Altiplano.Texto else Altiplano.TextoTerciario, maxLines = 1)
                    Box(
                        Modifier.padding(top = 3.dp).fillMaxWidth(0.72f).height((70 * (litros / max) * avance).dp.coerceAtLeast(3.dp))
                            .clip(RoundedCornerShape(topStart = 7.dp, topEnd = 7.dp, bottomStart = 3.dp, bottomEnd = 3.dp))
                            .background(Brush.verticalGradient(listOf(color, color.copy(alpha = 0.55f)))),
                    )
                    Text(DIAS_CORTOS[diaSemana(f)], style = Texto.Apoyo.copy(fontSize = 11.sp, fontWeight = if (esHoy) FontWeight.Bold else FontWeight.Normal),
                        color = if (esHoy) VERDE else Altiplano.TextoTerciario, modifier = Modifier.padding(top = 4.dp))
                }
            }
        }
        Spacer(Modifier.height(8.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            listOf(VERDE to "Aceptada", ROSA to "Rechazada", AMBAR to "Por analizar").forEach { (c, t) ->
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(9.dp).clip(CircleShape).background(c))
                    Text(" $t", style = Texto.Apoyo.copy(fontSize = 11.sp), color = Altiplano.TextoTerciario)
                }
            }
        }
    }
}

@Composable
private fun SemanaProductorReal(prov: JsonObject, ultimos: List<JsonObject>) {
    val aceptadas = ultimos.filter { it.txt("estado") == "ACEPTADO" && it.txt("ausente") != "true" }
    val total = aceptadas.sumOf { it.num("litros") }
    val conLeche = ultimos.count { it.txt("ausente") != "true" }
    var meta by remember { mutableStateOf(0f) }
    LaunchedEffect(total) { meta = total.toFloat() }
    val litros by animateFloatAsState(meta, tween(900, easing = FastOutSlowInEasing), label = "total")
    PantallaScroll {
        Aparece(0) {
            Column(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp))
                    .background(Brush.linearGradient(listOf(Color(0xFF1C4F6E), Color(0xFF1E6E3E))))
                    .padding(18.dp),
            ) {
                Text("📊  TUS ÚLTIMOS 7 DÍAS", style = Texto.Eyebrow, color = Color(0xFFD7EEF6))
                Row(verticalAlignment = Alignment.Bottom) {
                    Text(formatear(litros.toDouble()), style = Texto.Dato.copy(fontSize = 46.sp, fontWeight = FontWeight.Bold), color = Color.White)
                    Text("  litros aceptados", style = Texto.Fila, color = Color(0xFFD7EEF6), modifier = Modifier.padding(bottom = 10.dp))
                }
                Text(
                    "$conLeche ${if (conLeche == 1) "día" else "días"} con leche · ${aceptadas.size} aceptadas" +
                        (prov.num("promedio_litros").takeIf { it > 0 }?.let { " · promedio ${formatear(it)} L" } ?: ""),
                    style = Texto.Cuerpo, color = Color(0xFFD7EEF6),
                )
            }
        }
        if (ultimos.isEmpty()) Aparece(1) { AvisoCampo("No tienes entregas en los últimos 7 días.") }
        else Aparece(1) { TiraSemana(ultimos, conTitulo = false) }
        ultimos.forEachIndexed { k, x ->
            Aparece(2 + k) {
                val color = colorEntrega(x)
                Row(Modifier.fillMaxWidth().height(IntrinsicSize.Min).clip(RoundedCornerShape(16.dp)).background(color.copy(alpha = 0.08f))) {
                    Box(Modifier.width(6.dp).fillMaxHeight().background(color))
                    Row(Modifier.weight(1f).padding(horizontal = 14.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text("${x.txt("dia")} ${fechaCorta(x.txt("fecha"))}", style = Texto.Fila, color = Altiplano.Texto)
                            Text(if (x.txt("ausente") == "true") "No entregó" else "Anotado a las ${x.txt("hora")}" +
                                if (x.txt("confirmada") == "true") " · ✔ confirmaste" else "",
                                style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                        }
                        if (x.txt("ausente") != "true") Text("${formatear(x.num("litros"))} L", style = Texto.Dato.copy(fontSize = 20.sp), color = Altiplano.Texto,
                            modifier = Modifier.padding(end = 10.dp))
                        ChipEstado(estadoEntrega(x))
                    }
                }
            }
        }
    }
}

@Composable
private fun PagosReal(pagos: List<JsonObject>) {
    val pagados = pagos.filter { it.txt("estado") == "PAGADO" }
    PantallaScroll {
        pagos.firstOrNull { it.txt("estado") == "EN_CURSO" }?.let { Aparece(0) { PagoSemana(it) } }
        if (pagados.isNotEmpty()) Aparece(1) {
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Tarjeta(Modifier.weight(1f)) {
                    Text("💵  Cobrado", style = Texto.Apoyo, color = VERDE)
                    Text(soles(pagados.sumOf { it.num("monto") }), style = Texto.Dato.copy(fontSize = 22.sp), color = Altiplano.Texto, maxLines = 1)
                    Text("en ${pagados.size} ${if (pagados.size == 1) "semana pagada" else "semanas pagadas"}", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                }
                Tarjeta(Modifier.weight(1f)) {
                    Text("🥛  Litros", style = Texto.Apoyo, color = CELESTE)
                    Text(formatear(pagados.sumOf { it.num("litros") }, 0) + " L", style = Texto.Dato.copy(fontSize = 22.sp), color = Altiplano.Texto, maxLines = 1)
                    Text("aceptados y pagados", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                }
            }
        }
        val anteriores = pagos.filter { it.txt("estado") != "EN_CURSO" }
        if (anteriores.isNotEmpty()) Aparece(2) { Text("SEMANAS ANTERIORES", style = Texto.Eyebrow, color = Altiplano.TextoTerciario, modifier = Modifier.padding(top = 6.dp)) }
        anteriores.forEachIndexed { k, p ->
            Aparece(3 + k) {
                val pagado = p.txt("estado") == "PAGADO"
                val color = if (pagado) VERDE else AMBAR
                Row(Modifier.fillMaxWidth().height(IntrinsicSize.Min).clip(RoundedCornerShape(16.dp)).background(color.copy(alpha = 0.08f))) {
                    Box(Modifier.width(6.dp).fillMaxHeight().background(color))
                    Column(Modifier.weight(1f).padding(horizontal = 14.dp, vertical = 12.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text("${fechaCorta(p.txt("inicio"))} – ${fechaCorta(p.txt("fin"))}", style = Texto.Fila, color = Altiplano.Texto)
                                Text(p.txt("semana"), style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                            }
                            Text(soles(p.num("monto")), style = Texto.Dato.copy(fontSize = 20.sp), color = Altiplano.Texto)
                        }
                        Text(
                            "${formatear(p.num("litros"))} L × ${soles(p.num("precio"))}" +
                                if (pagado) " · ✔ pagado" + (p.txt("pagado_el").takeIf { it.isNotEmpty() }?.let { " el ${fechaCorta(it)}" } ?: "") +
                                    (p.txt("metodo").takeIf { it.isNotEmpty() }?.let { " (${metodoTexto(it)})" } ?: "")
                                else " · ⏳ por pagar",
                            style = Texto.Apoyo, color = if (pagado) Altiplano.TextoSecundario else AMBAR, modifier = Modifier.padding(top = 4.dp),
                        )
                    }
                }
            }
        }
    }
}
