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
import pe.gob.huata.ecolacteos.datos.Rol
import pe.gob.huata.ecolacteos.datos.Sesion
import pe.gob.huata.ecolacteos.destinosDe
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

/** Estado de Compras con datos reales. */
object ComprasReal {
    val cantidades = mutableStateMapOf<Long, Double>() // venta: producto → cuánto lleva (0 = no lleva); varios van en una nota
    var clienteId by mutableStateOf<Long?>(null) // por id: al agregar un cliente la lista se reordena
    var buscarCliente by mutableStateOf("")
    var verInactivos by mutableStateOf(false)
    var editandoCliente by mutableStateOf<JsonObject?>(null) // el que se edita; vacío = nuevo
    var mesVentas by mutableStateOf<String?>(null) // null = este mes · "todo"
    var mensaje by mutableStateOf<String?>(null)
    var version by mutableStateOf(0)
    var pestanaVentas by mutableStateOf(0)  // Nueva venta · Ventas y notas
    // nueva venta paso a paso, como en la web: 1 cliente · 2 productos · 3 confirmar
    var pasoVenta by mutableStateOf(1)
    var familia by mutableStateOf<Long?>(null)  // base elegida (Yogurt natural…); sus sabores se eligen debajo
    var producto by mutableStateOf<Long?>(null) // el que se va a agregar
    var cuantos by mutableStateOf(1.0)
    var pestanaAlmacen by mutableStateOf(0) // Stock y avisos · Pérdidas
}

private val NOMBRE_CANAL = mapOf("MAYORISTA" to "Mayorista", "DIRECTO" to "Cliente directo", "PLANTA" to "Proveedor de leche")

/**
 * Compras con la base, como en la web de oficina y en el orden del día de la planta:
 * Compras de insumos · Almacén (stock y pérdidas) · Fórmulas · Ventas (nueva y
 * mes por mes con su nota) · Kardex.
 * [irA] cambia de opción del menú; desde Almacén, tocar algo abre su kardex.
 */
@Composable
fun ContenidoComprasReal(ruta: String, irA: (Int) -> Unit) {
    val c = ComprasReal
    when (ruta) {
        "compras/formulas" -> FormulasReal(puedeAceptar = false)
        "compras/ventas" -> ConPestanas(listOf("Nueva venta", "Ventas y notas"), c.pestanaVentas, { c.pestanaVentas = it }) {
            if (c.pestanaVentas == 0) VentaReal { c.pestanaVentas = 1 } else VentasDelMes()
        }
        "compras/almacen" -> ConPestanas(listOf("Stock y avisos", "Pérdidas"), c.pestanaAlmacen, { c.pestanaAlmacen = it }) {
            if (c.pestanaAlmacen == 0) InventarioReal { irA(destinosDe(Rol.COMPRAS).indexOfFirst { it.ruta == "compras/kardex" }) }
            else PerdidasReal("compras")
        }
        "compras/ventas-mes" -> VentasDelMes()
        "compras/kardex" -> KardexCompleto()
        "compras/ventas-nueva" -> VentaReal { irA(0) }
        "compras/inventario" -> InventarioReal { irA(0) }
        "compras/orden" -> ComprasInsumosReal()
        else -> MovimientosReal()
    }
}

/**
 * Almacén: lo que hay y lo que se pierde. Tocar uno abre su kardex ([alKardex]);
 * "Corregir" anota lo que se malogró, se venció o no cuadró al contar. Comprar
 * se hace solo en Compras.
 */
@Composable
private fun InventarioReal(alKardex: () -> Unit) {
    var corregir by remember { mutableStateOf<JsonObject?>(null) }
    CargaServidor("/api/v1/compras/inventario", clave = ComprasReal.version + Perdidas.version * 1000) { d, _ ->
        PantallaScroll {
            Perdidas.mensaje?.let { AvisoCampo(it) }
            // "falta azúcar para el yogurt", "cuajo bajo el mínimo"…
            d.lista("avisos").forEach { a -> AvisoCampo(a.txt("texto"), esAlerta = a.txt("nivel") == "FALTA") }
            if (d.lista("avisos").isNotEmpty()) Text("Lo que falta se compra en Compras.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            Seccion("Insumos · toca uno para ver su kardex")
            d.lista("insumos").forEach { i ->
                val bajo = i.num("stock") < i.num("minimo")
                Tarjeta(borde = if (bajo) Altiplano.AlertaBorde else Altiplano.Borde, onClick = {
                    KardexElegido.tipo = "insumo"; KardexElegido.id = i.num("id").toLong(); alKardex()
                }) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(i.txt("nombre"), style = Texto.Fila, color = Altiplano.Texto, modifier = Modifier.weight(1f))
                        Text("${formatear(i.num("stock"), 2)} ${i.txt("unidad")}", style = Texto.Fila, color = if (bajo) Altiplano.Alerta else Altiplano.Texto)
                        TextButton(onClick = { Perdidas.mensaje = null; corregir = i }) { Text("Corregir", color = Altiplano.CelesteAcento) }
                    }
                    Spacer(Modifier.height(9.dp))
                    BarraNivel((i.num("stock") / (i.num("minimo") * 2).coerceAtLeast(0.001)).toFloat(), if (bajo) Altiplano.Alerta else Altiplano.Exito)
                    Spacer(Modifier.height(6.dp))
                    Text(
                        "mínimo ${formatear(i.num("minimo"), 2)} ${i.txt("unidad")} · ${soles(i.num("costo"))} c/u" + if (bajo) " · bajo el mínimo" else "",
                        style = Texto.Apoyo, color = if (bajo) Altiplano.Alerta else Altiplano.TextoTerciario,
                    )
                }
            }
            Seccion("Producto terminado · toca uno para ver su kardex")
            d.lista("productos").forEach { p ->
                FilaLista(p.txt("nombre"), p.txt("unidad"), onClick = {
                    KardexElegido.tipo = "producto"; KardexElegido.id = p.num("id").toLong(); alKardex()
                }) {
                    Text(formatear(p.num("stock"), 0), style = Texto.Fila, color = Altiplano.Texto)
                }
            }
            Text("El queso, yogurt o leche que se pierde se anota en la pestaña Pérdidas.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            corregir?.let { HojaAjuste(it) { corregir = null } }
        }
    }
}

@Composable
private fun VentaReal(irA: (Int) -> Unit) {
    val scope = rememberCoroutineScope()
    var enviando by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val v = ComprasReal

    CargaServidor("/api/v1/compras/ventas-datos", clave = v.version) { d, _ ->
        val prods = d.lista("productos")
        val clientes = d.lista("clientes")
        val inactivos = d.lista("inactivos")
        if (prods.isEmpty()) return@CargaServidor
        val c = clientes.find { it.num("id").toLong() == v.clienteId }
        // lo que lleva: (producto, cantidad, precio según el tipo de cliente)
        val lineas = prods.mapNotNull { p ->
            val cant = v.cantidades[p.num("id").toLong()] ?: 0.0
            if (cant <= 0) null else Triple(p, cant, c?.let { p.obj("precios")?.num(it.txt("tipo")) } ?: 0.0)
        }
        val total = lineas.sumOf { (_, cant, precio) -> cant * precio }
        val sinStock = lineas.filter { (p, cant, _) -> cant > p.num("stock") }
        val sinPrecio = if (c == null) emptyList() else lineas.filter { it.third <= 0 }
        // sin cliente no se pasa a productos; sin productos no se pasa a confirmar
        val paso = when {
            c == null -> 1
            v.pasoVenta == 3 && lineas.isEmpty() -> 2
            else -> v.pasoVenta
        }

        PantallaScroll {
            v.mensaje?.takeIf { it.startsWith("✓ Cliente") }?.let { AvisoCampo(it) }
            Segmentos(listOf("1 Cliente", "2 Productos", "3 Confirmar"), paso - 1) { i ->
                if (i == 0 || c != null && (i == 1 || lineas.isNotEmpty())) v.pasoVenta = i + 1
            }
            when (paso) {
                1 -> PasoCliente(clientes, inactivos, c)
                2 -> PasoProductos(prods, c!!, lineas, total)
                else -> {
                    Seccion("Revisa la nota")
                    TarjetaDato("TOTAL", soles(total), apoyo = c!!.txt("nombre"), destacada = true) {
                        lineas.forEach { (p, cant, precio) ->
                            Spacer(Modifier.height(6.dp))
                            FilaValor("${formatear(cant, 0)} × ${p.txt("nombre")} a ${soles(precio)}", soles(cant * precio))
                            Text(textoLotes(p, cant), style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                        }
                    }
                    sinStock.forEach { (p, _, _) -> AvisoCampo("Solo hay ${formatear(p.num("stock"), 0)} de ${p.txt("nombre")} en stock.", esAlerta = true) }
                    sinPrecio.forEach { (p, _, _) -> AvisoCampo("${p.txt("nombre")} no tiene precio para este tipo de cliente: ponlo en Ajustes → Precios.", esAlerta = true) }
                    CampoCorreoVenta(c)
                    error?.let { AvisoCampo("No se pudo registrar: $it", esAlerta = true) }
                }
            }
            if (paso == 3) BotonPrimario(
                when {
                    enviando -> "Registrando…"
                    lineas.size > 1 -> "✓ Registrar venta (${lineas.size} productos, una nota)"; else -> "✓ Registrar venta"
                },
                c != null && lineas.isNotEmpty() && sinStock.isEmpty() && sinPrecio.isEmpty() && !enviando,
            ) {
                val cli = c ?: return@BotonPrimario
                scope.launch {
                    enviando = true; error = null
                    try {
                        val r = Sesion.enviar("/api/v1/compras/ventas", buildJsonObject {
                            put("cliente_id", cli.num("id").toLong())
                            putJsonArray("items") {
                                lineas.forEach { (p, cant, _) -> addJsonObject { put("producto_id", p.num("id").toLong()); put("cantidad", cant) } }
                            }
                            correoParaVenta()?.let { put("correo", it) } // opcional: mandar la nota
                        })
                        v.cantidades.clear()
                        v.pasoVenta = 1; v.familia = null; v.producto = null
                        v.mensaje = "✓ Venta registrada: ${soles(r.num("total"))} a ${cli.txt("nombre")}" + textoCorreo(r)
                        NotaVenta.ultima = UltimaNota(r.num("id").toLong(), r.txt("numero"), r.num("total"), cli.txt("nombre"), r.txt("correo"))
                        v.mesVentas = null // la venta recién hecha está en este mes
                        v.version++
                        irA(3)
                    } catch (t: Throwable) { error = t.message }
                    enviando = false
                }
            }
            if (paso == 3) TextButton(onClick = { v.pasoVenta = 2 }) { Text("← Productos", color = Altiplano.CelesteAcento) }
        }
        v.editandoCliente?.let { HojaCliente(it) }
    }
}

/** Paso 1: buscador + los últimos clientes a los que se vendió. Al tocar uno pasa solo a productos. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun PasoCliente(clientes: List<JsonObject>, inactivos: List<JsonObject>, c: JsonObject?) {
    val v = ComprasReal
    val q = v.buscarCliente.trim().lowercase()
    val elegir = { x: JsonObject -> v.clienteId = x.num("id").toLong(); v.buscarCliente = ""; v.pasoVenta = 2 }
    Row(verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.weight(1f)) { Seccion("¿A quién le vendes?") }
        TextButton(onClick = { v.editandoCliente = JsonObject(emptyMap()) }) { Text("+ Nuevo cliente", color = Altiplano.Exito) }
    }
    Campo(v.buscarCliente, { v.buscarCliente = it.take(40) }, "Buscar por nombre, DNI/RUC o teléfono")
    if (q.isEmpty()) {
        val ultimos = clientes.filter { it.txt("ultima_venta").isNotEmpty() }.sortedByDescending { it.txt("ultima_venta") }.take(6)
        if (ultimos.isNotEmpty()) {
            Text("Últimos clientes", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                ultimos.forEach { x -> Chip(x.txt("nombre"), c?.num("id") == x.num("id")) { elegir(x) } }
            }
        } else if (clientes.isEmpty()) AvisoCampo("Todavía no hay clientes: agrega el primero.")
    } else {
        // al buscar también salen los desactivados: tocarlo lo abre para reactivarlo
        val visibles = (clientes + inactivos).filter {
            it.txt("nombre").lowercase().contains(q) || it.txt("ruc").contains(q) || it.txt("telefono").contains(q)
        }.take(8)
        if (visibles.isEmpty()) AvisoCampo("Nadie coincide con «${v.buscarCliente.trim()}». Agrégalo con + Nuevo cliente.")
        visibles.forEach { x ->
            val activo = x.txt("activo") != "false" && x.txt("activo") != "0"
            Opcion(
                x.txt("nombre") + if (activo) "" else " (desactivado)",
                listOfNotNull(NOMBRE_CANAL[x.txt("tipo")], x.txt("ruc").ifEmpty { null }, x.txt("telefono").ifEmpty { null }).joinToString(" · "),
                c?.num("id") == x.num("id"),
            ) { if (activo) elegir(x) else v.editandoCliente = x }
        }
    }
    if (c != null) {
        // el de "Proveedor de leche" es del sistema y no se edita
        if (c.txt("tipo") != "PLANTA") TextButton(onClick = { v.editandoCliente = c }) { Text("Editar a ${c.txt("nombre")}", color = Altiplano.CelesteAcento) }
        BotonPrimario("Seguir con ${c.txt("nombre")} →") { v.pasoVenta = 2 }
    }
}

/**
 * Paso 2, como en la web: los productos separados en Quesos / Yogurt; si uno tiene sabores
 * (Yogurt natural → fresa, maracuyá…) se eligen debajo. Luego cuántos y "Agregar".
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun PasoProductos(prods: List<JsonObject>, c: JsonObject, lineas: List<Triple<JsonObject, Double, Double>>, total: Double) {
    val v = ComprasReal
    val id = { p: JsonObject -> p.num("id").toLong() }
    val precio = { p: JsonObject -> p.obj("precios")?.num(c.txt("tipo")) ?: 0.0 }
    val bases = prods.filter { p -> p.txt("base_id").isEmpty() || prods.none { it.txt("id") == p.txt("base_id") } }
    // base → sus sabores con stock (la base primero)
    val familias = bases.map { b -> b to (listOf(b) + prods.filter { it.txt("base_id") == b.txt("id") }).filter { it.num("stock") > 0 } }
        .filter { it.second.isNotEmpty() }
    val elegir = { p: JsonObject -> v.producto = id(p); v.cuantos = 1.0 }

    Seccion("¿Qué lleva ${c.txt("nombre")}?")
    if (familias.isEmpty()) AvisoCampo("No hay productos en stock. Primero cierra un lote en Producir.")
    listOf("QUESO" to "🧀 Quesos", "YOGURT" to "🥛 Yogurt", "OTRO" to "Otros").forEach { (tipo, nombre) ->
        val delTipo = familias.filter { (b, _) -> b.txt("tipo").ifEmpty { "OTRO" } == tipo }
        if (delTipo.isNotEmpty()) {
            Text(nombre, style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                delTipo.forEach { (b, sabores) ->
                    val etiqueta = (if (sabores.size > 1) b.txt("nombre").replace(Regex("\\s+natural$", RegexOption.IGNORE_CASE), "") + " · ${sabores.size} sabores"
                        else b.txt("nombre")) + if (sabores.any(::vencePronto)) " ⏳" else ""
                    Chip(etiqueta, v.familia == id(b)) {
                        v.familia = id(b)
                        if (sabores.size == 1) elegir(sabores[0]) else v.producto = null
                    }
                }
            }
        }
    }
    familias.find { id(it.first) == v.familia }?.takeIf { it.second.size > 1 }?.let { (b, sabores) ->
        Tarjeta {
            Text("¿De qué sabor? · ${b.txt("nombre")}", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            Spacer(Modifier.height(8.dp))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                sabores.forEach { p ->
                    val nombre = if (p.txt("base_id").isEmpty()) "Natural / sin sabor" else p.txt("variante").ifEmpty { p.txt("nombre") }
                    Chip("$nombre (${formatear(p.num("stock"), 0)})" + if (vencePronto(p)) " ⏳" else "", v.producto == id(p)) { elegir(p) }
                }
            }
        }
    }
    prods.find { id(it) == v.producto }?.let { p ->
        val ya = v.cantidades[id(p)] ?: 0.0
        val pasa = ya + v.cuantos > p.num("stock")
        Tarjeta(borde = Altiplano.VerdeBorde) {
            Text(p.txt("nombre"), style = Texto.Fila, color = Altiplano.Texto)
            Text("${soles(precio(p))} c/u · hay ${formatear(p.num("stock"), 0)} ${p.txt("unidad")}" + if (ya > 0) " · ya lleva ${formatear(ya, 0)}" else "",
                style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            // sale primero lo que vence primero
            Text(textoLotes(p, ya + v.cuantos), style = Texto.Apoyo, color = if (vencePronto(p)) Altiplano.Aviso else Altiplano.TextoTerciario)
            if (p.num("vencido") > 0) AvisoCampo("${formatear(p.num("vencido"), 0)} ${p.txt("unidad")} ya vencieron: no se venden, regístralos en Almacén → Pérdidas.")
            Spacer(Modifier.height(8.dp))
            StepperGrande(v.cuantos, { v.cuantos = (it ?: 1.0).coerceAtLeast(1.0) }, paso = 1.0, minimo = 1.0, decimales = 0,
                unidad = p.txt("unidad"), fueraDeRango = pasa, compacto = true)
            if (pasa) AvisoCampo("Solo hay ${formatear(p.num("stock"), 0)} de ${p.txt("nombre")}.", esAlerta = true)
            Spacer(Modifier.height(8.dp))
            // si agrega fresa dos veces, se junta
            BotonPrimario("Agregar ${formatear(v.cuantos, 0)} × ${p.txt("nombre")}", !pasa) {
                v.cantidades[id(p)] = ya + v.cuantos; v.producto = null; v.familia = null; v.cuantos = 1.0
            }
        }
    }

    Seccion("Lleva")
    if (lineas.isEmpty()) Text("Todavía no lleva nada.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
    lineas.forEach { (p, cant, pr) ->
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text("${formatear(cant, 0)} × ${p.txt("nombre")}", style = Texto.Cuerpo, color = Altiplano.Texto)
                Text(textoLotes(p, cant), style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            }
            Text(soles(cant * pr), style = Texto.Fila, color = Altiplano.Texto)
            TextButton(onClick = { v.cantidades.remove(id(p)) }) { Text("✕", color = Altiplano.Alerta) }
        }
    }
    if (lineas.isNotEmpty()) {
        FilaValor("Total", soles(total))
        BotonPrimario("Revisar →") { v.pasoVenta = 3 }
    }
    TextButton(onClick = { v.pasoVenta = 1 }) { Text("← Cliente", color = Altiplano.CelesteAcento) }
}

/** De qué lotes sale [cant] de p: primero el que vence primero, igual que el servidor. */
private fun textoLotes(p: JsonObject, cant: Double): String {
    var falta = cant
    val usa = p.lista("lotes").mapNotNull { l ->
        if (falta <= 0.005) null else minOf(falta, l.num("queda")).let { u -> falta -= u; l to u }
    }
    return usa.joinToString(" · ") { (l, u) ->
        val dias = l.num("dias").toInt()
        (if (usa.size > 1) "${formatear(u, 0)} del " else "Sale del ") + "lote ${l.txt("codigo")}, vence el ${fechaCorta(l.txt("vence_el"))}" +
            when (dias) { 0 -> " (hoy)"; 1 -> " (mañana)"; else -> " (en $dias días)" }
    }
}

/** Tiene un lote que vence en 7 días o menos: hay que venderlo primero. */
private fun vencePronto(p: JsonObject) = p.lista("lotes").any { it.num("dias") <= 7 }

/** Agregar o editar a quién le vendemos; el DNI o RUC es opcional. Al agregar uno, queda elegido para la venta. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun HojaCliente(x: JsonObject) {
    val nuevo = x.isEmpty()
    val scope = rememberCoroutineScope()
    var nombre by remember { mutableStateOf(x.txt("nombre")) }
    var tipo by remember { mutableStateOf(x.txt("tipo").ifEmpty { "MAYORISTA" }) }
    var doc by remember { mutableStateOf(x.txt("ruc")) }
    var telefono by remember { mutableStateOf(x.txt("telefono")) }
    var email by remember { mutableStateOf(x.txt("email")) }
    var activo by remember { mutableStateOf(nuevo || (x.txt("activo") != "false" && x.txt("activo") != "0")) }
    var error by remember { mutableStateOf<String?>(null) }
    var enviando by remember { mutableStateOf(false) }
    val cerrar = { ComprasReal.editandoCliente = null }

    Hoja(if (nuevo) "Nuevo cliente" else "Editar cliente", cerrar) {
        Campo(nombre, { nombre = it.take(120) }, "Nombre del cliente o de su negocio", palabras = true)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Chip("Mayorista", tipo == "MAYORISTA") { tipo = "MAYORISTA" }
            Chip("Cliente directo", tipo == "DIRECTO") { tipo = "DIRECTO" }
        }
        Campo(doc, { doc = it.filter(Char::isDigit).take(11) }, "DNI o RUC (opcional)", teclado = KeyboardType.Number)
        Campo(telefono, { telefono = it.filter { ch -> ch.isDigit() || ch == ' ' || ch == '+' }.take(20) }, "Teléfono (opcional)", teclado = KeyboardType.Phone)
        Campo(email, { email = it.trim().take(120) }, "Correo para la nota (opcional)", teclado = KeyboardType.Email)
        if (!nuevo) Row(verticalAlignment = Alignment.CenterVertically) {
            Text(if (activo) "Activo: aparece para vender" else "Desactivado: ya no aparece para vender",
                style = Texto.Cuerpo, color = Altiplano.TextoSecundario, modifier = Modifier.weight(1f))
            Switch(activo, { activo = it }, colors = SwitchDefaults.colors(checkedTrackColor = Altiplano.VerdeMarca))
        }
        error?.let { AvisoCampo(it, esAlerta = true) }
        BotonPrimario(if (enviando) "Guardando…" else if (nuevo) "Agregar cliente" else "Guardar cambios", !enviando) {
            error = when {
                nombre.trim().length < 3 -> "Escribe el nombre del cliente o de su negocio"
                doc.isNotEmpty() && doc.length != 8 && !Regex("^(10|15|16|17|20)\\d{9}$").matches(doc) ->
                    "El DNI lleva 8 dígitos y el RUC 11 (empieza en 10, 15, 16, 17 o 20)"
                email.isNotEmpty() && !esCorreo(email) -> "Ese correo no es válido"
                else -> null
            }
            if (error == null) scope.launch {
                enviando = true
                try {
                    val r = Sesion.enviar("/api/v1/compras/clientes" + if (nuevo) "" else "/${x.txt("id")}", buildJsonObject {
                        put("nombre", nombre.trim()); put("tipo", tipo)
                        if (doc.isNotEmpty()) put("documento", doc)
                        if (telefono.isNotBlank()) put("telefono", telefono.trim())
                        if (email.isNotEmpty()) put("email", email)
                        if (!nuevo) put("activo", activo)
                    })
                    val id = r.num("id").toLong()
                    if (nuevo || activo) ComprasReal.clienteId = id
                    else if (ComprasReal.clienteId == id) ComprasReal.clienteId = null // desactivado: ya no se puede elegir
                    ComprasReal.mensaje = "✓ Cliente ${r.txt("nombre")} " + when {
                        nuevo -> "agregado y elegido para esta venta"
                        !activo -> "desactivado"
                        else -> "actualizado"
                    }
                    ComprasReal.buscarCliente = ""
                    ComprasReal.version++
                    cerrar()
                } catch (t: Throwable) { error = t.message }
                enviando = false
            }
        }
    }
}

/** Ventas mes por mes con flechitas (o todas), con el total vendido; tocar una abre su nota. */
@Composable
private fun VentasDelMes() {
    val v = ComprasReal
    val abrirNota = rememberAbrirNota()
    var mostrar by remember(v.mesVentas) { mutableStateOf(40) }
    CargaServidor("/api/v1/compras/movimientos?mes=${v.mesVentas ?: "actual"}", clave = v.version) { d, _ ->
        val per = d.obj("periodo")
        val todo = per?.txt("mes") == "todo"
        val ventas = d.lista("movimientos")
        PantallaScroll {
            v.mensaje?.takeIf { it.startsWith("✓ Venta") }?.let { AvisoCampo(it) }
            TarjetaUltimaNota()
            if (todo) Tarjeta { Text("Todas las ventas", style = Texto.Fila, color = Altiplano.Texto) }
            else Flechitas(
                nombreMes(per?.txt("mes") ?: "").replaceFirstChar { it.uppercase() }, null,
                onAnterior = per?.txt("anterior")?.takeIf { it.isNotEmpty() }?.let { { v.mesVentas = it } },
                onSiguiente = per?.txt("siguiente")?.takeIf { it.isNotEmpty() }?.let { { v.mesVentas = it } },
            )
            TextButton(onClick = { v.mesVentas = if (todo) null else "todo" }) {
                Text(if (todo) "Ver mes por mes" else "Ver todo", color = Altiplano.CelesteAcento)
            }
            val n = per?.num("ventas")?.toInt() ?: ventas.size
            TarjetaDato("VENDIDO", soles(per?.num("vendido") ?: 0.0), apoyo = "$n ${if (n == 1) "venta" else "ventas"}", destacada = true)
            if (ventas.isEmpty()) AvisoCampo(if (todo) "Todavía no hay ventas." else "No hubo ventas en este mes.")
            ventas.take(mostrar).forEach { m ->
                val venta = m.txt("venta_id").toLongOrNull()
                FilaLista(m.txt("que"), "${m.txt("ref")} · ${fechaCorta(m.txt("fecha"))} · ${m.txt("numero")} (tocar para ver)",
                    onClick = venta?.let { id -> { abrirNota(id) } }) {
                    Text(m.txt("delta"), style = Texto.Fila, color = Altiplano.TextoSecundario)
                }
            }
            if (ventas.size > mostrar) TextButton(onClick = { mostrar += 40 }) {
                Text("Ver más (${ventas.size - mostrar})", color = Altiplano.CelesteAcento)
            }
        }
    }
}

@Composable
private fun MovimientosReal() {
    val abrirNota = rememberAbrirNota()
    CargaServidor("/api/v1/compras/movimientos", clave = ComprasReal.version) { d, _ ->
        PantallaScroll {
            ComprasReal.mensaje?.let { AvisoCampo(it) }
            TarjetaUltimaNota()
            d.lista("movimientos").forEach { m ->
                // las ventas se tocan para ver/imprimir su nota
                val venta = m.txt("venta_id").toLongOrNull()
                FilaLista(m.txt("que"), "${m.txt("ref")} · ${fechaCorta(m.txt("fecha"))}" + (venta?.let { " · ${m.txt("numero")} (tocar para ver)" } ?: ""),
                    onClick = venta?.let { id -> { abrirNota(id) } }) {
                    Text(m.txt("delta"), style = Texto.Fila, color = if (m.txt("entrada") == "true") Altiplano.Exito else Altiplano.TextoSecundario)
                }
            }
        }
    }
}
