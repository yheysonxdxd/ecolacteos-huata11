package pe.gob.huata.ecolacteos.ui.componentes

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import kotlinx.serialization.json.*
import pe.gob.huata.ecolacteos.datos.Sesion
import pe.gob.huata.ecolacteos.tema.*

/**
 * Pide [ruta] al servidor y muestra "cargando…", el error con botón de
 * reintento, o el [contenido] con la respuesta. [clave] fuerza recargar.
 */
@Composable
fun CargaServidor(ruta: String, clave: Any? = null, contenido: @Composable (JsonObject, recargar: () -> Unit) -> Unit) {
    var intento by remember { mutableStateOf(0) }
    var datos by remember(ruta, clave) { mutableStateOf<JsonObject?>(null) }
    var error by remember(ruta, clave) { mutableStateOf<String?>(null) }

    LaunchedEffect(ruta, clave, intento) {
        error = null
        try { datos = Sesion.consultar(ruta) } catch (e: Throwable) { error = e.message ?: "sin conexión" }
    }

    val d = datos
    when {
        d != null -> contenido(d) { intento++ }
        error != null -> PantallaScroll {
            AvisoCampo("No se pudo leer la base de datos: $error", esAlerta = true)
            BotonPrimario("Reintentar") { intento++ }
        }
        else -> Box(Modifier.fillMaxWidth().padding(40.dp), contentAlignment = Alignment.Center) {
            CircularProgressIndicator(color = Altiplano.Exito)
        }
    }
}

// lectura cómoda de la respuesta JSON
fun JsonObject.txt(k: String): String = (this[k] as? JsonPrimitive)?.takeIf { it !is JsonNull }?.content ?: ""
fun JsonObject.num(k: String): Double = txt(k).toDoubleOrNull() ?: 0.0
fun JsonObject.obj(k: String): JsonObject? = this[k] as? JsonObject
fun JsonObject.lista(k: String): List<JsonObject> = (this[k] as? JsonArray)?.map { it.jsonObject } ?: emptyList()

/** "2026-09-14" → "14 set" */
fun fechaCorta(iso: String): String {
    val meses = listOf("ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "set", "oct", "nov", "dic")
    val p = iso.take(10).split("-")
    if (p.size != 3) return iso
    return "${p[2].toInt()} ${meses[p[1].toInt() - 1]}"
}
