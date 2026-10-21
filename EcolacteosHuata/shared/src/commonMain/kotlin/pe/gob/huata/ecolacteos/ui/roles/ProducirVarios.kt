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

/** Cuánto se quiere de cada producto al producir varios a la vez (0 = no se hace) y la pestaña de Nueva orden. */
object VariosApp {
    val cantidad = mutableStateMapOf<Long, Double>()
    var modo by mutableStateOf(0) // Nueva orden: 0 un producto · 1 varios a la vez
}

/** Lo que se hará de un producto: cuántos, con cuántos litros. */
private class Elegido(val p: JsonObject, val q: Double, val litros: Double, val unidad: String)

/** Un insumo con lo que necesitan todos los productos elegidos y quiénes lo usan. */
private class Uso(val nombre: String, val unidad: String, val stock: Double, val necesita: Double, val productos: List<String>)

/** Rendimiento mínimo por litro: /compras/formulas lo trae así; /planta/recetas, por cada 100 L. */
internal fun rendMin(p: JsonObject) = p.num("rendimiento_min").takeIf { it > 0 } ?: (p.num("min_100l") / 100)
internal fun rendMax(p: JsonObject) = p.num("rendimiento_max").takeIf { it > 0 } ?: (p.num("max_100l") / 100)

/**
 * "¿Qué vas a producir hoy?", como en la web: la única forma de producir, sea uno
 * o varios. Cuánto de cada producto (los sabores debajo de su base), la leche
 * total y los insumos sumados (el cultivo láctico lo usan el queso y el yogurt).
 * Con [puedeIniciar] se abren todos los lotes juntos; si algo falta, ninguno.
 */
@Composable
fun ProducirVarios(productos: List<JsonObject>, tanque: Double, puedeIniciar: Boolean, onListo: (Noti) -> Unit) {
    var confirmar by remember { mutableStateOf(false) }
    val elegidos = productos.mapNotNull { p ->
        val q = VariosApp.cantidad[p.num("id").toLong()] ?: 0.0
        val min = rendMin(p)
        if (q <= 0 || min <= 0) null
        else Elegido(p, q, ceil(q / min - 1e-9), p.txt("unidad").substringBefore(' '))
    }
    val litros = elegidos.sumOf { it.litros }
    val insumos = elegidos.flatMap { e -> e.p.lista("insumos").map { i -> i to e } }
        .groupBy { it.first.num("id").toLong() }
        .map { (_, usos) ->
            val i = usos.first().first
            Uso(i.txt("nombre"), i.txt("unidad"), i.num("stock"),
                usos.sumOf { (x, e) -> cantidadInsumo(x.num("por_100l"), e.litros, x.txt("unidad")) }, usos.map { it.second.p.txt("nombre") })
        }
        .sortedWith(compareByDescending<Uso> { it.productos.size }.thenBy { it.nombre })
    val faltan = buildList {
        if (litros > tanque) add("leche (${formatear(litros - tanque)} L)")
        insumos.filter { it.necesita > it.stock + 1e-9 }.forEach { add("${it.nombre.lowercase()} (${formatear(it.necesita - it.stock, 3)} ${it.unidad})") }
    }
    val texto = elegidos.joinToString(" y ") { "${formatear(it.q, 0)} ${it.unidad} de ${it.p.txt("nombre")}" }

    Tarjeta(fondo = Color(0xFF1B2A36), borde = when { elegidos.isEmpty() -> Altiplano.Borde; faltan.isEmpty() -> Altiplano.VerdeBorde; else -> Altiplano.AlertaBorde }) {
        Text("¿Qué vas a producir hoy?", style = Texto.TituloPantalla.copy(fontSize = 19.sp), color = Altiplano.Texto)
        Text("Pon cuánto quieres de cada uno (0 = no se hace). Se revisa que la leche y los insumos alcancen. " +
            "En el tanque hay ${formatear(tanque)} L.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
        productos.forEach { p ->
            val id = p.num("id").toLong()
            val u = p.txt("unidad").substringBefore(' ')
            val q = VariosApp.cantidad[id] ?: 0.0
            val e = elegidos.firstOrNull { it.p === p }
            Spacer(Modifier.height(12.dp))
            val sabor = p.txt("base_id").isNotEmpty()
            Text(nombreCorto(p), style = Texto.Fila, color = Altiplano.Texto, modifier = Modifier.padding(start = if (sabor) 16.dp else 0.dp))
            StepperGrande(q, { VariosApp.cantidad[id] = (it ?: 0.0).coerceAtLeast(0.0) }, paso = if (u == "litros") 10.0 else 1.0,
                minimo = 0.0, decimales = 0, unidad = u + (e?.let { " · ${formatear(it.litros, 0)} L de leche" } ?: ""), compacto = true)
        }
        if (elegidos.isNotEmpty()) {
            HorizontalDivider(Modifier.padding(vertical = 10.dp), color = Altiplano.BordeSuave)
            FilaValor("Leche total · hay ${formatear(tanque)} L", "${formatear(litros, 0)} L" + if (litros > tanque) " ✗" else " ✓",
                if (litros > tanque) Altiplano.Alerta else Altiplano.Exito)
            insumos.forEach { i ->
                val falta = i.necesita > i.stock + 1e-9
                FilaValor(
                    "${i.nombre} · hay ${formatear(i.stock, 2)} ${i.unidad}" + if (i.productos.size > 1) " (lo usan los ${i.productos.size})" else "",
                    "${formatear(i.necesita, 3)} ${i.unidad}" + if (falta) " ✗" else " ✓",
                    if (falta) Altiplano.Alerta else Altiplano.Exito,
                )
            }
            Spacer(Modifier.height(8.dp))
            if (faltan.isEmpty()) Tarjeta(fondo = Altiplano.VerdeMarcaSuave, borde = Altiplano.VerdeBorde) {
                Text("✓ Sí se puede hacer $texto.", style = Texto.Cuerpo, color = Altiplano.Exito)
            }
            else AvisoCampo("✗ No alcanza: falta ${faltan.joinToString(", ")}. Baja alguna cantidad o consigue lo que falta.", esAlerta = true)
            if (puedeIniciar) {
                BotonPrimario("Aceptar: iniciar ${elegidos.size} ${if (elegidos.size == 1) "lote" else "lotes"}", habilitado = faltan.isEmpty()) { confirmar = true }
            } else if (faltan.isEmpty()) {
                Text("Los lotes los inicia el operario o el administrador.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            }
        }
    }

    if (confirmar) ConfirmarVarios(elegidos, texto, onCerrar = { confirmar = false }, onListo = onListo)
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
