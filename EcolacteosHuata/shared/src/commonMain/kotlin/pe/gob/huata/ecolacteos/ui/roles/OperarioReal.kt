package pe.gob.huata.ecolacteos.ui.roles

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
        PantallaScroll {
            Notificacion(Produccion.noti)
            d.lista("avisos").filter { it.txt("nivel") == "FALTA" }.forEach { AvisoCampo(it.txt("texto"), esAlerta = true) }
            if (recetas.isEmpty()) AvisoCampo("No hay productos que se estén produciendo.")
            else ProducirVarios(recetas, d.num("tanque"), puedeIniciar = true) { n -> Produccion.noti = n; OperarioReal.version++; irA(2) }
        }
    }
}

@Composable
internal fun LotesReal() {
    val scope = rememberCoroutineScope()
    var cerrando by remember { mutableStateOf<String?>(null) }
    var confirmar by remember { mutableStateOf<Pair<JsonObject, Double>?>(null) } // lote y lo que salió

    CargaServidor("/api/v1/planta/lotes", clave = OperarioReal.version) { d, _ ->
        PantallaScroll {
            Notificacion(Produccion.noti)
            d.lista("lotes").forEach { l ->
                val id = l.txt("id")
                val abierto = l.txt("estado") == "EN_PROCESO"
                val min = l.num("esperado_min"); val max = l.num("esperado_max")
                val u = unidadCorta(l.txt("unidad"))
                val real = if (abierto) null else l.num("obtenido")
                val desv = if (abierto) null else l.num("desvio_pct")
                val fuera = l.txt("fuera_referencia") == "1" || l.txt("fuera_referencia") == "true"
                Tarjeta(fondo = Color(0xFF1B2A36), borde = if (abierto) Altiplano.VerdeBorde else if (fuera) Altiplano.AlertaBorde else Color(0xFF3A4A52)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(l.txt("codigo"), style = Texto.TituloPantalla.copy(fontSize = 19.sp), color = Altiplano.Texto)
                            Text("${l.txt("producto")} · ${formatear(l.num("litros_leche"))} L · ${fechaCorta(l.txt("iniciado_en"))} · vence ${fechaCorta(l.txt("vence_el"))}", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                        }
                        ChipEstado(if (abierto) Estado.PENDIENTE else if (fuera) Estado.RECHAZADO else Estado.ACEPTADO)
                    }
                    Spacer(Modifier.height(12.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(20.dp)) {
                        MiniCifra("Esperado", esperadoLote(min, max, u))
                        MiniCifra("Real", real?.let { "${formatear(it, 2)} $u" } ?: "—")
                        MiniCifra("Desvío", desv?.let { (if (it > 0) "+" else "") + formatear(it) + " %" } ?: "—",
                            if (desv == null) Altiplano.Texto else if (fuera) Altiplano.Alerta else Altiplano.Exito)
                    }
                    if (abierto) {
                        // fórmula con la que se inició: lo que se descontó del almacén y los pasos a seguir
                        val usados = l.lista("insumos")
                        if (usados.isNotEmpty()) {
                            HorizontalDivider(Modifier.padding(vertical = 12.dp), color = Altiplano.BordeSuave)
                            Text("Insumos del lote", style = Texto.Apoyo, color = Altiplano.CelesteAcento)
                            usados.forEach { i -> FilaValor(i.txt("nombre"), "${formatear(i.num("cantidad"), 3)} ${i.txt("unidad")}") }
                        }
                        PasosFormula(pasosDe(l))
                        // quesos y envases se cuentan enteros; litros y kilos, con decimales (8.55 L no es "9")
                        val enteros = !l.txt("unidad").startsWith("litro") && !l.txt("unidad").startsWith("kg")
                        val sugerido = (min + max) / 2
                        val valor = OperarioReal.cierre[id] ?: if (enteros) kotlin.math.round(sugerido) else kotlin.math.round(sugerido * 100) / 100
                        HorizontalDivider(Modifier.padding(vertical = 12.dp), color = Altiplano.BordeSuave)
                        Text("Cantidad obtenida al cierre · se acepta de ${formatear(min * 0.97, 2)} a ${formatear(max * 1.03, 2)} $u",
                            style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                        StepperGrande(valor, { OperarioReal.cierre[id] = it ?: 0.0 }, paso = if (enteros) 1.0 else 0.5,
                            decimales = if (enteros) 0 else 2, unidad = u, compacto = true)
                        Spacer(Modifier.height(10.dp))
                        BotonPrimario(if (cerrando == id) "Cerrando…" else "Cerrar lote", cerrando == null) { confirmar = l to valor }
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
