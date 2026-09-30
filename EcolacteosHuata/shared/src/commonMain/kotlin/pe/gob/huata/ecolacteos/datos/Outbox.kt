package pe.gob.huata.ecolacteos.datos

import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.map
import kotlinx.serialization.json.JsonObject

enum class EstadoOutbox { LOCAL, ENVIANDO, SINCRONIZADO, ERROR }

enum class TipoOperacion {
    ENTREGA, ANALISIS, PROVEEDOR, UBICACION, CONFIRMACION, SOLICITUD_ZONA
}

data class OperacionPendiente(
    val uuid: String,
    val tipo: TipoOperacion,
    val cuerpo: JsonObject,
    val estado: EstadoOutbox = EstadoOutbox.LOCAL,
    val intentos: Int = 0,
    val error: String? = null,
)

/**
 * Regla de oro: ninguna pantalla llama a la API. Escribe en SQLDelight, encola
 * aquí y sigue. SyncWorker (WorkManager en Android) empuja cuando hay red, con
 * backoff exponencial.
 *
 * El uuid se genera en el teléfono y es la clave de idempotencia del servidor:
 * reintentar un lote tras un corte de señal no duplica nada.
 */
interface Outbox {
    val pendientes: Flow<List<OperacionPendiente>>
    val cantidadPendiente: Flow<Int>

    suspend fun encolar(tipo: TipoOperacion, cuerpo: JsonObject): String
    suspend fun marcar(uuid: String, estado: EstadoOutbox, error: String? = null)
    /** APLICADO y DUPLICADO se limpian igual; ERROR se queda visible. */
    suspend fun limpiarSincronizados()
    suspend fun reintentar(uuid: String)
}

/** Implementación en memoria para previews y tests de UI. */
class OutboxEnMemoria : Outbox {
    private val estado = MutableStateFlow<List<OperacionPendiente>>(emptyList())
    override val pendientes: Flow<List<OperacionPendiente>> = estado
    override val cantidadPendiente: Flow<Int> =
        estado.map { ops ->
            ops.count { it.estado != EstadoOutbox.SINCRONIZADO }
        }

    override suspend fun encolar(tipo: TipoOperacion, cuerpo: JsonObject): String {
        val uuid = nuevoUuid()
        estado.value = estado.value + OperacionPendiente(uuid, tipo, cuerpo)
        return uuid
    }

    override suspend fun marcar(uuid: String, nuevo: EstadoOutbox, error: String?) {
        estado.value = estado.value.map {
            if (it.uuid == uuid) it.copy(estado = nuevo, error = error, intentos = it.intentos + 1)
            else it
        }
    }

    override suspend fun limpiarSincronizados() {
        estado.value = estado.value.filter { it.estado != EstadoOutbox.SINCRONIZADO }
    }

    override suspend fun reintentar(uuid: String) = marcar(uuid, EstadoOutbox.LOCAL)
}

/** uuid v4 generado en el teléfono (clave de idempotencia del servidor). */
@OptIn(kotlin.uuid.ExperimentalUuidApi::class)
fun nuevoUuid(): String = kotlin.uuid.Uuid.random().toString()
