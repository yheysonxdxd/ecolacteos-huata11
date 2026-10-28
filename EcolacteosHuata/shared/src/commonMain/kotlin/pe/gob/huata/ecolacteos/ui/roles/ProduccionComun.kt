package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.animateContentSize
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.clickable
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.rotate
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.delay
import kotlinx.serialization.json.*
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*
import kotlin.math.ceil

/**
 * Lo común de Producir (operario, administrador y Compras), igual que en la web:
 * la notificación de lo que pasó, la lista compacta de recetas y cuánto se usa
 * de cada insumo (los envases, enteros).
 */
object Produccion {
    /** Lo que pasó al iniciar o cerrar un lote. La de éxito se va sola a los 7 s. */
    var noti by mutableStateOf<Noti?>(null)
    /** Receta desplegada en la lista de recetas. */
    var abierta by mutableStateOf<Long?>(null)
}

class Noti(val titulo: String, val texto: String, val detalle: String? = null, val error: Boolean = false, val icono: String = if (error) "!" else "✓")

/** Lo que se cuenta por unidad (envases) va entero, hacia arriba: no existe medio envase. Igual que en el servidor. */
internal fun cantidadInsumo(por100: Double, litros: Double, unidad: String): Double {
    val c = por100 * litros / 100
    return if (unidad.trim().lowercase() in setOf("un", "und", "unidad", "unidades")) ceil(c - 1e-9) else c
}

/** "Yogurt de fresa" se muestra como "↳ Fresa" debajo de su base. */
internal fun nombreCorto(p: JsonObject) = if (p.txt("base_id").isNotEmpty()) "↳ ${p.txt("variante")}" else p.txt("nombre")

/** Tarjeta con ícono, título, texto y ✕; la de éxito se cierra sola a los 7 segundos. */
@Composable
fun Notificacion(n: Noti?) {
    if (n == null) return
    LaunchedEffect(n) { if (!n.error) { delay(7_000); if (Produccion.noti === n) Produccion.noti = null } }
    val color = if (n.error) Altiplano.Alerta else if (n.icono == "⚠") Altiplano.Aviso else Altiplano.Exito
    Surface(
        shape = RoundedCornerShape(14.dp), color = Color(0xFF142333),
        border = BorderStroke(1.dp, if (n.error) Altiplano.AlertaBorde else Altiplano.VerdeBorde),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Row(Modifier.height(IntrinsicSize.Min)) {
            Box(Modifier.width(4.dp).fillMaxHeight().background(color)) // franja de color a la izquierda
            Row(Modifier.padding(14.dp), verticalAlignment = Alignment.Top) {
                Box(
                    Modifier.size(34.dp).background(color.copy(alpha = .15f), CircleShape).border(1.dp, color.copy(alpha = .5f), CircleShape),
                    contentAlignment = Alignment.Center,
                ) { Text(n.icono, color = color, fontWeight = FontWeight.Bold) }
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(n.titulo, style = Texto.Fila, color = Altiplano.Texto, fontWeight = FontWeight.SemiBold)
                    Text(n.texto, style = Texto.Cuerpo, color = Altiplano.TextoSecundario, modifier = Modifier.padding(top = 2.dp))
                    n.detalle?.let { Text(it, style = Texto.Apoyo, color = Altiplano.TextoTerciario, modifier = Modifier.padding(top = 4.dp)) }
                }
                TextButton(onClick = { Produccion.noti = null }, contentPadding = PaddingValues(4.dp)) {
                    Text("✕", color = Altiplano.TextoTerciario, fontSize = 14.sp)
                }
            }
        }
    }
}

/** "13 quesos" si rinde siempre igual; "10–11 quesos" si varía. */
private fun rindeTexto(p: JsonObject): String {
    val u = p.txt("unidad").substringBefore(' ')
    val a = formatear(rendMin(p) * 100, 1); val b = formatear(rendMax(p) * 100, 1)
    return (if (a == b) a else "$a–$b") + " $u por 100 L"
}

/**
 * Recetas: cada base con su dibujo y sus sabores debajo (con su color). Al tocar una se abre
 * con lo que lleva por cada 100 L (lo del sabor resaltado), cuánto dura y los pasos numerados.
 */
@Composable
fun RecetasCompactas(productos: List<JsonObject>) {
    Seccion("Recetas · toca una para verla")
    val ids = productos.map { it.num("id").toLong() }.toSet()
    val bases = productos.filter { it.txt("base_id").isEmpty() || it.num("base_id").toLong() !in ids }
    bases.forEach { base ->
        val sabores = productos.filter { it.txt("base_id") == base.txt("id") }
        val tono = colorReceta(base)
        Surface(shape = RoundedCornerShape(18.dp), color = Color(0xFF17242F), border = BorderStroke(1.dp, Altiplano.Borde),
            modifier = Modifier.fillMaxWidth().animateContentSize()) {
            Column(Modifier.padding(6.dp)) {
                FilaReceta(base, tono, esBase = true)
                sabores.forEach { s -> FilaReceta(s, colorReceta(s), esBase = false) }
            }
        }
    }
}

@Composable
private fun FilaReceta(p: JsonObject, tono: Color, esBase: Boolean) {
    val id = p.num("id").toLong()
    val abierta = Produccion.abierta == id
    val giro by animateFloatAsState(if (abierta) 180f else 0f, label = "flecha")
    val propios = p.lista("propios").map { it.num("insumo_id").toLong() }.toSet()
    val sabor = p.txt("base_id").isNotEmpty()
    val fondo by animateColorAsState(if (abierta) tono.copy(alpha = .08f) else Color.Transparent, label = "fondo")
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(fondo)) {
        Row(Modifier.fillMaxWidth().clickable { Produccion.abierta = if (abierta) null else id }.padding(horizontal = 10.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically) {
            if (esBase) {
                Box(Modifier.size(52.dp, 44.dp).background(tono.copy(alpha = .12f), RoundedCornerShape(13.dp)), contentAlignment = Alignment.Center) {
                    DibujoReceta(p.txt("tipo").ifEmpty { "OTRO" }, tono, Modifier.size(42.dp, 34.dp))
                }
            } else {
                Spacer(Modifier.width(20.dp))
                Box(Modifier.size(12.dp).background(tono, CircleShape))
                Spacer(Modifier.width(8.dp))
            }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(if (sabor) p.txt("variante") else p.txt("nombre"), style = (if (esBase) Texto.Fila.copy(fontWeight = FontWeight.SemiBold) else Texto.Fila), color = Altiplano.Texto)
                Text(if (sabor) "lo del ${p.txt("base")} + " + p.lista("insumos").filter { it.num("id").toLong() in propios }.joinToString(", ") { it.txt("nombre").lowercase() }
                    else rindeTexto(p) + " · dura ${p.txt("vida_util_dias")} días", style = Texto.Apoyo, color = Altiplano.TextoTerciario, maxLines = 2)
            }
            Text("▾", color = Altiplano.TextoTerciario, modifier = Modifier.rotate(giro))
        }
        AnimatedVisibility(abierta, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
            Column(Modifier.padding(start = 14.dp, end = 14.dp, bottom = 14.dp)) {
                Text(if (sabor) "Lleva todo lo del ${p.txt("base")} más lo resaltado · por cada 100 L de leche" else "Por cada 100 L de leche",
                    style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                val insumos = p.lista("insumos")
                if (insumos.isEmpty()) Text("Esta fórmula no tiene insumos todavía.", style = Texto.Apoyo, color = Altiplano.Aviso, modifier = Modifier.padding(top = 6.dp))
                insumos.forEach { i ->
                    val delSabor = i.num("id").toLong() in propios
                    Row(Modifier.fillMaxWidth().padding(top = 6.dp).clip(RoundedCornerShape(10.dp))
                        .background(if (delSabor) tono.copy(alpha = .14f) else Color(0xFF1C2C39)).padding(horizontal = 12.dp, vertical = 9.dp),
                        verticalAlignment = Alignment.CenterVertically) {
                        Text(i.txt("nombre") + if (delSabor) "  ★" else "", style = Texto.Cuerpo, color = Altiplano.TextoSecundario, modifier = Modifier.weight(1f))
                        Text("${formatear(i.num("por_100l"), 3)} ${i.txt("unidad")}", style = Texto.Cuerpo.copy(fontWeight = FontWeight.SemiBold),
                            color = if (delSabor) tono else Altiplano.Texto)
                    }
                }
                val pasos = (p["procedimiento"] as? JsonArray)?.map { it.jsonPrimitive.content } ?: emptyList()
                if (pasos.isNotEmpty()) {
                    Text("Cómo se hace", style = Texto.Fila.copy(fontWeight = FontWeight.SemiBold), color = Altiplano.Texto, modifier = Modifier.padding(top = 14.dp, bottom = 2.dp))
                    pasos.forEachIndexed { k, paso ->
                        Row(Modifier.padding(top = 8.dp), verticalAlignment = Alignment.Top) {
                            Box(Modifier.size(26.dp).background(tono.copy(alpha = .18f), CircleShape).border(1.dp, tono.copy(alpha = .5f), CircleShape),
                                contentAlignment = Alignment.Center) {
                                Text("${k + 1}", style = Texto.Apoyo.copy(fontWeight = FontWeight.Bold), color = Altiplano.Texto)
                            }
                            Spacer(Modifier.width(10.dp))
                            Text(paso, style = Texto.Cuerpo, color = Altiplano.TextoSecundario, modifier = Modifier.weight(1f).padding(top = 3.dp))
                        }
                    }
                }
            }
        }
    }
}
