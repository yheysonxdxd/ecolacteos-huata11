package pe.gob.huata.ecolacteos.datos

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.*
import kotlin.time.Clock
import kotlin.time.Duration.Companion.hours
import kotlin.time.ExperimentalTime

/**
 * Última respuesta de cada pantalla guardada en el teléfono: sin conexión se
 * muestra lo último que se vio (con su fecha y hora) en vez del error. Al
 * volver la conexión las pantallas se actualizan solas (ver [Conexion]).
 *
 * Va por usuario y se borra al cerrar sesión. La nota de venta (PDF) no se
 * guarda: pesa mucho y se pide una sola vez.
 */
object CopiaLecturas {
    private const val INDICE = "lecturas_indice"

    class Copia(val datos: JsonObject, val hora: String)

    private fun clave(ruta: String) = "lectura:${Sesion.usuarioId ?: 0}:$ruta"

    private fun seGuarda(ruta: String) = !ruta.contains("/nota")

    suspend fun guardar(ruta: String, datos: JsonObject) {
        if (!seGuarda(ruta)) return
        val c = clave(ruta)
        val valor = buildJsonObject { put("hora", ahoraPeru()); put("datos", datos) }.toString()
        // cifrar y escribir al disco fuera del hilo de la pantalla
        withContext(Dispatchers.Default) {
            AlmacenLocal.guardar(c, valor)
            val indice = leerIndice()
            if (c !in indice) AlmacenLocal.guardar(INDICE, (indice + c).joinToString("\n"))
        }
    }

    fun leer(ruta: String): Copia? {
        if (!seGuarda(ruta)) return null
        val o = AlmacenLocal.leer(clave(ruta))
            ?.let { runCatching { Json.parseToJsonElement(it).jsonObject }.getOrNull() } ?: return null
        val datos = o["datos"] as? JsonObject ?: return null
        return Copia(datos, (o["hora"] as? JsonPrimitive)?.content ?: "")
    }

    fun borrarTodo() {
        leerIndice().forEach { AlmacenLocal.borrar(it) }
        AlmacenLocal.borrar(INDICE)
    }

    private fun leerIndice(): List<String> =
        AlmacenLocal.leer(INDICE)?.split("\n")?.filter { it.isNotBlank() } ?: emptyList()

    /** "20 oct 10:32" en hora del Perú (UTC-5). */
    @OptIn(ExperimentalTime::class)
    private fun ahoraPeru(): String {
        val t = (Clock.System.now() - 5.hours).toString() // 2026-10-20T15:32:10Z
        val meses = listOf("ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "set", "oct", "nov", "dic")
        return "${t.substring(8, 10).toInt()} ${meses[t.substring(5, 7).toInt() - 1]} ${t.substring(11, 16)}"
    }
}

/** Avisa a las pantallas que la conexión volvió, para que se actualicen solas. */
object Conexion {
    var vuelta by mutableStateOf(0)
        private set

    fun volvio() { vuelta++ }
}
