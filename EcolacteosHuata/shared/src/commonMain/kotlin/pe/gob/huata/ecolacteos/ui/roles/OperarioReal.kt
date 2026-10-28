package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateContentSize
import androidx.compose.animation.core.*
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.launch
import kotlinx.serialization.json.*
import pe.gob.huata.ecolacteos.datos.Rol
import pe.gob.huata.ecolacteos.indiceDe
import pe.gob.huata.ecolacteos.datos.Sesion
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

/** Estado del operario con datos reales. */
object OperarioReal {
    var cierre = mutableStateMapOf<String, Double>() // cantidad obtenida por lote abierto
    var version by mutableStateOf(0)
}

/** Operario con la base: recetas, iniciar lote con leche aceptada, cerrar lotes y pérdidas. */
@Composable
fun ContenidoOperarioReal(ruta: String, irA: (Int) -> Unit) {
    when (ruta) {
        "operario/recetas" -> RecetasReal()
        "operario/nueva" -> NuevaOrdenReal(irA)
        "operario/perdidas" -> PerdidasReal("planta") // la leche que se corta, el queso que se pasa
        else -> LotesReal()
    }
}

private fun unidadCorta(u: String) = when {
    u.startsWith("quesos") -> "quesos"
    u.startsWith("baldes") -> "baldes"
    else -> u
}

@Composable
private fun RecetasReal() {
    CargaServidor("/api/v1/planta/recetas", clave = OperarioReal.version) { d, _ ->
        PantallaScroll {
            d.lista("avisos").forEach { a -> AvisoCampo(a.txt("texto"), esAlerta = a.txt("nivel") == "FALTA") }
            RecetasCompactas(d.lista("recetas"))
        }
    }
}

private fun pasosDe(o: JsonObject) = (o["procedimiento"] as? JsonArray)?.map { it.jsonPrimitive.content } ?: emptyList()

/** "Cómo se hace": los pasos numerados de la fórmula. */
@Composable
private fun PasosFormula(pasos: List<String>) {
    if (pasos.isEmpty()) return
    HorizontalDivider(Modifier.padding(vertical = 12.dp), color = Altiplano.BordeSuave)
    Text("Cómo se hace", style = Texto.Apoyo, color = Altiplano.CelesteAcento)
    pasos.forEachIndexed { k, p ->
        Text("${k + 1}. $p", style = Texto.Cuerpo, color = Altiplano.TextoSecundario, modifier = Modifier.padding(top = 6.dp))
    }
}

/** Nueva orden: la misma pantalla de producir que en la web (uno o varios productos a la vez). */
@Composable
private fun NuevaOrdenReal(irA: (Int) -> Unit) {
    CargaServidor("/api/v1/planta/recetas", clave = OperarioReal.version) { d, _ ->
        val recetas = d.lista("recetas")
        val avisos: @Composable ColumnScope.() -> Unit = {
            Notificacion(Produccion.noti)
            d.lista("avisos").filter { it.txt("nivel") == "FALTA" }.forEach { AvisoCampo(it.txt("texto"), esAlerta = true) }
        }
        if (recetas.isEmpty()) PantallaScroll { avisos(); AvisoCampo("No hay productos que se estén produciendo.") }
        else PantallaProducir(recetas, d.num("tanque"), puedeIniciar = true, arriba = avisos,
            onListo = { n -> Produccion.noti = n; OperarioReal.version++; irA(indiceDe(Rol.OPERARIO, "operario/lotes")) })
    }
}

@Composable
internal fun LotesReal() {
    val scope = rememberCoroutineScope()
    var cerrando by remember { mutableStateOf<String?>(null) }
    var confirmar by remember { mutableStateOf<Pair<JsonObject, Double>?>(null) } // lote y lo que salió

    CargaServidor("/api/v1/planta/lotes", clave = OperarioReal.version) { d, _ ->
        val lotes = d.lista("lotes")
        val abiertos = lotes.filter { it.txt("estado") == "EN_PROCESO" }
        val terminados = lotes.filter { it.txt("estado") != "EN_PROCESO" }
        PantallaScroll {
            Notificacion(Produccion.noti)
            Seccion(if (abiertos.isEmpty()) "En proceso" else "En proceso · ${abiertos.size}")
            if (abiertos.isEmpty()) Tarjeta(fondo = Color(0xFF16232F), borde = Altiplano.BordeSuave) {
                Text("No hay lotes en proceso. Para hacer uno, ve a Producir.", style = Texto.Cuerpo, color = Altiplano.TextoTerciario)
            }
            abiertos.forEach { l -> LoteAbierto(l, cerrando == l.txt("id")) { valor -> confirmar = l to valor } }
            if (terminados.isNotEmpty()) {
                Seccion("Terminados hace poco")
                Surface(shape = RoundedCornerShape(16.dp), color = Color(0xFF16232F), border = BorderStroke(1.dp, Altiplano.BordeSuave)) {
                    Column {
                        terminados.forEachIndexed { k, l ->
                            if (k > 0) HorizontalDivider(color = Altiplano.BordeSuave)
                            LoteTerminado(l)
                        }
                    }
                }
            }
        }
        confirmar?.let { (l, valor) ->
            ConfirmarCierre(l, valor, onNo = { confirmar = null }) {
                confirmar = null
                val id = l.txt("id")
                scope.launch {
                    cerrando = id
                    val u = unidadCorta(l.txt("unidad"))
                    try {
                        val r = Sesion.enviar("/api/v1/planta/lotes/$id/cerrar", buildJsonObject { put("obtenido", valor) })
                        val fuera = r.txt("fuera_referencia") == "true" || r.txt("fuera_referencia") == "1"
                        val desv = r.num("desvio_pct")
                        Produccion.noti = Noti(
                            "Lote ${r.txt("codigo")} cerrado",
                            "Entraron ${formatear(valor, 2)} $u de ${l.txt("producto")} al stock. Ya se pueden vender.",
                            "Diferencia con lo esperado: ${if (desv > 0) "+" else ""}${formatear(desv)} % · " +
                                if (fuera) "salió fuera de lo esperado" else "dentro de lo esperado",
                            icono = if (fuera) "⚠" else "✓",
                        )
                        OperarioReal.cierre.remove(id)
                        OperarioReal.version++
                    } catch (t: Throwable) { Produccion.noti = Noti("No se pudo cerrar el lote", t.message ?: "sin conexión", error = true) }
                    cerrando = null
                }
            }
        }
    }
}


/** El producto de un lote como lo entiende el dibujo y el color: el nombre y si es queso o yogurt. */
private fun productoDeLote(l: JsonObject) = buildJsonObject {
    val n = l.txt("producto")
    put("nombre", n)
    put("tipo", if (Regex("queso", RegexOption.IGNORE_CASE).containsMatchIn(n)) "QUESO" else if (Regex("yogurt", RegexOption.IGNORE_CASE).containsMatchIn(n)) "YOGURT" else "OTRO")
}

/** "hace 3 h", "hace 2 días" desde "2026-10-27 10:00:00" (hora de Lima). */
private fun haceCuanto(fechaHora: String): String {
    val iso = fechaHora.trim().replace(' ', 'T').let { if (it.length <= 19) "$it-05:00" else it }
    val min = runCatching { (kotlin.time.Clock.System.now() - kotlin.time.Instant.parse(iso)).inWholeMinutes }.getOrNull() ?: return ""
    return when {
        min < 1 -> "recién"
        min < 60 -> "hace $min min"
        min < 60 * 24 -> "hace ${min / 60} h"
        else -> "hace ${min / 1440} ${if (min / 1440 == 1L) "día" else "días"}"
    }
}

/** Un lote en proceso: qué es, cuánto lleva, cuánto se espera, "¿Cuánto salió?" y cerrar. La receta del lote, plegada. */
@Composable
private fun LoteAbierto(l: JsonObject, cerrando: Boolean, onCerrar: (Double) -> Unit) {
    val id = l.txt("id")
    val p = productoDeLote(l)
    val tono = colorReceta(p)
    val min = l.num("esperado_min"); val max = l.num("esperado_max")
    val u = unidadCorta(l.txt("unidad"))
    var verReceta by remember(id) { mutableStateOf(false) }
    val giro by animateFloatAsState(if (verReceta) 180f else 0f, label = "flecha")
    // quesos y envases se cuentan enteros; litros y kilos, con decimales (8.55 L no es "9")
    val enteros = !l.txt("unidad").startsWith("litro") && !l.txt("unidad").startsWith("kg")
    val sugerido = (min + max) / 2
    val valor = OperarioReal.cierre[id] ?: if (enteros) kotlin.math.round(sugerido) else kotlin.math.round(sugerido * 100) / 100

    Surface(shape = RoundedCornerShape(18.dp), color = Color(0xFF17242F), border = BorderStroke(1.dp, tono.copy(alpha = .55f)),
        modifier = Modifier.fillMaxWidth().animateContentSize()) {
        Column {
            Box(Modifier.fillMaxWidth().height(4.dp).background(tono))
            Column(Modifier.padding(16.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(58.dp, 48.dp).background(tono.copy(alpha = .12f), RoundedCornerShape(14.dp)), contentAlignment = Alignment.Center) {
                        DibujoReceta(p.txt("tipo"), tono, Modifier.size(46.dp, 38.dp))
                    }
                    Spacer(Modifier.width(12.dp))
                    Column(Modifier.weight(1f)) {
                        Text(l.txt("producto"), style = Texto.Fila.copy(fontWeight = FontWeight.SemiBold), color = Altiplano.Texto)
                        Text("Lote ${l.txt("codigo")}" + haceCuanto(l.txt("iniciado_en")).let { if (it.isEmpty()) "" else " · empezó $it" },
                            style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                    }
                    // en proceso: un puntito que late
                    val latido = rememberInfiniteTransition(label = "latido")
                    val a by latido.animateFloat(.35f, 1f, infiniteRepeatable(tween(900), RepeatMode.Reverse), label = "a")
                    Row(verticalAlignment = Alignment.CenterVertically,
                        modifier = Modifier.background(Altiplano.AvisoFondo, RoundedCornerShape(99.dp)).border(1.dp, Altiplano.AvisoBorde, RoundedCornerShape(99.dp))
                            .padding(horizontal = 10.dp, vertical = 5.dp)) {
                        Box(Modifier.size(7.dp).background(Altiplano.Aviso.copy(alpha = a), CircleShape))
                        Text("  En proceso", style = Texto.Apoyo.copy(fontWeight = FontWeight.SemiBold), color = Altiplano.Aviso)
                    }
                }
                Row(Modifier.padding(top = 14.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    DatoLote("🥛 Leche", "${formatear(l.num("litros_leche"), 0)} L", Modifier.weight(1f))
                    DatoLote("🎯 Se espera", esperadoLote(min, max, u), Modifier.weight(1.4f))
                    DatoLote("📅 Vence", fechaCorta(l.txt("vence_el")), Modifier.weight(1f))
                }

                // lo que importa al terminar: cuánto salió
                Text("¿Cuánto salió?", style = Texto.Fila.copy(fontWeight = FontWeight.SemiBold), color = Altiplano.Texto, modifier = Modifier.padding(top = 16.dp))
                Text("Se acepta de ${formatear(min * 0.97, 2)} a ${formatear(max * 1.03, 2)} $u", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                Spacer(Modifier.height(8.dp))
                StepperGrande(valor, { OperarioReal.cierre[id] = it ?: 0.0 }, paso = if (enteros) 1.0 else 0.5,
                    decimales = if (enteros) 0 else 2, unidad = u, compacto = true)
                Spacer(Modifier.height(10.dp))
                BotonPrimario(if (cerrando) "Cerrando…" else "Cerrar lote", !cerrando) { onCerrar(valor) }

                // la receta con la que se hizo: plegada para que la lista no sea larguísima
                val usados = l.lista("insumos")
                val pasos = pasosDe(l)
                if (usados.isNotEmpty() || pasos.isNotEmpty()) {
                    Row(Modifier.fillMaxWidth().padding(top = 12.dp).clip(RoundedCornerShape(12.dp)).clickable { verReceta = !verReceta }
                        .background(Color(0xFF1C2C39)).padding(horizontal = 12.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text("📖  Receta del lote · ${usados.size} insumos, ${pasos.size} pasos", style = Texto.Cuerpo, color = Altiplano.CelesteAcento, modifier = Modifier.weight(1f))
                        Text("▾", color = Altiplano.CelesteAcento, modifier = Modifier.rotate(giro))
                    }
                    AnimatedVisibility(verReceta, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
                        Column(Modifier.padding(top = 6.dp)) {
                            if (usados.isNotEmpty()) {
                                Text("Se descontó del almacén", style = Texto.Apoyo, color = Altiplano.TextoTerciario, modifier = Modifier.padding(top = 6.dp))
                                usados.forEach { i -> FilaValor(i.txt("nombre"), "${formatear(i.num("cantidad"), 3)} ${i.txt("unidad")}") }
                            }
                            PasosFormula(pasos)
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun DatoLote(etiqueta: String, valor: String, modifier: Modifier) {
    Column(modifier.background(Color(0xFF1C2C39), RoundedCornerShape(12.dp)).padding(horizontal = 10.dp, vertical = 8.dp)) {
        Text(etiqueta, style = Texto.Apoyo, color = Altiplano.TextoTerciario, maxLines = 1)
        Text(valor, style = Texto.Cuerpo.copy(fontSize = 14.sp, fontWeight = FontWeight.SemiBold), color = Altiplano.Texto, maxLines = 1)
    }
}

/** Un lote cerrado, en una fila: qué, cuándo, cuánto salió y la diferencia con lo esperado. */
@Composable
private fun LoteTerminado(l: JsonObject) {
    val p = productoDeLote(l)
    val tono = colorReceta(p)
    val u = unidadCorta(l.txt("unidad"))
    val desv = l.num("desvio_pct")
    val fuera = l.txt("fuera_referencia") == "1" || l.txt("fuera_referencia") == "true"
    val c = if (fuera) Altiplano.Alerta else Altiplano.Exito
    Row(Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(10.dp).background(tono, CircleShape))
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(l.txt("producto"), style = Texto.Cuerpo.copy(fontSize = 14.5f.sp, fontWeight = FontWeight.SemiBold), color = Altiplano.Texto)
            Text("${l.txt("codigo")} · ${fechaCorta(l.txt("iniciado_en"))} · ${formatear(l.num("litros_leche"), 0)} L", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
        }
        Column(horizontalAlignment = Alignment.End) {
            Text("${formatear(l.num("obtenido"), 2)} $u", style = Texto.Cuerpo.copy(fontSize = 14.5f.sp, fontWeight = FontWeight.SemiBold), color = Altiplano.Texto)
            Text((if (fuera) "⚠ " else "✓ ") + (if (desv > 0) "+" else "") + formatear(desv) + " %", style = Texto.Apoyo.copy(fontWeight = FontWeight.SemiBold), color = c)
        }
    }
}

/** Lo esperado con sus decimales: redondeado, 8.55 L se veía "9–9" (como en la web). */
private fun esperadoLote(min: Double, max: Double, u: String) =
    if (min == max) "${formatear(min, 2)} $u" else "${formatear(min, 2)}–${formatear(max, 2)} $u"

/**
 * "¿Cerrar el lote?" con lo que salió frente a lo esperado. Fuera de lo
 * esperado (3 % menos o más, como el servidor) se avisa en amarillo para
 * revisar la cantidad antes de cerrar.
 */
@Composable
private fun ConfirmarCierre(l: JsonObject, obtenido: Double, onNo: () -> Unit, onSi: () -> Unit) {
    val min = l.num("esperado_min"); val max = l.num("esperado_max")
    val u = unidadCorta(l.txt("unidad"))
    val medio = (min + max) / 2
    val desvio = if (medio > 0) (obtenido - medio) / medio * 100 else 0.0
    val fuera = obtenido < min * 0.97 || obtenido > max * 1.03
    val color = if (fuera) Altiplano.Aviso else Altiplano.Exito
    AlertDialog(
        onDismissRequest = onNo, containerColor = Color(0xFF142333),
        icon = {
            Box(Modifier.size(56.dp).background(color.copy(alpha = .15f), androidx.compose.foundation.shape.CircleShape),
                contentAlignment = Alignment.Center) { Text(if (fuera) "⚠" else "✓", color = color, fontSize = 26.sp) }
        },
        title = { Text("¿Cerrar el lote ${l.txt("codigo")}?", color = Altiplano.Texto) },
        text = {
            Column {
                Text("Entran ${formatear(obtenido, 2)} $u de ${l.txt("producto")} al stock y ya se pueden vender." +
                    if (fuera) " Salió fuera de lo esperado (se acepta de ${formatear(min * 0.97, 2)} a ${formatear(max * 1.03, 2)} $u): revisa que la cantidad esté bien." else "",
                    style = Texto.Cuerpo, color = Altiplano.TextoSecundario)
                Spacer(Modifier.height(10.dp))
                FilaValor("Leche usada", "${formatear(l.num("litros_leche"), 2)} L")
                FilaValor("Se esperaba", esperadoLote(min, max, u))
                FilaValor("Salió", "${formatear(obtenido, 2)} $u", if (fuera) Altiplano.Alerta else Altiplano.Exito)
                FilaValor("Diferencia", "${if (desvio > 0) "+" else ""}${formatear(desvio)} %", if (fuera) Altiplano.Alerta else Altiplano.Exito)
            }
        },
        confirmButton = { BotonPrimario("Sí, cerrar lote") { onSi() } },
        dismissButton = { TextButton(onClick = onNo) { Text("Todavía no", color = Altiplano.TextoTerciario) } },
    )
}
