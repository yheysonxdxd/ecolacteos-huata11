package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import kotlinx.serialization.json.*
import pe.gob.huata.ecolacteos.datos.Sesion
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*
import kotlin.time.Clock
import kotlin.time.Duration.Companion.days
import kotlin.time.Duration.Companion.hours

/** Qué kardex se está viendo (también lo elige Almacén al tocar un insumo). */
object KardexElegido {
    var tipo by mutableStateOf("insumo") // insumo | producto | leche
    var id by mutableStateOf<Long?>(null)
    var periodo by mutableStateOf(0)    // 0 todo · 1 este mes · 2 últimos 7 días
    var contable by mutableStateOf(false)
    var version by mutableStateOf(0)
}

/** Fecha en Lima (UTC-5) hace [dias] días: "2026-10-01". */
internal fun fechaLima(dias: Int = 0): String = (Clock.System.now() - 5.hours - dias.days).toString().take(10)

private val TIPO_MOV = mapOf("INICIAL" to "Saldo inicial", "ANTERIOR" to "Saldo anterior", "COMPRA" to "Compra",
    "PRODUCCION" to "Producción", "AJUSTE" to "Ajuste", "VENTA" to "Venta", "ACOPIO" to "Acopio", "MERMA" to "Pérdida")

/**
 * Kardex de insumos, de cada producto (queso, yogurt) y de la leche, como en la
 * web de oficina: resumen en una frase, lista sencilla y, si se pide, con costos.
 */
@Composable
fun KardexCompleto() {
    val k = KardexElegido
    CargaServidor("/api/v1/compras/inventario", clave = Perdidas.version) { inv, _ ->
        val insumos = inv.lista("insumos")
        val productos = inv.lista("productos")
        // pestañas: Insumos | Queso Paria | Yogurt natural | Leche
        val pestanas = listOf("insumo" to null as Long?) + productos.map { "producto" to it.num("id").toLong() } + listOf("leche" to null)
        val nombres = listOf("Insumos") + productos.map { it.txt("nombre") } + listOf("Leche")
        val activa = pestanas.indexOfFirst { it.first == k.tipo && (k.tipo != "producto" || it.second == k.id) }.coerceAtLeast(0)
        if (k.tipo == "insumo" && insumos.none { it.num("id").toLong() == k.id }) k.id = insumos.firstOrNull()?.num("id")?.toLong()
        if (k.tipo == "producto" && productos.none { it.num("id").toLong() == k.id }) k.id = productos.firstOrNull()?.num("id")?.toLong()

        Column(Modifier.fillMaxSize()) {
            Column(Modifier.padding(start = Medidas.Pantalla, end = Medidas.Pantalla, top = 12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                PestanasScroll(nombres, activa) { i -> k.tipo = pestanas[i].first; k.id = pestanas[i].second }
                if (k.tipo == "insumo") {
                    PestanasScroll(insumos.map { it.txt("nombre") }, insumos.indexOfFirst { it.num("id").toLong() == k.id }.coerceAtLeast(0)) {
                        k.id = insumos[it].num("id").toLong()
                    }
                }
            }
            val desde = when (k.periodo) { 1 -> fechaLima().take(8) + "01"; 2 -> fechaLima(6); else -> null }
            val ruta = "/api/v1/compras/kardex?tipo=${k.tipo}" + (if (k.tipo != "leche") "&id=${k.id ?: 0}" else "") + (desde?.let { "&desde=$it" } ?: "")
            Box(Modifier.weight(1f)) { KardexDe(ruta) }
        }
    }
}

@Composable
private fun KardexDe(ruta: String) {
    val k = KardexElegido
    var mostrar by remember(ruta) { mutableStateOf(40) }
    var hojaAjuste by remember { mutableStateOf(false) }
    var hojaPerdida by remember { mutableStateOf(false) }

    CargaServidor(ruta, clave = k.version + Perdidas.version * 1000) { d, _ ->
        val item = d.obj("item")
        val u = item?.txt("unidad") ?: ""
        val t = d.obj("totales")
        val filas = d.lista("filas")
        PantallaScroll {
            Perdidas.mensaje?.let { AvisoCampo(it) }
            Segmentos(listOf("Todo", "Este mes", "7 días"), k.periodo) { k.periodo = it }
            TarjetaDato(
                "QUEDA · ${item?.txt("nombre")?.uppercase() ?: ""}", "${formatear(t?.obj("saldo")?.num("cantidad") ?: 0.0, 2)} $u",
                apoyo = if (k.contable) "vale ${soles(t?.obj("saldo")?.num("total") ?: 0.0)} · costo promedio ${soles(t?.obj("saldo")?.num("costo") ?: 0.0)}"
                        else (item?.num("minimo")?.takeIf { it > 0 }?.let { "mínimo ${formatear(it, 2)} $u" }),
                destacada = true,
            )
            // "Entraron 60 kg (60 kg por compras). Salieron 31.6 kg (para hacer lotes). Ahora quedan 28.4 kg."
            Tarjeta { Text(resumen(d, u, k.periodo != 0), style = Texto.Cuerpo, color = Altiplano.Texto) }

            Row(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.weight(1f)) {
                    if (item?.txt("tipo") == "insumo") BotonPrimario("+ Ajuste (merma, conteo)") { hojaAjuste = true }
                    else BotonPrimario(if (k.tipo == "leche") "+ Leche perdida" else "+ Registrar pérdida") { hojaPerdida = true }
                }
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("Con costos", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                    Spacer(Modifier.width(6.dp))
                    Switch(k.contable, { k.contable = it }, colors = SwitchDefaults.colors(checkedTrackColor = Altiplano.VerdeMarca))
                }
            }
            Text("Lo más reciente arriba. A la derecha, lo que entró o salió y cuánto quedó.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            if (filas.isEmpty()) AvisoCampo("No hay movimientos en estas fechas.")
            filas.reversed().take(mostrar).forEach { f -> FilaKardex(f, u, k.contable) }
            if (filas.size > mostrar) TextButton(onClick = { mostrar += 40 }) {
                Text("Ver más (${filas.size - mostrar} anteriores)", color = Altiplano.CelesteAcento)
            }

            if (hojaAjuste && item != null) HojaAjuste(item) { hojaAjuste = false }
            if (hojaPerdida) CargaPerdida(if (k.tipo == "leche") "LECHE" else "PRODUCTO", k.id) { hojaPerdida = false }
        }
    }
}

@Composable
private fun FilaKardex(f: JsonObject, u: String, contable: Boolean) {
    val entrada = f.obj("entrada"); val salida = f.obj("salida"); val saldo = f.obj("saldo")
    val perdida = f.txt("tipo") == "MERMA"
    val sub = buildString {
        append(fechaCorta(f.txt("fecha"))).append(" ").append(f.txt("fecha").drop(11).take(5))
        append(" · ").append(TIPO_MOV[f.txt("tipo")] ?: f.txt("tipo"))
        f.txt("usuario").takeIf { it.isNotEmpty() }?.let { append(" · ").append(it) }
        if (contable) (entrada ?: salida)?.let { append(" · ${soles(it.num("costo"))} c/u = ${soles(it.num("total"))}") }
    }
    Tarjeta(borde = if (perdida) Altiplano.AlertaBorde else Altiplano.Borde) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(f.txt("texto").ifEmpty { f.txt("detalle") }, style = Texto.Cuerpo, color = if (perdida) Altiplano.Alerta else Altiplano.Texto)
                Text(sub, style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            }
            Spacer(Modifier.width(10.dp))
            Column(horizontalAlignment = Alignment.End) {
                when {
                    entrada != null -> Text("+ ${formatear(entrada.num("cantidad"), 3)} $u", style = Texto.Fila, color = Altiplano.Exito)
                    salida != null -> Text("− ${formatear(salida.num("cantidad"), 3)} $u", style = Texto.Fila, color = if (perdida) Altiplano.Alerta else Altiplano.Aviso)
                }
                Text("queda ${formatear(saldo?.num("cantidad") ?: 0.0, 2)}", style = Texto.Apoyo, color = Altiplano.TextoSecundario)
            }
        }
    }
}

private val POR_ENTRA = mapOf("INICIAL" to "que ya había", "COMPRA" to "por compras", "AJUSTE" to "por ajustes",
    "ACOPIO" to "de acopio aceptado", "PRODUCCION" to "hechos en lotes")
private val POR_SALE = mapOf("PRODUCCION" to "para hacer lotes", "VENTA" to "vendidos", "AJUSTE" to "por mermas o ajustes", "MERMA" to "perdidos")

private fun resumen(d: JsonObject, u: String, conInicio: Boolean): String {
    val t = d.obj("totales") ?: return ""
    val r = d.lista("resumen")
    fun detalle(lado: String, nombres: Map<String, String>) = r.filter { it.num(lado) > 0 }
        .joinToString(", ") { "${formatear(it.num(lado), 3)} $u ${nombres[it.txt("tipo")] ?: it.txt("tipo").lowercase()}" }
    val entro = t.obj("entradas")?.num("cantidad") ?: 0.0
    val salio = t.obj("salidas")?.num("cantidad") ?: 0.0
    return buildString {
        if (conInicio) append("Al empezar había ${formatear(t.num("inicio"), 3)} $u. ")
        append(if (entro > 0) "Entraron ${formatear(entro, 3)} $u (${detalle("entra", POR_ENTRA)}). " else "No entró nada. ")
        append(if (salio > 0) "Salieron ${formatear(salio, 3)} $u (${detalle("sale", POR_SALE)}). " else "No salió nada. ")
        append("Ahora quedan ${formatear(t.obj("saldo")?.num("cantidad") ?: 0.0, 3)} $u.")
    }
}

/** Abre la hoja de pérdida con los datos que necesita (motivos, productos, lotes). */
@Composable
private fun CargaPerdida(tipo: String, productoId: Long?, onCerrar: () -> Unit) {
    var datos by remember { mutableStateOf<JsonObject?>(null) }
    LaunchedEffect(Unit) { datos = runCatching { Sesion.consultar("/api/v1/compras/mermas") }.getOrNull() }
    datos?.let { HojaPerdida("compras", tipo, productoId, it, onCerrar) }
}

/** Ajuste de un insumo: lo que no es compra ni producción (merma, vencido, conteo físico, devolución). */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun HojaAjuste(item: JsonObject, onCerrar: () -> Unit) {
    var salida by remember { mutableStateOf(true) }
    var cantidad by remember { mutableStateOf("") }
    var motivo by remember { mutableStateOf("") }
    var enviando by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    val u = item.txt("unidad")

    Hoja("Ajuste de ${item.txt("nombre")}", onCerrar) {
        Text("Stock actual: ${formatear(item.num("stock"), 3)} $u. Para lo que no es compra ni producción.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Chip("Salida (merma, vencido, faltante)", salida) { salida = true }
            Chip("Entrada (sobrante, devolución)", !salida) { salida = false }
        }
        Campo(cantidad, { cantidad = it.filter { c -> c.isDigit() || c == '.' }.take(10) }, "Cantidad ($u)", teclado = KeyboardType.Decimal)
        Campo(motivo, { motivo = it.take(120) }, "Motivo (ej.: bolsa de azúcar mojada)")
        error?.let { AvisoCampo(it, esAlerta = true) }
        BotonPrimario(if (enviando) "Guardando…" else "Registrar ajuste", habilitado = !enviando) {
            val cant = cantidad.toDoubleOrNull() ?: 0.0
            error = when {
                cant <= 0 -> "Escribe la cantidad"
                motivo.trim().length < 3 -> "Escribe el motivo"
                salida && cant > item.num("stock") -> "Solo hay ${formatear(item.num("stock"), 3)} $u"
                else -> null
            }
            if (error != null) return@BotonPrimario
            scope.launch {
                enviando = true
                try {
                    val r = Sesion.enviar("/api/v1/compras/ajustes", buildJsonObject {
                        put("insumo_id", item.num("id").toLong()); put("tipo", if (salida) "SALIDA" else "ENTRADA")
                        put("cantidad", cant); put("motivo", motivo.trim())
                    })
                    Perdidas.mensaje = "✓ Ajuste registrado · ${r.txt("insumo")} queda con ${formatear(r.num("stock"), 3)} $u"
                    KardexElegido.version++
                    onCerrar()
                } catch (t: Throwable) { error = t.message }
                enviando = false
            }
        }
    }
}
