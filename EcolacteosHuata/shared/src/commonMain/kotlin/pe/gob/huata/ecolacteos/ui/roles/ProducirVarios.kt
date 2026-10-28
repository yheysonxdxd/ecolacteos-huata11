package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.animateContentSize
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.launch
import kotlinx.serialization.json.*
import pe.gob.huata.ecolacteos.datos.Sesion
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*
import kotlin.math.ceil
import kotlin.math.floor

/** Cuánto se quiere de cada producto al producir varios a la vez (0 = no se hace), qué familias están abiertas y la pestaña de Nueva orden. */
object VariosApp {
    val cantidad = mutableStateMapOf<Long, Double>()
    val abiertas = mutableStateMapOf<String, Boolean>()
    var modo by mutableStateOf(0) // Nueva orden: 0 un producto · 1 varios a la vez
}

/** Lo que se hará de un producto: cuántos, con cuántos litros. */
private class Elegido(val p: JsonObject, val q: Double, val litros: Double, val unidad: String)

/** Un insumo con lo que necesitan todos los productos elegidos y quiénes lo usan. */
private class Uso(val nombre: String, val unidad: String, val stock: Double, val necesita: Double, val productos: List<String>)

/** Una base con sus sabores (o todos los quesos juntos, [mezcla]), como se ve al producir. */
private class Familia(val clave: String, val nombre: String, val tipo: String, val unidad: String, val items: List<JsonObject>, val mezcla: Boolean)

/** Rendimiento mínimo por litro: /compras/formulas lo trae así; /planta/recetas, por cada 100 L. */
internal fun rendMin(p: JsonObject) = p.num("rendimiento_min").takeIf { it > 0 } ?: (p.num("min_100l") / 100)
internal fun rendMax(p: JsonObject) = p.num("rendimiento_max").takeIf { it > 0 } ?: (p.num("max_100l") / 100)

private fun hex(s: String) = Color(("FF" + s.removePrefix("#")).toLong(16))

/** Color de cada receta, el mismo de la web: el de la fruta en los sabores, crema o dorado en quesos y yogurt. */
internal fun colorReceta(p: JsonObject?): Color {
    val n = ((p?.txt("variante") ?: "") + " " + (p?.txt("nombre") ?: "")).lowercase()
    val frutas = listOf("fresa" to "#e5566f", "frambuesa" to "#d6336c", "mora" to "#8e3f8f", "arándano" to "#5b5fc7", "maracuy" to "#f0b429",
        "lúcuma" to "#c98a3c", "lucuma" to "#c98a3c", "plátano" to "#f2d14a", "platano" to "#f2d14a", "durazno" to "#f4a261", "mango" to "#f7a325",
        "piña" to "#e9c46a", "coco" to "#e9e4d8", "vainilla" to "#efe1b0", "guanábana" to "#cfe3c0", "aguaymanto" to "#f39c12", "chirimoya" to "#b5cf8a",
        "camu" to "#c0392b", "orégano" to "#7aa65a", "ají" to "#e76f51", "hierbas" to "#6a994e", "aceituna" to "#556b2f", "pimienta" to "#5c4a3d")
    frutas.firstOrNull { n.contains(it.first) }?.let { return hex(it.second) }
    return when (p?.txt("tipo")) {
        "QUESO" -> hex(if ("mantecoso" in n) "#f3e2a9" else if ("andino" in n) "#e7b54a" else "#f1cf6b")
        "YOGURT" -> hex("#e9e3d3")
        else -> hex("#9fc5e8")
    }
}

/** "quesos de 1 kg" → "quesos". */
private fun unidadDe(p: JsonObject) = p.txt("unidad").substringBefore(' ')

/** 0,02 kg → "20 g"; 0,01 L → "10 ml"; lo demás con su unidad. Igual que en la web. */
private fun cantidadBonita(c: Double, unidad: String): String {
    val u = unidad.trim()
    if (u == "kg" && c > 0 && c < 1) return formatear(c * 1000, if (c * 1000 < 10) 1 else 0) + " g"
    if (u == "L" && c > 0 && c < 1) return formatear(c * 1000, if (c * 1000 < 10) 1 else 0) + " ml"
    return formatear(c, if (c >= 100) 0 else 2) + " $u"
}

/** Cada base con sus sabores, separados en Quesos / Yogurt / Otros; los quesos, todos en un solo "Quesos". Como la web. */
private fun gruposProducir(productos: List<JsonObject>): List<Pair<String, List<Familia>>> {
    val ids = productos.map { it.num("id").toLong() }.toSet()
    val bases = productos.filter { it.txt("base_id").isEmpty() || it.num("base_id").toLong() !in ids }
    val familias = bases.map { b ->
        Familia(b.txt("id"), b.txt("nombre"), b.txt("tipo").ifEmpty { "OTRO" }, b.txt("unidad"),
            listOf(b) + productos.filter { it.txt("base_id") == b.txt("id") }, mezcla = false)
    }
    val quesos = familias.filter { it.tipo == "QUESO" }
    val quesosJuntos = if (quesos.size > 1) listOf(Familia("QUESOS", "Quesos", "QUESO", quesos.first().unidad, quesos.flatMap { it.items }, mezcla = true)) else quesos
    return listOf("QUESO" to "🧀  Quesos", "YOGURT" to "🥛  Yogurt", "OTRO" to "Otros")
        .map { (tipo, nombre) -> nombre to if (tipo == "QUESO") quesosJuntos else familias.filter { it.tipo == tipo } }
        .filter { it.second.isNotEmpty() }
}

/** Cómo se llama cada renglón dentro de su familia: "Queso Paria", "Fresa", "Natural". */
private fun nombreEnFamilia(fa: Familia, x: JsonObject) = when {
    fa.mezcla -> x.txt("nombre")
    x.txt("base_id").isNotEmpty() -> x.txt("variante")
    fa.items.size > 1 -> "Natural"
    else -> x.txt("nombre")
}

/** Lo que sale de lo elegido: qué se hace, cuánta leche, cuánto de cada insumo y qué falta. */
private class Plan(val elegidos: List<Elegido>, val litros: Double, val insumos: List<Uso>, val faltan: List<String>) {
    val ok get() = elegidos.isNotEmpty() && faltan.isEmpty()
    val texto get() = elegidos.joinToString(" y ") { "${formatear(it.q, 0)} ${it.unidad} de ${it.p.txt("nombre")}" }
    val lotes get() = "${elegidos.size} ${if (elegidos.size == 1) "lote" else "lotes"}"
}

private fun planProducir(productos: List<JsonObject>, tanque: Double): Plan {
    val elegidos = productos.mapNotNull { p ->
        val q = VariosApp.cantidad[p.num("id").toLong()] ?: 0.0
        val min = rendMin(p)
        if (q <= 0 || min <= 0) null
        else Elegido(p, q, ceil(q / min - 1e-9), unidadDe(p))
    }
    val litros = elegidos.sumOf { it.litros }
    val insumos = elegidos.flatMap { e -> e.p.lista("insumos").map { i -> i to e } }
        .groupBy { it.first.num("id").toLong() }
        .map { (_, usos) ->
            val i = usos.first().first
            Uso(i.txt("nombre"), i.txt("unidad"), i.num("stock"),
                usos.sumOf { (x, e) -> cantidadInsumo(x.num("por_100l"), e.litros, x.txt("unidad")) }, usos.map { it.second.p.txt("nombre") })
        }
        .sortedWith(compareByDescending<Uso> { it.necesita > it.stock + 1e-9 }.thenByDescending { it.productos.size }.thenBy { it.nombre })
    val faltan = buildList {
        if (litros > tanque) add("leche (${formatear(litros - tanque, 0)} L)")
        insumos.filter { it.necesita > it.stock + 1e-9 }.forEach { add("${it.nombre.lowercase()} (${cantidadBonita(it.necesita - it.stock, it.unidad)})") }
    }
    return Plan(elegidos, litros, insumos, faltan)
}

/**
 * "¿Qué vas a producir hoy?", como en la web: el tanque con lo que se usa, cada base con sus sabores
 * (− / + y "máx."), lo que se usa de cada insumo y si alcanza. Abajo, fija, una barra que dice si alcanza
 * sin tener que bajar. Con [puedeIniciar] se abren todos los lotes juntos; si algo falta, ninguno.
 * [arriba] (avisos) y [abajo] (recetas) van en el mismo scroll.
 */
@Composable
fun PantallaProducir(
    productos: List<JsonObject>, tanque: Double, puedeIniciar: Boolean, onListo: (Noti) -> Unit,
    arriba: @Composable ColumnScope.() -> Unit = {}, abajo: @Composable ColumnScope.() -> Unit = {},
) {
    var confirmar by remember { mutableStateOf(false) }
    val plan = planProducir(productos, tanque)
    Box(Modifier.fillMaxSize()) {
        Column(
            Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(Medidas.Pantalla)
                .padding(bottom = if (plan.elegidos.isNotEmpty()) 92.dp else 0.dp),
            verticalArrangement = Arrangement.spacedBy(Medidas.Hueco),
        ) {
            arriba()
            ContenidoProducir(productos, tanque, plan, puedeIniciar) { confirmar = true }
            abajo()
        }
        AnimatedVisibility(
            plan.elegidos.isNotEmpty(), modifier = Modifier.align(Alignment.BottomCenter),
            enter = slideInVertically { it } + fadeIn(), exit = slideOutVertically { it } + fadeOut(),
        ) { BarraProducir(plan, tanque, puedeIniciar) { confirmar = true } }
    }
    if (confirmar) ConfirmarVarios(plan.elegidos, plan.texto, onCerrar = { confirmar = false }, onListo = onListo)
}

@Composable
private fun ColumnScope.ContenidoProducir(productos: List<JsonObject>, tanque: Double, plan: Plan, puedeIniciar: Boolean, onIniciar: () -> Unit) {
    val litrosDe = { p: JsonObject -> plan.elegidos.firstOrNull { it.p === p }?.litros ?: 0.0 }
    /** Lo más que se puede hacer de [p] con la leche que queda (sin contar lo que ya usa p) y sus insumos. */
    val maximoDe = { p: JsonObject ->
        val libre = (tanque - (plan.litros - litrosDe(p))).coerceAtLeast(0.0)
        val alcanza = (p["alcanza_litros"] as? JsonPrimitive)?.doubleOrNull
        floor((if (alcanza == null) libre else minOf(libre, alcanza)) * rendMin(p) + 1e-9)
    }

    TanqueLeche(tanque, plan.elegidos, plan.litros, plan.ok)

    Column(Modifier.padding(top = 6.dp)) {
        Text("¿Qué vas a producir hoy?", style = Texto.TituloPantalla.copy(fontSize = 21.sp), color = Altiplano.Texto)
        Text("Pon cuánto quieres de cada uno con − y +. Abajo ves al toque si alcanzan la leche y los insumos.",
            style = Texto.Apoyo, color = Altiplano.TextoTerciario, modifier = Modifier.padding(top = 2.dp))
    }

    gruposProducir(productos).forEach { (grupo, familias) ->
        Seccion(grupo)
        familias.forEach { fa -> FamiliaProducir(fa, litrosDe, maximoDe) }
    }

    if (plan.elegidos.isNotEmpty() && plan.insumos.isNotEmpty()) {
        Seccion("Lo que se usa")
        Tarjeta(fondo = Color(0xFF16232F), borde = Altiplano.BordeSuave) {
            plan.insumos.forEachIndexed { k, i -> if (k > 0) Spacer(Modifier.height(10.dp)); FilaInsumo(i) }
        }
    }

    // el veredicto: al cambiar de sí a no (o al revés) vuelve a entrar con un rebote
    AnimatedContent(
        targetState = if (plan.elegidos.isEmpty()) 0 else if (plan.ok) 1 else 2,
        transitionSpec = { (scaleIn(spring(dampingRatio = Spring.DampingRatioMediumBouncy), initialScale = .92f) + fadeIn()) togetherWith fadeOut() },
    ) { estado ->
        when (estado) {
            0 -> Tarjeta(fondo = Color(0xFF16232F), borde = Altiplano.BordeSuave) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("☝", fontSize = 22.sp); Spacer(Modifier.width(12.dp))
                    Text("Elige qué hacer y aquí ves si alcanza.", style = Texto.Cuerpo, color = Altiplano.TextoTerciario)
                }
            }
            1 -> Veredicto("✓ Sí se puede", "Vas a hacer ${plan.texto}.", Altiplano.Exito, Altiplano.ExitoFondo)
            else -> Veredicto("✗ No alcanza", "Falta ${plan.faltan.joinToString(", ")}. Baja alguna cantidad o consigue lo que falta.", Altiplano.Alerta, Altiplano.AlertaFondo)
        }
    }
    if (puedeIniciar) {
        BotonPrimario(if (plan.elegidos.isEmpty()) "Iniciar lotes" else "Iniciar ${plan.lotes}", habilitado = plan.ok, onClick = onIniciar)
    } else if (plan.ok) {
        Text("Los lotes los inicia el operario o el administrador.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
    }
    if (plan.elegidos.isNotEmpty()) {
        TextButton(onClick = { VariosApp.cantidad.clear() }, modifier = Modifier.fillMaxWidth()) {
            Text("Limpiar todo", color = Altiplano.TextoTerciario)
        }
    }
}

/** La barra fija de abajo: si alcanza, cuánta leche se usa (con los colores del tanque) y el botón para iniciar. */
@Composable
private fun BarraProducir(plan: Plan, tanque: Double, puedeIniciar: Boolean, onIniciar: () -> Unit) {
    val color = if (plan.ok) Altiplano.Exito else Altiplano.Alerta
    val total = maxOf(tanque, plan.litros, 0.0001)
    Surface(shape = RoundedCornerShape(18.dp), color = Color(0xFF13202B), border = BorderStroke(1.dp, color.copy(alpha = .55f)),
        shadowElevation = 12.dp, modifier = Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 10.dp)) {
        Column {
            // el tanque en chiquito: un tramo de color por producto
            Row(Modifier.fillMaxWidth().height(4.dp).background(Color(0xFF223444))) {
                plan.elegidos.forEach { e ->
                    val w by animateFloatAsState((e.litros / total).toFloat(), spring(stiffness = Spring.StiffnessLow), label = "mini")
                    if (w > 0.001f) Box(Modifier.weight(w).fillMaxHeight().background(colorReceta(e.p)))
                }
                val libre = ((total - plan.litros) / total).toFloat()
                if (libre > 0.001f) Spacer(Modifier.weight(libre))
            }
            Row(Modifier.padding(start = 16.dp, end = 10.dp, top = 10.dp, bottom = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(if (plan.ok) "✓ Sí alcanza" else "✗ No alcanza", style = Texto.Fila.copy(fontWeight = FontWeight.Bold), color = color)
                    Text("Usas ${formatear(plan.litros, 0)} de ${formatear(tanque, 0)} L de leche", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                }
                if (puedeIniciar) {
                    Surface(onClick = { if (plan.ok) onIniciar() }, shape = RoundedCornerShape(12.dp),
                        color = if (plan.ok) Altiplano.VerdeMarca else Color(0xFF223444),
                        border = BorderStroke(1.dp, if (plan.ok) Altiplano.Exito.copy(alpha = .6f) else Altiplano.Borde)) {
                        Text("Iniciar ${plan.lotes}", style = Texto.Fila.copy(fontSize = 15.sp, fontWeight = FontWeight.SemiBold),
                            color = if (plan.ok) Altiplano.Texto else Altiplano.TextoApagado, modifier = Modifier.padding(horizontal = 16.dp, vertical = 12.dp))
                    }
                }
            }
        }
    }
}

/** El tanque: una barra con un tramo de color por producto elegido, lo que queda libre y, si se pasa, en rojo. */
@Composable
private fun TanqueLeche(tanque: Double, elegidos: List<Elegido>, litros: Double, ok: Boolean) {
    val uso by animateFloatAsState(litros.toFloat(), tween(500), label = "uso")
    val pasa = litros > tanque + 1e-9
    val total = maxOf(tanque, litros, 0.0001)
    Surface(shape = RoundedCornerShape(18.dp), color = Color(0xFF14212C), border = BorderStroke(1.dp, if (pasa) Altiplano.AlertaBorde else Altiplano.VerdeBorde)) {
        Column(Modifier.fillMaxWidth().padding(16.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("🥛  TANQUE DE LECHE", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
                    Row(verticalAlignment = Alignment.Bottom) {
                        Text(formatear(tanque, 0), style = Texto.DatoMedio, color = Altiplano.Texto)
                        Text(" L", style = Texto.Fila, color = Altiplano.TextoTerciario, modifier = Modifier.padding(bottom = 4.dp))
                    }
                }
                if (elegidos.isNotEmpty()) {
                    val c = if (ok) Altiplano.Exito else Altiplano.Alerta
                    Text(if (ok) "✓ Alcanza" else "✗ No alcanza", style = Texto.Apoyo.copy(fontWeight = FontWeight.SemiBold), color = c,
                        modifier = Modifier.background(c.copy(alpha = .14f), RoundedCornerShape(99.dp)).border(1.dp, c.copy(alpha = .4f), RoundedCornerShape(99.dp))
                            .padding(horizontal = 10.dp, vertical = 5.dp))
                }
            }
            Spacer(Modifier.height(12.dp))
            // la barra: los tramos crecen con resorte; lo libre es el resto. Si se pasa, lo que no hay va en rojo tras una marca blanca
            Box(Modifier.fillMaxWidth().height(22.dp).clip(RoundedCornerShape(11.dp)).background(Color(0xFF223444))) {
                Row(Modifier.fillMaxSize()) {
                    elegidos.forEach { e ->
                        val w by animateFloatAsState((e.litros / total).toFloat(), spring(stiffness = Spring.StiffnessLow), label = "tramo")
                        if (w > 0.001f) Box(Modifier.weight(w).fillMaxHeight().background(colorReceta(e.p)))
                    }
                    val libre by animateFloatAsState(((total - litros) / total).toFloat().coerceAtLeast(0f), spring(stiffness = Spring.StiffnessLow), label = "libre")
                    if (libre > 0.001f) Spacer(Modifier.weight(libre))
                }
                if (pasa) Row(Modifier.fillMaxSize()) {
                    val hay = (tanque / total).toFloat().coerceIn(0.001f, 0.999f)
                    Spacer(Modifier.weight(hay))
                    Box(Modifier.width(3.dp).fillMaxHeight().background(Color.White))
                    Box(Modifier.weight(1f - hay).fillMaxHeight().background(Altiplano.Alerta.copy(alpha = .8f)))
                }
            }
            Row(Modifier.fillMaxWidth().padding(top = 8.dp)) {
                Text("Usas ${formatear(uso.toDouble(), 0)} L", style = Texto.Cuerpo, color = Altiplano.TextoSecundario, modifier = Modifier.weight(1f))
                Text(if (uso > tanque) "Faltan ${formatear(uso - tanque, 0)} L" else "Quedan ${formatear(tanque - uso, 0)} L",
                    style = Texto.Cuerpo.copy(fontWeight = FontWeight.SemiBold), color = if (uso > tanque) Altiplano.Alerta else Altiplano.Exito)
            }
            if (elegidos.isNotEmpty()) {
                FlowRow(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    elegidos.forEach { e ->
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Box(Modifier.size(9.dp).background(colorReceta(e.p), CircleShape))
                            Text("  ${e.p.txt("nombre")} · ${formatear(e.litros, 0)} L", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                        }
                    }
                }
            }
        }
    }
}

/** Una base con su dibujo: cerrada dice cuántos sabores tiene (o lo elegido); abierta, un renglón por sabor. */
@Composable
private fun FamiliaProducir(fa: Familia, litrosDe: (JsonObject) -> Double, maximoDe: (JsonObject) -> Double) {
    val tono = colorReceta(fa.items.first())
    val activa = fa.items.any { (VariosApp.cantidad[it.num("id").toLong()] ?: 0.0) > 0 }
    val sola = fa.items.size == 1
    val abierta = sola || VariosApp.abiertas[fa.clave] == true
    val borde by animateColorAsState(if (activa) tono.copy(alpha = .7f) else Altiplano.Borde, label = "borde")
    val giro by animateFloatAsState(if (abierta) 180f else 0f, label = "flecha")
    val u = if (unidadDe(fa.items.first()) == "litros") "L" else unidadDe(fa.items.first())

    Surface(shape = RoundedCornerShape(18.dp), color = if (activa) Color(0xFF1A2B37) else Color(0xFF17242F), border = BorderStroke(1.dp, borde),
        modifier = Modifier.fillMaxWidth().animateContentSize()) {
        Column {
            Row(
                Modifier.fillMaxWidth().then(if (sola) Modifier else Modifier.clickable { VariosApp.abiertas[fa.clave] = !abierta })
                    .padding(horizontal = 14.dp, vertical = 12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Box(Modifier.size(58.dp, 48.dp).background(tono.copy(alpha = .12f), RoundedCornerShape(14.dp)), contentAlignment = Alignment.Center) {
                    DibujoReceta(fa.tipo, tono, Modifier.size(46.dp, 38.dp))
                }
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(if (fa.mezcla) "Quesos" else fa.nombre.replace(Regex("\\s+natural$", RegexOption.IGNORE_CASE), ""),
                        style = Texto.Fila.copy(fontWeight = FontWeight.SemiBold), color = Altiplano.Texto)
                    val elegidosTxt = fa.items.filter { (VariosApp.cantidad[it.num("id").toLong()] ?: 0.0) > 0 }.joinToString(" · ") {
                        formatear(VariosApp.cantidad[it.num("id").toLong()] ?: 0.0, 0) + " " +
                            if (fa.mezcla) it.txt("nombre").replace(Regex("^queso\\s+", RegexOption.IGNORE_CASE), "") else "$u ${nombreEnFamilia(fa, it).lowercase()}"
                    }
                    if (!sola) Text(elegidosTxt.ifEmpty { "${fa.items.size} " + (if (fa.mezcla) "tipos de queso" else if (fa.tipo == "YOGURT") "sabores" else "variedades") + " · toca para elegir" },
                        style = Texto.Apoyo, color = if (elegidosTxt.isEmpty()) Altiplano.TextoTerciario else tono)
                }
                if (!sola) {
                    // un puntito por sabor: se llena cuando se elige
                    Row(horizontalArrangement = Arrangement.spacedBy(3.dp)) {
                        fa.items.forEach { x ->
                            val lleno = (VariosApp.cantidad[x.num("id").toLong()] ?: 0.0) > 0
                            Box(Modifier.size(if (lleno) 9.dp else 7.dp).background(colorReceta(x).copy(alpha = if (lleno) 1f else .35f), CircleShape))
                        }
                    }
                    Spacer(Modifier.width(10.dp))
                    Text("▾", color = Altiplano.TextoTerciario, fontSize = 16.sp, modifier = Modifier.rotate(giro))
                }
            }
            AnimatedVisibility(abierta, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
                Column(Modifier.padding(start = 10.dp, end = 10.dp, bottom = 10.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    fa.items.forEach { x -> ItemProducir(fa, x, litrosDe(x), maximoDe(x)) }
                }
            }
        }
    }
}

/** Un sabor (o un queso): su punto de color, cuánto rinde o cuánta leche usa, − cantidad + y "máx.". */
@Composable
private fun ItemProducir(fa: Familia, x: JsonObject, litros: Double, maximo: Double) {
    val id = x.num("id").toLong()
    val q = VariosApp.cantidad[id] ?: 0.0
    val elegido = q > 0
    val tono = colorReceta(x)
    val u = unidadDe(x)
    val paso = if (u == "litros") 5.0 else 1.0
    val fondo by animateColorAsState(if (elegido) tono.copy(alpha = .1f) else Color(0xFF1C2C39), label = "fondo")
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(fondo).padding(horizontal = 12.dp, vertical = 10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            if (fa.items.size > 1) { Box(Modifier.size(10.dp).background(tono, CircleShape)); Spacer(Modifier.width(10.dp)) }
            Column(Modifier.weight(1f)) {
                Text(nombreEnFamilia(fa, x), style = Texto.Fila, color = Altiplano.Texto)
                Text(if (elegido) "usa ${formatear(litros, 0)} L de leche" else "rinde ${formatear(rendMin(x) * 100, 1)} $u por 100 L",
                    style = Texto.Apoyo, color = if (elegido) tono else Altiplano.TextoTerciario)
            }
            if (maximo > 0 && q != maximo) {
                Text("máx. ${formatear(maximo, 0)}", style = Texto.Apoyo.copy(fontWeight = FontWeight.SemiBold), color = Altiplano.CelesteAcento,
                    modifier = Modifier.clip(RoundedCornerShape(99.dp)).clickable { VariosApp.cantidad[id] = maximo }
                        .border(1.dp, Altiplano.CelesteAcento.copy(alpha = .35f), RoundedCornerShape(99.dp)).padding(horizontal = 9.dp, vertical = 4.dp))
            }
        }
        Row(Modifier.padding(top = 8.dp), verticalAlignment = Alignment.CenterVertically) {
            BotonPaso("−", habilitado = elegido) { VariosApp.cantidad[id] = (q - paso).coerceAtLeast(0.0) }
            var texto by remember(q) { mutableStateOf(if (q > 0) formatear(q, 0) else "") }
            BasicTextField(
                value = texto,
                onValueChange = { t -> texto = t.filter { it.isDigit() }.take(5); VariosApp.cantidad[id] = texto.toDoubleOrNull() ?: 0.0 },
                singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                textStyle = TextStyle(color = Altiplano.Texto, fontSize = 20.sp, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center),
                cursorBrush = SolidColor(Altiplano.Exito),
                modifier = Modifier.padding(horizontal = 8.dp).width(84.dp).height(46.dp)
                    .background(Color(0xFF0F1A24), RoundedCornerShape(12.dp)).border(1.dp, if (elegido) tono.copy(alpha = .6f) else Altiplano.Borde, RoundedCornerShape(12.dp))
                    .padding(top = 11.dp),
                decorationBox = { campo -> Box(contentAlignment = Alignment.Center) { if (texto.isEmpty()) Text("0", color = Altiplano.TextoApagado, fontSize = 20.sp); campo() } },
            )
            BotonPaso("+", habilitado = true, resaltado = true) { VariosApp.cantidad[id] = q + paso }
            Spacer(Modifier.width(10.dp))
            Text(u, style = Texto.Apoyo, color = Altiplano.TextoTerciario)
        }
    }
}

/** − o + redondo, grande para el dedo. */
@Composable
private fun BotonPaso(signo: String, habilitado: Boolean, resaltado: Boolean = false, onClick: () -> Unit) {
    Surface(onClick = { if (habilitado) onClick() }, shape = CircleShape, modifier = Modifier.size(46.dp),
        color = if (resaltado) Altiplano.VerdeMarcaSuave else Color(0xFF223444),
        border = BorderStroke(1.dp, if (resaltado) Altiplano.VerdeBorde else Altiplano.Borde)) {
        Box(contentAlignment = Alignment.Center) {
            Text(signo, fontSize = 22.sp, fontWeight = FontWeight.SemiBold, color = if (!habilitado) Altiplano.TextoApagado else if (resaltado) Altiplano.Exito else Altiplano.Texto)
        }
    }
}

/** Un insumo de lo que se usa: cuánto se necesita, una barra con lo que hay y si falta. */
@Composable
private fun FilaInsumo(i: Uso) {
    val falta = i.necesita > i.stock + 1e-9
    val cubre by animateFloatAsState(if (i.necesita > 0) (i.stock / i.necesita).toFloat().coerceIn(0f, 1f) else 1f, tween(450), label = "cubre")
    Column {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(i.nombre, style = Texto.Cuerpo, color = Altiplano.TextoSecundario, modifier = Modifier.weight(1f))
            Text(cantidadBonita(i.necesita, i.unidad), style = Texto.Cuerpo.copy(fontWeight = FontWeight.SemiBold), color = if (falta) Altiplano.Alerta else Altiplano.Texto)
        }
        Box(Modifier.padding(vertical = 5.dp).fillMaxWidth().height(5.dp).clip(RoundedCornerShape(5.dp)).background(Color(0xFF223444))) {
            Box(Modifier.fillMaxWidth(cubre).fillMaxHeight().background(if (falta) Altiplano.Alerta else Altiplano.Exito, RoundedCornerShape(5.dp)))
        }
        Text(if (falta) "Falta ${cantidadBonita(i.necesita - i.stock, i.unidad)} (hay ${cantidadBonita(i.stock, i.unidad)})"
            else "Hay ${cantidadBonita(i.stock, i.unidad)}" + if (i.productos.size > 1) " · lo usan los ${i.productos.size}" else "",
            style = Texto.Apoyo, color = if (falta) Altiplano.Alerta else Altiplano.TextoTerciario)
    }
}

@Composable
private fun Veredicto(titulo: String, texto: String, color: Color, fondo: Color) {
    Surface(shape = RoundedCornerShape(16.dp), color = fondo, border = BorderStroke(1.dp, color.copy(alpha = .45f)), modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp)) {
            Text(titulo, style = Texto.Fila.copy(fontSize = 18.sp, fontWeight = FontWeight.Bold), color = color)
            Text(texto, style = Texto.Cuerpo, color = Altiplano.TextoSecundario, modifier = Modifier.padding(top = 4.dp))
        }
    }
}

/** El dibujito de la familia: rueda de queso con su tajada, vasito de yogurt o botella. Del color de la receta. */
@Composable
internal fun DibujoReceta(tipo: String, tono: Color, modifier: Modifier = Modifier) {
    Canvas(modifier) {
        val w = size.width; val h = size.height
        val oscuro = Color(tono.red * .78f, tono.green * .78f, tono.blue * .78f, 1f)
        when (tipo) {
            "QUESO" -> {
                drawOval(Color.Black.copy(alpha = .25f), Offset(w * .08f, h * .78f), Size(w * .84f, h * .2f))
                // costado de la rueda y la tapa
                drawRect(oscuro, Offset(w * .1f, h * .42f), Size(w * .8f, h * .36f))
                drawOval(oscuro, Offset(w * .1f, h * .62f), Size(w * .8f, h * .3f))
                drawOval(tono, Offset(w * .1f, h * .26f), Size(w * .8f, h * .32f))
                // la tajada que falta
                val tajada = Path().apply { moveTo(w * .5f, h * .42f); lineTo(w * .9f, h * .38f); lineTo(w * .82f, h * .3f); close() }
                drawPath(tajada, Color(0xFF0F1A24).copy(alpha = .5f))
                listOf(.3f to .38f, .42f to .46f, .62f to .36f, .25f to .6f, .55f to .62f, .75f to .58f).forEach { (x, y) ->
                    drawCircle(Color.Black.copy(alpha = .18f), radius = w * .03f, center = Offset(w * x, h * y))
                }
            }
            "YOGURT" -> {
                drawOval(Color.Black.copy(alpha = .25f), Offset(w * .22f, h * .86f), Size(w * .56f, h * .12f))
                val vaso = Path().apply { moveTo(w * .26f, h * .2f); lineTo(w * .74f, h * .2f); lineTo(w * .68f, h * .9f); lineTo(w * .32f, h * .9f); close() }
                drawPath(vaso, Color(0xFFF4F1EA))
                val banda = Path().apply { moveTo(w * .285f, h * .42f); lineTo(w * .715f, h * .42f); lineTo(w * .695f, h * .68f); lineTo(w * .305f, h * .68f); close() }
                drawPath(banda, tono)
                drawRoundRect(Color(0xFF2E8B57), Offset(w * .22f, h * .1f), Size(w * .56f, h * .12f), CornerRadius(w * .03f))
            }
            else -> {
                drawOval(Color.Black.copy(alpha = .25f), Offset(w * .3f, h * .86f), Size(w * .4f, h * .12f))
                drawRoundRect(tono, Offset(w * .34f, h * .22f), Size(w * .32f, h * .68f), CornerRadius(w * .08f))
                drawRoundRect(Color(0xFF2E8B57), Offset(w * .4f, h * .08f), Size(w * .2f, h * .16f), CornerRadius(w * .03f))
            }
        }
    }
}

@Composable
private fun ConfirmarVarios(elegidos: List<Elegido>, texto: String, onCerrar: () -> Unit, onListo: (Noti) -> Unit) {
    val scope = rememberCoroutineScope()
    var enviando by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    AlertDialog(
        onDismissRequest = onCerrar, containerColor = Altiplano.Fondo,
        title = { Text("Hacer $texto", color = Altiplano.Texto) },
        text = {
            Column {
                Text(if (elegidos.size == 1) "Al aceptar se inicia el lote y se descuenta del almacén:" else "Al aceptar se inician los lotes a la vez y se descuenta del almacén:",
                    color = Altiplano.TextoTerciario)
                Spacer(Modifier.height(8.dp))
                elegidos.forEach { e ->
                    FilaValor("Leche para ${e.p.txt("nombre")}", "${formatear(e.litros, 0)} L")
                    Text("Se espera que salgan " + (if (rendMin(e.p) == rendMax(e.p)) formatear(e.litros * rendMin(e.p), 2)
                        else "entre ${formatear(e.litros * rendMin(e.p), 2)} y ${formatear(e.litros * rendMax(e.p), 2)}") + " ${e.unidad}.",
                        style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                }
                error?.let { Text(it, color = Altiplano.Alerta) }
            }
        },
        confirmButton = {
            TextButton(enabled = !enviando, onClick = {
                scope.launch {
                    enviando = true
                    try {
                        val r = Sesion.enviar("/api/v1/planta/lotes/varios", buildJsonObject {
                            putJsonArray("lotes") {
                                elegidos.forEach { e -> addJsonObject { put("producto_id", e.p.num("id").toLong()); put("litros", e.litros) } }
                            }
                        })
                        val lotes = r.lista("lotes")
                        VariosApp.cantidad.clear()
                        onCerrar()
                        onListo(Noti(
                            if (lotes.size == 1) "Lote ${lotes[0].txt("codigo")} iniciado" else "${lotes.size} lotes iniciados",
                            lotes.joinToString(", ") { "${it.txt("producto")} con ${formatear(it.num("litros"), 0)} L" } + ". Se descontaron la leche y los insumos.",
                            "Ciérralo en Lotes cuando termine, con lo que salió.",
                        ))
                    } catch (t: Throwable) { error = t.message }
                    enviando = false
                }
            }) { Text(if (enviando) "Iniciando…" else "Aceptar e iniciar ${elegidos.size} ${if (elegidos.size == 1) "lote" else "lotes"}", color = Altiplano.Exito) }
        },
        dismissButton = { TextButton(onClick = onCerrar) { Text("Cancelar", color = Altiplano.TextoTerciario) } },
    )
}
