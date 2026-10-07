package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import kotlinx.serialization.json.*
import pe.gob.huata.ecolacteos.datos.ConfigServidor
import pe.gob.huata.ecolacteos.datos.Sesion
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*
import kotlin.time.Clock
import kotlin.time.Duration.Companion.hours
import kotlin.time.Instant

/** Estado de Compras de insumos (sobrevive al cambiar de pestaña). */
object CompraInsumos {
    var pestana by mutableStateOf(0)            // Por llegar · Nueva orden · Recibidas · Vendedores
    var mes by mutableStateOf<String?>(null)    // Recibidas: null = este mes · "todo"
    var buscarVendedor by mutableStateOf("")
    var editandoVendedor by mutableStateOf<JsonObject?>(null)
    var vendedorId by mutableStateOf<Long?>(null)
    val lineas = mutableStateMapOf<Long, Double>() // insumo → cantidad
    var nuevoVendedor by mutableStateOf(false)
    var recibiendo by mutableStateOf<JsonObject?>(null)
    var anulando by mutableStateOf<JsonObject?>(null)
    var mensaje by mutableStateOf<String?>(null)
    var version by mutableStateOf(0)
}

private val TIPOS = listOf("FACTURA" to "Factura", "BOLETA" to "Boleta", "RECIBO" to "Recibo")

private fun hoyLima(): String = (Clock.System.now() - 5.hours).toString().take(10)

/** Para un campo que se edita: 1250.5 → "1250.5", 95.0 → "95" (sin separador de miles). */
private fun simple(v: Double): String = if (v % 1.0 == 0.0) v.toLong().toString() else (kotlin.math.round(v * 100) / 100).toString()

/** "50 kg Sal industrial · 1 L Cuajo líquido" */
private fun resumen(o: JsonObject) = o.lista("items").joinToString(" · ") { "${formatear(it.num("cantidad"), 2)} ${it.txt("unidad")} ${it.txt("insumo")}" }

/**
 * Compras de insumos, como en la web de oficina: a quién se le compra
 * (persona con DNI o empresa con RUC), varios insumos por orden y, cuando
 * llega, el comprobante (factura, boleta o recibo) con su foto.
 */
@Composable
fun ComprasInsumosReal() {
    val c = CompraInsumos
    ConPestanas(listOf("Por llegar", "Nueva orden", "Recibidas", "Vendedores"), c.pestana, { c.pestana = it; c.recibiendo = null; c.anulando = null }) {
        CargaServidor("/api/v1/compras/ordenes", clave = c.version + ComprasReal.version * 1000) { d, _ ->
            when {
                c.recibiendo != null -> RecibirOrden(c.recibiendo!!, d)
                c.anulando != null -> AnularOrden(c.anulando!!)
                c.pestana == 0 -> PorLlegar(d)
                c.pestana == 1 -> NuevaOrden(d)
                c.pestana == 2 -> Recibidas()
                else -> Vendedores()
            }
        }
    }
}

@Composable
private fun PorLlegar(d: JsonObject) {
    val pendientes = d.lista("ordenes").filter { it.txt("estado") == "EMITIDA" }
    PantallaScroll {
        CompraInsumos.mensaje?.let { AvisoCampo(it) }
        if (pendientes.isEmpty()) AvisoCampo("No hay órdenes por llegar. Para pedir insumos, ve a «Nueva orden».")
        pendientes.forEach { o ->
            Tarjeta(borde = Altiplano.VerdeBorde) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text("${o.txt("codigo")} · ${o.txt("proveedor")}", style = Texto.Fila, color = Altiplano.Texto)
                        Text(resumen(o), style = Texto.Apoyo, color = Altiplano.TextoSecundario)
                        Text("estimado ${soles(o.num("costo_estimado"))} · ${fechaCorta(o.txt("created_at"))}", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                    }
                    Column(horizontalAlignment = Alignment.End) {
                        TextButton(onClick = { CompraInsumos.mensaje = null; CompraInsumos.recibiendo = o }) { Text("Llegó", color = Altiplano.Exito) }
                        TextButton(onClick = { CompraInsumos.mensaje = null; CompraInsumos.anulando = o }) { Text("Anular", color = Altiplano.Alerta) }
                    }
                }
            }
        }
    }
}

/** Anular una orden que no llegó (se pidió mal): no mueve stock. */
@Composable
private fun AnularOrden(o: JsonObject) {
    val c = CompraInsumos
    val scope = rememberCoroutineScope()
    var motivo by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var enviando by remember { mutableStateOf(false) }

    PantallaScroll {
        Tarjeta(borde = Altiplano.AlertaBorde) {
            Text("Anular ${o.txt("codigo")}", style = Texto.Fila, color = Altiplano.Texto)
            Text("${o.txt("proveedor")} · ${resumen(o)}", style = Texto.Apoyo, color = Altiplano.TextoSecundario)
        }
        AvisoCampo("La orden deja de estar «por llegar». El stock no cambia porque todavía no llegó nada.")
        CampoTexto(motivo, { motivo = it.take(255) }, "¿Por qué se anula?")
        error?.let { AvisoCampo(it, esAlerta = true) }
        BotonPrimario(if (enviando) "Guardando…" else "Anular orden", !enviando) {
            error = if (motivo.trim().length < 5) "Escribe por qué se anula la orden" else null
            if (error == null) scope.launch {
                enviando = true
                try {
                    Sesion.enviar("/api/v1/compras/ordenes/${o.txt("id")}/anular", buildJsonObject { put("motivo", motivo.trim()) })
                    c.mensaje = "✓ Orden ${o.txt("codigo")} anulada"
                    c.anulando = null; c.version++; ComprasReal.version++
                } catch (t: Throwable) { error = t.message }
                enviando = false
            }
        }
        TextButton(onClick = { c.anulando = null }, modifier = Modifier.fillMaxWidth()) { Text("Volver", color = Altiplano.TextoSecundario) }
    }
}

// ---------------------------------------------------------------- nueva orden

@Composable
private fun NuevaOrden(d: JsonObject) {
    val c = CompraInsumos
    val scope = rememberCoroutineScope()
    var enviando by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    CargaServidor("/api/v1/compras/inventario", clave = c.version + ComprasReal.version * 1000) { inv, _ ->
        val insumos = inv.lista("insumos")
        val vendedores = d.lista("vendedores")
        val estimado = c.lineas.entries.sumOf { (id, cant) -> cant * (insumos.find { it.num("id").toLong() == id }?.num("costo") ?: 0.0) }

        PantallaScroll {
            c.mensaje?.let { AvisoCampo(it) }
            Seccion("¿A quién se le compra?")
            if (vendedores.isEmpty() && !c.nuevoVendedor) AvisoCampo("Todavía no hay vendedores: agrega el primero.")
            if (vendedores.size > 5) Campo(c.buscarVendedor, { c.buscarVendedor = it.take(40) }, "Buscar vendedor por nombre o DNI/RUC")
            // el elegido se ve siempre, aunque no coincida con lo buscado
            vendedores.filter { coincide(it, c.buscarVendedor) || it.num("id").toLong() == c.vendedorId }.forEach { v ->
                val id = v.num("id").toLong()
                Opcion(v.txt("nombre"), "${v.txt("tipo_documento")} ${v.txt("documento")}", c.vendedorId == id) { c.vendedorId = id }
            }
            if (c.nuevoVendedor) FormVendedor(
                alGuardar = { id -> c.vendedorId = id; c.nuevoVendedor = false; c.version++ },
                alCancelar = { c.nuevoVendedor = false },
            ) else TextButton(onClick = { c.nuevoVendedor = true }) { Text("+ Nuevo vendedor (persona o empresa)", color = Altiplano.Exito) }

            Seccion("Insumos · toca para agregar o quitar")
            insumos.forEach { i ->
                val id = i.num("id").toLong()
                val bajo = i.num("stock") < i.num("minimo")
                val cant = c.lineas[id]
                Opcion(i.txt("nombre"), "hay ${formatear(i.num("stock"), 2)} ${i.txt("unidad")}" + if (bajo) " · bajo el mínimo" else "", cant != null) {
                    if (cant != null) c.lineas.remove(id) else c.lineas[id] = 1.0
                }
                if (cant != null) Tarjeta {
                    StepperGrande(cant, { c.lineas[id] = it ?: 1.0 }, paso = 1.0, minimo = 0.5, decimales = 1, unidad = i.txt("unidad"), compacto = true)
                }
            }
            Tarjeta {
                FilaValor("Vendedor", vendedores.find { it.num("id").toLong() == c.vendedorId }?.txt("nombre") ?: "elige uno")
                FilaValor("Insumos", "${c.lineas.size}")
                FilaValor("Costo estimado", soles(estimado))
            }
            error?.let { AvisoCampo("No se pudo guardar: $it", esAlerta = true) }
            BotonPrimario(if (enviando) "Emitiendo…" else "Emitir orden de compra", !enviando && c.vendedorId != null && c.lineas.isNotEmpty()) {
                scope.launch {
                    enviando = true; error = null
                    try {
                        val r = Sesion.enviar("/api/v1/compras/ordenes", buildJsonObject {
                            put("vendedor_id", c.vendedorId!!)
                            putJsonArray("items") {
                                c.lineas.forEach { (id, cant) -> addJsonObject { put("insumo_id", id); put("cantidad", cant) } }
                            }
                        })
                        c.mensaje = "✓ ${r.txt("codigo")} emitida por ${soles(r.num("costo_estimado"))}. Cuando llegue, toca «Llegó» y registra el comprobante."
                        c.lineas.clear(); c.pestana = 0; c.version++
                    } catch (t: Throwable) { error = t.message }
                    enviando = false
                }
            }
        }
    }
}

/** Vendedor de insumos (persona con DNI o empresa con RUC): alta rápida o, con [v], editarlo o desactivarlo. */
@Composable
private fun FormVendedor(alGuardar: (Long) -> Unit, alCancelar: () -> Unit, v: JsonObject? = null) {
    val scope = rememberCoroutineScope()
    var ruc by remember { mutableStateOf(if (v?.txt("tipo_documento") == "DNI") 0 else 1) } // 0 = persona (DNI), 1 = empresa (RUC)
    var doc by remember { mutableStateOf(v?.txt("documento") ?: "") }
    var nombre by remember { mutableStateOf(v?.txt("nombre") ?: "") }
    var telefono by remember { mutableStateOf(v?.txt("telefono") ?: "") }
    var activo by remember { mutableStateOf(v == null || v.txt("activo") == "true") }
    var error by remember { mutableStateOf<String?>(null) }
    var enviando by remember { mutableStateOf(false) }
    val largo = if (ruc == 1) 11 else 8

    Tarjeta(borde = Altiplano.VerdeBorde) {
        Text(if (v == null) "Nuevo vendedor" else "Editar vendedor", style = Texto.Fila, color = Altiplano.Texto)
        Spacer(Modifier.height(8.dp))
        Segmentos(listOf("Persona (DNI)", "Empresa (RUC)"), ruc) { ruc = it; doc = doc.take(if (it == 1) 11 else 8) }
        Spacer(Modifier.height(8.dp))
        CampoTexto(doc, { doc = it.filter(Char::isDigit).take(largo) }, if (ruc == 1) "RUC (11 dígitos)" else "DNI (8 dígitos)", KeyboardType.Number)
        CampoTexto(nombre, { nombre = it.take(120) }, if (ruc == 1) "Razón social" else "Nombre y apellidos")
        CampoTexto(telefono, { telefono = it.filter { ch -> ch.isDigit() || ch == ' ' || ch == '+' }.take(20) }, "Teléfono (opcional)", KeyboardType.Phone)
        if (v != null) Row(verticalAlignment = Alignment.CenterVertically) {
            Text(if (activo) "Activo: aparece para comprarle" else "Desactivado: ya no aparece en las órdenes",
                style = Texto.Cuerpo, color = Altiplano.TextoSecundario, modifier = Modifier.weight(1f))
            Switch(activo, { activo = it }, colors = SwitchDefaults.colors(checkedTrackColor = Altiplano.VerdeMarca))
        }
        error?.let { AvisoCampo(it, esAlerta = true) }
        Row(horizontalArrangement = Arrangement.End, modifier = Modifier.fillMaxWidth()) {
            TextButton(onClick = alCancelar) { Text("Cancelar", color = Altiplano.TextoSecundario) }
            TextButton(enabled = !enviando, onClick = {
                error = when {
                    doc.length != largo -> if (ruc == 1) "El RUC debe tener 11 dígitos" else "El DNI debe tener 8 dígitos"
                    nombre.trim().length < 3 -> "Escribe el nombre o la razón social"
                    else -> null
                }
                if (error == null) scope.launch {
                    enviando = true
                    try {
                        val r = Sesion.enviar("/api/v1/compras/vendedores" + (v?.let { "/${it.txt("id")}" } ?: ""), buildJsonObject {
                            put("tipo_documento", if (ruc == 1) "RUC" else "DNI"); put("documento", doc); put("nombre", nombre.trim())
                            if (telefono.isNotBlank()) put("telefono", telefono.trim())
                            v?.txt("direccion")?.takeIf { it.isNotEmpty() }?.let { put("direccion", it) } // la dirección se edita en la web
                            if (v != null) put("activo", activo)
                        })
                        alGuardar(r.num("id").toLong())
                    } catch (t: Throwable) { error = t.message }
                    enviando = false
                }
            }) { Text(if (enviando) "Guardando…" else "Guardar", color = Altiplano.Exito) }
        }
    }
}

// --------------------------------------------------------------------- llegó

@Composable
private fun RecibirOrden(o: JsonObject, d: JsonObject) {
    val c = CompraInsumos
    val scope = rememberCoroutineScope()
    val items = o.lista("items")
    var tipo by remember { mutableStateOf<Int?>(null) }
    var serie by remember { mutableStateOf("") }
    var numero by remember { mutableStateOf("") }
    var fecha by remember { mutableStateOf(hoyLima()) }
    val pagos = remember { mutableStateMapOf<String, String>().apply { items.forEach { put(it.txt("id"), simple(it.num("costo_estimado"))) } } }
    var vendedorId by remember { mutableStateOf(o.obj("vendedor")?.num("id")?.toLong()) }
    var foto by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var enviando by remember { mutableStateOf(false) }
    val elegir = rememberElegirFoto { f -> if (f != null) foto = f }

    val vendedor = d.lista("vendedores").find { it.num("id").toLong() == vendedorId } ?: o.obj("vendedor")
    val total = pagos.values.sumOf { it.replace(",", ".").toDoubleOrNull() ?: 0.0 }
    val clave = tipo?.let { TIPOS[it].first }

    PantallaScroll {
        Tarjeta(borde = Altiplano.VerdeBorde) {
            Text("Llegó ${o.txt("codigo")}", style = Texto.Fila, color = Altiplano.Texto)
            Text("${o.txt("proveedor")} · ${resumen(o)}", style = Texto.Apoyo, color = Altiplano.TextoSecundario)
        }
        if (o.obj("vendedor") == null) {
            Seccion("Vendedor (esta orden antigua no lo tenía)")
            d.lista("vendedores").forEach { v ->
                val id = v.num("id").toLong()
                Opcion(v.txt("nombre"), "${v.txt("tipo_documento")} ${v.txt("documento")}", vendedorId == id) { vendedorId = if (vendedorId == id) null else id }
            }
        }
        Seccion("¿Qué comprobante te dieron?")
        Segmentos(TIPOS.map { it.second }, tipo ?: -1) { tipo = it }
        if (clave == "FACTURA" && vendedor?.txt("tipo_documento") != "RUC") AvisoCampo("Solo hay factura si el vendedor tiene RUC. Si te dio boleta, elige boleta.", esAlerta = true)
        if (clave != null && clave != "RECIBO") Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Box(Modifier.weight(1f)) { CampoTexto(serie, { serie = it.uppercase().filter(Char::isLetterOrDigit).take(4) }, if (clave == "FACTURA") "Serie (F001)" else "Serie (B001)") }
            Box(Modifier.weight(1.4f)) { CampoTexto(numero, { numero = it.filter(Char::isDigit).take(8) }, "Número", KeyboardType.Number) }
        }
        CampoFecha(fecha, { fecha = it }, "Fecha del comprobante")

        Seccion("¿Cuánto se pagó? (con IGV)")
        Tarjeta {
            items.forEach { it ->
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("${formatear(it.num("cantidad"), 2)} ${it.txt("unidad")} ${it.txt("insumo")}", style = Texto.Cuerpo, color = Altiplano.Texto, modifier = Modifier.weight(1f))
                    Box(Modifier.width(120.dp)) {
                        CampoTexto(pagos[it.txt("id")] ?: "", { v -> pagos[it.txt("id")] = v.filter { ch -> ch.isDigit() || ch == '.' || ch == ',' }.take(9) }, "S/", KeyboardType.Decimal)
                    }
                }
            }
            HorizontalDivider(Modifier.padding(vertical = 8.dp), color = Altiplano.BordeSuave)
            if (clave == "FACTURA") {
                val sub = kotlin.math.round(total / 1.18 * 100) / 100
                FilaValor("Subtotal (sin IGV)", soles(sub))
                FilaValor("IGV 18 %", soles(total - sub))
            }
            FilaValor("Total", soles(total))
        }

        Seccion("Foto del comprobante (opcional)")
        if (foto == null) Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedButton(onClick = elegir.camara, modifier = Modifier.weight(1f)) { Text("Sacar foto", color = Altiplano.Texto) }
            OutlinedButton(onClick = elegir.galeria, modifier = Modifier.weight(1f)) { Text("De la galería", color = Altiplano.Texto) }
        } else Row(verticalAlignment = Alignment.CenterVertically) {
            Text("✓ Foto lista para guardar", style = Texto.Cuerpo, color = Altiplano.Exito, modifier = Modifier.weight(1f))
            TextButton(onClick = { foto = null }) { Text("Quitar", color = Altiplano.TextoSecundario) }
        }

        error?.let { AvisoCampo(it, esAlerta = true) }
        BotonPrimario(if (enviando) "Guardando…" else "Registrar y subir stock", !enviando && tipo != null) {
            error = when {
                clave == "FACTURA" && vendedor?.txt("tipo_documento") != "RUC" -> "Solo hay factura si el vendedor tiene RUC."
                clave != "RECIBO" && serie.isBlank() -> "Escribe la serie del comprobante"
                clave != "RECIBO" && numero.isBlank() -> "Escribe el número del comprobante"
                !Regex("""\d{4}-\d{2}-\d{2}""").matches(fecha) -> "Escribe la fecha así: ${hoyLima()}"
                pagos.values.any { it.replace(",", ".").toDoubleOrNull() == null } -> "Escribe cuánto se pagó por cada insumo"
                else -> null
            }
            if (error == null) scope.launch {
                enviando = true
                try {
                    val r = Sesion.enviar("/api/v1/compras/ordenes/${o.txt("id")}/recibir", buildJsonObject {
                        put("comprobante_tipo", clave)
                        if (clave != "RECIBO") { put("comprobante_serie", serie); put("comprobante_numero", numero) }
                        put("comprobante_fecha", fecha)
                        vendedorId?.let { put("vendedor_id", it) }
                        foto?.let { put("foto", it) }
                        putJsonArray("items") {
                            items.forEach { it -> addJsonObject { put("id", it.num("id").toLong()); put("costo_real", pagos[it.txt("id")]!!.replace(",", ".").toDouble()) } }
                        }
                    })
                    c.mensaje = "✓ ${r.txt("codigo")} recibida con ${r.txt("comprobante")} por ${soles(r.num("total"))}: el stock ya subió"
                    c.recibiendo = null; c.version++; ComprasReal.version++
                } catch (t: Throwable) { error = t.message }
                enviando = false
            }
        }
        TextButton(onClick = { c.recibiendo = null }, modifier = Modifier.fillMaxWidth()) { Text("Volver", color = Altiplano.TextoSecundario) }
    }
}

// ------------------------------------------------------------------ recibidas

/** Recibidas mes por mes con flechitas (o todas), con lo gastado; tocar una con foto la abre. */
@Composable
private fun Recibidas() {
    val c = CompraInsumos
    val uri = LocalUriHandler.current
    val scope = rememberCoroutineScope()
    var paraFoto by remember { mutableStateOf<JsonObject?>(null) }
    val elegir = rememberElegirFoto { f ->
        val o = paraFoto ?: return@rememberElegirFoto
        if (f != null) scope.launch {
            try {
                Sesion.enviar("/api/v1/compras/ordenes/${o.txt("id")}/foto", buildJsonObject { put("foto", f) })
                c.mensaje = "✓ Foto del comprobante de ${o.txt("codigo")} guardada"; c.version++
            } catch (t: Throwable) { c.mensaje = "No se pudo guardar la foto: ${t.message}" }
        }
    }
    fun verFoto(o: JsonObject) = scope.launch {
        try { uri.openUri(ConfigServidor.baseUrl + Sesion.consultar("/api/v1/compras/ordenes/${o.txt("id")}/foto").txt("url")) }
        catch (t: Throwable) { c.mensaje = "No se pudo abrir la foto: ${t.message}" }
    }

    CargaServidor("/api/v1/compras/ordenes?mes=${c.mes ?: "actual"}", clave = c.version + ComprasReal.version * 1000) { d, _ ->
        val per = d.obj("periodo")
        val todo = per?.txt("mes") == "todo"
        val recibidas = d.lista("ordenes").filter { it.txt("estado") == "RECIBIDA" }
        PantallaScroll {
            c.mensaje?.let { AvisoCampo(it, esAlerta = it.startsWith("No se")) }
            if (todo) Tarjeta { Text("Todas las compras", style = Texto.Fila, color = Altiplano.Texto) }
            else Flechitas(
                nombreMes(per?.txt("mes") ?: "").replaceFirstChar { it.uppercase() }, null,
                onAnterior = per?.txt("anterior")?.takeIf { it.isNotEmpty() }?.let { { c.mes = it } },
                onSiguiente = per?.txt("siguiente")?.takeIf { it.isNotEmpty() }?.let { { c.mes = it } },
            )
            TextButton(onClick = { c.mes = if (todo) null else "todo" }) {
                Text(if (todo) "Ver mes por mes" else "Ver todo", color = Altiplano.CelesteAcento)
            }
            val n = per?.num("compras")?.toInt() ?: recibidas.size
            TarjetaDato("GASTADO EN INSUMOS", soles(per?.num("gastado") ?: 0.0), apoyo = "$n ${if (n == 1) "compra recibida" else "compras recibidas"}", destacada = true)
            if (recibidas.isEmpty()) AvisoCampo(if (todo) "Todavía no llegó ninguna compra." else "No llegó ninguna compra en este mes.")
            recibidas.forEach { o ->
                val conFoto = o.txt("tiene_foto") == "true"
                Tarjeta(onClick = if (conFoto) ({ verFoto(o) }) else null) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text("${o.txt("codigo")} · ${o.txt("proveedor")}", style = Texto.Fila, color = Altiplano.Texto)
                            Text(resumen(o), style = Texto.Apoyo, color = Altiplano.TextoSecundario)
                            Text(
                                listOfNotNull(o.txt("comprobante").ifBlank { null }, o.txt("comprobante_fecha").ifBlank { null }?.let { fechaCorta(it) },
                                    soles(o.num("total"))).joinToString(" · ") + if (o.txt("igv").isNotBlank()) " (IGV ${soles(o.num("igv"))})" else "",
                                style = Texto.Apoyo, color = Altiplano.TextoTerciario,
                            )
                        }
                        if (conFoto) Text("Ver foto", style = Texto.Apoyo, color = Altiplano.Exito)
                        else TextButton(onClick = { paraFoto = o; elegir.camara() }) { Text("+ Foto", color = Altiplano.Exito) }
                    }
                }
            }
        }
    }
}

// ----------------------------------------------------------------- vendedores

/** Nombre o DNI/RUC contiene lo buscado. */
private fun coincide(v: JsonObject, buscado: String): Boolean {
    val q = buscado.trim().lowercase()
    return q.isEmpty() || v.txt("nombre").lowercase().contains(q) || v.txt("documento").contains(q)
}

/** A quién le compramos: buscar, ver cuánto se le compró, editar o desactivar. */
@Composable
private fun Vendedores() {
    val c = CompraInsumos
    CargaServidor("/api/v1/compras/vendedores", clave = c.version) { d, _ ->
        val todos = d.lista("vendedores")
        val lista = todos.filter { coincide(it, c.buscarVendedor) }
        PantallaScroll {
            c.mensaje?.let { AvisoCampo(it, esAlerta = it.startsWith("No se")) }
            // key: al tocar otro vendedor el formulario se llena de nuevo
            if (c.nuevoVendedor || c.editandoVendedor != null) key(c.editandoVendedor?.txt("id")) {
                FormVendedor(
                    alGuardar = { _ ->
                        c.mensaje = if (c.editandoVendedor != null) "✓ Vendedor actualizado" else "✓ Vendedor agregado"
                        c.nuevoVendedor = false; c.editandoVendedor = null; c.version++
                    },
                    alCancelar = { c.nuevoVendedor = false; c.editandoVendedor = null },
                    v = c.editandoVendedor,
                )
            } else {
                TextButton(onClick = { c.mensaje = null; c.nuevoVendedor = true }) { Text("+ Nuevo vendedor (persona o empresa)", color = Altiplano.Exito) }
            }
            if (todos.size > 5) Campo(c.buscarVendedor, { c.buscarVendedor = it.take(40) }, "Buscar por nombre o DNI/RUC")
            if (todos.isEmpty()) AvisoCampo("Todavía no hay vendedores.")
            else if (lista.isEmpty()) AvisoCampo("Nadie coincide con «${c.buscarVendedor.trim()}».")
            Text("Toca uno para editarlo o desactivarlo.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            lista.forEach { v ->
                val activo = v.txt("activo") == "true"
                val compras = v.num("compras").toInt()
                FilaLista(
                    v.txt("nombre") + if (activo) "" else " (desactivado)",
                    "${v.txt("tipo_documento")} ${v.txt("documento")}" + (v.txt("telefono").ifEmpty { null }?.let { " · $it" } ?: "") +
                        (v.txt("ultima").ifEmpty { null }?.let { " · última ${fechaCorta(it)}" } ?: ""),
                    onClick = { c.mensaje = null; c.nuevoVendedor = false; c.editandoVendedor = v },
                    borde = if (activo) Altiplano.Borde else Altiplano.BordeSuave,
                ) {
                    Column(horizontalAlignment = Alignment.End) {
                        Text(soles(v.num("pagado")), style = Texto.Fila, color = if (activo) Altiplano.Texto else Altiplano.TextoTerciario)
                        Text("$compras ${if (compras == 1) "compra" else "compras"}", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                    }
                }
            }
        }
    }
}

/** Fecha que se elige en un calendario (no se escribe); no deja elegir días que todavía no llegan. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun CampoFecha(valor: String, onCambio: (String) -> Unit, etiqueta: String) {
    var abierto by remember { mutableStateOf(false) }
    Tarjeta(onClick = { abierto = true }) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(etiqueta, style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                Text(fechaCorta(valor) + " " + valor.take(4) + if (valor == hoyLima()) " (hoy)" else "", style = Texto.Fila, color = Altiplano.Texto)
            }
            Text("Cambiar", style = Texto.Apoyo, color = Altiplano.CelesteAcento)
        }
    }
    if (!abierto) return
    // el calendario trabaja en milisegundos UTC a medianoche
    val hoy = Instant.parse("${hoyLima()}T00:00:00Z").toEpochMilliseconds()
    val estado = rememberDatePickerState(
        initialSelectedDateMillis = Instant.parse("${valor}T00:00:00Z").toEpochMilliseconds(),
        selectableDates = object : SelectableDates {
            override fun isSelectableDate(utcTimeMillis: Long) = utcTimeMillis <= hoy
        },
    )
    DatePickerDialog(
        onDismissRequest = { abierto = false },
        confirmButton = {
            TextButton(onClick = {
                estado.selectedDateMillis?.let { onCambio(Instant.fromEpochMilliseconds(it).toString().take(10)) }
                abierto = false
            }) { Text("Listo") }
        },
        dismissButton = { TextButton(onClick = { abierto = false }) { Text("Cancelar") } },
    ) { DatePicker(estado, showModeToggle = false) }
}

@Composable
private fun CampoTexto(valor: String, onCambio: (String) -> Unit, etiqueta: String, teclado: KeyboardType = KeyboardType.Text) {
    OutlinedTextField(
        value = valor, onValueChange = onCambio, label = { Text(etiqueta) }, singleLine = true,
        keyboardOptions = KeyboardOptions(keyboardType = teclado),
        colors = OutlinedTextFieldDefaults.colors(
            focusedTextColor = Altiplano.Texto, unfocusedTextColor = Altiplano.Texto,
            focusedBorderColor = Altiplano.VerdeMarca, unfocusedBorderColor = Altiplano.Borde,
            focusedLabelColor = Altiplano.TextoSecundario, unfocusedLabelColor = Altiplano.TextoTerciario,
            cursorColor = Altiplano.Exito,
        ),
        modifier = Modifier.fillMaxWidth(),
    )
}
