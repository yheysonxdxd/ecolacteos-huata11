package pe.gob.huata.ecolacteos.ui.roles

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
    var receta by mutableStateOf(0)
    var litros by mutableStateOf(100.0)
    var cierre = mutableStateMapOf<String, Double>() // cantidad obtenida por lote abierto
    var mensaje by mutableStateOf<String?>(null)
    var version by mutableStateOf(0)
}

/** Operario con la base: recetas, iniciar lote con leche aceptada y cerrar lotes. */
@Composable
fun ContenidoOperarioReal(ruta: String, irA: (Int) -> Unit) {
    when (ruta) {
        "operario/recetas" -> RecetasReal()
        "operario/nueva" -> NuevaOrdenReal(irA)
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
            d.lista("recetas").forEach { r ->
                Tarjeta(fondo = Color(0xFF1B2A36), borde = Color(0xFF3A4A52)) {
                    Row(verticalAlignment = Alignment.Bottom) {
                        Text(r.txt("nombre"), style = Texto.TituloPantalla.copy(fontSize = 19.sp), color = Altiplano.Texto, modifier = Modifier.weight(1f))
                        Text("base 100 L", style = Texto.Apoyo, color = Altiplano.CelesteAcento)
                    }
                    Spacer(Modifier.height(10.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(22.dp)) {
                        MiniCifra("Rinde", "${formatear(r.num("min_100l"), 0)} a ${formatear(r.num("max_100l"), 0)} ${unidadCorta(r.txt("unidad"))}")
                        MiniCifra("Vida útil", "${r.txt("vida_util_dias")} días")
                    }
                    HorizontalDivider(Modifier.padding(vertical = 12.dp), color = Altiplano.BordeSuave)
                    val ins = r.lista("insumos")
                    if (ins.isEmpty()) Text("Sin insumos registrados en la receta.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                    ins.forEach { i -> FilaValor("${i.txt("nombre")} (${i.txt("unidad")})", formatear(i.num("por_100l"), 3)) }
                }
            }
            Text("Unidad del queso: ${d.lista("recetas").firstOrNull()?.txt("unidad") ?: ""}.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
        }
    }
}

@Composable
private fun NuevaOrdenReal(irA: (Int) -> Unit) {
    val scope = rememberCoroutineScope()
    var enviando by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    CargaServidor("/api/v1/planta/recetas", clave = OperarioReal.version) { d, recargar ->
        val recetas = d.lista("recetas")
        val tanque = d.num("tanque")
        if (recetas.isEmpty()) { PantallaScroll { AvisoCampo("No hay productos registrados.") }; return@CargaServidor }
        val r = recetas[OperarioReal.receta.coerceIn(0, recetas.lastIndex)]
        val litros = OperarioReal.litros
        val reqs = r.lista("insumos").map { i -> Triple(i.txt("nombre"), i.num("por_100l") * litros / 100, i.num("stock")) }
        val faltan = reqs.filter { it.second > it.third }
        val sinLeche = litros > tanque

        PantallaScroll {
            Segmentos(recetas.map { it.txt("nombre") }, OperarioReal.receta) { OperarioReal.receta = it; LimiteOrden.aviso = null }
            ElegirQuesos(r, tanque)
            Tarjeta {
                Text("Litros a procesar · leche aceptada disponible ${formatear(tanque)} L", style = Texto.Cuerpo, color = Altiplano.TextoSecundario)
                Spacer(Modifier.height(10.dp))
                StepperGrande(litros, { val (l, aviso) = limitarLitros(it ?: 50.0, tanque); OperarioReal.litros = l; LimiteOrden.aviso = aviso }, paso = 50.0, minimo = 50.0, decimales = 0, unidad = "litros · pasos de 50", fueraDeRango = sinLeche)
            }
            Tarjeta {
                Seccion("Insumos requeridos")
                reqs.forEach { (k, need, st) -> FilaValor("$k · stock ${formatear(st, 2)}", formatear(need, 3), if (need > st) Altiplano.Alerta else Altiplano.Texto) }
                HorizontalDivider(Modifier.padding(vertical = 10.dp), color = Altiplano.BordeSuave)
                FilaValor("Rendimiento esperado", "${formatear(litros * r.num("min_100l") / 100, 0)} a ${formatear(litros * r.num("max_100l") / 100, 0)} ${unidadCorta(r.txt("unidad"))}", Altiplano.Exito)
                FilaValor("Vence en", "${r.txt("vida_util_dias")} días")
            }
            if (sinLeche) AvisoCampo(
                if (tanque <= 0) "No hay leche aceptada sin procesar. Llega cuando el acopiador sincroniza y Calidad la acepta."
                else "Solo hay ${formatear(tanque)} L de leche aceptada disponible.", esAlerta = true,
            )
            if (faltan.isNotEmpty()) AvisoCampo("Falta " + faltan.joinToString(" y ") { it.first.lowercase() } + ". Pide a Compras que reponga.", esAlerta = true)
            error?.let { AvisoCampo("No se pudo iniciar: $it", esAlerta = true) }
            BotonPrimario(if (enviando) "Iniciando…" else "Iniciar lote", faltan.isEmpty() && !sinLeche && !enviando) {
                scope.launch {
                    enviando = true; error = null
                    try {
                        val res = Sesion.enviar("/api/v1/planta/lotes", buildJsonObject {
                            put("producto_id", r.num("id").toLong()); put("litros", litros)
                        })
                        OperarioReal.mensaje = "✓ Lote ${res.txt("codigo")} iniciado con ${formatear(litros, 0)} L"
                        OperarioReal.version++
                        irA(2)
                    } catch (t: Throwable) { error = t.message; recargar() }
                    enviando = false
                }
            }
        }
    }
}

@Composable
private fun LotesReal() {
    val scope = rememberCoroutineScope()
    var cerrando by remember { mutableStateOf<String?>(null) }

    CargaServidor("/api/v1/planta/lotes", clave = OperarioReal.version) { d, _ ->
        PantallaScroll {
            OperarioReal.mensaje?.let { AvisoCampo(it, esAlerta = it.startsWith("No se pudo")) }
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
                        MiniCifra("Esperado", "${formatear(min, 0)}–${formatear(max, 0)} $u")
                        MiniCifra("Real", real?.let { "${formatear(it, 0)} $u" } ?: "—")
                        MiniCifra("Desvío", desv?.let { (if (it > 0) "+" else "") + formatear(it) + " %" } ?: "—",
                            if (desv == null) Altiplano.Texto else if (fuera) Altiplano.Alerta else Altiplano.Exito)
                    }
                    if (abierto) {
                        val valor = OperarioReal.cierre[id] ?: kotlin.math.round((min + max) / 2)
                        HorizontalDivider(Modifier.padding(vertical = 12.dp), color = Altiplano.BordeSuave)
                        Text("Cantidad obtenida al cierre", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                        StepperGrande(valor, { OperarioReal.cierre[id] = it ?: 0.0 }, paso = 1.0, decimales = 0, unidad = u, compacto = true)
                        Spacer(Modifier.height(10.dp))
                        BotonPrimario(if (cerrando == id) "Cerrando…" else "Cerrar lote", cerrando == null) {
                            scope.launch {
                                cerrando = id
                                try {
                                    val r = Sesion.enviar("/api/v1/planta/lotes/$id/cerrar", buildJsonObject { put("obtenido", valor) })
                                    OperarioReal.mensaje = "✓ ${r.txt("codigo")} cerrado · desvío ${formatear(r.num("desvio_pct"))} %"
                                    OperarioReal.cierre.remove(id)
                                    OperarioReal.version++
                                } catch (t: Throwable) { OperarioReal.mensaje = "No se pudo cerrar: ${t.message}" }
                                cerrando = null
                            }
                        }
                    }
                }
            }
        }
    }
}
