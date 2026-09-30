package pe.gob.huata.ecolacteos.datos

import io.ktor.client.*
import io.ktor.client.call.*
import io.ktor.client.plugins.contentnegotiation.*
import io.ktor.client.request.*
import io.ktor.http.*
import io.ktor.serialization.kotlinx.json.*
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.*

@Serializable
data class RespuestaPush(
    val cursor: String,
    val resultados: List<ResultadoOperacion>,
)

@Serializable
data class ResultadoOperacion(
    val uuid: String,
    val estado: String,                // APLICADO | DUPLICADO | ERROR
    val id: Long? = null,
    val error: String? = null,
    @kotlinx.serialization.SerialName("aviso_desvio") val avisoDesvio: JsonObject? = null,
    val veredicto: String? = null,
)

/**
 * Cliente de los tres endpoints que sostienen el modo offline.
 * [baseUrl] apunta al servidor local de la planta (por LAN o VPN).
 */
class ClienteSync(private val baseUrl: String, private val token: () -> String?) {

    private val http = HttpClient {
        install(ContentNegotiation) {
            json(Json { ignoreUnknownKeys = true; isLenient = true })
        }
    }

    private fun HttpRequestBuilder.auth() {
        token()?.let { header(HttpHeaders.Authorization, "Bearer $it") }
    }

    /** Carga inicial: flota, croquis, padrón y parámetros de calidad. */
    suspend fun bootstrap(): JsonObject =
        http.get("$baseUrl/api/v1/sync/bootstrap") { auth() }.body()

    /** Sube el outbox. Lotes de hasta 500 para no reventar la conexión rural. */
    suspend fun push(operaciones: List<OperacionPendiente>): RespuestaPush =
        http.post("$baseUrl/api/v1/sync/push") {
            auth()
            contentType(ContentType.Application.Json)
            setBody(buildJsonObject {
                putJsonArray("operaciones") {
                    operaciones.take(500).forEach { op ->
                        addJsonObject {
                            put("uuid", op.uuid)
                            put("tipo", op.tipo.name)
                            op.cuerpo.forEach { (k, v) -> put(k, v) }
                        }
                    }
                }
            })
        }.body()

    /** Cambios desde el último cursor guardado. */
    suspend fun pull(desde: String?): JsonObject =
        http.get("$baseUrl/api/v1/sync/pull") {
            auth()
            desde?.let { parameter("desde", it) }
        }.body()
}
