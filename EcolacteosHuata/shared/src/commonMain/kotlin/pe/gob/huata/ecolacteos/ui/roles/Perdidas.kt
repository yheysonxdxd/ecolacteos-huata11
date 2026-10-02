package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import kotlinx.serialization.json.*
import pe.gob.huata.ecolacteos.datos.Sesion
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

/** Para recargar las pantallas que muestran stock después de registrar una pérdida. */
object Perdidas {
    var version by mutableStateOf(0)
    var mensaje by mutableStateOf<String?>(null)
}

/**
 * Pérdidas (mermas): la leche que se corta o se derrama, el queso que se pasa,
 * el yogurt que se malogra. [base] es "planta" (operario) o "compras".
 */
@Composable
fun PerdidasReal(base: String) {
    var hoja by remember { mutableStateOf<String?>(null) } // "LECHE" o "PRODUCTO"
    CargaServidor("/api/v1/$base/mermas", clave = Perdidas.version) { d, _ ->
        PantallaScroll {
            Perdidas.mensaje?.let { AvisoCampo(it) }
            Text("Se descuenta del stock y sale en el kardex.", style = Texto.Cuerpo, color = Altiplano.TextoTerciario)
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Box(Modifier.weight(1f)) { BotonPrimario("+ Leche perdida") { hoja = "LECHE" } }
                Box(Modifier.weight(1f)) { BotonPrimario("+ Queso o yogurt") { hoja = "PRODUCTO" } }
            }
            val leche = d.obj("leche")
            Tarjeta {
                FilaValor("Leche sin procesar", "${formatear(leche?.num("sin_usar") ?: 0.0)} L")
                d.lista("productos").forEach { p -> FilaValor(p.txt("nombre"), "${formatear(p.num("stock"), 2)} ${p.txt("unidad")}") }
            }
            Seccion("Últimas pérdidas")
            val ultimas = d.lista("mermas")
            if (ultimas.isEmpty()) Text("Todavía no se registró ninguna pérdida.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            ultimas.forEach { m ->
                FilaLista(
                    m.txt("que") + (m.txt("lote").takeIf { it.isNotEmpty() }?.let { " · lote $it" } ?: ""),
                    "${m.txt("motivo_texto")}${m.txt("detalle").takeIf { it.isNotEmpty() }?.let { " · $it" } ?: ""} · " +
                        "${fechaCorta(m.txt("fecha"))}${m.txt("usuario").takeIf { it.isNotEmpty() }?.let { " · $it" } ?: ""}",
                    borde = Altiplano.AlertaBorde,
                ) { Text("− ${formatear(m.num("cantidad"), 2)} ${m.txt("unidad_corta")}", style = Texto.Fila, color = Altiplano.Alerta) }
            }
            hoja?.let { t -> HojaPerdida(base, t, null, d, onCerrar = { hoja = null }) }
        }
    }
}

/** Formulario de la pérdida. [datos] es la respuesta de GET {base}/mermas (motivos, productos y lotes). */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun HojaPerdida(base: String, tipoInicial: String, productoInicial: Long?, datos: JsonObject, onCerrar: () -> Unit) {
    var tipo by remember { mutableStateOf(tipoInicial) }
    val productos = datos.lista("productos")
    var productoId by remember { mutableStateOf(productoInicial ?: productos.firstOrNull()?.num("id")?.toLong()) }
    var motivo by remember { mutableStateOf<String?>(null) }
    var cantidad by remember { mutableStateOf("") }
    var loteId by remember { mutableStateOf<Long?>(null) }
    var detalle by remember { mutableStateOf("") }
    var enviando by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()

    val producto = productos.firstOrNull { it.num("id").toLong() == productoId }
    val unidad = if (tipo == "LECHE") "L" else producto?.txt("unidad") ?: ""
    val hay = if (tipo == "LECHE") datos.obj("leche")?.num("sin_usar") ?: 0.0 else producto?.num("stock") ?: 0.0
    val motivos = (datos.obj("motivos")?.get(tipo) as? JsonObject)?.mapValues { it.value.jsonPrimitive.content } ?: emptyMap()

    Hoja("Registrar pérdida", onCerrar) {
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Chip("Leche", tipo == "LECHE") { tipo = "LECHE"; motivo = null; loteId = null }
            productos.forEach { p ->
                val id = p.num("id").toLong()
                Chip(p.txt("nombre"), tipo == "PRODUCTO" && productoId == id) { tipo = "PRODUCTO"; productoId = id; motivo = null; loteId = null }
            }
        }
        Text(
            if (tipo == "LECHE") "Hay ${formatear(hay)} L de leche sin procesar. Se descuenta de la más antigua."
            else "Hay ${formatear(hay, 2)} $unidad en almacén.",
            style = Texto.Apoyo, color = Altiplano.TextoTerciario,
        )
        Text("¿QUÉ PASÓ?", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            motivos.forEach { (clave, texto) -> Chip(texto, motivo == clave) { motivo = clave } }
        }
        Campo(cantidad, { cantidad = it.filter { c -> c.isDigit() || c == '.' }.take(8) }, "¿Cuánto se perdió? ($unidad)", teclado = KeyboardType.Decimal)
        val lotes = producto?.lista("lotes") ?: emptyList()
        if (tipo == "PRODUCTO" && lotes.isNotEmpty()) {
            Text("¿DE QUÉ LOTE ERA? (OPCIONAL)", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Chip("No sé", loteId == null) { loteId = null }
                lotes.forEach { l ->
                    val id = l.num("id").toLong()
                    Chip("${l.txt("codigo")} · vence ${fechaCorta(l.txt("vence_el"))}", loteId == id) { loteId = id }
                }
            }
        }
        Campo(detalle, { detalle = it.take(200) }, if (motivo == "OTRO") "Explica qué pasó" else "Detalle (opcional)")
        error?.let { AvisoCampo(it, esAlerta = true) }
        BotonPrimario(if (enviando) "Guardando…" else "Registrar pérdida", habilitado = !enviando) {
            val cant = cantidad.toDoubleOrNull() ?: 0.0
            error = when {
                tipo == "PRODUCTO" && producto == null -> "Elige si es queso o yogurt"
                cant <= 0 -> "Escribe cuánto se perdió"
                cant > hay + 0.005 -> "Solo hay ${formatear(hay, 2)} $unidad"
                motivo == null -> "Elige qué pasó"
                motivo == "OTRO" && detalle.isBlank() -> "Escribe qué pasó"
                else -> null
            }
            if (error != null) return@BotonPrimario
            scope.launch {
                enviando = true
                try {
                    val r = Sesion.enviar("/api/v1/$base/mermas", buildJsonObject {
                        put("tipo", tipo)
                        if (tipo == "PRODUCTO") put("producto_id", productoId)
                        loteId?.let { put("lote_id", it) }
                        put("cantidad", cant); put("motivo", motivo)
                        detalle.trim().takeIf { it.isNotEmpty() }?.let { put("detalle", it) }
                    })
                    val que = if (tipo == "LECHE") "leche" else producto?.txt("nombre") ?: ""
                    Perdidas.mensaje = "✓ Pérdida registrada: ${formatear(cant, 2)} $unidad de $que · quedan ${formatear(r.num("stock"), 2)} $unidad"
                    Perdidas.version++
                    onCerrar()
                } catch (t: Throwable) { error = t.message }
                enviando = false
            }
        }
    }
}
