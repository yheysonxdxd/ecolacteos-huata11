package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import kotlinx.serialization.json.*
import pe.gob.huata.ecolacteos.datos.Sesion
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

/** Pestaña elegida dentro de cada opción del menú del admin (como en la web de oficina). */
object AdminPestanas {
    var acopio by mutableStateOf(0)
    var dinero by mutableStateOf(-1) // -1 = el menú de tarjetas (DineroMenu)
    var padron by mutableStateOf(0)
    var planta by mutableStateOf(-1) // -1 = el menú de tarjetas (PlantaMenu)
    var mas by mutableStateOf(-1)    // -1 = el menú de tarjetas (MasMenu)
    var calidad by mutableStateOf(0)

    /** Tocar otra vez la opción de abajo vuelve a su menú de tarjetas (o a su vista principal). */
    fun volverAlMenu(ruta: String) {
        when (ruta) {
            "admin/planta" -> planta = -1
            "admin/mas" -> mas = -1
            "admin/dinero" -> dinero = -1
            "admin/acopio" -> AcopioCalendario.conciliar = false
            "compras/ventas" -> ComprasReal.pestanaVentas = -1
            "compras/almacen" -> ComprasReal.pestanaAlmacen = -1
            "compras/orden" -> CompraInsumos.pestana = -1
            "compras/mas" -> ComprasMas.abierta = -1
        }
    }
}

/** Acopio → Totales → Calendario: el mes a la vista y el día tocado (vacíos = el último con entregas). */
object AcopioCalendario {
    var conciliar by mutableStateOf(false) // la tarjeta "Campo vs. planta" abierta
    var activo by mutableStateOf(false)
    var mes by mutableStateOf<String?>(null)
    var fecha by mutableStateOf<String?>(null)
}

/**
 * Admin con datos de la base. Cinco opciones abajo: Inicio (lo de hoy y botones grandes para
 * lo que más se hace), Acopio (Totales · Conciliación), Dinero (Ventas y pagos · Costos),
 * Planta (tarjetas en el orden del día) y Más (Calidad, Padrón y Ajustes en tarjetas).
 * [irA] cambia de opción de abajo por su ruta.
 */
@Composable
fun ContenidoAdminReal(ruta: String, e: EstadoAdmin, irA: (String) -> Unit = {}) {
    val a = AdminPestanas
    when (ruta) {
        "admin/inicio" -> InicioAdmin(irA)
        "admin/mas" -> MasMenu()
        "admin/acopio" -> AcopioAdmin()
        // adulteraciones (precio reducido o baja) y los demás rechazos, que se resuelven capacitando
        "admin/calidad" -> ConPestanas(listOf("Adulteraciones", "Rechazos"), a.calidad, { a.calidad = it }) {
            if (a.calidad == 0) AdulteracionesReal() else RechazosReal()
        }
        "admin/dinero" -> DineroMenu(e)
        "admin/padron" -> ConPestanas(listOf("Proveedores", "Trabajadores", "Cambios"), a.padron, { a.padron = it }) {
            if (a.padron == 2) SolicitudesReal() else PadronReal(a.padron == 1)
        }
        // en el orden del día de la planta: comprar → guardar → producir → vender; el kardex al final
        "admin/planta" -> PlantaMenu()
        "admin/config" -> ConfiguracionReal()
        // rutas anteriores (por si alguna pantalla las usa todavía)
        "admin/totales" -> TotalesReal(e)
        "admin/negocio" -> DineroReal(e)
        "admin/padron-old" -> PadronReal(false)
        "admin/solicitudes" -> SolicitudesReal()
        "admin/conciliacion" -> ConciliacionReal()
        else -> CostosReal()
    }
}

/** Un mes de lunes a domingo: cada día con sus litros (verde más fuerte = más leche); tocar uno muestra sus totales. */
@Composable
internal fun CalendarioMes(m: JsonObject) {
    val cal = AcopioCalendario
    val dias = m.lista("dias").associateBy { it.txt("fecha") }
    val max = maxOf(dias.values.maxOfOrNull { it.num("litros") } ?: 0.0, 1.0)
    val (anio, mes) = m.txt("mes").split("-").map { it.toInt() }
    val ultimoDia = when (mes) { 2 -> if (anio % 4 == 0 && (anio % 100 != 0 || anio % 400 == 0)) 29 else 28; 4, 6, 9, 11 -> 30; else -> 31 }
    // día de la semana del 1 (lunes = 0), con la fórmula de Zeller
    val antes = run {
        val (a, mm) = if (mes < 3) (anio - 1) to (mes + 12) else anio to mes
        val h = (1 + 13 * (mm + 1) / 5 + a % 100 + (a % 100) / 4 + (a / 100) / 4 + 5 * (a / 100)) % 7 // 0 = sábado
        (h + 5) % 7
    }
    val celdas: List<Int?> = List(antes) { null } + (1..ultimoDia).toList()
    Flechitas(
        nombreMes(m.txt("mes")).replaceFirstChar { it.uppercase() },
        "${formatear(m.num("litros"), 0)} L en el mes · ${dias.size} días con acopio",
        onAnterior = { cal.mes = m.txt("anterior") },
        onSiguiente = m.txt("siguiente").takeIf { it.isNotEmpty() }?.let { { cal.mes = it } },
    )
    Tarjeta {
        Row { listOf("lu", "ma", "mi", "ju", "vi", "sá", "do").forEach { Text(it, Modifier.weight(1f), style = Texto.Apoyo, color = Altiplano.TextoTerciario, textAlign = TextAlign.Center) } }
        celdas.chunked(7).forEach { semana ->
            Row(horizontalArrangement = Arrangement.spacedBy(4.dp), modifier = Modifier.padding(top = 4.dp)) {
                (0 until 7).forEach { k ->
                    val dia = semana.getOrNull(k)
                    if (dia == null) Spacer(Modifier.weight(1f)) else {
                        val fecha = "${m.txt("mes")}-${dia.toString().padStart(2, '0')}"
                        val x = dias[fecha]
                        val futuro = fecha > m.txt("hoy")
                        val elegido = fecha == cal.fecha
                        val nivel = x?.let { (it.num("litros") / max).coerceIn(0.15, 1.0).toFloat() } ?: 0f
                        Column(
                            Modifier.weight(1f).height(50.dp).clip(RoundedCornerShape(8.dp))
                                .background(if (x != null) Altiplano.Exito.copy(alpha = 0.15f + 0.5f * nivel) else Altiplano.Superficie)
                                .border(if (elegido) 2.dp else 1.dp, if (elegido) Altiplano.Exito else Altiplano.Borde, RoundedCornerShape(8.dp))
                                .clickable(enabled = !futuro) { cal.fecha = fecha }
                                .padding(4.dp),
                        ) {
                            Text("$dia", style = Texto.Apoyo, color = if (fecha == m.txt("hoy")) Altiplano.Aviso else if (futuro) Altiplano.TextoApagado else Altiplano.TextoSecundario)
                            x?.let { Text(formatear(it.num("litros"), 0), style = Texto.Apoyo, color = Altiplano.Texto, maxLines = 1) }
                        }
                    }
                }
            }
        }
        Spacer(Modifier.height(6.dp))
        Text("Toca un día para ver sus totales abajo.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
    }
}

@Composable
private fun TotalesReal(e: EstadoAdmin) {
    val cal = AcopioCalendario
    // con el calendario: primero el mes (litros por día) y luego los totales del día tocado
    var mesDatos by remember { mutableStateOf<JsonObject?>(null) }
    var errorCal by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(cal.activo, cal.mes) {
        if (!cal.activo) return@LaunchedEffect
        errorCal = null
        try {
            val m = Sesion.consultar("/api/v1/admin/acopio/calendario" + (cal.mes?.let { "?mes=$it" } ?: ""))
            if (cal.fecha?.startsWith(m.txt("mes")) != true) cal.fecha = m.lista("dias").lastOrNull()?.txt("fecha") ?: (m.txt("mes") + "-01")
            mesDatos = m
        } catch (t: Throwable) { errorCal = t.message }
    }
    val ruta = when {
        !cal.activo -> "/api/v1/admin/totales?periodo=" + listOf("dia", "semana", "mes")[e.periodo]
        cal.fecha != null -> "/api/v1/admin/totales?fecha=${cal.fecha}"
        else -> null
    }
    val botones: @Composable () -> Unit = {
        Segmentos(listOf("Día", "Semana", "Mes", "Calendario"), if (cal.activo) 3 else e.periodo) {
            if (it == 3) cal.activo = true else { cal.activo = false; e.periodo = it }
        }
    }
    if (ruta == null) { PantallaScroll { botones(); errorCal?.let { AvisoCampo(it, esAlerta = true) } ?: Text("Cargando el mes…", style = Texto.Apoyo, color = Altiplano.TextoTerciario) }; return }
    CargaServidor(ruta) { d, _ ->
        val vehiculos = d.lista("vehiculos")
        val maximo = maxOf(vehiculos.maxOfOrNull { it.num("litros") } ?: 0.0, 1.0)
        PantallaScroll {
            botones()
            if (cal.activo) mesDatos?.let { CalendarioMes(it) }
            TarjetaDato(
                "LITROS ACOPIADOS", formatear(d.num("litros"), 0), "L",
                "${soles(d.num("a_pagar"))} a pagar a proveedores · " +
                    if (d.txt("desde") == d.txt("hasta")) fechaCorta(d.txt("desde"))
                    else "${fechaCorta(d.txt("desde"))} – ${fechaCorta(d.txt("hasta"))}",
                destacada = true,
            )
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Tarjeta(Modifier.weight(1f)) { MiniCifra("Aceptados", "${formatear(d.num("aceptados_pct"))} %", Altiplano.Exito) }
                Tarjeta(Modifier.weight(1f)) { MiniCifra("Rechazados", "${formatear(d.num("rechazados_pct"))} %", Altiplano.Alerta) }
                Tarjeta(Modifier.weight(1f)) { MiniCifra("Adulterada", "${formatear(d.num("adulterada_pct"))} %", Altiplano.Alerta) }
            }
            val pend = d.num("pendientes").toInt()
            if (pend > 0) AvisoCampo("$pend entregas esperan diagnóstico de Calidad.")
            Seccion("Por vehículo · litros y calidad")
            vehiculos.forEach { v ->
                val conAnalisis = v.num("analisis") > 0
                val rechazo = v.num("rechazo_pct")
                val adult = v.num("adulteradas").toInt()
                val malo = rechazo > 8 || adult > 0
                Tarjeta(borde = if (malo) Altiplano.AlertaBorde else Altiplano.Borde) {
                    Row {
                        Text(
                            "${v.txt("codigo")} · ${v.txt("acopiador").ifEmpty { "sin acopiador" }}",
                            style = Texto.Fila, color = Altiplano.Texto, modifier = Modifier.weight(1f),
                        )
                        Text("${formatear(v.num("litros"), 0)} L", style = Texto.Fila, color = Altiplano.Texto)
                    }
                    Spacer(Modifier.height(8.dp)); BarraNivel((v.num("litros") / maximo).toFloat()); Spacer(Modifier.height(5.dp))
                    Text(v.txt("ruta"), style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                    // promedios de los análisis de Calidad en estas mismas fechas
                    if (conAnalisis) {
                        Spacer(Modifier.height(10.dp))
                        Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                            MiniCifra("Grasa", "${formatear(v.num("grasa"), 2)} %")
                            MiniCifra("Densidad", formatear(v.num("densidad"), 3))
                            MiniCifra("Temp.", "${formatear(v.num("temperatura"))} °C")
                            MiniCifra("Rechazo", "${formatear(rechazo)} %", if (rechazo > 8) Altiplano.Alerta else Altiplano.Texto)
                        }
                        if (adult > 0) {
                            Spacer(Modifier.height(6.dp))
                            Text("$adult ${if (adult == 1) "entrega adulterada" else "entregas adulteradas"}", style = Texto.Apoyo, color = Altiplano.Alerta)
                        }
                    } else {
                        Spacer(Modifier.height(4.dp))
                        Text("Sin análisis de Calidad en estas fechas", style = Texto.Apoyo, color = Altiplano.TextoApagado)
                    }
                }
            }
        }
    }
}

@Composable
internal fun PadronReal(verTrabajadores: Boolean) {
    CargaServidor("/api/v1/admin/padron", clave = PadronUi.version) { d, recargar ->
        val provs = d.lista("proveedores")
        val trab = d.lista("trabajadores")
        PantallaScroll {
            AltasPadron(verTrabajadores, recargar)
            if (!verTrabajadores) {
                // tocar al proveedor: su ficha; en la fila, cambiar comunidad o editar su traslado
                ProveedoresPadron(provs)
            } else {
                trab.forEach { u ->
                    FilaLista(u.txt("nombre"), "DNI ${u.txt("dni")} · ${u.txt("rol").lowercase().replaceFirstChar { it.uppercase() }}", onClick = { TrabajadorElegido.valor = u }) {
                        ChipEstado(if (u.txt("activo") == "true") Estado.ACEPTADO else Estado.RECHAZADO)
                    }
                }
                SesionesAbiertas()
            }
        }
    }
}


@Composable
internal fun SolicitudesReal() {
    var version by remember { mutableStateOf(0) }
    var mensaje by remember { mutableStateOf<String?>(null) }
    var enviando by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()

    fun resolver(id: String, aprobar: Boolean) {
        scope.launch {
            enviando = id; mensaje = null
            try {
                val r = Sesion.enviar("/api/v1/admin/solicitudes/$id/resolver", buildJsonObject { put("aprobar", aprobar) })
                mensaje = if (aprobar) "✓ Aprobada · pasa al ${r.txt("vehiculo")} desde el ${fechaCorta(r.txt("vigente_desde"))}"
                          else "Solicitud rechazada"
                version++
            } catch (t: Throwable) {
                mensaje = "No se pudo guardar: ${t.message}"
            } finally { enviando = null }
        }
    }

    CargaServidor("/api/v1/admin/solicitudes", clave = version + PadronUi.version * 1000) { d, _ ->
        val lista = d.lista("solicitudes")
        PantallaScroll {
            // los productores casi no tienen internet: llaman o vienen a Huata y aquí se registra
            BotonPrimario("+ Registrar cambio (llamó o vino a Huata)") { PadronUi.traslado = null to false }
            PadronUi.mensaje?.let { AvisoCampo(it, esAlerta = it.startsWith("No se pudo")) }
            mensaje?.let { AvisoCampo(it, esAlerta = it.startsWith("No se pudo")) }
            if (lista.isEmpty()) AvisoCampo("Todavía no hay cambios de comunidad.")
            lista.forEach { s ->
                val estado = s.txt("estado")
                Tarjeta(borde = if (estado == "PENDIENTE") Altiplano.VerdeBorde else Altiplano.Borde) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(s.txt("proveedor"), style = Texto.Fila, color = Altiplano.Texto, modifier = Modifier.weight(1f))
                        ChipEstado(when (estado) { "APROBADA" -> Estado.ACEPTADO; "RECHAZADA" -> Estado.RECHAZADO; else -> Estado.PENDIENTE })
                    }
                    Spacer(Modifier.height(6.dp))
                    Text("${s.txt("origen")}  →  ${s.txt("destino")}", style = Texto.Cuerpo, color = Altiplano.TextoSecundario)
                    s.txt("motivo").takeIf { it.isNotEmpty() }?.let { Text("“$it”", style = Texto.Apoyo, color = Altiplano.TextoTerciario) }
                    Text(
                        (when (s.txt("canal")) { "LLAMADA" -> "📞 Llamó"; "OFICINA" -> "🏠 Vino a Huata"; else -> "📱 Por la app" }) +
                            (s.txt("registrada_por").takeIf { it.isNotEmpty() }?.let { " · registró $it" } ?: "") +
                            " · para el ${fechaCorta(s.txt("solicitada_para"))}",
                        style = Texto.Apoyo, color = Altiplano.TextoTerciario,
                    )
                    when {
                        estado == "PENDIENTE" && enviando == s.txt("id") -> {
                            Spacer(Modifier.height(12.dp)); LinearProgressIndicator(Modifier.fillMaxWidth(), color = Altiplano.Exito)
                        }
                        estado == "PENDIENTE" -> {
                            Spacer(Modifier.height(12.dp))
                            BotonesDecision("Rechazar", "Aprobar", { resolver(s.txt("id"), false) }, { resolver(s.txt("id"), true) })
                        }
                        estado == "APROBADA" -> {
                            Spacer(Modifier.height(8.dp))
                            Text("Vigente desde el ${fechaCorta(s.txt("vigente_desde"))}", style = Texto.Apoyo, color = Altiplano.Exito)
                        }
                    }
                }
            }
            HojasPadron()
        }
    }
}

/** Día que se concilia (null = el último con acopio) y el mensaje de lo último guardado. */
private object Conciliacion {
    var fecha by mutableStateOf<String?>(null)
    var version by mutableStateOf(0)
    var mensaje by mutableStateOf<String?>(null)
}

/**
 * Campo vs. planta día por día, como en la web: por cada vehículo que acopió
 * ese día se anotan los litros que llegaron a planta y se ve la diferencia.
 */
@Composable
internal fun ConciliacionReal() {
    val c = Conciliacion
    CargaServidor("/api/v1/admin/conciliacion" + (c.fecha?.let { "?fecha=$it" } ?: ""), clave = c.version) { d, _ ->
        val fecha = d.txt("fecha")
        val filas = d.lista("filas")
        val hoy = fechaLima()
        PantallaScroll {
            Flechitas(
                "Acopio del ${fechaCorta(fecha)}" + if (fecha == hoy) " (hoy)" else "",
                "tolerancia ±${formatear(d.num("tolerancia"))} %",
                onAnterior = { c.fecha = sumarDias(fecha, -1); c.mensaje = null },
                onSiguiente = if (fecha < hoy) ({ c.fecha = sumarDias(fecha, 1); c.mensaje = null }) else null,
            )
            c.mensaje?.let { AvisoCampo(it, esAlerta = it.startsWith("No se")) }
            if (filas.isEmpty()) AvisoCampo("Ese día no hubo acopio en campo.")
            if (filas.any { it.txt("litros_planta").isEmpty() }) {
                Text("Escribe cuántos litros llegaron a planta de cada vehículo (lo que marcó la balanza o la tina).",
                    style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            }
            filas.forEach { v -> FilaConciliacion(v, fecha) }
        }
    }
}

@Composable
private fun FilaConciliacion(v: JsonObject, fecha: String) {
    val scope = rememberCoroutineScope()
    val anotado = v.txt("litros_planta").isNotEmpty()
    var litros by remember(v, fecha) { mutableStateOf(if (anotado) v.txt("litros_planta") else "") }
    var editando by remember(v, fecha) { mutableStateOf(!anotado) }
    var enviando by remember { mutableStateOf(false) }
    val fuera = v.txt("fuera_tolerancia") == "1" || v.txt("fuera_tolerancia") == "true"

    Tarjeta(borde = when { !anotado -> Altiplano.AvisoBorde; fuera -> Altiplano.AlertaBorde; else -> Altiplano.Borde }) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(v.txt("codigo"), style = Texto.Fila, color = Altiplano.Texto, modifier = Modifier.weight(1f))
            if (anotado && !editando) TextButton(onClick = { editando = true }) { Text("Corregir", color = Altiplano.CelesteAcento) }
        }
        Spacer(Modifier.height(6.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(20.dp)) {
            MiniCifra("Campo", "${formatear(v.num("litros_campo"))} L")
            if (anotado) {
                MiniCifra("Planta", "${formatear(v.num("litros_planta"))} L")
                MiniCifra("Diferencia", formatear(v.num("diferencia_pct"), 2) + " %", if (fuera) Altiplano.Alerta else Altiplano.Exito)
            }
        }
        if (editando) {
            Spacer(Modifier.height(10.dp))
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Campo(litros, { litros = it.filter { ch -> ch.isDigit() || ch == '.' || ch == ',' }.take(8) },
                    "Litros en planta", teclado = KeyboardType.Decimal, modifier = Modifier.weight(1f))
                TextButton(enabled = !enviando, onClick = {
                    val l = litros.replace(",", ".").toDoubleOrNull()
                    if (l == null || l < 0) {
                        Conciliacion.mensaje = "No se guardó: escribe los litros que llegaron de ${v.txt("codigo")}"
                        return@TextButton
                    }
                    scope.launch {
                        enviando = true
                        try {
                            val r = Sesion.enviar("/api/v1/admin/conciliacion", buildJsonObject {
                                put("fecha", fecha); put("vehiculo_id", v.num("vehiculo_id").toLong()); put("litros_planta", l)
                            })
                            val f = r.txt("fuera_tolerancia") == "true" || r.txt("fuera_tolerancia") == "1"
                            Conciliacion.mensaje = "✓ ${v.txt("codigo")}: ${formatear(r.num("litros_planta"))} L en planta · " +
                                "diferencia ${formatear(r.num("diferencia_pct"), 2)} %" + if (f) " (fuera de tolerancia)" else ""
                            Conciliacion.version++
                        } catch (t: Throwable) { Conciliacion.mensaje = "No se pudo guardar: ${t.message}" }
                        enviando = false
                    }
                }) { Text(if (enviando) "…" else "Guardar", color = Altiplano.Exito) }
            }
        }
    }
}

@Composable
internal fun CostosReal() {
    CargaServidor("/api/v1/admin/costos") { d, _ ->
        val leche = d.num("costo_leche_litro")
        val insumos = d.num("costo_insumos_litro")
        PantallaScroll {
            TarjetaDato(
                "COSTO POR LITRO PROCESADO", soles(leche + insumos),
                apoyo = "${formatear(d.num("litros_procesados"), 0)} L procesados desde el ${fechaCorta(d.txt("desde"))}",
                destacada = true,
            ) {
                Spacer(Modifier.height(10.dp))
                FilaValor("Leche", soles(leche))
                FilaValor("Insumos (según recetas y última compra)", soles(insumos))
            }
            Tarjeta {
                Seccion("Rendimiento real vs. esperado")
                d.lista("rendimiento").forEach { r ->
                    val bajo = r.num("real_100l") < r.num("esperado_100l")
                    FilaValor("${r.txt("producto")} · esperado por 100 L", formatear(r.num("esperado_100l")))
                    FilaValor("${r.txt("producto")} · real (${r.num("lotes").toInt()} lotes)", formatear(r.num("real_100l")), if (bajo) Altiplano.Aviso else Altiplano.Exito)
                }
            }
            Tarjeta {
                Seccion("Ventas · 30 días")
                FilaValor("Total vendido", soles(d.num("ventas_total")))
                FilaValor("Número de ventas", d.num("ventas_cantidad").toInt().toString())
            }
            Tarjeta {
                Seccion("Precios de venta vigentes")
                d.lista("precios").forEach { p ->
                    val canal = when (p.txt("tipo_cliente")) { "MAYORISTA" -> "Mayorista"; "DIRECTO" -> "Mercados / directo"; else -> "Público en planta" }
                    FilaValor("${p.txt("producto")} · $canal", soles(p.num("precio")))
                }
            }
        }
    }
}
