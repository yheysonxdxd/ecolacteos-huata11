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

/** Semana a la vista (null = esta semana), el último mensaje y el rechazo que se está anotando. */
private object Rechazos {
    var semana by mutableStateOf<String?>(null)
    var version by mutableStateOf(0)
    var mensaje by mutableStateOf<String?>(null)
    var capacitar by mutableStateOf<JsonObject?>(null)
}

/**
 * Admin > Calidad > Rechazos, como en la web: lo que Calidad rechazó por grasa,
 * temperatura, pH… (no adulteración), semana por semana, con la causa y si ya
 * se capacitó al productor. "Ya se le capacitó" vale para sus rechazos pendientes.
 */
@Composable
internal fun RechazosReal() {
    val r = Rechazos
    CargaServidor("/api/v1/admin/rechazos" + (r.semana?.let { "?semana=$it" } ?: ""), clave = r.version) { d, _ ->
        val filas = d.lista("filas")
        val resumen = d.obj("resumen") ?: JsonObject(emptyMap())
        PantallaScroll {
            Flechitas(
                "Semana ${fechaCorta(d.txt("inicio"))} – ${fechaCorta(d.txt("fin"))}",
                if (d.txt("siguiente").isEmpty()) "esta semana" else null,
                onAnterior = { r.semana = d.txt("anterior"); r.mensaje = null },
                onSiguiente = d.txt("siguiente").takeIf { it.isNotEmpty() }?.let { s -> { r.semana = s; r.mensaje = null } },
            )
            r.mensaje?.let { AvisoCampo(it, esAlerta = it.startsWith("No se")) }
            val porCapacitar = d.num("por_capacitar").toInt()
            if (porCapacitar > 0) {
                AvisoCampo("$porCapacitar ${if (porCapacitar == 1) "productor espera" else "productores esperan"} su capacitación (de todas las semanas).")
            }

            TarjetaDato(
                "RECHAZOS", "${resumen.num("rechazos").toInt()}",
                apoyo = "${resumen.num("proveedores").toInt()} productores · ${formatear(resumen.num("litros_rechazados"))} L sin pagar",
                destacada = true,
            ) {
                val causas = resumen.lista("por_causa")
                val max = maxOf(causas.maxOfOrNull { it.num("veces") } ?: 1.0, 1.0)
                causas.forEach { c ->
                    Spacer(Modifier.height(10.dp))
                    Row {
                        Text(c.txt("causa"), style = Texto.Cuerpo, color = Altiplano.TextoSecundario, modifier = Modifier.weight(1f))
                        Text("${c.num("veces").toInt()}", style = Texto.Fila, color = Altiplano.Texto)
                    }
                    Spacer(Modifier.height(4.dp)); BarraNivel((c.num("veces") / max).toFloat(), Altiplano.Aviso)
                }
            }

            if (filas.isEmpty()) AvisoCampo("Calidad no rechazó ninguna entrega esta semana.")
            filas.forEach { f -> FilaRechazo(f) }
        }
    }
    r.capacitar?.let { HojaCapacitar(it) }
}

@Composable
private fun FilaRechazo(f: JsonObject) {
    val capacitado = f.txt("capacitado_en").isNotEmpty()
    val rechazada = f.txt("rechazada") == "true"
    val causas = (f["causas"] as? JsonArray)?.mapNotNull { (it as? JsonPrimitive)?.content } ?: emptyList()
    Tarjeta(borde = if (capacitado) Altiplano.Borde else Altiplano.AvisoBorde) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(f.txt("proveedor"), style = Texto.Fila, color = Altiplano.Texto, modifier = Modifier.weight(1f))
            Text("${formatear(f.num("litros"))} L", style = Texto.Fila, color = if (rechazada) Altiplano.Alerta else Altiplano.Texto)
        }
        Text(
            "${fechaCorta(f.txt("fecha"))} · ${f.txt("comunidad")} · ${f.txt("vehiculo")}" +
                (f.txt("analista").takeIf { it.isNotEmpty() }?.let { " · analizó $it" } ?: ""),
            style = Texto.Apoyo, color = Altiplano.TextoTerciario,
        )
        Spacer(Modifier.height(6.dp))
        causas.forEach { Text(it, style = Texto.Cuerpo, color = Altiplano.TextoSecundario) }
        if (!rechazada) Text("Se recibió igual (se le pagó)", style = Texto.Apoyo, color = Altiplano.Aviso)
        if (f.txt("repite") == "true") {
            Text("⚠ ${f.num("veces_30_dias").toInt()} rechazos en 30 días", style = Texto.Apoyo, color = Altiplano.Alerta)
        }
        Spacer(Modifier.height(8.dp))
        if (capacitado) {
            Text(
                "✓ Capacitado el ${fechaCorta(f.txt("capacitado_en"))}" +
                    (f.txt("capacitado_por").takeIf { it.isNotEmpty() }?.let { " por $it" } ?: "") +
                    (f.txt("nota").takeIf { it.isNotEmpty() }?.let { " · “$it”" } ?: ""),
                style = Texto.Apoyo, color = Altiplano.Exito,
            )
        } else {
            BotonPrimario("Ya se le capacitó") { Rechazos.capacitar = f; Rechazos.mensaje = null }
        }
    }
}

@Composable
private fun HojaCapacitar(f: JsonObject) {
    val scope = rememberCoroutineScope()
    var nota by remember(f) { mutableStateOf("") }
    var enviando by remember { mutableStateOf(false) }
    var error by remember(f) { mutableStateOf<String?>(null) }
    val causas = (f["causas"] as? JsonArray)?.mapNotNull { (it as? JsonPrimitive)?.content } ?: emptyList()

    Hoja("Ya se capacitó a ${f.txt("proveedor")}", onCerrar = { Rechazos.capacitar = null }) {
        Text("${f.txt("comunidad")} · rechazada el ${fechaCorta(f.txt("fecha"))} por ${causas.joinToString(", ")}",
            style = Texto.Apoyo, color = Altiplano.TextoTerciario)
        Text("Vale también para sus otros rechazos pendientes hasta ese día.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
        Campo(nota, { nota = it.take(200) }, "Qué se le dijo (opcional)")
        error?.let { AvisoCampo(it, esAlerta = true) }
        BotonPrimario(if (enviando) "Guardando…" else "Guardar", habilitado = !enviando) {
            scope.launch {
                enviando = true; error = null
                try {
                    val r = Sesion.enviar("/api/v1/admin/rechazos/${f.txt("id")}/capacitado", buildJsonObject {
                        put("nota", nota.trim().ifEmpty { null })
                    })
                    val n = r.num("marcados").toInt()
                    Rechazos.mensaje = "✓ Anotado: ya se capacitó a ${r.txt("proveedor")}" + if (n > 1) " (vale para sus $n rechazos pendientes)." else "."
                    Rechazos.capacitar = null
                    Rechazos.version++
                } catch (t: Throwable) {
                    error = "No se pudo guardar: ${t.message}"
                }
                enviando = false
            }
        }
    }
}
