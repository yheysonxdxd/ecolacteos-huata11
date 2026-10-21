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
    "PRODUCCION" to "Producción", "AJUSTE" to "Corrección", "VENTA" to "Venta", "ACOPIO" to "Acopio", "MERMA" to "Pérdida")

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
        // pestañas: Insumos | Queso (todos) | Yogurt (todos, con sus sabores) | Leche
        val grupos = gruposKardex(productos)
        val pestanas = listOf("insumo" to null as Long?) + grupos.map { "producto" to it.id } + listOf("leche" to null)
        val nombres = listOf("Insumos") + grupos.map { it.nombre } + listOf("Leche")
        val activa = pestanas.indexOfFirst { (tipo, id) ->
            tipo == k.tipo && (k.tipo != "producto" || grupos.any { it.id == id && k.id in it.ids })
        }.coerceAtLeast(0)
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

private val NOMBRE_FAMILIA = mapOf("YOGURT" to "Yogurt", "QUESO" to "Queso")

/** Productos que van en un mismo kardex, igual que la web y el servidor (Kardex::producto). */
private class GrupoKardex(val id: Long, val nombre: String, val ids: MutableList<Long> = mutableListOf())

private fun gruposKardex(productos: List<JsonObject>): List<GrupoKardex> {
    val grupos = LinkedHashMap<String, GrupoKardex>()
    productos.forEach { p ->
        val id = p.num("id").toLong()
        val tipo = p.txt("tipo")
        val clave = if (tipo in NOMBRE_FAMILIA) "$tipo|${p.txt("unidad")}" else "p${p.txt("base_id").toLongOrNull() ?: id}"
        grupos.getOrPut(clave) { GrupoKardex(id, NOMBRE_FAMILIA[tipo] ?: p.txt("nombre")) }.ids += id
    }
    return grupos.values.toList()
}

@Composable
private fun KardexDe(ruta: String) {
    val k = KardexElegido
    var mostrar by remember(ruta) { mutableStateOf(40) }

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

            // el kardex solo se consulta: corregir un insumo y registrar pérdidas se hace en Almacén
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.CenterVertically) {
                Text("Para corregir o anotar una pérdida, ve a Almacén.", style = Texto.Apoyo, color = Altiplano.TextoTerciario,
                    modifier = Modifier.weight(1f))
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
                // en Queso / Yogurt: de cuál es (Queso andino, Yogurt de fresa…)
                f.txt("que").takeIf { it.isNotEmpty() }?.let { Text(it, style = Texto.Fila, color = Altiplano.Texto) }
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

private val POR_ENTRA = mapOf("INICIAL" to "que ya había", "COMPRA" to "por compras", "AJUSTE" to "por correcciones",
    "ACOPIO" to "de acopio aceptado", "PRODUCCION" to "hechos en lotes")
private val POR_SALE = mapOf("PRODUCCION" to "para hacer lotes", "VENTA" to "vendidos", "AJUSTE" to "por correcciones", "MERMA" to "perdidos")

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

/** Qué pasó con el insumo, en palabras de todos los días (como en la web). tipo null: se elige o se calcula. */
private data class Razon(val texto: String, val tipo: String?, val motivo: String)

private val RAZONES = listOf(
    Razon("Se malogró, mojó o derramó", "SALIDA", "Se malogró"),
    Razon("Se venció", "SALIDA", "Se venció"),
    Razon("Contamos y no cuadra", null, "Conteo"),
    Razon("Nos devolvieron", "ENTRADA", "Devolución"),
    Razon("Otra cosa", null, ""),
)

/**
 * Corregir el stock de un insumo: lo que no es compra ni producción. Al contar,
 * se escribe cuánto hay de verdad y la app calcula si sobra o falta.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun HojaAjuste(item: JsonObject, onCerrar: () -> Unit) {
    var razon by remember { mutableStateOf<Razon?>(null) }
    var salida by remember { mutableStateOf(true) } // solo para "Otra cosa"
    var cantidad by remember { mutableStateOf("") }
    var hay by remember { mutableStateOf("") }
    var detalle by remember { mutableStateOf("") }
    var enviando by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    val u = item.txt("unidad")
    val stock = item.num("stock")
    val contamos = razon?.motivo == "Conteo"
    val diferencia = hay.replace(",", ".").toDoubleOrNull()?.let { it - stock }

    Hoja("Corregir ${item.txt("nombre")}", onCerrar) {
        Text("El sistema dice que hay ${formatear(stock, 3)} $u. ¿Qué pasó?", style = Texto.Cuerpo, color = Altiplano.TextoSecundario)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            RAZONES.forEach { r -> Chip(r.texto, razon == r) { razon = r; error = null } }
        }
        val r = razon
        if (r != null) {
            if (contamos) {
                Campo(hay, { hay = it.filter { c -> c.isDigit() || c == '.' || c == ',' }.take(10) }, "¿Cuánto hay de verdad? ($u)", teclado = KeyboardType.Decimal)
                diferencia?.let { dif ->
                    Text(
                        when {
                            kotlin.math.abs(dif) < 0.0005 -> "Cuadra con el sistema: no hay nada que corregir."
                            dif > 0 -> "Sobran ${formatear(dif, 3)} $u: se sumarán al stock."
                            else -> "Faltan ${formatear(-dif, 3)} $u: se restarán del stock."
                        },
                        style = Texto.Cuerpo, color = if (dif < 0) Altiplano.Aviso else Altiplano.Exito,
                    )
                }
            } else {
                if (r.tipo == null) Segmentos(listOf("Hay menos", "Hay más"), if (salida) 0 else 1) { salida = it == 0 }
                Campo(cantidad, { cantidad = it.filter { c -> c.isDigit() || c == '.' || c == ',' }.take(10) },
                    if ((r.tipo ?: if (salida) "SALIDA" else "ENTRADA") == "SALIDA") "¿Cuánto se perdió? ($u)" else "¿Cuánto entró? ($u)",
                    teclado = KeyboardType.Decimal)
            }
            Campo(detalle, { detalle = it.take(80) }, if (r.motivo.isEmpty()) "Cuenta brevemente qué pasó" else "Detalle (opcional, ej.: bolsa mojada)")
        }
        error?.let { AvisoCampo(it, esAlerta = true) }
        BotonPrimario(if (enviando) "Guardando…" else "Corregir stock", habilitado = !enviando && r != null) {
            val rz = razon ?: return@BotonPrimario
            var tipo = rz.tipo ?: if (salida) "SALIDA" else "ENTRADA"
            var cant = cantidad.replace(",", ".").toDoubleOrNull() ?: 0.0
            error = null
            if (contamos) {
                val dif = diferencia
                when {
                    dif == null || dif + stock < 0 -> error = "¿Cuánto hay de verdad? Escríbelo"
                    kotlin.math.abs(dif) < 0.0005 -> error = "Cuadra con lo que dice el sistema: no hay nada que corregir"
                    else -> { tipo = if (dif > 0) "ENTRADA" else "SALIDA"; cant = kotlin.math.abs(dif) }
                }
            } else if (cant <= 0) error = "Escribe la cantidad"
            if (error == null && rz.motivo.isEmpty() && detalle.trim().length < 3) error = "Cuenta brevemente qué pasó"
            if (error == null && tipo == "SALIDA" && cant > stock + 0.0005) error = "Solo hay ${formatear(stock, 3)} $u"
            if (error != null) return@BotonPrimario
            val motivo = if (contamos) "Conteo: había ${formatear(stock + (diferencia ?: 0.0), 3)} $u (el sistema decía ${formatear(stock, 3)})"
                         else listOf(rz.motivo, detalle.trim()).filter { it.isNotEmpty() }.joinToString(" · ")
            scope.launch {
                enviando = true
                try {
                    val res = Sesion.enviar("/api/v1/compras/ajustes", buildJsonObject {
                        put("insumo_id", item.num("id").toLong()); put("tipo", tipo)
                        put("cantidad", kotlin.math.round(cant * 1000) / 1000); put("motivo", motivo)
                    })
                    Perdidas.mensaje = "✓ Corregido · ${res.txt("insumo")} queda con ${formatear(res.num("stock"), 3)} $u"
                    KardexElegido.version++
                    Perdidas.version++ // Almacén vuelve a leer el stock
                    onCerrar()
                } catch (t: Throwable) { error = t.message }
                enviando = false
            }
        }
    }
}
