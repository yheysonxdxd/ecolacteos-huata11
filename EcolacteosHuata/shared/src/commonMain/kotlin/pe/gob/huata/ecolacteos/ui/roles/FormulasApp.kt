package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.launch
import kotlinx.serialization.json.*
import pe.gob.huata.ecolacteos.datos.Sesion
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*
import kotlin.math.ceil

/** Cuánto se quiere hacer de cada producto (quesos, litros de yogurt). */
object FormulasApp {
    val quiero = mutableStateMapOf<Long, Double>()
    var mensaje by mutableStateOf<String?>(null)
    var version by mutableStateOf(0)
}

/**
 * "Quiero hacer 10 quesos": la leche y los insumos que hacen falta, si alcanza
 * o no, y los pasos. Con [puedeAceptar] (administrador) se inicia el lote.
 */
@Composable
fun FormulasReal(puedeAceptar: Boolean, onLoteIniciado: () -> Unit = {}) {
    var confirmar by remember { mutableStateOf<Pair<JsonObject, Double>?>(null) } // producto y litros
    CargaServidor("/api/v1/compras/formulas", clave = FormulasApp.version) { d, recargar ->
        val tanque = d.num("tanque")
        PantallaScroll {
            FormulasApp.mensaje?.let { AvisoCampo(it, esAlerta = it.startsWith("No se pudo")) }
            Text("Escribe cuánto quieres hacer y te dice qué necesitas. En el tanque hay ${formatear(tanque)} L de leche aceptada.",
                style = Texto.Cuerpo, color = Altiplano.TextoTerciario)
            d.lista("avisos").filter { it.txt("nivel") == "FALTA" }.forEach { AvisoCampo(it.txt("texto"), esAlerta = true) }

            // queso y yogurt (o los que haya) con la leche del día: todo revisado junto
            if (d.lista("productos").size > 1) {
                ProducirVarios(d.lista("productos"), tanque, puedeAceptar) { msg ->
                    FormulasApp.mensaje = msg
                    OperarioReal.version++
                    recargar(); onLoteIniciado()
                }
            }

            d.lista("productos").forEach { p ->
                val id = p.num("id").toLong()
                val u = p.txt("unidad").substringBefore(' ')
                val q = FormulasApp.quiero[id] ?: 10.0
                val min = p.num("rendimiento_min")
                val litros = if (min > 0) ceil(q / min - 1e-9) else 0.0
                val insumos = p.lista("insumos")
                val necesita = { i: JsonObject -> i.num("por_100l") * litros / 100 }
                val faltan = buildList {
                    if (litros > tanque) add("leche (${formatear(litros - tanque)} L)")
                    insumos.filter { necesita(it) > it.num("stock") + 1e-9 }.forEach {
                        add("${it.txt("nombre").lowercase()} (${formatear(necesita(it) - it.num("stock"), 3)} ${it.txt("unidad")})")
                    }
                }

                Tarjeta(fondo = Color(0xFF1B2A36), borde = if (faltan.isEmpty()) Altiplano.VerdeBorde else Altiplano.AlertaBorde) {
                    Text(p.txt("nombre"), style = Texto.TituloPantalla.copy(fontSize = 19.sp), color = Altiplano.Texto)
                    Text("Rinde ${formatear(min * 100, 0)}–${formatear(p.num("rendimiento_max") * 100, 0)} $u por cada 100 L · vence en ${p.txt("vida_util_dias")} días",
                        style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                    Spacer(Modifier.height(10.dp))
                    Text("¿Cuántos $u quieres hacer?", style = Texto.Fila, color = Altiplano.Texto)
                    StepperGrande(q, { FormulasApp.quiero[id] = (it ?: 0.0).coerceAtLeast(0.0) }, paso = if (u == "litros") 10.0 else 1.0,
                        minimo = 0.0, decimales = 0, unidad = "$u · necesitas ${formatear(litros, 0)} L de leche", compacto = true)
                    HorizontalDivider(Modifier.padding(vertical = 10.dp), color = Altiplano.BordeSuave)
                    if (insumos.isEmpty()) Text("Esta fórmula no tiene insumos.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                    insumos.forEach { i ->
                        val falta = necesita(i) > i.num("stock") + 1e-9
                        FilaValor(
                            "${i.txt("nombre")} · hay ${formatear(i.num("stock"), 2)} ${i.txt("unidad")}",
                            "${formatear(necesita(i), 3)} ${i.txt("unidad")}" + if (falta) " ✗" else " ✓",
                            if (falta) Altiplano.Alerta else Altiplano.Exito,
                        )
                    }
                    Spacer(Modifier.height(8.dp))
                    if (q <= 0) AvisoCampo("Escribe cuántos $u quieres hacer.")
                    else if (faltan.isEmpty()) Tarjeta(fondo = Altiplano.VerdeMarcaSuave, borde = Altiplano.VerdeBorde) {
                        Text("✓ Sí se puede hacer ${formatear(q, 0)} $u. Hay leche e insumos suficientes.", style = Texto.Cuerpo, color = Altiplano.Exito)
                    }
                    else AvisoCampo("✗ No se puede. Para hacer ${formatear(q, 0)} $u falta ${faltan.joinToString(", ")}. Hay que conseguirlo antes de producir.", esAlerta = true)
                    if (puedeAceptar) BotonPrimario("Aceptar: hacer ${formatear(q, 0)} $u", habilitado = q > 0 && faltan.isEmpty()) { confirmar = p to litros }
                    else if (q > 0 && faltan.isEmpty()) Text("El lote lo inicia el operario o el administrador.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)

                    val pasos = (p["procedimiento"] as? JsonArray)?.map { it.jsonPrimitive.content } ?: emptyList()
                    if (pasos.isNotEmpty()) {
                        HorizontalDivider(Modifier.padding(vertical = 12.dp), color = Altiplano.BordeSuave)
                        Text("Cómo se hace", style = Texto.Apoyo, color = Altiplano.CelesteAcento)
                        pasos.forEachIndexed { k, paso -> Text("${k + 1}. $paso", style = Texto.Cuerpo, color = Altiplano.TextoSecundario, modifier = Modifier.padding(top = 6.dp)) }
                    }
                }
            }
        }
        confirmar?.let { (p, litros) -> ConfirmarLote(p, litros, onCerrar = { confirmar = null }) { recargar(); onLoteIniciado() } }
    }
}

@Composable
private fun ConfirmarLote(p: JsonObject, litros: Double, onCerrar: () -> Unit, onListo: () -> Unit) {
    val scope = rememberCoroutineScope()
    var enviando by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val u = p.txt("unidad").substringBefore(' ')
    val q = FormulasApp.quiero[p.num("id").toLong()] ?: 10.0
    AlertDialog(
        onDismissRequest = onCerrar, containerColor = Altiplano.Fondo,
        title = { Text("Hacer ${formatear(q, 0)} $u de ${p.txt("nombre")}", color = Altiplano.Texto) },
        text = {
            Column {
                Text("Al aceptar se inicia el lote y se descuenta del almacén:", color = Altiplano.TextoTerciario)
                Spacer(Modifier.height(8.dp))
                FilaValor("Leche del tanque", "${formatear(litros, 0)} L")
                p.lista("insumos").forEach { i -> FilaValor(i.txt("nombre"), "${formatear(i.num("por_100l") * litros / 100, 3)} ${i.txt("unidad")}") }
                Spacer(Modifier.height(6.dp))
                Text("Deben salir entre ${formatear(litros * p.num("rendimiento_min"), 0)} y ${formatear(litros * p.num("rendimiento_max"), 0)} $u.",
                    style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                error?.let { Text(it, color = Altiplano.Alerta) }
            }
        },
        confirmButton = {
            TextButton(enabled = !enviando, onClick = {
                scope.launch {
                    enviando = true
                    try {
                        val r = Sesion.enviar("/api/v1/planta/lotes", buildJsonObject { put("producto_id", p.num("id").toLong()); put("litros", litros) })
                        FormulasApp.mensaje = "✓ Lote ${r.txt("codigo")} iniciado con ${formatear(litros, 0)} L. Ciérralo en Lotes cuando termine."
                        OperarioReal.version++
                        onCerrar(); onListo()
                    } catch (t: Throwable) { error = t.message }
                    enviando = false
                }
            }) { Text(if (enviando) "Iniciando…" else "Aceptar e iniciar lote", color = Altiplano.Exito) }
        },
        dismissButton = { TextButton(onClick = onCerrar) { Text("Cancelar", color = Altiplano.TextoTerciario) } },
    )
}
