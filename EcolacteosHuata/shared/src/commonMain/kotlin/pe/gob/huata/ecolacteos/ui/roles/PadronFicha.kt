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

/**
 * Padrón del administrador en la app, igual que en la web de oficina:
 * tocar al proveedor abre su ficha (leche, pagos por semana, comunidades) con
 * Editar · Trasladar · Eliminar; en la fila, "Cambiar comunidad" o "Editar traslado".
 * Se muestra una hoja a la vez.
 */
object PadronUi {
    var ficha by mutableStateOf<Long?>(null)
    var editar by mutableStateOf<JsonObject?>(null)    // la ficha del proveedor que se edita
    var eliminar by mutableStateOf<JsonObject?>(null)
    var traslado by mutableStateOf<Pair<Long?, Boolean>?>(null) // proveedor (null = buscarlo) y si es corregir
    var mensaje by mutableStateOf<String?>(null)
    var version by mutableStateOf(0)
    fun listo(texto: String) { mensaje = texto; version++; ficha = null; editar = null; eliminar = null; traslado = null }
}

private val CANAL = mapOf("APP" to "📱 Por la app", "LLAMADA" to "📞 Llamó", "OFICINA" to "🏠 Vino a Huata")

/** Lista de proveedores del Padrón (agrupados por vehículo). */
@Composable
fun ProveedoresPadron(provs: List<JsonObject>) {
    PadronUi.mensaje?.let { AvisoCampo(it, esAlerta = it.startsWith("No se pudo")) }
    Text("Toca un proveedor para ver todo: su leche, sus pagos y sus comunidades.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
    provs.groupBy { it.txt("vehiculo") }.forEach { (vehiculo, lista) ->
        Seccion("$vehiculo · ${lista.size}")
        lista.forEach { p ->
            val id = p.num("id").toLong()
            val activo = p.txt("estado") == "ACTIVO"
            val reciente = p.obj("cambio_reciente")
            val programado = p.obj("programado")
            Tarjeta(borde = if (activo) Altiplano.Borde else Altiplano.AlertaBorde, onClick = { PadronUi.ficha = id }) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(p.txt("nombre"), style = Texto.Fila, color = Altiplano.Texto)
                        Text("DNI ${p.txt("dni")} · ${p.txt("comunidad")}", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                        reciente?.let { Text("🔄 Cambió el ${fechaCorta(it.txt("desde"))} · antes ${it.txt("antes")}", style = Texto.Apoyo, color = Altiplano.CelesteAcento) }
                        programado?.let { Text("⏳ Pasa a ${it.txt("comunidad")} el ${fechaCorta(it.txt("desde"))}", style = Texto.Apoyo, color = Altiplano.Aviso) }
                    }
                    ChipEstado(if (activo) Estado.ACEPTADO else Estado.RECHAZADO)
                }
                if (activo) Row {
                    if (programado != null || reciente != null) TextButton(onClick = { PadronUi.traslado = id to true }) { Text("Editar traslado", color = Altiplano.CelesteAcento) }
                    if (programado == null) TextButton(onClick = { PadronUi.traslado = id to false }) { Text("Cambiar comunidad", color = Altiplano.Exito) }
                }
            }
        }
    }
    HojasPadron()
}

/** La hoja abierta (ficha, editar, eliminar o traslado). También la usa "Cambios de comunidad". */
@Composable
fun HojasPadron() {
    PadronUi.ficha?.let { FichaProveedor(it) }
    PadronUi.editar?.let { HojaEditarProveedor(it) }
    PadronUi.eliminar?.let { DialogoEliminar(it) }
    PadronUi.traslado?.let { (id, corregir) -> HojaTraslado(id, corregir) }
}

// ------------------------------------------------------------------- ficha

@Composable
private fun FichaProveedor(id: Long) {
    var semanas by remember(id) { mutableStateOf(8) }
    var d by remember(id) { mutableStateOf<JsonObject?>(null) }
    var error by remember(id) { mutableStateOf<String?>(null) }
    var abierta by remember(id) { mutableStateOf<String?>(null) }
    var recargar by remember(id) { mutableStateOf(0) } // después de pagar, la ficha se vuelve a leer
    var pagar by remember(id) { mutableStateOf<Triple<String, String, List<Long>>?>(null) }
    var pagado by remember(id) { mutableStateOf<String?>(null) }
    LaunchedEffect(id, semanas, recargar) {
        try { d = Sesion.consultar("/api/v1/admin/proveedores/$id/ficha?semanas=$semanas") } catch (t: Throwable) { error = t.message }
    }
    val f = d
    Hoja(f?.obj("proveedor")?.txt("nombre") ?: "Cargando…", { PadronUi.ficha = null }) {
        error?.let { AvisoCampo(it, esAlerta = true) }
        if (f == null) { if (error == null) CircularProgressIndicator(color = Altiplano.Exito); return@Hoja }
        val p = f.obj("proveedor")!!
        val leche = f.obj("leche")
        val pagos = f.obj("pagos")
        val activo = p.txt("estado") == "ACTIVO"

        Text("DNI ${p.txt("dni")} · inscrito el ${fechaCorta(p.txt("inscrito"))}" +
            (p.txt("vacas").takeIf { it.isNotEmpty() }?.let { " · $it vacas" } ?: "") +
            if (p.txt("usa_app") == "true") " · usa la app" else " · no usa la app", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
        if (!activo) AvisoCampo("De baja: ${p.txt("motivo_baja")}", esAlerta = true)
        Text("📍 ${p.txt("comunidad")} · lo recoge ${p.txt("vehiculo").ifEmpty { "—" }}" +
            (p.txt("acopiador").takeIf { it.isNotEmpty() }?.let { " ($it)" } ?: ""), style = Texto.Cuerpo, color = Altiplano.Texto)
        p.obj("programado")?.let { Text("⏳ Pasa a ${it.txt("comunidad")} el ${fechaCorta(it.txt("desde"))}", style = Texto.Apoyo, color = Altiplano.Aviso) }

        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Tarjeta(Modifier.weight(1f)) { MiniCifra("Leche por día", leche?.num("por_dia")?.takeIf { it > 0 }?.let { "${formatear(it)} L" } ?: "—", Altiplano.Exito) }
            Tarjeta(Modifier.weight(1f)) { MiniCifra("Esta semana", "${formatear(leche?.num("litros_7") ?: 0.0)} L") }
            Tarjeta(Modifier.weight(1f)) {
                val porPagar = pagos?.num("por_pagar") ?: 0.0
                MiniCifra("Por pagarle", soles(porPagar), if (porPagar > 0) Altiplano.Aviso else Altiplano.Exito)
            }
        }
        Text("${leche?.num("dias_30")?.toInt() ?: 0} días entregó en los últimos 30 · ${formatear(leche?.num("litros_30") ?: 0.0, 0)} L" +
            (p.txt("promedio").takeIf { it.isNotEmpty() }?.let { " · dijo ${formatear(it.toDouble())} L/día" } ?: ""),
            style = Texto.Apoyo, color = Altiplano.TextoTerciario)
        pagos?.obj("semana_en_curso")?.let { Text("Semana en curso: ${soles(it.num("monto"))}, se paga el ${fechaCorta(it.txt("se_paga"))}.", style = Texto.Apoyo, color = Altiplano.TextoTerciario) }

        // lo que se le debe: pagarlo aquí mismo, todo junto o semana por semana
        pagado?.let { AvisoCampo(it) }
        val pendientes = pagos?.lista("pendientes").orEmpty()
        if (pendientes.isNotEmpty()) Tarjeta(borde = Altiplano.AvisoBorde) {
            val debe = pendientes.sumOf { it.num("monto") }
            val nombre = p.txt("nombre")
            Text("Se le debe ${soles(debe)}", style = Texto.Fila, color = Altiplano.Aviso)
            Text(if (pendientes.size == 1) "1 semana sin pagar" else "${pendientes.size} semanas sin pagar · desde el ${fechaCorta(pendientes[0].txt("inicio"))}",
                style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            Spacer(Modifier.height(8.dp))
            BotonPrimario(if (pendientes.size > 1) "Pagar todo (${soles(debe)})" else "Pagar ${soles(debe)}") {
                pagar = Triple("Pagar a $nombre", "Todo lo que se le debe: ${soles(debe)}. ¿Cómo se pagó?", pendientes.map { it.num("id").toLong() })
            }
            if (pendientes.size > 1) pendientes.forEach { b ->
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("Semana ${fechaCorta(b.txt("inicio"))} – ${fechaCorta(b.txt("fin"))}", style = Texto.Cuerpo, color = Altiplano.Texto, modifier = Modifier.weight(1f))
                    Text(soles(b.num("monto")), style = Texto.Fila, color = Altiplano.Texto)
                    TextButton(onClick = {
                        pagar = Triple("Pagar a $nombre", "Semana del ${fechaCorta(b.txt("inicio"))}: ${soles(b.num("monto"))}. ¿Cómo se pagó?", listOf(b.num("id").toLong()))
                    }) { Text("Pagar esta", color = Altiplano.Exito) }
                }
            }
        }

        Text("ENTREGAS Y PAGOS POR SEMANA", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
        val lista = f.lista("semanas")
        if (lista.isEmpty()) Text("Todavía no entregó leche.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
        lista.forEach { s ->
            val ini = s.txt("inicio")
            val estado = s.txt("estado")
            Tarjeta(onClick = { abierta = if (abierta == ini) null else ini }) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text("${if (abierta == ini) "▾" else "▸"} ${fechaCorta(ini)} – ${fechaCorta(s.txt("fin"))}", style = Texto.Fila, color = Altiplano.Texto)
                        Text("${s.num("dias").toInt()} días · ${formatear(s.num("litros"))} L" +
                            (if (s.num("litros_aceptados") < s.num("litros")) " (${formatear(s.num("litros_aceptados"))} aceptados)" else "") +
                            " · ${soles(s.num("monto"))}", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                    }
                    Text(
                        when (estado) {
                            "PAGADO" -> "✓ ${fechaCorta(s.txt("pagado_el"))}" + when (s.txt("metodo")) { "YAPE" -> " · Yape"; "EFECTIVO" -> " · efectivo"; else -> "" }
                            "EN_CURSO" -> "en curso"
                            else -> "Pendiente"
                        },
                        style = Texto.Apoyo, color = when (estado) { "PAGADO" -> Altiplano.Exito; "PENDIENTE" -> Altiplano.Aviso; else -> Altiplano.TextoSecundario },
                    )
                    if (estado == "PENDIENTE" && s.txt("boleta_id").isNotEmpty() && s.num("monto") > 0) TextButton(onClick = {
                        pagar = Triple("Pagar a ${p.txt("nombre")}", "Semana del ${fechaCorta(ini)}: ${soles(s.num("monto"))}. ¿Cómo se pagó?",
                            listOf(s.num("boleta_id").toLong()))
                    }) { Text("Pagar", color = Altiplano.Exito) }
                }
                if (abierta == ini) {
                    Spacer(Modifier.height(6.dp))
                    s.lista("detalle").forEach { e ->
                        val rechazada = e.txt("calidad") == "RECHAZADO"
                        Text("${fechaCorta(e.txt("fecha"))}: " + if (e.txt("ausente") == "true") "no entregó" else
                            "${formatear(e.num("litros"))} L" + when (e.txt("calidad")) { "RECHAZADO" -> " (rechazada)"; "PENDIENTE" -> " (por analizar)"; else -> "" },
                            style = Texto.Apoyo, color = if (rechazada) Altiplano.Alerta else Altiplano.TextoSecundario)
                    }
                }
            }
        }
        if (f.txt("hay_mas") == "true") TextButton(onClick = { semanas += 8 }) { Text("Ver semanas anteriores", color = Altiplano.CelesteAcento) }
        Text("En total se le pagó ${soles(pagos?.num("pagado_total") ?: 0.0)}.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)

        Text("COMUNIDADES", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
        val pasos = f.lista("historial")
        val actual = pasos.indexOfLast { it.txt("futuro") != "true" }
        pasos.forEachIndexed { k, h ->
            Text(
                (if (k == actual) "● " else if (h.txt("futuro") == "true") "⏳ " else "○ ") +
                    "${h.txt("comunidad")} · ${fechaCorta(h.txt("desde"))} · ${h.txt("como")}" +
                    (h.txt("quien").takeIf { it.isNotEmpty() }?.let { " ($it)" } ?: "") +
                    (h.txt("motivo").takeIf { it.isNotEmpty() }?.let { " · “$it”" } ?: ""),
                style = Texto.Apoyo, color = if (k == actual) Altiplano.Exito else Altiplano.TextoSecundario,
            )
        }

        if (activo) {
            Spacer(Modifier.height(6.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Box(Modifier.weight(1f)) { BotonPrimario("Editar") { PadronUi.ficha = null; PadronUi.editar = f } }
                if (p.obj("programado") == null) Box(Modifier.weight(1f)) { BotonPrimario("Trasladar") { PadronUi.ficha = null; PadronUi.traslado = id to false } }
            }
            TextButton(onClick = { PadronUi.ficha = null; PadronUi.eliminar = f }) { Text("Eliminar proveedor", color = Altiplano.Alerta) }
        }
    }
    pagar?.let { (titulo, texto, ids) ->
        DialogoPago(titulo, texto, ids, onCerrar = { pagar = null }, alPagar = { pagado = it; recargar++ })
    }
}

// ------------------------------------------------------------------ editar

/** Comunidades con el vehículo que pasa por cada una (de GET admin/solicitudes). */
@Composable
private fun comunidadesConVehiculo(): List<JsonObject>? {
    var lista by remember { mutableStateOf<List<JsonObject>?>(null) }
    LaunchedEffect(Unit) { lista = runCatching { Sesion.consultar("/api/v1/admin/solicitudes").lista("comunidades") }.getOrNull() }
    return lista
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun HojaEditarProveedor(ficha: JsonObject) {
    val p = ficha.obj("proveedor")!!
    val id = p.num("id").toLong()
    val programado = p.obj("programado")
    val original = (programado?.num("comunidad_id") ?: p.num("comunidad_id")).toLong()
    var nombre by remember { mutableStateOf(p.txt("nombre")) }
    var dni by remember { mutableStateOf(p.txt("dni")) }
    var promedio by remember { mutableStateOf(p.txt("promedio").toDoubleOrNull()?.let { formatear(it) } ?: "") }
    var vacas by remember { mutableStateOf(p.txt("vacas")) }
    var comunidad by remember { mutableStateOf(original) }
    var enviando by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val comunidades = comunidadesConVehiculo()
    val scope = rememberCoroutineScope()
    val volver = { PadronUi.editar = null; PadronUi.ficha = id }

    Hoja("Editar proveedor", volver) {
        Text("Para corregir errores. Si se mudó de verdad, usa “Trasladar” (así queda en su historial).", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
        Campo(nombre, { nombre = it.take(120) }, "Nombre y apellidos", palabras = true)
        Campo(dni, { dni = it.filter(Char::isDigit).take(8) }, "DNI", teclado = KeyboardType.NumberPassword)
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Campo(promedio, { promedio = it.filter { c -> c.isDigit() || c == '.' }.take(5) }, "Litros/día que dice dar",
                teclado = KeyboardType.Decimal, modifier = Modifier.weight(1f))
            Campo(vacas, { vacas = it.filter(Char::isDigit).take(3) }, "Vacas", teclado = KeyboardType.Number, modifier = Modifier.weight(1f))
        }
        Text(if (programado != null) "COMUNIDAD A LA QUE SE TRASLADA EL ${fechaCorta(programado.txt("desde")).uppercase()}" else "COMUNIDAD",
            style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
        if (comunidades == null) CircularProgressIndicator(color = Altiplano.Exito)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            comunidades?.filter { it.txt("vehiculo").isNotEmpty() }?.forEach { c ->
                Chip("${c.txt("nombre")} · ${c.txt("vehiculo")}", c.num("id").toLong() == comunidad) { comunidad = c.num("id").toLong() }
            }
        }
        if (comunidad != original) AvisoCampo(
            if (programado != null) "Se corrige el destino del traslado programado. Sigue en su comunidad hasta el ${fechaCorta(programado.txt("desde"))}."
            else "Se corrige su comunidad: desde ahora lo recoge el vehículo de esa ruta. Sus entregas y pagos pasados no cambian. " +
                "Si el error fue en un traslado, ese mismo traslado se corrige.",
        )
        error?.let { AvisoCampo(it, esAlerta = true) }
        BotonPrimario(if (enviando) "Guardando…" else "Guardar cambios", habilitado = !enviando) {
            error = when {
                nombre.trim().split(Regex("\\s+")).size < 2 -> "Escribe nombre y apellidos"
                dni.length != 8 -> "El DNI debe tener 8 dígitos"
                else -> null
            }
            if (error != null) return@BotonPrimario
            scope.launch {
                enviando = true
                try {
                    val r = Sesion.enviar("/api/v1/admin/proveedores/$id", buildJsonObject {
                        put("nombre", nombre.trim()); put("dni", dni); put("comunidad_id", comunidad)
                        promedio.toDoubleOrNull()?.let { put("promedio_litros", it) }
                        vacas.toIntOrNull()?.let { put("vacas_ordeno", it) }
                    })
                    val que = when (r.txt("comunidad_corregida")) {
                        "ALTA" -> " · comunidad corregida"; "TRASLADO" -> " · se corrigió el destino de su traslado"
                        "TRASLADO_ANULADO" -> " · el traslado se anuló"; "PROGRAMADO" -> " · se corrigió el traslado programado"; else -> ""
                    }
                    PadronUi.listo("✓ Datos de ${nombre.trim()} corregidos$que")
                    PadronUi.ficha = id
                } catch (t: Throwable) { error = t.message }
                enviando = false
            }
        }
    }
}

// ---------------------------------------------------------------- eliminar

@Composable
private fun DialogoEliminar(ficha: JsonObject) {
    val p = ficha.obj("proveedor")!!
    val h = ficha.obj("historia")
    val id = p.num("id").toLong()
    val conHistoria = (h?.num("entregas") ?: 0.0) + (h?.num("pagos") ?: 0.0) + (h?.num("sanciones") ?: 0.0) > 0
    var baja by remember { mutableStateOf(conHistoria) }
    var aviso by remember { mutableStateOf<String?>(null) }
    var motivo by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var enviando by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val volver = { PadronUi.eliminar = null; PadronUi.ficha = id }

    AlertDialog(
        onDismissRequest = volver, containerColor = Altiplano.Fondo,
        title = { Text((if (baja) "Dar de baja a " else "Eliminar a ") + p.txt("nombre"), color = Altiplano.Texto) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                if (!baja) Text("Nunca entregó leche: se borra del padrón por completo. Úsalo si se registró por error.", color = Altiplano.TextoSecundario)
                else {
                    Text(aviso ?: "Ya entregó leche (${h?.num("entregas")?.toInt() ?: 0} entregas): no se puede borrar sin perder sus entregas y pagos.", color = Altiplano.Aviso)
                    Text("Se da de baja: ya no aparece en las rutas ni en la app del acopiador. Su historia y pagos se quedan.", color = Altiplano.TextoSecundario)
                    OutlinedTextField(motivo, { motivo = it.take(300) }, label = { Text("¿Por qué se da de baja?") }, colors = coloresCampo(), modifier = Modifier.fillMaxWidth())
                }
                error?.let { Text(it, color = Altiplano.Alerta) }
            }
        },
        confirmButton = {
            TextButton(enabled = !enviando, onClick = {
                if (baja && motivo.trim().length < 3) { error = "Escribe por qué se da de baja"; return@TextButton }
                scope.launch {
                    enviando = true
                    try {
                        if (baja) {
                            Sesion.enviar("/api/v1/admin/proveedores/$id/baja", buildJsonObject { put("motivo", motivo.trim()) })
                            PadronUi.listo("${p.txt("nombre")} quedó dado de baja (su historia se guarda)")
                        } else {
                            Sesion.enviar("/api/v1/admin/proveedores/$id/eliminar", JsonObject(emptyMap()))
                            PadronUi.listo("${p.txt("nombre")} fue eliminado del padrón")
                        }
                    } catch (t: Throwable) {
                        if (!baja) { baja = true; aviso = t.message } else error = t.message // tenía pagos: pasar a dar de baja
                    }
                    enviando = false
                }
            }) { Text(if (enviando) "Guardando…" else if (baja) "Dar de baja" else "Eliminar", color = Altiplano.Alerta) }
        },
        dismissButton = { TextButton(onClick = volver) { Text("Cancelar", color = Altiplano.TextoTerciario) } },
    )
}

// ---------------------------------------------------------------- traslado

/**
 * Trasladar (el productor llamó o vino a Huata) o corregir su traslado
 * programado o reciente: comunidad, fecha (si todavía no se hizo), cómo avisó y motivo.
 * Sin [proveedorId] primero se busca al productor.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun HojaTraslado(proveedorId: Long?, corregir: Boolean) {
    var datos by remember { mutableStateOf<JsonObject?>(null) }
    var traslado by remember { mutableStateOf<JsonObject?>(null) }
    var proveedor by remember { mutableStateOf<JsonObject?>(null) }
    var buscar by remember { mutableStateOf("") }
    var comunidad by remember { mutableStateOf<Long?>(null) }
    var canal by remember { mutableStateOf<String?>(null) }
    var dias by remember { mutableStateOf(0) }     // desde hoy + dias
    var fechaFija by remember { mutableStateOf<String?>(null) } // la del traslado que se corrige
    var motivo by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var enviando by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()

    LaunchedEffect(Unit) {
        try {
            val d = Sesion.consultar("/api/v1/admin/solicitudes")
            datos = d
            proveedor = proveedorId?.let { id -> d.lista("proveedores").firstOrNull { it.num("id").toLong() == id } }
            if (corregir && proveedorId != null) {
                val t = Sesion.consultar("/api/v1/admin/proveedores/$proveedorId/traslado").obj("traslado")!!
                traslado = t; comunidad = t.num("destino_id").toLong(); canal = t.txt("canal")
                motivo = t.txt("motivo"); fechaFija = t.txt("desde")
            }
        } catch (t: Throwable) { error = t.message }
    }

    Hoja(if (corregir) "Corregir traslado" else "Cambio de comunidad", { PadronUi.traslado = null }) {
        Text(if (corregir) "Si te equivocaste de lugar, de fecha o de cómo avisó, corrígelo aquí."
             else "El productor llamó o vino a Huata. El cambio queda hecho al guardar.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
        error?.let { AvisoCampo(it, esAlerta = true) }
        val d = datos ?: run { if (error == null) CircularProgressIndicator(color = Altiplano.Exito); return@Hoja }
        val p = proveedor
        if (p == null) {
            Campo(buscar, { buscar = it }, "Busca al productor (nombre o DNI)")
            if (buscar.trim().length >= 2) {
                val b = buscar.trim().lowercase()
                d.lista("proveedores").filter { "${it.txt("nombre")} ${it.txt("dni")}".lowercase().contains(b) }.take(8).forEach { x ->
                    FilaLista(x.txt("nombre"), "DNI ${x.txt("dni")} · ${x.txt("comunidad")}", onClick = { proveedor = x })
                }
            }
            return@Hoja
        }
        val t = traslado
        Tarjeta {
            Text(p.txt("nombre"), style = Texto.Fila, color = Altiplano.Texto)
            Text(
                if (t != null) "Sale de ${t.txt("origen")} · " + (if (t.txt("programado") == "true") "se traslada" else "se trasladó") + " el ${fechaCorta(t.txt("desde"))}"
                else "Ahora está en ${p.txt("comunidad")} (vehículo ${p.txt("vehiculo").ifEmpty { "—" }})",
                style = Texto.Apoyo, color = Altiplano.TextoTerciario,
            )
        }
        val origen = t?.num("origen_id")?.toLong() ?: p.num("comunidad_id").toLong()
        Text(if (corregir) "¿A QUÉ COMUNIDAD VA?" else "¿A QUÉ COMUNIDAD SE CAMBIA?", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            d.lista("comunidades").filter { it.num("id").toLong() != origen && it.txt("vehiculo").isNotEmpty() }.forEach { c ->
                Chip("${c.txt("nombre")} · ${c.txt("vehiculo")}", c.num("id").toLong() == comunidad) { comunidad = c.num("id").toLong() }
            }
        }
        Text("¿CÓMO AVISÓ?", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Chip("📞 Llamó por teléfono", canal == "LLAMADA") { canal = "LLAMADA" }
            Chip("🏠 Vino a Huata", canal == "OFICINA") { canal = "OFICINA" }
            if (t?.txt("canal") == "APP") Chip("📱 Por la app", canal == "APP") { canal = "APP" }
        }
        val yaHecho = t != null && t.txt("programado") != "true"
        if (yaHecho) Text("Ya se hizo, así que su fecha no se cambia. Si no iba, usa “Anular traslado”.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
        else {
            Text("¿DESDE CUÁNDO?", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                fechaFija?.let { f -> Chip("El ${fechaCorta(f)} (como estaba)", true.takeIf { dias < 0 } ?: false) { dias = -1 } }
                listOf(0 to "Hoy", 1 to "Mañana", 2 to "En 2 días", 3 to "En 3 días", 7 to "En una semana").forEach { (n, txt) ->
                    Chip(txt, dias == n && !(fechaFija != null && dias < 0)) { dias = n }
                }
            }
            LaunchedEffect(fechaFija) { if (fechaFija != null) dias = -1 }
        }
        Campo(motivo, { motivo = it.take(300) }, "Motivo (opcional)")
        error?.let { AvisoCampo(it, esAlerta = true) }

        BotonPrimario(if (enviando) "Guardando…" else if (corregir) "Guardar corrección" else "Guardar cambio", habilitado = !enviando) {
            val c = comunidad
            error = when { c == null -> "Elige la comunidad"; canal == null -> "Indica si llamó o vino a Huata"; else -> null }
            if (error != null || c == null) return@BotonPrimario
            val desde = if (dias < 0) fechaFija else fechaLima(-dias)
            scope.launch {
                enviando = true
                try {
                    val id = p.num("id").toLong()
                    if (corregir) {
                        val r = Sesion.enviar("/api/v1/admin/proveedores/$id/traslado", buildJsonObject {
                            put("comunidad_id", c); put("canal", canal); put("motivo", motivo.trim())
                            if (!yaHecho) put("desde", desde)
                        })
                        PadronUi.listo("✓ Traslado corregido: ${p.txt("nombre")} va a ${r.txt("comunidad")} desde el ${fechaCorta(r.txt("desde"))}")
                    } else {
                        val r = Sesion.enviar("/api/v1/admin/proveedores/$id/cambiar-comunidad", buildJsonObject {
                            put("comunidad_id", c); put("canal", canal); put("desde", desde)
                            motivo.trim().takeIf { it.isNotEmpty() }?.let { put("motivo", it) }
                        })
                        PadronUi.listo("✓ ${r.txt("proveedor")} pasa a ${r.txt("comunidad")} desde el ${fechaCorta(r.txt("vigente_desde"))}. " +
                            "Lo recoge el ${r.txt("vehiculo")}. Si te equivocaste, usa “Editar traslado”.")
                    }
                } catch (t: Throwable) { error = t.message }
                enviando = false
            }
        }
        if (corregir && t != null) TextButton(enabled = !enviando, onClick = {
            scope.launch {
                enviando = true
                try {
                    val r = Sesion.enviar("/api/v1/admin/proveedores/${p.num("id").toLong()}/traslado/anular", JsonObject(emptyMap()))
                    PadronUi.listo("Traslado anulado: ${p.txt("nombre")} se queda en ${r.txt("se_queda_en")}")
                } catch (t: Throwable) { error = t.message }
                enviando = false
            }
        }) { Text("Anular traslado", color = Altiplano.Alerta) }
    }
}
