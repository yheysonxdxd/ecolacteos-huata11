package pe.gob.huata.ecolacteos.ui.roles

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

/**
 * Recetas, compactas: una línea por producto (los sabores debajo de su base);
 * al tocar se abre con sus insumos por 100 L y los pasos.
 */
@Composable
fun RecetasCompactas(productos: List<JsonObject>) {
    Seccion("Recetas · toca una para verla")
    productos.forEach { p ->
        val id = p.num("id").toLong()
        val abierta = Produccion.abierta == id
        val sabor = p.txt("base_id").isNotEmpty()
        val u = p.txt("unidad").substringBefore(' ')
        val propios = p.lista("propios").map { it.num("insumo_id").toLong() }.toSet()
        Tarjeta(
            modifier = Modifier.padding(start = if (sabor) 18.dp else 0.dp),
            fondo = Color(0xFF1B2A36), borde = if (abierta) Altiplano.VerdeBorde else Color(0xFF3A4A52),
            onClick = { Produccion.abierta = if (abierta) null else id },
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text((if (abierta) "▾ " else "▸ ") + nombreCorto(p), style = Texto.Fila, color = Altiplano.Texto, modifier = Modifier.weight(1f))
                Text("${formatear(rendMin(p) * 100, 0)}–${formatear(rendMax(p) * 100, 0)} $u por 100 L",
                    style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            }
            if (sabor && !abierta) Text("lo del ${p.txt("base")} + " +
                p.lista("insumos").filter { it.num("id").toLong() in propios }.joinToString(", ") { it.txt("nombre").lowercase() },
                style = Texto.Apoyo, color = Altiplano.TextoTerciario, modifier = Modifier.padding(top = 2.dp))
            if (abierta) {
                Text(
                    if (sabor) "Lleva todo lo del ${p.txt("base")} más lo marcado · dura ${p.txt("vida_util_dias")} días"
                    else "Cantidades por cada 100 L de leche · dura ${p.txt("vida_util_dias")} días",
                    style = Texto.Apoyo, color = Altiplano.TextoTerciario, modifier = Modifier.padding(top = 4.dp),
                )
                HorizontalDivider(Modifier.padding(vertical = 10.dp), color = Altiplano.BordeSuave)
                val insumos = p.lista("insumos")
                if (insumos.isEmpty()) Text("Esta fórmula no tiene insumos todavía.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                insumos.forEach { i ->
                    val delSabor = i.num("id").toLong() in propios
                    FilaValor(i.txt("nombre") + if (delSabor) "  · del sabor" else "",
                        "${formatear(i.num("por_100l"), 3)} ${i.txt("unidad")}", if (delSabor) Altiplano.Exito else Altiplano.Texto)
                }
                val pasos = (p["procedimiento"] as? JsonArray)?.map { it.jsonPrimitive.content } ?: emptyList()
                if (pasos.isNotEmpty()) {
                    HorizontalDivider(Modifier.padding(vertical = 10.dp), color = Altiplano.BordeSuave)
                    Text("Cómo se hace", style = Texto.Apoyo, color = Altiplano.CelesteAcento)
                    pasos.forEachIndexed { k, paso ->
                        Text("${k + 1}. $paso", style = Texto.Cuerpo, color = Altiplano.TextoSecundario, modifier = Modifier.padding(top = 6.dp))
                    }
                }
            }
        }
    }
}
