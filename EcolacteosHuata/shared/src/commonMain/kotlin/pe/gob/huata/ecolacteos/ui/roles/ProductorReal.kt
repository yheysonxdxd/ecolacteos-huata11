package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import pe.gob.huata.ecolacteos.datos.Sesion
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

/**
 * Productor con datos de la base (GET /productor/resumen): entrega de hoy con
 * confirmación, sus últimos 7 días y sus pagos semanales. La pestaña
 * Comunidad sigue con la pantalla de siempre.
 */
@Composable
fun ContenidoProductorReal(ruta: String, e: EstadoProductor) {
    if (ruta == "productor/comunidad") { ComunidadProductorReal(); return }

    CargaServidor("/api/v1/productor/resumen") { d, recargar ->
        val prov = d.obj("proveedor") ?: JsonObject(emptyMap())
        when (ruta) {
            "productor/hoy" -> HoyReal(prov, d.obj("hoy"), d.lista("pagos").firstOrNull(), recargar)
            "productor/semana" -> SemanaProductorReal(prov, d.lista("ultimos"))
            else -> PagosReal(d.lista("pagos"))
        }
    }
}

private fun estadoEntrega(x: JsonObject): Estado = when {
    x.txt("ausente") == "true" -> Estado.NO_ENTREGO
    x.txt("estado") == "ACEPTADO" -> Estado.ACEPTADO
    x.txt("estado") == "RECHAZADO" -> Estado.RECHAZADO
    else -> Estado.PENDIENTE
}

@Composable
private fun HoyReal(prov: JsonObject, hoy: JsonObject?, enCurso: JsonObject?, recargar: () -> Unit) {
    val scope = rememberCoroutineScope()
    var enviando by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    PantallaScroll {
        Text("Hola, ${prov.txt("nombre").substringBefore(' ')}", style = Texto.Fila, color = Altiplano.TextoSecundario)
        if (hoy == null) {
            TarjetaDato(
                "TU ENTREGA DE HOY", "—",
                apoyo = "Todavía no registran tu leche de hoy · ${prov.txt("vehiculo")}" +
                    (prov.txt("acopiador").takeIf { it.isNotEmpty() }?.let { " · $it" } ?: ""),
                destacada = true,
            )
        } else {
            TarjetaDato(
                "TU ENTREGA DE HOY", formatear(hoy.num("litros")), "L",
                "Registrada por ${prov.txt("acopiador").ifEmpty { "el acopiador" }} · ${prov.txt("vehiculo")} · ${hoy.txt("hora")}",
                destacada = true,
            ) { Spacer(Modifier.height(12.dp)); ChipEstado(estadoEntrega(hoy)) }

            if (hoy.txt("confirmada") == "true") AvisoCampo("Confirmaste que la cantidad es correcta.")
            else if (hoy.txt("ausente") != "true") {
                BotonPrimario(if (enviando) "Enviando…" else "Confirmar que es correcta", habilitado = !enviando) {
                    scope.launch {
                        enviando = true; error = null
                        try { Sesion.confirmarEntrega(hoy.num("id").toLong()); recargar() }
                        catch (t: Throwable) { error = t.message }
                        enviando = false
                    }
                }
                error?.let { AvisoCampo("No se pudo confirmar: $it", esAlerta = true) }
            }
            Text("Si no coincide con lo que entregaste, avisa hoy al acopiador. Así se evitan reclamos el viernes.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
        }
        enCurso?.let {
            TarjetaDato(
                "PAGO DE ESTA SEMANA", soles(it.num("monto")),
                apoyo = "${formatear(it.num("litros"))} L aceptados × ${soles(it.num("precio"))} · se paga el viernes",
                destacada = true,
            )
        }
    }
}

@Composable
private fun SemanaProductorReal(prov: JsonObject, ultimos: List<JsonObject>) {
    val total = ultimos.filter { it.txt("estado") == "ACEPTADO" }.sumOf { it.num("litros") }
    val barras = ultimos.reversed().map { it.txt("dia") to it.num("litros") }
    PantallaScroll {
        TarjetaDato(
            "TUS LITROS · ÚLTIMOS 7 DÍAS", formatear(total), "L",
            apoyo = "Aceptados · tu promedio: ${prov.num("promedio_litros").takeIf { it > 0 }?.let { "${formatear(it)} L" } ?: "sin calcular"}",
        ) {
            if (barras.isNotEmpty()) {
                Spacer(Modifier.height(14.dp))
                Barras(barras, maxOf(barras.maxOf { it.second }, 1.0))
            }
        }
        if (ultimos.isEmpty()) AvisoCampo("No tienes entregas en los últimos 7 días.")
        ultimos.forEach { x ->
            FilaLista("${x.txt("dia")} ${fechaCorta(x.txt("fecha"))}", if (x.txt("ausente") == "true") "No entregó" else "${formatear(x.num("litros"))} L") {
                ChipEstado(estadoEntrega(x))
            }
        }
    }
}

@Composable
private fun PagosReal(pagos: List<JsonObject>) {
    PantallaScroll {
        pagos.forEach { p ->
            val estado = p.txt("estado")
            Tarjeta(borde = if (estado == "PAGADO") Altiplano.Borde else Altiplano.VerdeBorde) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(
                            if (estado == "EN_CURSO") "Semana en curso" else "${fechaCorta(p.txt("inicio"))} – ${fechaCorta(p.txt("fin"))}",
                            style = Texto.Fila, color = Altiplano.Texto,
                        )
                        if (estado != "EN_CURSO") Text(p.txt("semana"), style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                    }
                    ChipEstado(if (estado == "PAGADO") Estado.PAGADO else Estado.PENDIENTE)
                }
                Spacer(Modifier.height(8.dp))
                Text(soles(p.num("monto")), style = Texto.DatoMedio, color = Altiplano.Texto)
                Text(
                    "${formatear(p.num("litros"))} L aceptados · ${soles(p.num("precio"))} por litro" +
                        (p.txt("pagado_el").takeIf { it.isNotEmpty() }?.let { " · pagado el ${fechaCorta(it)}" } ?: ""),
                    style = Texto.Apoyo, color = Altiplano.TextoTerciario,
                )
            }
        }
    }
}
