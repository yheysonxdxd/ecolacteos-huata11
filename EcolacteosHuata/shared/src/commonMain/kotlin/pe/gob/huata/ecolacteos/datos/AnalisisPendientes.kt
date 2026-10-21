package pe.gob.huata.ecolacteos.datos

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import kotlinx.serialization.Serializable
import kotlinx.serialization.builtins.ListSerializer
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject

/**
 * Análisis de calidad hechos sin conexión: quedan guardados en el teléfono
 * (sobreviven al cierre de la app) y se envían solos cuando vuelve la conexión.
 * Cada uno lleva su uuid desde que se anotó: reenviarlo no lo duplica.
 */
object AnalisisPendientes {
    @Serializable
    data class Pendiente(
        val uuid: String,
        val entregaId: Long,
        val proveedor: String,
        val cuerpo: JsonObject,
    )

    /** Resultado de enviar uno: [respuesta] del servidor, o [rechazo] si no lo aceptó. */
    class Enviado(val pendiente: Pendiente, val respuesta: JsonObject?, val rechazo: String?)

    private val json = Json { ignoreUnknownKeys = true }
    private val serializador = ListSerializer(Pendiente.serializer())

    /** Cuántos esperan conexión (para mostrarlo en pantalla). */
    var cantidad by mutableStateOf(0)
        private set
    var enviando by mutableStateOf(false)
        private set

    private fun clave() = "analisis_pendientes:${Sesion.usuarioId ?: 0}"

    fun todos(): List<Pendiente> =
        AlmacenLocal.leer(clave())
            ?.let { runCatching { json.decodeFromString(serializador, it) }.getOrNull() }
            ?: emptyList()

    private fun guardar(lista: List<Pendiente>) {
        if (lista.isEmpty()) AlmacenLocal.borrar(clave())
        else AlmacenLocal.guardar(clave(), json.encodeToString(serializador, lista))
        cantidad = lista.size
    }

    /** Releer al entrar (puede haber pendientes de antes de cerrar la app). */
    fun refrescar() { cantidad = todos().size }

    fun agregar(p: Pendiente) = guardar(todos().filter { it.uuid != p.uuid } + p)

    fun entregas(): Set<Long> = todos().map { it.entregaId }.toSet()

    /**
     * Envía los guardados, en orden. Si se corta la conexión se detiene y los
     * que faltan esperan al siguiente intento. Uno que el servidor rechaza
     * (p. ej. otro analista ya analizó esa entrega) se quita y se informa.
     */
    suspend fun enviarTodos(): List<Enviado> {
        if (enviando) return emptyList()
        enviando = true
        val hechos = mutableListOf<Enviado>()
        try {
            for (p in todos()) {
                val e = try {
                    Enviado(p, Sesion.enviarOperacion(TipoOperacion.ANALISIS, p.cuerpo, p.uuid), null)
                } catch (t: RechazoServidor) {
                    Enviado(p, null, t.message ?: "rechazado")
                } catch (t: Throwable) {
                    break // sin conexión, sesión vencida o error del servidor: se reintenta luego
                }
                hechos += e
                guardar(todos().filter { it.uuid != p.uuid })
            }
        } finally {
            enviando = false
        }
        return hechos
    }
}
