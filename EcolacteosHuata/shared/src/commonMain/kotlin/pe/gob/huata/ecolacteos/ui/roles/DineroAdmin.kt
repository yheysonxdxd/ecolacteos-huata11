package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import kotlinx.serialization.json.putJsonArray
import kotlinx.serialization.json.add
import pe.gob.huata.ecolacteos.datos.Sesion
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

/**
 * Pestaña "Dinero" del admin: lo que entra (Ventas, ver NegocioAdmin) y lo
 * que sale (Pagos a proveedores). La boleta es semanal, de lunes a domingo,
 * y se paga el viernes en efectivo o por Yape.
 */
object DineroAdmin {
    var vista by mutableStateOf(0)            // 0 = ventas, 1 = pagos, 2 = lo que se debe
    var semana by mutableStateOf<String?>(null) // lunes elegido; null = última cerrada
    var version by mutableStateOf(0)
    var mensaje by mutableStateOf<String?>(null)
}

@Composable
fun DineroReal(e: EstadoAdmin) {
    Column(Modifier.fillMaxSize()) {
        Box(Modifier.padding(start = Medidas.Pantalla, end = Medidas.Pantalla, top = 12.dp)) {
            Segmentos(listOf("Ventas", "Pagos", "Lo que se debe"), DineroAdmin.vista) { DineroAdmin.vista = it; DineroAdmin.mensaje = null }
        }
        Box(Modifier.weight(1f)) {
            when (DineroAdmin.vista) {
                0 -> NegocioReal(e)
                1 -> PagosProveedores()
                else -> DeudasProveedores()
            }
        }
    }
}

@Composable
private fun PagosProveedores() {
    var pagarTodos by remember { mutableStateOf<List<JsonObject>?>(null) }
    var abierta by remember { mutableStateOf<JsonObject?>(null) }
    val ruta = "/api/v1/admin/pagos" + (DineroAdmin.semana?.let { "?semana=$it" } ?: "")

    CargaServidor(ruta, clave = DineroAdmin.version) { d, _ ->
        val boletas = d.lista("boletas")
        val pendientes = boletas.filter { it.txt("estado") == "PENDIENTE" }

        PantallaScroll {
            // semana por semana con flechitas, como en la web (no hay "siguiente" después de la última cerrada)
            fun ir(lunes: String) { DineroAdmin.semana = lunes; DineroAdmin.mensaje = null }
            Flechitas(
                "Semana ${fechaCorta(d.txt("inicio"))} – ${fechaCorta(d.txt("fin"))}",
                "de lunes a domingo · se paga el viernes",
                onAnterior = d.txt("anterior").takeIf { it.isNotEmpty() }?.let { { ir(it) } },
                onSiguiente = d.txt("siguiente").takeIf { it.isNotEmpty() }?.let { { ir(it) } },
            )
            // que no se olvide ninguna semana: las otras con boletas sin pagar
            val otras = d.lista("otras_con_pendientes")
            if (otras.isNotEmpty()) Tarjeta(borde = Altiplano.AvisoBorde) {
                Text(
                    if (otras.size == 1) "Hay otra semana con pagos pendientes:" else "Hay ${otras.size} semanas más con pagos pendientes:",
                    style = Texto.Cuerpo, color = Altiplano.Aviso,
                )
                Spacer(Modifier.height(8.dp))
                Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    otras.forEach { s ->
                        ChipSemana(
                            "${fechaCorta(s.txt("inicio"))} – ${fechaCorta(s.txt("fin"))} · ${s.num("pendientes").toInt()} por ${soles(s.num("monto"))}",
                            elegida = false, alerta = true,
                        ) { ir(s.txt("inicio")) }
                    }
                }
            }

            DineroAdmin.mensaje?.let { AvisoCampo(it) }
            TarjetaDato(
                "POR PAGAR", soles(d.num("pendiente")),
                apoyo = "Semana ${fechaCorta(d.txt("inicio"))} – ${fechaCorta(d.txt("fin"))} · " +
                    "total ${soles(d.num("total"))} · pagado ${soles(d.num("pagado"))}",
                destacada = true,
            )
            if (pendientes.isNotEmpty()) {
                BotonPrimario("Pagar a los ${pendientes.size} pendientes (${soles(d.num("pendiente"))})") { pagarTodos = pendientes }
            } else if (boletas.isNotEmpty()) {
                AvisoCampo("Esta semana ya está pagada completa.")
            }
            if (boletas.isEmpty()) AvisoCampo("No hay nada que pagar en esta semana.")

            boletas.forEach { b ->
                val pagada = b.txt("estado") == "PAGADO"
                FilaLista(
                    b.txt("proveedor"),
                    "${b.txt("comunidad")} · ${formatear(b.num("litros"))} L" +
                        if (pagada) " · pagado el ${fechaCorta(b.txt("pagado_el"))} (${metodoTexto(b.txt("metodo"))})" else "",
                    onClick = { abierta = b },
                    borde = if (pagada) Altiplano.Borde else Altiplano.VerdeBorde,
                ) {
                    Column(horizontalAlignment = Alignment.End) {
                        Text(soles(b.num("monto")), style = Texto.Fila, color = Altiplano.Texto)
                        Spacer(Modifier.height(4.dp))
                        ChipEstado(if (pagada) Estado.PAGADO else Estado.PENDIENTE)
                    }
                }
            }
        }
    }

    pagarTodos?.let { lista ->
        DialogoPago(
            titulo = "Pagar a ${lista.size} proveedores",
            texto = "Total ${soles(lista.sumOf { it.num("monto") })}. ¿Cómo se pagó?",
            ids = lista.map { it.num("id").toLong() },
            onCerrar = { pagarTodos = null },
        )
    }
    abierta?.let { b -> HojaBoleta(b, onCerrar = { abierta = null }) }
}

/**
 * Todo lo que se le debe a cada proveedor, sin ir semana por semana (como Dinero → Lo que se debe
 * en la web): el que espera desde hace más arriba; al tocarlo se ven sus semanas sin pagar.
 */
@Composable
private fun DeudasProveedores() {
    var pagar by remember { mutableStateOf<Triple<String, String, List<Long>>?>(null) } // título, texto, boletas
    var verDias by remember { mutableStateOf<JsonObject?>(null) }
    val abiertos = remember { mutableStateMapOf<String, Boolean>() }
    var buscar by remember { mutableStateOf("") }

    CargaServidor("/api/v1/admin/pagos/deudas", clave = DineroAdmin.version) { d, _ ->
        val provs = d.lista("proveedores")
        val q = buscar.trim().lowercase()
        PantallaScroll {
            DineroAdmin.mensaje?.let { AvisoCampo(it) }
            TarjetaDato(
                "SE DEBE A PROVEEDORES", if (d.num("total") > 0) soles(d.num("total")) else "✓ No se debe nada",
                apoyo = "${provs.size} ${if (provs.size == 1) "proveedor" else "proveedores"} · ${d.num("cuantas").toInt()} boletas sin pagar · " +
                    "semanas cerradas hasta el ${fechaCorta(d.txt("hasta"))}",
                destacada = true,
            )
            if (d.num("cuantas") > 1) BotonPrimario("Pagar todo (${soles(d.num("total"))})") {
                pagar = Triple("Pagar todo lo que se debe", "${soles(d.num("total"))} a ${provs.size} proveedores. ¿Cómo se pagó?",
                    provs.flatMap { p -> p.lista("semanas").map { it.num("id").toLong() } })
            }
            if (provs.size > 5) Campo(buscar, { buscar = it.take(40) }, "Buscar por nombre, DNI, comunidad o vehículo")
            if (provs.isEmpty()) AvisoCampo("No se le debe a nadie: todas las semanas cerradas están pagadas.")
            provs.filter { p -> q.isEmpty() || listOf("nombre", "dni", "comunidad", "vehiculo").any { p.txt(it).lowercase().contains(q) } }.forEach { p ->
                val clave = p.txt("proveedor_id")
                val semanas = p.lista("semanas")
                val abierto = abiertos[clave] == true
                Tarjeta(borde = if (abierto) Altiplano.VerdeBorde else Altiplano.Borde, onClick = { abiertos[clave] = !abierto }) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(p.txt("nombre"), style = Texto.Fila, color = Altiplano.Texto)
                            Text("${p.txt("comunidad")} · ${p.txt("vehiculo")} · se le debe desde el ${fechaCorta(p.txt("desde"))}",
                                style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                            Text((if (semanas.size == 1) "1 semana" else "${semanas.size} semanas") + if (abierto) " ▴" else " ▾",
                                style = Texto.Apoyo, color = Altiplano.Aviso)
                        }
                        Column(horizontalAlignment = Alignment.End) {
                            Text(soles(p.num("total")), style = Texto.Fila, color = Altiplano.Texto)
                            TextButton(onClick = {
                                pagar = Triple("Pagar a ${p.txt("nombre")}", "Todo lo que se le debe: ${soles(p.num("total"))}. ¿Cómo se pagó?",
                                    semanas.map { it.num("id").toLong() })
                            }) { Text("Pagar", color = Altiplano.Exito) }
                        }
                    }
                    if (abierto) semanas.forEach { s ->
                        HorizontalDivider(color = Altiplano.BordeSuave)
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text("Semana ${fechaCorta(s.txt("inicio"))} – ${fechaCorta(s.txt("fin"))}", style = Texto.Cuerpo, color = Altiplano.Texto)
                                Text("${formatear(s.num("litros"))} L aceptados · ${soles(s.num("monto"))}", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                            }
                            TextButton(onClick = {
                                verDias = buildJsonObject {
                                    put("id", s.num("id").toLong()); put("proveedor", p.txt("nombre")); put("dni", p.txt("dni"))
                                    put("comunidad", p.txt("comunidad")); put("vehiculo", p.txt("vehiculo"))
                                    put("monto", s.num("monto")); put("litros", s.num("litros")); put("estado", "PENDIENTE")
                                }
                            }) { Text("Ver días", color = Altiplano.CelesteAcento) }
                            if (semanas.size > 1) TextButton(onClick = {
                                pagar = Triple("Pagar a ${p.txt("nombre")}", "Semana del ${fechaCorta(s.txt("inicio"))}: ${soles(s.num("monto"))}. ¿Cómo se pagó?",
                                    listOf(s.num("id").toLong()))
                            }) { Text("Pagar esta", color = Altiplano.Exito) }
                        }
                    }
                }
            }
        }
    }
    pagar?.let { (titulo, texto, ids) -> DialogoPago(titulo, texto, ids, onCerrar = { pagar = null }) }
    verDias?.let { b -> HojaBoleta(b, onCerrar = { verDias = null }) }
}

/** Pide efectivo o Yape y registra el pago de las boletas [ids]; [alPagar] avisa a quien lo abrió (la ficha del Padrón se recarga). */
@Composable
internal fun DialogoPago(titulo: String, texto: String, ids: List<Long>, onCerrar: () -> Unit, alPagar: (String) -> Unit = {}) {
    val scope = rememberCoroutineScope()
    var enviando by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    fun pagar(metodo: String) {
        scope.launch {
            enviando = true; error = null
            try {
                val r = Sesion.enviar("/api/v1/admin/pagos/pagar", buildJsonObject {
                    putJsonArray("ids") { ids.forEach { add(it) } }
                    put("metodo", metodo)
                })
                DineroAdmin.mensaje = "✓ Se registraron ${r.txt("pagadas")} pagos por ${soles(r.num("total"))} (${metodoTexto(metodo)})"
                DineroAdmin.version++
                alPagar(DineroAdmin.mensaje!!)
                onCerrar()
            } catch (t: Throwable) {
                error = t.message ?: "sin conexión"
            }
            enviando = false
        }
    }

    AlertDialog(
        onDismissRequest = { if (!enviando) onCerrar() },
        containerColor = Altiplano.Fondo,
        title = { Text(titulo, color = Altiplano.Texto) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text(texto, color = Altiplano.TextoTerciario)
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    BotonMetodo("Efectivo", Modifier.weight(1f), !enviando) { pagar("EFECTIVO") }
                    BotonMetodo("Yape", Modifier.weight(1f), !enviando) { pagar("YAPE") }
                }
                if (enviando) Text("Registrando…", color = Altiplano.TextoTerciario)
                error?.let { Text(it, color = Altiplano.Alerta) }
            }
        },
        confirmButton = {},
        dismissButton = { TextButton(onClick = onCerrar, enabled = !enviando) { Text("Cancelar", color = Altiplano.TextoTerciario) } },
    )
}

/** Detalle día por día de una boleta (para reclamos) y pago individual. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun HojaBoleta(b: JsonObject, onCerrar: () -> Unit) {
    var detalle by remember { mutableStateOf<JsonObject?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var pagar by remember { mutableStateOf(false) }
    LaunchedEffect(b) {
        try { detalle = Sesion.consultar("/api/v1/admin/pagos/${b.num("id").toLong()}") }
        catch (t: Throwable) { error = t.message ?: "sin conexión" }
    }

    ModalBottomSheet(
        onDismissRequest = onCerrar, containerColor = Color(0xFF142333),
        sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true),
    ) {
        Column(
            Modifier.verticalScroll(rememberScrollState()).padding(horizontal = Medidas.Pantalla).padding(bottom = 22.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Text(b.txt("proveedor"), style = Texto.TituloPantalla, color = Altiplano.Texto)
            Text("DNI ${b.txt("dni")} · ${b.txt("comunidad")} · ${b.txt("vehiculo")}", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            error?.let { AvisoCampo("No se pudo leer el detalle: $it", esAlerta = true) }
            detalle?.let { d ->
                d.lista("dias").forEach { dia ->
                    val nota = dia.txt("nota")
                    FilaValor(
                        fechaCorta(dia.txt("fecha")) + if (nota.isNotEmpty()) " · $nota" else " · ${formatear(dia.num("litros"))} L",
                        if (dia.num("monto") > 0) soles(dia.num("monto")) else "—",
                        if (nota == "rechazado en calidad") Altiplano.Alerta else Altiplano.Texto,
                    )
                }
                HorizontalDivider(color = Altiplano.BordeSuave)
                FilaValor("Total · ${formatear(d.num("litros"))} L aceptados", soles(d.num("monto")), Altiplano.Exito)
            }
            if (b.txt("estado") == "PAGADO") {
                AvisoCampo("Pagado el ${fechaCorta(b.txt("pagado_el"))} en ${metodoTexto(b.txt("metodo")).lowercase()}" +
                    (b.txt("pagado_por").takeIf { it.isNotEmpty() }?.let { " · registró $it" } ?: ""))
            } else {
                BotonPrimario("Pagar ${soles(b.num("monto"))}") { pagar = true }
            }
        }
    }
    if (pagar) DialogoPago(
        titulo = "Pagar a ${b.txt("proveedor")}",
        texto = "${soles(b.num("monto"))} por ${formatear(b.num("litros"))} L. ¿Cómo se pagó?",
        ids = listOf(b.num("id").toLong()),
        onCerrar = { pagar = false; onCerrar() },
    )
}

internal fun metodoTexto(m: String) = when (m) {
    "YAPE" -> "Yape"
    "EFECTIVO" -> "Efectivo"
    else -> m
}

@Composable
private fun BotonMetodo(texto: String, modifier: Modifier, habilitado: Boolean, onClick: () -> Unit) {
    Surface(
        onClick = { if (habilitado) onClick() }, modifier = modifier.height(56.dp), shape = RoundedCornerShape(14.dp),
        color = Altiplano.VerdeMarcaSuave, border = BorderStroke(1.dp, Altiplano.VerdeMarca),
    ) {
        Box(contentAlignment = Alignment.Center) { Text(texto, style = Texto.Fila, color = Altiplano.Texto) }
    }
}

@Composable
private fun ChipSemana(texto: String, elegida: Boolean, alerta: Boolean, onClick: () -> Unit) {
    Surface(
        onClick = onClick, shape = RoundedCornerShape(12.dp),
        color = if (elegida) Altiplano.VerdeMarcaSuave else Altiplano.Superficie,
        border = BorderStroke(1.dp, when { elegida -> Altiplano.VerdeMarca; alerta -> Altiplano.AvisoBorde; else -> Altiplano.Borde }),
    ) {
        Text(texto, style = Texto.Cuerpo, color = if (elegida) Altiplano.Texto else Altiplano.TextoSecundario,
            modifier = Modifier.padding(horizontal = 12.dp, vertical = 9.dp))
    }
}
