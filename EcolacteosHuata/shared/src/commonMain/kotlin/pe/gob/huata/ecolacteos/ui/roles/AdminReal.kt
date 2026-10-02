package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import kotlinx.serialization.json.*
import pe.gob.huata.ecolacteos.datos.Sesion
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

/** Pestaña elegida dentro de cada opción del menú del admin (como en la web de oficina). */
object AdminPestanas {
    var acopio by mutableStateOf(0)
    var dinero by mutableStateOf(0)
    var padron by mutableStateOf(0)
    var planta by mutableStateOf(0)
}

/**
 * Admin con datos de la base. Pocas opciones abajo y pestañas arriba:
 * Acopio (Totales · Conciliación), Dinero (Ventas y pagos · Costos),
 * Padrón (Proveedores · Trabajadores · Cambios de comunidad), Calidad y
 * Planta (Fórmulas · Lotes · Almacén · Pérdidas · Kardex · Órdenes · Vender · Últimas ventas).
 */
@Composable
fun ContenidoAdminReal(ruta: String, e: EstadoAdmin) {
    val a = AdminPestanas
    when (ruta) {
        "admin/acopio" -> ConPestanas(listOf("Totales", "Conciliación"), a.acopio, { a.acopio = it }) {
            if (a.acopio == 0) TotalesReal(e) else ConciliacionReal()
        }
        "admin/dinero" -> ConPestanas(listOf("Ventas y pagos", "Costos"), a.dinero, { a.dinero = it }) {
            if (a.dinero == 0) DineroReal(e) else CostosReal()
        }
        "admin/padron" -> ConPestanas(listOf("Proveedores", "Trabajadores", "Cambios"), a.padron, { a.padron = it }) {
            if (a.padron == 2) SolicitudesReal() else PadronReal(a.padron == 1)
        }
        "admin/planta" -> ConPestanas(
            listOf("Fórmulas", "Lotes", "Almacén", "Pérdidas", "Kardex", "Órdenes", "Vender", "Últimas ventas"), a.planta, { a.planta = it },
        ) {
            when (a.planta) {
                0 -> FormulasReal(puedeAceptar = true) { a.planta = 1 } // al iniciar un lote, a Lotes
                1 -> LotesReal()
                2 -> ContenidoComprasReal("compras/inventario") { a.planta = 4 } // tocar un insumo: su kardex
                3 -> PerdidasReal("compras")
                4 -> KardexCompleto()
                5 -> ContenidoComprasReal("compras/orden") {}
                6 -> ContenidoComprasReal("compras/ventas-nueva") { a.planta = 7 } // después de vender: la nota
                else -> ContenidoComprasReal("compras/movimientos") {}
            }
        }
        // rutas anteriores (por si alguna pantalla las usa todavía)
        "admin/totales" -> TotalesReal(e)
        "admin/negocio" -> DineroReal(e)
        "admin/padron-old" -> PadronReal(false)
        "admin/calidad" -> CalidadAdminReal()
        "admin/solicitudes" -> SolicitudesReal()
        "admin/conciliacion" -> ConciliacionReal()
        else -> CostosReal()
    }
}

@Composable
private fun TotalesReal(e: EstadoAdmin) {
    val periodo = listOf("dia", "semana", "mes")[e.periodo]
    CargaServidor("/api/v1/admin/totales?periodo=$periodo") { d, _ ->
        val vehiculos = d.lista("vehiculos")
        val maximo = maxOf(vehiculos.maxOfOrNull { it.num("litros") } ?: 0.0, 1.0)
        PantallaScroll {
            Segmentos(listOf("Día", "Semana", "Mes"), e.periodo) { e.periodo = it }
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
            Seccion("Por vehículo")
            vehiculos.forEach { v ->
                Tarjeta {
                    Row {
                        Text(
                            "${v.txt("codigo")} · ${v.txt("acopiador").ifEmpty { "sin acopiador" }}",
                            style = Texto.Fila, color = Altiplano.Texto, modifier = Modifier.weight(1f),
                        )
                        Text("${formatear(v.num("litros"), 0)} L", style = Texto.Fila, color = Altiplano.Texto)
                    }
                    Spacer(Modifier.height(8.dp)); BarraNivel((v.num("litros") / maximo).toFloat()); Spacer(Modifier.height(5.dp))
                    Text(v.txt("ruta"), style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                }
            }
        }
    }
}

@Composable
private fun PadronReal(verTrabajadores: Boolean) {
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

/** Calidad: promedios por vehículo y, en la otra pestaña, los sancionados por adulteración. */
@Composable
private fun CalidadAdminReal() {
    var verAdulteraciones by remember { mutableStateOf(false) }
    Column {
        Box(Modifier.padding(start = Medidas.Pantalla, end = Medidas.Pantalla, top = Medidas.Pantalla)) {
            Segmentos(listOf("Por vehículo", "Adulteraciones"), if (verAdulteraciones) 1 else 0) { verAdulteraciones = it == 1 }
        }
        if (verAdulteraciones) AdulteracionesReal() else CalidadVehiculosReal()
    }
}

@Composable
private fun CalidadVehiculosReal() {
    CargaServidor("/api/v1/admin/calidad") { d, _ ->
        PantallaScroll {
            Text("Promedios de los últimos 30 días por vehículo.", style = Texto.Cuerpo, color = Altiplano.TextoTerciario)
            d.lista("vehiculos").forEach { r ->
                val rechazo = r.num("rechazo_pct")
                val malo = rechazo > 8 || r.num("adulteradas") > 0
                Tarjeta(borde = if (malo) Altiplano.AlertaBorde else Altiplano.Borde) {
                    Row {
                        Text("${r.txt("codigo")} · ${r.txt("acopiador").ifEmpty { "sin acopiador" }}", style = Texto.Fila, color = Altiplano.Texto, modifier = Modifier.weight(1f))
                        ChipEstado(if (malo) Estado.RECHAZADO else Estado.ACEPTADO)
                    }
                    Spacer(Modifier.height(10.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                        MiniCifra("Grasa", "${formatear(r.num("grasa"), 2)} %")
                        MiniCifra("Densidad", formatear(r.num("densidad"), 3))
                        MiniCifra("Adult.", r.num("adulteradas").toInt().toString(), if (r.num("adulteradas") > 0) Altiplano.Alerta else Altiplano.Texto)
                        MiniCifra("Rechazo", "${formatear(rechazo)} %", if (rechazo > 8) Altiplano.Alerta else Altiplano.Texto)
                    }
                    Spacer(Modifier.height(6.dp))
                    Text("${r.num("analisis").toInt()} análisis · temp. media ${formatear(r.num("temperatura"))} °C", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                }
            }
        }
    }
}

@Composable
private fun SolicitudesReal() {
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

@Composable
private fun ConciliacionReal() {
    CargaServidor("/api/v1/admin/conciliacion") { d, _ ->
        val tol = d.num("tolerancia")
        PantallaScroll {
            AvisoCampo("Fase 2: requiere caudalímetro en la tina de recepción. Por ahora se compara con el pesaje manual de planta.")
            Text("Recepción del ${fechaCorta(d.txt("fecha"))} · tolerancia ±${formatear(tol)} %", style = Texto.Cuerpo, color = Altiplano.TextoTerciario)
            d.lista("vehiculos").forEach { v ->
                val dif = v.num("diferencia_pct")
                val fuera = v.txt("fuera_tolerancia") == "1" || v.txt("fuera_tolerancia") == "true"
                Tarjeta(borde = if (fuera) Altiplano.AlertaBorde else Altiplano.Borde) {
                    Text(v.txt("codigo"), style = Texto.Fila, color = Altiplano.Texto)
                    Spacer(Modifier.height(8.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(20.dp)) {
                        MiniCifra("Campo", "${formatear(v.num("litros_campo"))} L")
                        MiniCifra("Planta", "${formatear(v.num("litros_planta"))} L")
                        MiniCifra("Diferencia", formatear(dif, 2) + " %", if (fuera) Altiplano.Alerta else Altiplano.Exito)
                    }
                }
            }
        }
    }
}

@Composable
private fun CostosReal() {
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
