package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.ui.text.input.KeyboardType
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import pe.gob.huata.ecolacteos.datos.ConfigServidor
import pe.gob.huata.ecolacteos.datos.Sesion
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

/**
 * Nota de venta en PDF. El servidor da un enlace firmado (vale 30 min) y se
 * abre en el navegador del celular, que muestra el PDF con los botones de
 * imprimir y compartir (WhatsApp). No es boleta electrónica SUNAT.
 */
data class UltimaNota(val id: Long, val numero: String, val total: Double, val cliente: String, val correo: String = "")

object NotaVenta {
    var ultima by mutableStateOf<UltimaNota?>(null)
    /** Correo opcional escrito en la venta (se rellena con el guardado del cliente). */
    var correo by mutableStateOf("")
}

/** El correo escrito en la venta, si parece válido; si no, null (no se manda nada). */
fun correoParaVenta(): String? = NotaVenta.correo.trim().takeIf { esCorreo(it) }

internal fun esCorreo(s: String) = Regex("^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$").matches(s.trim())

/** Texto que se agrega al mensaje de la venta según cómo fue el correo. */
fun textoCorreo(r: kotlinx.serialization.json.JsonObject): String = when {
    r.txt("correo_enviado") == "true" -> " · nota enviada a ${r.txt("correo")}"
    r.txt("correo_error").isNotEmpty() -> " · ${r.txt("correo_error")}"
    else -> ""
}

/** Campo opcional en Ventas: "Enviar la nota al correo". */
@Composable
fun CampoCorreoVenta(cliente: kotlinx.serialization.json.JsonObject?) {
    // al cambiar de cliente se pone el correo que tenga guardado (o se vacía)
    LaunchedEffect(cliente?.txt("id")) { NotaVenta.correo = cliente?.txt("email") ?: "" }
    val escrito = NotaVenta.correo.trim()
    OutlinedTextField(
        value = NotaVenta.correo, onValueChange = { NotaVenta.correo = it.take(120).trim() },
        label = { Text("Enviar la nota al correo (opcional)") },
        placeholder = { Text("cliente@gmail.com") },
        singleLine = true,
        isError = escrito.isNotEmpty() && !esCorreo(escrito),
        supportingText = if (escrito.isNotEmpty() && !esCorreo(escrito)) { { Text("Ese correo no parece válido: no se enviará") } } else null,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
        colors = OutlinedTextFieldDefaults.colors(
            focusedTextColor = Altiplano.Texto, unfocusedTextColor = Altiplano.Texto,
            focusedBorderColor = Altiplano.VerdeMarca, unfocusedBorderColor = Altiplano.Borde,
            focusedLabelColor = Altiplano.TextoSecundario, unfocusedLabelColor = Altiplano.TextoTerciario,
            cursorColor = Altiplano.Exito,
        ),
        modifier = Modifier.fillMaxWidth(),
    )
}

/** Pide el enlace al servidor y lo abre. Devuelve un mensaje de error o null. */
private suspend fun abrirNota(ventaId: Long, abrir: (String) -> Unit): String? = try {
    val url = Sesion.consultar("/api/v1/compras/ventas/$ventaId/nota").txt("url")
    abrir(ConfigServidor.baseUrl + url)
    null
} catch (t: Throwable) {
    "No se pudo abrir la nota: ${t.message ?: "sin conexión"}"
}

/** Tarjeta con la venta recién hecha y el botón para imprimir su nota. */
@Composable
fun TarjetaUltimaNota() {
    val n = NotaVenta.ultima ?: return
    val uri = LocalUriHandler.current
    val scope = rememberCoroutineScope()
    var abriendo by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    Tarjeta(borde = Altiplano.VerdeBorde) {
        Text("NOTA DE VENTA ${n.numero}", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
        Spacer(Modifier.height(4.dp))
        Text("${soles(n.total)} · ${n.cliente}", style = Texto.Fila, color = Altiplano.Texto)
        Spacer(Modifier.height(10.dp))
        BotonPrimario(if (abriendo) "Abriendo…" else "Ver / imprimir nota en PDF", habilitado = !abriendo) {
            scope.launch { abriendo = true; error = abrirNota(n.id) { uri.openUri(it) }; abriendo = false }
        }
        error?.let { Spacer(Modifier.height(8.dp)); AvisoCampo(it, esAlerta = true) }
        Spacer(Modifier.height(10.dp))
        EnviarPorCorreo(n)
    }
}

/** Mandar (o volver a mandar) la nota por correo después de la venta. */
@Composable
private fun EnviarPorCorreo(n: UltimaNota) {
    val scope = rememberCoroutineScope()
    var correo by remember(n.id) { mutableStateOf(n.correo) }
    var enviando by remember { mutableStateOf(false) }
    var resultado by remember(n.id) { mutableStateOf<String?>(null) }

    Row(verticalAlignment = androidx.compose.ui.Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        OutlinedTextField(
            value = correo, onValueChange = { correo = it.take(120).trim() },
            label = { Text("Correo del cliente") }, singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
            colors = OutlinedTextFieldDefaults.colors(
                focusedTextColor = Altiplano.Texto, unfocusedTextColor = Altiplano.Texto,
                focusedBorderColor = Altiplano.VerdeMarca, unfocusedBorderColor = Altiplano.Borde,
                focusedLabelColor = Altiplano.TextoSecundario, unfocusedLabelColor = Altiplano.TextoTerciario,
                cursorColor = Altiplano.Exito,
            ),
            modifier = Modifier.weight(1f),
        )
        TextButton(
            enabled = !enviando && esCorreo(correo),
            onClick = {
                scope.launch {
                    enviando = true
                    resultado = try {
                        Sesion.enviar("/api/v1/compras/ventas/${n.id}/enviar-nota", buildJsonObject { put("correo", correo) })
                        "✓ Nota enviada a $correo"
                    } catch (t: Throwable) { t.message ?: "No se pudo enviar" }
                    enviando = false
                }
            },
        ) { Text(if (enviando) "Enviando…" else "Enviar", color = Altiplano.Exito) }
    }
    resultado?.let { Spacer(Modifier.height(6.dp)); AvisoCampo(it, esAlerta = !it.startsWith("✓")) }
}

/** Para reimprimir desde una lista (Movimientos, últimas ventas del admin). */
@Composable
fun rememberAbrirNota(): (Long) -> Unit {
    val uri = LocalUriHandler.current
    val scope = rememberCoroutineScope()
    return { id -> scope.launch { abrirNota(id) { uri.openUri(it) }?.let { ComprasReal.mensaje = it } } }
}
