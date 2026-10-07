package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import kotlin.time.Instant
import kotlinx.serialization.json.*
import pe.gob.huata.ecolacteos.datos.Sesion
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

/** Pestaña elegida, el último mensaje y un número que sube para volver a cargar. */
private object Config {
    var pestana by mutableStateOf(0)
    var version by mutableStateOf(0)
    var mensaje by mutableStateOf<String?>(null)
}

/**
 * Configuración del admin, como en la web (Administración > Configuración):
 * precios (leche con fecha y venta), límites de calidad, acopio, empresa,
 * almacén (insumos y stock mínimo), vehículos con su ruta e historial.
 * Lo que se cambia rige para lo que se registre desde ahora.
 */
@Composable
fun ConfiguracionReal() {
    val c = Config
    ConPestanas(
        listOf("Precios", "Calidad", "Acopio", "Empresa", "Almacén", "Vehículos", "Historial"), c.pestana,
        { c.pestana = it; c.mensaje = null },
    ) {
        if (c.pestana in 4..5) {
            CargaServidor("/api/v1/admin/configuracion/maestros", clave = c.version) { d, _ ->
                PantallaScroll {
                    Avisos()
                    if (c.pestana == 4) AlmacenConfig(d) else VehiculosConfig(d)
                }
            }
        } else {
            CargaServidor("/api/v1/admin/configuracion", clave = c.version) { d, _ ->
                PantallaScroll {
                    Avisos()
                    when (c.pestana) {
                        0 -> PreciosConfig(d)
                        1 -> CamposConfig(d, "calidad")
                        2 -> CamposConfig(d, "acopio")
                        3 -> CamposConfig(d, "empresa")
                        else -> HistorialConfig(d)
                    }
                }
            }
        }
    }
}

@Composable
private fun Avisos() {
    if (Config.pestana != 6) {
        Text("Lo que cambies aquí rige para lo que se registre desde ahora; lo ya registrado no cambia. " +
            "Cada cambio queda en el Historial con quién lo hizo.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
    }
    Config.mensaje?.let { AvisoCampo(it, esAlerta = it.startsWith("No se")) }
}

/** Envía y deja el mensaje arriba; si sale bien, vuelve a cargar la pestaña. */
private suspend fun guardar(ruta: String, cuerpo: JsonObject, texto: (JsonObject) -> String) {
    try {
        val r = Sesion.enviar(ruta, cuerpo)
        Config.mensaje = texto(r)
        Config.version++
    } catch (t: Throwable) {
        Config.mensaje = "No se pudo guardar: ${t.message}"
    }
}

private fun leerNumero(s: String): Double? = s.trim().replace(',', '.').toDoubleOrNull()
private fun soloNumero(s: String) = s.filter { it.isDigit() || it == '.' || it == ',' }.take(10)
/** 3.0 → "3", 1.028 → "1.028" (como lo devuelve el servidor, sin ceros de más). */
private fun textoNumero(v: Double): String = if (v == v.toLong().toDouble()) v.toLong().toString() else v.toString()

// ---------------------------------------------------------------- precios

@Composable
private fun PreciosConfig(d: JsonObject) {
    val scope = rememberCoroutineScope()
    val leche = d.obj("precio_leche") ?: JsonObject(emptyMap())
    val hoyPrecio = leche.num("hoy")
    var precio by remember(d) { mutableStateOf("") }
    var desde by remember(d) { mutableStateOf(fechaLima()) }
    var enviando by remember { mutableStateOf(false) }

    Seccion("Precio de la leche")
    TarjetaDato("HOY SE PAGA", soles(hoyPrecio), "por litro", "Cada entrega se paga con el precio que regía el día que se entregó.", destacada = true)
    leche.lista("programados").forEach { p ->
        Tarjeta(borde = Altiplano.AvisoBorde) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("Desde el ${fechaCorta(p.txt("desde"))} se pagará ${soles(p.num("precio"))} por litro.",
                    style = Texto.Cuerpo, color = Altiplano.Texto, modifier = Modifier.weight(1f))
                TextButton(onClick = {
                    scope.launch {
                        guardar("/api/v1/admin/configuracion/precio-leche/${p.txt("id")}/quitar", buildJsonObject { }) {
                            "✓ Se quitó el precio de ${soles(p.num("precio"))} que iba a regir desde el ${fechaCorta(p.txt("desde"))}."
                        }
                    }
                }) { Text("Quitar", color = Altiplano.Alerta) }
            }
        }
    }
    Tarjeta {
        Campo(precio, { precio = soloNumero(it) }, "Precio nuevo por litro (S/ ${formatear(hoyPrecio, 2)} ahora)", teclado = KeyboardType.Decimal)
        Spacer(Modifier.height(10.dp))
        FechaDesdeHoy(desde, { desde = it }, "Desde el día")
        Spacer(Modifier.height(6.dp))
        Text("Conviene que empiece un lunes, para que toda la semana de pago tenga el mismo precio.",
            style = Texto.Apoyo, color = Altiplano.TextoTerciario)
        Spacer(Modifier.height(10.dp))
        BotonPrimario(if (enviando) "Guardando…" else "Guardar precio de la leche", habilitado = !enviando) {
            val p = leerNumero(precio)
            if (p == null || p <= 0) { Config.mensaje = "No se guardó: escribe el precio nuevo por litro"; return@BotonPrimario }
            scope.launch {
                enviando = true
                guardar("/api/v1/admin/configuracion/precio-leche", buildJsonObject { put("precio", p); put("desde", desde) }) { r ->
                    if (r.txt("desde") == fechaLima()) "✓ Desde hoy se paga ${soles(r.num("precio"))} por litro. Lo entregado antes conserva su precio."
                    else "✓ Anotado: desde el ${fechaCorta(r.txt("desde"))} se pagará ${soles(r.num("precio"))} por litro. Hasta entonces sigue ${soles(r.num("hoy"))}."
                }
                enviando = false
            }
        }
    }
    val anteriores = leche.lista("anteriores")
    if (anteriores.isNotEmpty()) {
        Text("Precios anteriores: " + anteriores.joinToString(" · ") { "${soles(it.num("precio"))} desde el ${fechaCorta(it.txt("desde"))}" },
            style = Texto.Apoyo, color = Altiplano.TextoTerciario)
    }

    // cuánto se paga la leche adulterada (porcentaje del precio)
    CamposConfig(d, "precios") { valor -> "Con el precio de hoy: ${soles(hoyPrecio * (leerNumero(valor) ?: 0.0) / 100)} por litro." }

    Seccion("Precios de venta")
    Text("Desde hoy, para las ventas nuevas. Las notas de venta ya hechas conservan su precio.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
    val tipos = d.obj("tipos_cliente") ?: JsonObject(emptyMap())
    d.lista("precios_venta").forEach { p ->
        Tarjeta {
            Text(p.txt("producto"), style = Texto.Fila, color = Altiplano.Texto)
            Text("por ${p.txt("unidad")}", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            val precios = p.obj("precios") ?: JsonObject(emptyMap())
            tipos.forEach { (tipo, nombre) ->
                Spacer(Modifier.height(8.dp))
                PrecioVenta(p, tipo, (nombre as? JsonPrimitive)?.content ?: tipo, precios.txt(tipo))
            }
        }
    }
}

@Composable
private fun PrecioVenta(p: JsonObject, tipo: String, nombre: String, actual: String) {
    val scope = rememberCoroutineScope()
    val inicial = actual.toDoubleOrNull()?.let { textoNumero(it) } ?: ""
    var valor by remember(p, actual) { mutableStateOf(inicial) }
    var enviando by remember { mutableStateOf(false) }
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        Campo(valor, { valor = soloNumero(it) }, "$nombre (S/)", teclado = KeyboardType.Decimal, modifier = Modifier.weight(1f))
        if (valor.trim() != inicial) {
            TextButton(enabled = !enviando, onClick = {
                val v = leerNumero(valor)
                if (v == null || v <= 0) { Config.mensaje = "No se guardó: escribe el precio"; return@TextButton }
                scope.launch {
                    enviando = true
                    guardar("/api/v1/admin/configuracion/precio-venta", buildJsonObject {
                        put("producto_id", p.num("producto_id").toLong()); put("tipo_cliente", tipo); put("precio", v)
                    }) { r ->
                        if (r.txt("cambio") == "true") "✓ ${p.txt("producto")} para ${nombre.lowercase()}: ${soles(r.num("precio"))} desde hoy" +
                            (r.txt("antes").takeIf { it.isNotEmpty() }?.let { " (antes ${soles(r.num("antes"))})." } ?: ".")
                        else "Ese ya era el precio."
                    }
                    enviando = false
                }
            }) { Text(if (enviando) "…" else "Guardar", color = Altiplano.Exito) }
        }
    }
}

// ---------------------------------------------------------------- calidad, acopio, empresa (y el % de adulterada)

@Composable
private fun CamposConfig(d: JsonObject, grupo: String, extra: ((String) -> String)? = null) {
    val scope = rememberCoroutineScope()
    val campos = d.lista("campos").filter { it.txt("grupo") == grupo }
    fun inicial(c: JsonObject) = if (c.txt("tipo") in listOf("numero", "porcentaje")) textoNumero(c.num("valor")) else c.txt("valor")
    val valores = remember(d) { mutableStateMapOf<String, String>().apply { campos.forEach { put(it.txt("clave"), inicial(it)) } } }
    var enviando by remember { mutableStateOf(false) }
    val cambiados = campos.filter { (valores[it.txt("clave")] ?: "").trim() != inicial(it) }

    Tarjeta {
        campos.forEachIndexed { k, c ->
            if (k > 0) Spacer(Modifier.height(12.dp))
            val clave = c.txt("clave")
            val numero = c.txt("tipo") in listOf("numero", "porcentaje")
            val unidad = c.txt("unidad").takeIf { it.isNotEmpty() }?.let { " ($it)" } ?: ""
            Campo(
                valores[clave] ?: "",
                {
                    valores[clave] = when {
                        numero -> soloNumero(it)
                        c.txt("tipo") == "ruc" -> it.filter(Char::isDigit).take(11)
                        else -> it.take(c.num("max").toInt().takeIf { m -> m > 0 } ?: 160)
                    }
                },
                c.txt("etiqueta") + unidad,
                teclado = when { numero -> KeyboardType.Decimal; c.txt("tipo") == "ruc" -> KeyboardType.Number; else -> KeyboardType.Text },
                palabras = c.txt("tipo") == "texto",
            )
            val ayuda = listOfNotNull(c.txt("ayuda").takeIf { it.isNotEmpty() }, extra?.invoke(valores[clave] ?: "")).joinToString(" ")
            if (ayuda.isNotEmpty()) {
                Spacer(Modifier.height(4.dp))
                Text(ayuda, style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            }
        }
        Spacer(Modifier.height(14.dp))
        val n = cambiados.size
        BotonPrimario(
            when { enviando -> "Guardando…"; n == 0 -> "Sin cambios"; else -> "Guardar $n ${if (n == 1) "cambio" else "cambios"}" },
            habilitado = n > 0 && !enviando,
        ) {
            scope.launch {
                enviando = true
                guardar("/api/v1/admin/configuracion", buildJsonObject {
                    putJsonObject("valores") {
                        cambiados.forEach { c ->
                            val v = (valores[c.txt("clave")] ?: "").trim()
                            if (c.txt("tipo") in listOf("numero", "porcentaje")) leerNumero(v)?.let { put(c.txt("clave"), it) } ?: put(c.txt("clave"), v)
                            else put(c.txt("clave"), v)
                        }
                    }
                }) { r ->
                    val x = r.num("cambios").toInt()
                    "✓ Guardado ($x ${if (x == 1) "cambio" else "cambios"}). Ya rige para lo que se registre desde ahora."
                }
                enviando = false
            }
        }
        if (n > 0 && !enviando) {
            TextButton(onClick = { campos.forEach { valores[it.txt("clave")] = inicial(it) } }) {
                Text("Deshacer", color = Altiplano.TextoTerciario)
            }
        }
    }
}

// ---------------------------------------------------------------- almacén

@Composable
private fun AlmacenConfig(d: JsonObject) {
    Text("El stock no se cambia aquí: sube con las compras y baja con la producción o con «Corregir stock» en el kardex. " +
        "El mínimo es desde cuánto se avisa que hay que comprar.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
    d.lista("insumos").forEach { i -> InsumoConfig(i) }
    InsumoConfig(null)
}

@Composable
private fun InsumoConfig(i: JsonObject?) {
    val scope = rememberCoroutineScope()
    val nombre0 = i?.txt("nombre") ?: ""
    val unidad0 = i?.txt("unidad") ?: "kg"
    val minimo0 = i?.let { textoNumero(it.num("minimo")) } ?: ""
    var nombre by remember(i) { mutableStateOf(nombre0) }
    var unidad by remember(i) { mutableStateOf(unidad0) }
    var minimo by remember(i) { mutableStateOf(minimo0) }
    var enviando by remember { mutableStateOf(false) }
    val bajo = i != null && i.num("stock") < i.num("minimo")
    val cambiado = nombre.trim() != nombre0 || unidad.trim() != unidad0 || minimo.trim() != minimo0

    Tarjeta(borde = if (bajo) Altiplano.AlertaBorde else Altiplano.Borde) {
        if (i == null) Text("INSUMO NUEVO", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
        else Row {
            Text(nombre0, style = Texto.Fila, color = Altiplano.Texto, modifier = Modifier.weight(1f))
            Text("hay ${formatear(i.num("stock"), 3)} ${i.txt("unidad")}", style = Texto.Apoyo, color = if (bajo) Altiplano.Alerta else Altiplano.TextoTerciario)
        }
        Spacer(Modifier.height(8.dp))
        Campo(nombre, { nombre = it.take(80) }, if (i == null) "Nombre (ej.: Bolsa para queso)" else "Nombre")
        Spacer(Modifier.height(8.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Campo(unidad, { unidad = it.take(12) }, "Unidad (kg, L, un)", modifier = Modifier.weight(1f))
            Campo(minimo, { minimo = soloNumero(it) }, "Stock mínimo", teclado = KeyboardType.Decimal, modifier = Modifier.weight(1f))
        }
        if (cambiado || i == null) {
            Spacer(Modifier.height(10.dp))
            BotonPrimario(if (enviando) "Guardando…" else if (i == null) "+ Agregar insumo" else "Guardar", habilitado = !enviando) {
                scope.launch {
                    enviando = true
                    guardar("/api/v1/admin/configuracion/insumos", buildJsonObject {
                        put("id", i?.num("id")?.toLong()); put("nombre", nombre.trim()); put("unidad", unidad.trim())
                        put("minimo", leerNumero(minimo))
                    }) { r ->
                        if (i != null) "✓ ${r.txt("nombre")} guardado"
                        else "✓ ${r.txt("nombre")} agregado al almacén. Su stock sube cuando se registre una compra."
                    }
                    enviando = false
                }
            }
        }
    }
}

// ---------------------------------------------------------------- vehículos y rutas

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun VehiculosConfig(d: JsonObject) {
    val vehiculos = d.lista("vehiculos")
    val acopiadores = d.lista("acopiadores")
    Text("Cada vehículo recoge las comunidades de su ruta en este orden. Si pasas una comunidad a otro vehículo, " +
        "sus proveedores pasan con ella desde hoy.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
    vehiculos.forEach { v -> VehiculoConfig(v, vehiculos, acopiadores) }
    val sueltas = d.lista("sueltas")
    if (sueltas.isNotEmpty()) AvisoCampo("Comunidades que no recoge ningún vehículo: " + sueltas.joinToString(", ") { it.txt("nombre") })
    VehiculoNuevo(acopiadores)
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun DatosVehiculo(
    codigo: String, onCodigo: (String) -> Unit, tipo: String, onTipo: (String) -> Unit,
    acopiador: Long?, onAcopiador: (Long?) -> Unit, acopiadores: List<JsonObject>,
) {
    Campo(codigo, { onCodigo(it.uppercase().filter { ch -> ch.isLetterOrDigit() || ch == '-' }.take(12)) }, "Código (ej.: C-04)")
    Text("TIPO", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Chip("Carro", tipo == "CARRO") { onTipo("CARRO") }
        Chip("Motocar (circuito)", tipo == "MOTOCAR") { onTipo("MOTOCAR") }
    }
    Text("ACOPIADOR", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Chip("Sin acopiador fijo", acopiador == null) { onAcopiador(null) }
        acopiadores.forEach { a -> Chip(a.txt("nombre"), a.num("id").toLong() == acopiador) { onAcopiador(a.num("id").toLong()) } }
    }
}

@Composable
private fun VehiculoConfig(v: JsonObject, todos: List<JsonObject>, acopiadores: List<JsonObject>) {
    val scope = rememberCoroutineScope()
    val id = v.num("id").toLong()
    val activo = v.txt("activo") == "true"
    val acop0 = v.txt("acopiador_id").toDoubleOrNull()?.toLong()
    var codigo by remember(v) { mutableStateOf(v.txt("codigo")) }
    var tipo by remember(v) { mutableStateOf(v.txt("tipo")) }
    var acopiador by remember(v) { mutableStateOf(acop0) }
    var editando by remember(v) { mutableStateOf(false) }
    var nueva by remember(v) { mutableStateOf("") }
    var enviando by remember { mutableStateOf(false) }
    val ruta = v.lista("ruta")
    val cambiado = codigo.trim() != v.txt("codigo") || tipo != v.txt("tipo") || acopiador != acop0

    fun enviarVehiculo(nuevoActivo: Boolean?) = scope.launch {
        enviando = true
        guardar("/api/v1/admin/configuracion/vehiculos", buildJsonObject {
            put("id", id); put("codigo", codigo.trim()); put("tipo", tipo); put("acopiador_id", acopiador)
            nuevoActivo?.let { put("activo", it) }
        }) { r ->
            when (nuevoActivo) {
                false -> "✓ El ${r.txt("codigo")} quedó sin usar"
                true -> "✓ El ${r.txt("codigo")} vuelve a estar en uso"
                null -> "✓ ${r.txt("codigo")} guardado"
            }
        }
        enviando = false
    }

    Tarjeta(borde = if (activo) Altiplano.Borde else Altiplano.BordeSuave) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text("${v.txt("codigo")} · ${if (v.txt("tipo") == "MOTOCAR") "motocar" else "carro"}" + if (activo) "" else " (sin usar)",
                    style = Texto.Fila, color = if (activo) Altiplano.Texto else Altiplano.TextoApagado)
                Text(v.txt("acopiador").ifEmpty { "sin acopiador fijo" } + " · ${ruta.size} comunidades · ${ruta.sumOf { it.num("proveedores") }.toInt()} proveedores",
                    style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            }
            TextButton(onClick = { editando = !editando }) { Text(if (editando) "Cerrar" else "Editar", color = Altiplano.CelesteAcento) }
        }
        if (editando) {
            Spacer(Modifier.height(8.dp))
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                DatosVehiculo(codigo, { codigo = it }, tipo, { tipo = it }, acopiador, { acopiador = it }, acopiadores)
                if (cambiado) BotonPrimario(if (enviando) "Guardando…" else "Guardar", habilitado = !enviando) { enviarVehiculo(null) }
                TextButton(enabled = !enviando, onClick = { enviarVehiculo(!activo) }) {
                    Text(if (activo) "Dejar de usar este vehículo" else "Volver a usar este vehículo", color = if (activo) Altiplano.Alerta else Altiplano.Exito)
                }
            }
        }

        Spacer(Modifier.height(10.dp))
        Text("RUTA", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
        if (ruta.isEmpty()) Text("Todavía no recoge ninguna comunidad.", style = Texto.Apoyo, color = Altiplano.TextoApagado)
        ruta.forEachIndexed { k, c -> ComunidadConfig(c, k, v, ruta, todos) }
        if (activo) {
            Spacer(Modifier.height(8.dp))
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Campo(nueva, { nueva = it.take(80) }, "Comunidad nueva en esta ruta", palabras = true, modifier = Modifier.weight(1f))
                TextButton(enabled = !enviando && nueva.isNotBlank(), onClick = {
                    scope.launch {
                        enviando = true
                        guardar("/api/v1/admin/configuracion/comunidades", buildJsonObject { put("nombre", nueva.trim()); put("vehiculo_id", id) }) { r ->
                            "✓ ${r.txt("nombre")} agregada al final de la ruta del ${r.txt("vehiculo")}. Ubícala en la web: Acopio → Mapa de Huata."
                        }
                        enviando = false
                    }
                }) { Text("+ Agregar", color = Altiplano.Exito) }
            }
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ComunidadConfig(c: JsonObject, k: Int, v: JsonObject, ruta: List<JsonObject>, todos: List<JsonObject>) {
    val scope = rememberCoroutineScope()
    val vid = v.num("id").toLong()
    var abierta by remember(c) { mutableStateOf(false) }
    var nombre by remember(c) { mutableStateOf(c.txt("nombre")) }
    var vehiculo by remember(c) { mutableStateOf(vid) }
    var enviando by remember { mutableStateOf(false) }

    fun mover(paso: Int) = scope.launch {
        val orden = ruta.map { it.num("id").toLong() }.toMutableList()
        orden[k] = orden[k + paso].also { orden[k + paso] = orden[k] }
        enviando = true
        guardar("/api/v1/admin/configuracion/vehiculos/$vid/ruta", buildJsonObject { putJsonArray("orden") { orden.forEach { add(it) } } }) {
            "✓ Orden de la ruta del ${v.txt("codigo")} guardado"
        }
        enviando = false
    }

    Row(verticalAlignment = Alignment.CenterVertically) {
        Text("${k + 1}", style = Texto.Apoyo, color = Altiplano.TextoTerciario, modifier = Modifier.width(22.dp))
        Column(Modifier.weight(1f)) {
            Text(c.txt("nombre"), style = Texto.Cuerpo, color = Altiplano.Texto)
            Text("${c.num("proveedores").toInt()} prov." + if (c.txt("con_gps") == "true") "" else " · sin ubicar en el mapa",
                style = Texto.Apoyo, color = if (c.txt("con_gps") == "true") Altiplano.TextoTerciario else Altiplano.Aviso)
        }
        TextButton(enabled = k > 0 && !enviando, onClick = { mover(-1) }, contentPadding = PaddingValues(6.dp)) { Text("↑") }
        TextButton(enabled = k < ruta.size - 1 && !enviando, onClick = { mover(1) }, contentPadding = PaddingValues(6.dp)) { Text("↓") }
        TextButton(onClick = { abierta = !abierta }, contentPadding = PaddingValues(6.dp)) { Text(if (abierta) "Cerrar" else "Editar", color = Altiplano.CelesteAcento) }
    }
    if (abierta) {
        Column(Modifier.padding(start = 22.dp, bottom = 8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Campo(nombre, { nombre = it.take(80) }, "Nombre", palabras = true)
            Text("LA RECOGE", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                todos.filter { it.txt("activo") == "true" || it.num("id").toLong() == vid }.forEach { o ->
                    val oid = o.num("id").toLong()
                    Chip(if (oid == vid) "${o.txt("codigo")} (este)" else "Pasar al ${o.txt("codigo")}", oid == vehiculo) { vehiculo = oid }
                }
            }
            val pasa = vehiculo != vid
            if (nombre.trim() != c.txt("nombre") || pasa) {
                BotonPrimario(if (enviando) "Guardando…" else "Guardar", habilitado = !enviando) {
                    scope.launch {
                        enviando = true
                        guardar("/api/v1/admin/configuracion/comunidades", buildJsonObject {
                            put("id", c.num("id").toLong()); put("nombre", nombre.trim()); put("vehiculo_id", vehiculo)
                        }) { r ->
                            val n = c.num("proveedores").toInt()
                            if (pasa) "✓ ${r.txt("nombre")} ahora la recoge el ${r.txt("vehiculo")}" + (if (n > 0) ", con sus $n proveedores" else "")
                            else "✓ ${r.txt("nombre")} guardada"
                        }
                        enviando = false
                    }
                }
            }
        }
    }
}

@Composable
private fun VehiculoNuevo(acopiadores: List<JsonObject>) {
    val scope = rememberCoroutineScope()
    var codigo by remember { mutableStateOf("") }
    var tipo by remember { mutableStateOf("CARRO") }
    var acopiador by remember { mutableStateOf<Long?>(null) }
    var enviando by remember { mutableStateOf(false) }
    Tarjeta {
        Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("VEHÍCULO NUEVO", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
            DatosVehiculo(codigo, { codigo = it }, tipo, { tipo = it }, acopiador, { acopiador = it }, acopiadores)
            BotonPrimario(if (enviando) "Guardando…" else "+ Agregar vehículo", habilitado = !enviando) {
                scope.launch {
                    enviando = true
                    guardar("/api/v1/admin/configuracion/vehiculos", buildJsonObject {
                        put("codigo", codigo.trim()); put("tipo", tipo); put("acopiador_id", acopiador)
                    }) { r ->
                        codigo = ""; acopiador = null
                        "✓ Vehículo ${r.txt("codigo")} agregado. Ahora agrégale comunidades a su ruta."
                    }
                    enviando = false
                }
            }
        }
    }
}

// ---------------------------------------------------------------- historial

@Composable
private fun HistorialConfig(d: JsonObject) {
    val h = d.lista("historial")
    Text("Los últimos 40 cambios de configuración y de precios, lo más reciente arriba.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
    if (h.isEmpty()) AvisoCampo("Todavía no se cambió nada.")
    h.forEach { x ->
        Tarjeta {
            Text(x.txt("que"), style = Texto.Fila, color = Altiplano.Texto)
            Spacer(Modifier.height(4.dp))
            Text("${x.txt("antes")}  →  ${x.txt("despues")}", style = Texto.Cuerpo, color = Altiplano.TextoSecundario)
            Text(
                "${fechaCorta(x.txt("fecha"))} ${x.txt("fecha").drop(11).take(5)} · ${x.txt("usuario").ifEmpty { "—" }}" +
                    (x.txt("desde").takeIf { it.isNotEmpty() }?.let { " · rige desde el ${fechaCorta(it)}" } ?: ""),
                style = Texto.Apoyo, color = Altiplano.TextoTerciario,
            )
        }
    }
}

// ---------------------------------------------------------------- fecha (hoy o más adelante)

/** Como el calendario del comprobante, pero al revés: solo hoy o días que todavía no llegan. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun FechaDesdeHoy(valor: String, onCambio: (String) -> Unit, etiqueta: String) {
    var abierto by remember { mutableStateOf(false) }
    val hoyIso = fechaLima()
    Tarjeta(onClick = { abierto = true }) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(etiqueta, style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                Text(fechaCorta(valor) + " " + valor.take(4) + if (valor == hoyIso) " (hoy)" else "", style = Texto.Fila, color = Altiplano.Texto)
            }
            Text("Cambiar", style = Texto.Apoyo, color = Altiplano.CelesteAcento)
        }
    }
    if (!abierto) return
    // el calendario trabaja en milisegundos UTC a medianoche
    val hoy = Instant.parse("${hoyIso}T00:00:00Z").toEpochMilliseconds()
    val estado = rememberDatePickerState(
        initialSelectedDateMillis = Instant.parse("${valor}T00:00:00Z").toEpochMilliseconds(),
        selectableDates = object : SelectableDates {
            override fun isSelectableDate(utcTimeMillis: Long) = utcTimeMillis >= hoy
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
