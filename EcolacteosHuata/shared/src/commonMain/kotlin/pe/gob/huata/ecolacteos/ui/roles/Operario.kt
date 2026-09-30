package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.animation.animateColorAsState
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import pe.gob.huata.ecolacteos.datos.*
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

data class Receta(val id: String, val nombre: String, val unidad: String, val porCien: Double, val vida: Int, val insumos: List<Pair<String, Double>>)
data class Lote(val id: String, val receta: Receta, val litros: Double, val real: Double?, val cierre: Double)

val RECETAS = listOf(
    Receta("paria", "Queso Paria", "kg", 12.5, 45, listOf("Cuajo líquido" to 0.012, "Sal industrial" to 2.5, "Cloruro de calcio" to 0.02, "Film termoencogible" to 12.5)),
    Receta("yogurt", "Yogurt natural", "L", 95.0, 21, listOf("Cultivo láctico" to 0.05, "Azúcar blanca" to 8.0, "Saborizante" to 1.5, "Envase 1 L" to 95.0)),
)

class EstadoOperario {
    var receta by mutableStateOf(0)
    var litros by mutableStateOf(300.0)
    var tanque by mutableStateOf(412.0)
    val stock = mutableStateMapOf("Cuajo líquido" to 0.8, "Sal industrial" to 40.0, "Cloruro de calcio" to 0.3, "Film termoencogible" to 90.0,
        "Cultivo láctico" to 1.2, "Azúcar blanca" to 60.0, "Saborizante" to 4.0, "Envase 1 L" to 380.0)
    val lotes = mutableStateListOf(
        Lote("L-2418", RECETAS[0], 400.0, null, 48.0),
        Lote("L-2417", RECETAS[1], 300.0, 279.0, 279.0),
        Lote("L-2416", RECETAS[0], 350.0, 41.0, 41.0),
    )
    private var n = 2419
    fun iniciar() {
        val r = RECETAS[receta]
        r.insumos.forEach { (k, c) -> stock[k] = (stock[k] ?: 0.0) - c * litros / 100 }
        tanque -= litros
        lotes.add(0, Lote("L-${n++}", r, litros, null, kotlin.math.round(litros * r.porCien / 100)))
    }
}

@Composable
fun ContenidoOperario(ruta: String, e: EstadoOperario, irA: (Int) -> Unit) {
    when (ruta) {
        "operario/recetas" -> PantallaScroll {
            RECETAS.forEach { r ->
                Tarjeta(fondo = Color(0xFF1B2A36), borde = Color(0xFF3A4A52)) {
                    Row(verticalAlignment = Alignment.Bottom) {
                        Text(r.nombre, style = Texto.TituloPantalla.copy(fontSize = 19.sp), color = Altiplano.Texto, modifier = Modifier.weight(1f))
                        Text("base 100 L", style = Texto.Apoyo, color = Altiplano.CelesteAcento)
                    }
                    Spacer(Modifier.height(10.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(22.dp)) {
                        MiniCifra("Rinde", "${formatear(r.porCien)} ${r.unidad}")
                        MiniCifra("Vida útil", "${r.vida} días")
                    }
                    HorizontalDivider(Modifier.padding(vertical = 12.dp), color = Altiplano.BordeSuave)
                    r.insumos.forEach { (k, c) -> FilaValor(k, formatear(c, 3)) }
                }
            }
            if (true) Text("Queso: 100 L rinden 12 a 13 quesos de ~1 kg.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
        }
        "operario/nueva" -> {
            val r = RECETAS[e.receta]
            val reqs = r.insumos.map { (k, c) -> Triple(k, c * e.litros / 100, e.stock[k] ?: 0.0) }
            val faltan = reqs.filter { it.second > it.third }
            PantallaScroll {
                Segmentos(RECETAS.map { it.nombre }, e.receta) { e.receta = it }
                Tarjeta {
                    Text("Litros a procesar · tanque ${formatear(e.tanque)} L", style = Texto.Cuerpo, color = Altiplano.TextoSecundario)
                    Spacer(Modifier.height(10.dp))
                    StepperGrande(e.litros, { e.litros = (it ?: 50.0).coerceIn(50.0, e.tanque) }, paso = 50.0, minimo = 50.0, maximo = e.tanque, decimales = 0, unidad = "litros · pasos de 50")
                }
                Tarjeta {
                    Seccion("Insumos requeridos")
                    reqs.forEach { (k, need, st) -> FilaValor("$k · stock ${formatear(st, 2)}", formatear(need, 3), if (need > st) Altiplano.Alerta else Altiplano.Texto) }
                    HorizontalDivider(Modifier.padding(vertical = 10.dp), color = Altiplano.BordeSuave)
                    FilaValor("Rendimiento esperado", "${formatear(e.litros * r.porCien / 100)} ${r.unidad}", Altiplano.Exito)
                    FilaValor("Vence en", "${r.vida} días")
                }
                if (faltan.isNotEmpty()) AvisoCampo("Falta " + faltan.joinToString(" y ") { it.first.lowercase() } + ". No se puede iniciar hasta reponer.", esAlerta = true)
                BotonPrimario("Iniciar lote", faltan.isEmpty()) { e.iniciar(); irA(2) }
            }
        }
        else -> PantallaScroll {
            e.lotes.forEachIndexed { i, l ->
                val esp = l.litros * l.receta.porCien / 100
                val desv = l.real?.let { (it - esp) / esp * 100 }
                Tarjeta(fondo = Color(0xFF1B2A36), borde = if (l.real == null) Altiplano.VerdeBorde else Color(0xFF3A4A52)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(l.id, style = Texto.TituloPantalla.copy(fontSize = 19.sp), color = Altiplano.Texto)
                            Text("${l.receta.nombre} · ${formatear(l.litros)} L de leche", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                        }
                        ChipEstado(if (l.real == null) Estado.PENDIENTE else Estado.ACEPTADO)
                    }
                    Spacer(Modifier.height(12.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(20.dp)) {
                        MiniCifra("Esperado", "${formatear(esp)} ${l.receta.unidad}")
                        MiniCifra("Real", l.real?.let { "${formatear(it)} ${l.receta.unidad}" } ?: "—")
                        MiniCifra("Desvío", desv?.let { (if (it > 0) "+" else "") + formatear(it) + " %" } ?: "—",
                            if (desv == null) Altiplano.Texto else if (kotlin.math.abs(desv) > 3) Altiplano.Alerta else Altiplano.Exito)
                    }
                    if (l.real == null) {
                        HorizontalDivider(Modifier.padding(vertical = 12.dp), color = Altiplano.BordeSuave)
                        Text("Cantidad obtenida al cierre", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                        StepperGrande(l.cierre, { e.lotes[i] = l.copy(cierre = it ?: 0.0) }, paso = 1.0, decimales = 0, unidad = l.receta.unidad, compacto = true)
                        Spacer(Modifier.height(10.dp))
                        BotonPrimario("Cerrar lote") { e.lotes[i] = l.copy(real = l.cierre) }
                    }
                }
            }
        }
    }
}

@Composable
internal fun MiniCifra(etiqueta: String, valor: String, color: Color = Altiplano.Texto) {
    Column {
        Text(etiqueta, style = Texto.Apoyo, color = Altiplano.TextoTerciario)
        Text(valor, style = Texto.Fila, color = color)
    }
}
