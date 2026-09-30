package pe.gob.huata.ecolacteos.ui.acopiador

import androidx.compose.runtime.*
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import pe.gob.huata.ecolacteos.datos.*
import kotlin.time.Clock
import kotlin.time.Duration.Companion.hours
import kotlin.time.ExperimentalTime

/**
 * Las entregas del acopiador quedan guardadas en el teléfono: si la app se
 * cierra (batería, sin señal, el sistema la mata) no se pierde nada.
 *
 * - Cada cambio en [EstadoAcopio] se escribe al almacén local.
 * - Al abrir la pantalla del acopiador se restaura lo guardado.
 * - Cada entrega se guarda con su uuid de servidor, así reenviarla tras un
 *   cierre no la duplica (el servidor responde DUPLICADO).
 * - Se guarda la hora real de registro: si se envía al día siguiente, llega
 *   con su fecha verdadera.
 * - Lo ya enviado de días anteriores se descarta; lo no enviado se conserva y
 *   se intenta enviar solo.
 */
object HoraRegistro {
    // uuid local de la entrega -> "yyyy-MM-dd HH:mm:ss" (hora Perú)
    private val horas = mutableMapOf<String, String>()

    fun de(e: Entrega): String? = horas[e.uuid]

    internal fun marcar(uuidLocal: String): String = horas.getOrPut(uuidLocal) { ahoraPeruLocal() }
    internal fun poner(uuidLocal: String, hora: String) { horas[uuidLocal] = hora }
}

@Serializable
private data class CopiaAcopio(
    val entregas: List<Entrega> = emptyList(),
    val enviados: List<Long> = emptyList(),
)

private val json = Json { ignoreUnknownKeys = true }

// una por acopiador: en un celular compartido cada uno ve y envía solo lo suyo
private fun claveAlmacen() = "acopio_" + if (Sesion.desdeServidor) "real_${Sesion.usuarioId}" else "demo"

/** Clave con la que se llenó EstadoAcopio en memoria (para vaciarlo si entra otro). */
private var claveEnMemoria: String? = null

/** Antes se guardaba todo en "acopio_real": pasa al primer acopiador que entre. */
private fun migrarClaveVieja(clave: String) {
    if (!clave.startsWith("acopio_real_") || AlmacenLocal.leer(clave) != null) return
    AlmacenLocal.leer("acopio_real")?.let { AlmacenLocal.guardar(clave, it); AlmacenLocal.borrar("acopio_real") }
}

@OptIn(ExperimentalTime::class)
private fun ahoraPeruLocal(): String =
    (Clock.System.now() - 5.hours).toString().take(19).replace('T', ' ') // UTC-5

/** Poner una vez dentro de la pantalla del acopiador. */
@Composable
fun RecordarAcopio(estado: EstadoAcopio) {
    val scope = rememberCoroutineScope()
    val clave = claveAlmacen()
    LaunchedEffect(clave) {
        // entró otro acopiador (u otro modo): lo del anterior ya está guardado con su clave
        if (claveEnMemoria != null && claveEnMemoria != clave) {
            estado.entregas.clear()
            estado.enviados.clear()
            ResultadoSync.mensaje = null
        }
        claveEnMemoria = clave
        migrarClaveVieja(clave)
        val atrasadas = restaurar(estado, clave)
        if (atrasadas > 0) sincronizarAcopio(estado, scope)
        snapshotFlow { estado.entregas.toMap() to estado.enviados.toList() }
            .collect { (entregas, enviados) -> guardar(clave, entregas, enviados) }
    }
}

/** Devuelve cuántas entregas sin enviar de días anteriores se recuperaron. */
internal fun restaurar(estado: EstadoAcopio, clave: String): Int {
    // primero los proveedores nuevos sin subir, para que sus entregas no se descarten
    if (Sesion.desdeServidor) ProveedoresNuevos.restaurar()
    val texto = AlmacenLocal.leer(clave) ?: return 0
    val copia = runCatching { json.decodeFromString<CopiaAcopio>(texto) }.getOrNull() ?: return 0
    val hoy = ahoraPeruLocal().take(10)
    val existen = Demo.proveedores.map { it.id }.toSet()
    var sinEnviar = 0
    var atrasadas = 0

    copia.entregas.forEach { e ->
        if (e.proveedorId !in existen || e.proveedorId in estado.entregas) return@forEach
        val enviada = e.proveedorId in copia.enviados
        val deOtroDia = !e.registradoEn.startsWith(hoy)
        if (deOtroDia && enviada) return@forEach

        // e.uuid ya es el uuid de servidor: se vuelve a usar tal cual
        uuidPorEntrega[e.uuid] = e.uuid
        HoraRegistro.poner(e.uuid, e.registradoEn)
        estado.entregas[e.proveedorId] = e
        if (enviada) estado.enviados.add(e.proveedorId)
        else { sinEnviar++; if (deOtroDia) atrasadas++ }
    }

    if (sinEnviar > 0 && ResultadoSync.mensaje == null) {
        ResultadoSync.huboError = false
        ResultadoSync.mensaje = "Se recuperaron $sinEnviar registros guardados sin enviar"
    }
    return atrasadas
}

internal fun guardar(clave: String, entregas: Map<Long, Entrega>, enviados: List<Long>) {
    val existen = Demo.proveedores.map { it.id }.toSet()
    val copia = CopiaAcopio(
        entregas = entregas.values.filter { it.proveedorId in existen }.map { e ->
            e.copy(
                uuid = uuidPorEntrega.getOrPut(e.uuid) { nuevoUuid() },
                registradoEn = HoraRegistro.marcar(e.uuid),
            )
        },
        enviados = enviados.filter { it in existen },
    )
    AlmacenLocal.guardar(clave, json.encodeToString(CopiaAcopio.serializer(), copia))
}
