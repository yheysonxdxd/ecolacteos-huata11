package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import pe.gob.huata.ecolacteos.datos.Sesion
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

/**
 * Pestaña "Comunidad" del productor con datos de la base:
 * GET /productor/comunidad y POST /productor/solicitud-zona.
 * El administrador la aprueba en su pestaña Solicitudes.
 */
@Composable
fun ComunidadProductorReal() {
    CargaServidor("/api/v1/productor/comunidad") { d, recargar ->
        val asig = d.obj("asignacion") ?: JsonObject(emptyMap())
        val destinos = d.lista("destinos")
        val solicitudes = d.lista("solicitudes")
        val pendiente = solicitudes.firstOrNull { it.txt("estado") == "PENDIENTE" }
        val dias = d.num("dias_anticipacion").toInt().takeIf { it > 0 } ?: 3

        var elegido by remember { mutableStateOf<Long?>(null) }
        var motivo by remember { mutableStateOf("") }
        var enviando by remember { mutableStateOf(false) }
        var error by remember { mutableStateOf<String?>(null) }
        val scope = rememberCoroutineScope()

        PantallaScroll {
            Tarjeta {
                Seccion("Tu asignación actual")
                Spacer(Modifier.height(6.dp))
                Text("${asig.txt("comunidad")} · ${asig.txt("vehiculo")}", style = Texto.Fila, color = Altiplano.Texto)
                Text(
                    "Acopiador: ${asig.txt("acopiador").ifEmpty { "sin asignar" }}" +
                        (asig.txt("desde").takeIf { it.isNotEmpty() }?.let { " · desde el ${fechaCorta(it)}" } ?: ""),
                    style = Texto.Apoyo, color = Altiplano.TextoTerciario,
                )
            }

            if (pendiente != null) {
                Seccion("Tu solicitud")
                FilaLista(
                    "Traslado a ${pendiente.txt("destino")}",
                    "Enviada el ${fechaCorta(pendiente.txt("created_at"))} · para el ${fechaCorta(pendiente.txt("solicitada_para"))} · espera aprobación",
                ) { ChipEstado(Estado.PENDIENTE) }
                Text(
                    "Mientras tanto sigues en ${asig.txt("comunidad")}. Cuando el administrador responda lo verás aquí.",
                    style = Texto.Apoyo, color = Altiplano.TextoTerciario,
                )
            } else {
                Seccion("Pedir cambio de zona")
                if (destinos.isEmpty()) AvisoCampo("No hay otras comunidades con ruta de acopio.")
                destinos.forEach { x ->
                    val id = x.num("comunidad_id").toLong()
                    Opcion(
                        x.txt("comunidad"),
                        "${x.txt("vehiculo")} · Acopiador: ${x.txt("acopiador").ifEmpty { "sin asignar" }}",
                        seleccionado = elegido == id,
                    ) { elegido = id; error = null }
                }
                OutlinedTextField(
                    value = motivo, onValueChange = { motivo = it.take(300) },
                    label = { Text("Motivo (opcional)") },
                    placeholder = { Text("Ej. rotación de pastos") },
                    colors = OutlinedTextFieldDefaults.colors(
                        focusedTextColor = Altiplano.Texto, unfocusedTextColor = Altiplano.Texto,
                        focusedBorderColor = Altiplano.VerdeMarca, unfocusedBorderColor = Altiplano.Borde,
                        focusedLabelColor = Altiplano.TextoSecundario, unfocusedLabelColor = Altiplano.TextoTerciario,
                        cursorColor = Altiplano.Exito,
                    ),
                    modifier = Modifier.fillMaxWidth(),
                )
                AvisoCampo("Avisa con $dias días de anticipación. El administrador aprueba y el cambio rige desde la fecha que indique.")
                error?.let { AvisoCampo("No se pudo enviar: $it", esAlerta = true) }
                BotonPrimario(
                    if (enviando) "Enviando…" else "Enviar solicitud",
                    habilitado = elegido != null && !enviando,
                ) {
                    val destino = elegido ?: return@BotonPrimario
                    scope.launch {
                        enviando = true; error = null
                        try {
                            Sesion.enviar("/api/v1/productor/solicitud-zona", buildJsonObject {
                                put("comunidad_destino_id", destino)
                                motivo.trim().takeIf { it.isNotEmpty() }?.let { put("motivo", it) }
                            })
                            recargar()
                        } catch (t: Throwable) {
                            error = t.message ?: "sin conexión"
                        }
                        enviando = false
                    }
                }
            }

            val anteriores = solicitudes.filter { it.txt("estado") != "PENDIENTE" }
            if (anteriores.isNotEmpty()) {
                Seccion("Solicitudes anteriores")
                anteriores.forEach { s ->
                    val aprobada = s.txt("estado") == "APROBADA"
                    FilaLista(
                        "Traslado a ${s.txt("destino")}",
                        if (aprobada) "Aprobada · rige desde el ${fechaCorta(s.txt("vigente_desde"))}"
                        else "Rechazada · pedida el ${fechaCorta(s.txt("created_at"))}",
                    ) { ChipEstado(if (aprobada) Estado.ACEPTADO else Estado.RECHAZADO) }
                }
            }
        }
    }
}
