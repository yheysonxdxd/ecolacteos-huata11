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

/** Stock de insumos y productos; tocar uno abre su kardex ([alKardex]). */
@Composable
private fun InventarioReal(alKardex: () -> Unit) {
    CargaServidor("/api/v1/compras/inventario", clave = ComprasReal.version + Perdidas.version * 1000) { d, _ ->
        PantallaScroll {
            // "falta azúcar para el yogurt", "cuajo bajo el mínimo"…
            d.lista("avisos").forEach { a ->
                AvisoCampo(a.txt("texto") + " Comprar al menos ${formatear(a.num("comprar"), 2)} ${a.txt("unidad")}.", esAlerta = a.txt("nivel") == "FALTA")
            }
            Seccion("Insumos · toca uno para ver su kardex")
            d.lista("insumos").forEach { i ->
                val bajo = i.num("stock") < i.num("minimo")
                Tarjeta(borde = if (bajo) Altiplano.AlertaBorde else Altiplano.Borde, onClick = {
                    KardexElegido.tipo = "insumo"; KardexElegido.id = i.num("id").toLong(); alKardex()
                }) {
                    Row {
                        Text(i.txt("nombre"), style = Texto.Fila, color = Altiplano.Texto, modifier = Modifier.weight(1f))
                        Text("${formatear(i.num("stock"), 2)} ${i.txt("unidad")}", style = Texto.Fila, color = if (bajo) Altiplano.Alerta else Altiplano.Texto)
                    }
                    Spacer(Modifier.height(9.dp))
                    BarraNivel((i.num("stock") / (i.num("minimo") * 2).coerceAtLeast(0.001)).toFloat(), if (bajo) Altiplano.Alerta else Altiplano.Exito)
                    Spacer(Modifier.height(6.dp))
                    Text(
                        "mínimo ${formatear(i.num("minimo"), 2)} ${i.txt("unidad")} · ${soles(i.num("costo"))} c/u" + if (bajo) " · reponer" else "",
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
        // buscar por nombre, DNI/RUC o teléfono; los desactivados solo si se piden
        val q = v.buscarCliente.trim().lowercase()
        val visibles = (clientes + if (v.verInactivos) inactivos else emptyList()).filter {
            q.isEmpty() || it.txt("nombre").lowercase().contains(q) || it.txt("ruc").contains(q) || it.txt("telefono").contains(q)
        }

        PantallaScroll {
            v.mensaje?.takeIf { it.startsWith("✓ Cliente") }?.let { AvisoCampo(it) }
            Seccion("Productos · cuánto lleva de cada uno")
            Text("Deja en 0 el que no lleva. Varios productos van en la misma nota de venta.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            prods.forEach { p ->
                val id = p.num("id").toLong()
                val cant = v.cantidades[id] ?: 0.0
                Tarjeta(borde = if (cant > 0) Altiplano.VerdeBorde else Altiplano.Borde) {
                    Row {
                        Text(p.txt("nombre"), style = Texto.Fila, color = Altiplano.Texto, modifier = Modifier.weight(1f))
                        Text("hay ${formatear(p.num("stock"), 0)}", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                    }
                    Spacer(Modifier.height(6.dp))
                    StepperGrande(cant, { v.cantidades[id] = (it ?: 0.0).coerceAtLeast(0.0) }, paso = 1.0, minimo = 0.0, decimales = 0,
                        unidad = p.txt("unidad"), fueraDeRango = cant > p.num("stock"), compacto = true)
                }
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.weight(1f)) { Seccion("Cliente") }
                TextButton(onClick = { v.editandoCliente = JsonObject(emptyMap()) }) { Text("+ Nuevo cliente", color = Altiplano.Exito) }
            }
            if (clientes.size + inactivos.size > 5) {
                Campo(v.buscarCliente, { v.buscarCliente = it.take(40) }, "Buscar por nombre, DNI/RUC o teléfono")
            }
            if (visibles.isEmpty()) {
                AvisoCampo(if (q.isEmpty()) "Todavía no hay clientes: agrega el primero." else "Nadie coincide con «${v.buscarCliente.trim()}».")
            }
            visibles.forEach { x ->
                val activo = x.txt("activo") != "false" && x.txt("activo") != "0"
                val id = x.num("id").toLong()
                Opcion(
                    x.txt("nombre") + if (activo) "" else " (desactivado)",
                    listOfNotNull(NOMBRE_CANAL[x.txt("tipo")], x.txt("ruc").ifEmpty { null }, x.txt("telefono").ifEmpty { null }).joinToString(" · "),
                    v.clienteId == id,
                    // sus precios de lo que se está vendiendo (o de todo, si aún no se eligió nada)
                    derecha = if (activo) (lineas.map { it.first }.ifEmpty { prods }).joinToString(" · ") { soles(it.obj("precios")?.num(x.txt("tipo")) ?: 0.0) } else null,
                ) { if (activo) v.clienteId = id else v.editandoCliente = x } // un desactivado se abre para reactivarlo
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                // el de "Proveedor de leche" es del sistema y no se edita
                if (c != null && c.txt("tipo") != "PLANTA") TextButton(onClick = { v.editandoCliente = c }) {
                    Text("Editar a ${c.txt("nombre")}", color = Altiplano.CelesteAcento)
                }
                Spacer(Modifier.weight(1f))
                if (inactivos.isNotEmpty()) TextButton(onClick = { v.verInactivos = !v.verInactivos }) {
                    Text(if (v.verInactivos) "Ocultar desactivados" else "Ver desactivados (${inactivos.size})", color = Altiplano.TextoSecundario)
                }
            }
            TarjetaDato(
                "TOTAL", soles(total),
                apoyo = when { lineas.isEmpty() -> "Pon cuánto lleva de cada producto"; c == null -> "Elige el cliente para ver el precio"; else -> null },
                destacada = true,
            ) {
                lineas.forEach { (p, cant, precio) ->
                    Spacer(Modifier.height(6.dp))
                    FilaValor("${formatear(cant, 0)} × ${p.txt("nombre")}" + if (c != null) " a ${soles(precio)}" else "",
                        if (c != null) soles(cant * precio) else "")
                }
            }
            sinStock.forEach { (p, _, _) -> AvisoCampo("Solo hay ${formatear(p.num("stock"), 0)} de ${p.txt("nombre")} en stock.", esAlerta = true) }
            sinPrecio.forEach { (p, _, _) -> AvisoCampo("${p.txt("nombre")} no tiene precio para este tipo de cliente: ponlo en Ajustes → Precios.", esAlerta = true) }
            CampoCorreoVenta(c)
            error?.let { AvisoCampo("No se pudo registrar: $it", esAlerta = true) }
            BotonPrimario(
                when {
                    enviando -> "Registrando…"; lineas.isEmpty() -> "Pon cuánto lleva"; c == null -> "Elige el cliente"
                    lineas.size > 1 -> "Registrar venta (${lineas.size} productos, una nota)"; else -> "Registrar venta"
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
                        v.mensaje = "✓ Venta registrada: ${soles(r.num("total"))} a ${cli.txt("nombre")}" + textoCorreo(r)
                        NotaVenta.ultima = UltimaNota(r.num("id").toLong(), r.txt("numero"), r.num("total"), cli.txt("nombre"), r.txt("correo"))
                        v.mesVentas = null // la venta recién hecha está en este mes
                        v.version++
                        irA(3)
                    } catch (t: Throwable) { error = t.message }
                    enviando = false
                }
            }
        }
        v.editandoCliente?.let { HojaCliente(it) }
    }
}

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
