package pe.gob.huata.ecolacteos.ui.acopiador

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch
import kotlinx.serialization.json.*
import pe.gob.huata.ecolacteos.datos.*
import pe.gob.huata.ecolacteos.tema.*
import kotlin.time.Clock
import kotlin.time.Duration.Companion.hours
import kotlin.time.ExperimentalTime

/** Mensaje del último envío, para mostrarlo debajo de la cabecera. */
object ResultadoSync {
    var enviando by mutableStateOf(false)
    var mensaje by mutableStateOf<String?>(null)
    var huboError by mutableStateOf(false)
}

// uuid de servidor por entrega local: reintentar no duplica (idempotencia)
internal val uuidPorEntrega =mutableMapOf<String, String>()

private val cliente get() = ClienteSync(ConfigServidor.baseUrl) { Sesion.token }

/** Lanza el envío de las entregas pendientes a la base de datos. */
fun sincronizarAcopio(estado: EstadoAcopio, scope: CoroutineScope) {
    if (ResultadoSync.enviando) return
    if (!Sesion.desdeServidor) { simularEnvioDemo(estado); return } // demo: nunca al servidor
    scope.launch {
        ResultadoSync.enviando = true
        ResultadoSync.mensaje = "Enviando a la base de datos…"
        ResultadoSync.huboError = false
        try {
            val (ok, errores) = enviar(estado)
            ResultadoSync.huboError = errores.isNotEmpty()
            ResultadoSync.mensaje = when {
                errores.isNotEmpty() -> "Enviados $ok · con error ${errores.size}: ${errores.first()}"
                ok == 0 -> "No hay registros nuevos por enviar"
                else -> "✓ $ok registros guardados en la base de datos"
            }
        } catch (e: Throwable) {
            ResultadoSync.huboError = true
            ResultadoSync.mensaje = "No se pudo conectar con el servidor (${ConfigServidor.baseUrl}): ${e.message}"
        } finally {
            ResultadoSync.enviando = false
        }
    }
}

@OptIn(ExperimentalTime::class)
private fun ahoraPeru(): String =
    (Clock.System.now() - 5.hours).toString().take(19).replace('T', ' ') // UTC-5

/** Devuelve (cantidad enviada, lista de errores). */
private suspend fun enviar(estado: EstadoAcopio): Pair<Int, List<String>> {
    // proveedores nuevos primero: sus entregas necesitan el id real
    val erroresProv = if (Sesion.desdeServidor) ProveedoresNuevos.subirPendientes(estado) else emptyList()
    val porEnviar = estado.entregas.filterKeys { it !in estado.enviados }
    if (porEnviar.isEmpty()) return 0 to erroresProv
    Sesion.asegurarToken()
    val errores = mutableListOf<String>()
    errores += erroresProv
    val idServidor = mutableMapOf<Long, Long>()

    if (Sesion.desdeServidor) {
        // los proveedores ya vienen de la base de datos: su id es el del servidor
        porEnviar.keys.filter { it > 0 }.forEach { idServidor[it] = it } // < 0: proveedor nuevo aún sin subir
    } else {
        // ids reales del servidor: vehículo por código y comunidad por nombre
        val boot = cliente.bootstrap()
        val vehiculoServidor = boot["vehiculos"]!!.jsonArray.associate {
            it.jsonObject["codigo"]!!.jsonPrimitive.content to it.jsonObject["id"]!!.jsonPrimitive.long
        }
        val comunidadesServidor = boot["comunidades"]!!.jsonArray.map {
            it.jsonObject["nombre"]!!.jsonPrimitive.content to it.jsonObject["id"]!!.jsonPrimitive.long
        }
        fun comunidadServidor(nombre: String) =
            comunidadesServidor.firstOrNull { it.first.equals(nombre, true) }?.second
                ?: comunidadesServidor.firstOrNull { it.first.contains(nombre, true) }?.second
                ?: error("la comunidad $nombre no existe en la base de datos")

        // 1) proveedores: uuid fijo por proveedor, así el servidor responde DUPLICADO si ya existe
        val proveedores = porEnviar.keys.map { id -> Demo.proveedores.first { it.id == id } }
        val opsProv = proveedores.map { p ->
            OperacionPendiente(
                uuid = "00000000-0000-4000-8000-" + p.id.toString().padStart(12, '0'),
                tipo = TipoOperacion.PROVEEDOR,
                cuerpo = buildJsonObject {
                    put("nombre", p.nombre)
                    put("dni", p.dni)
                    put("comunidad_id", comunidadServidor(Demo.comunidad(p.comunidadId).nombre))
                    put("vehiculo_id", vehiculoServidor[Demo.vehiculo(p.vehiculoId).codigo]
                        ?: error("el vehículo ${Demo.vehiculo(p.vehiculoId).codigo} no existe en la base de datos"))
                    p.promedioLitros?.let { put("promedio_litros", it) }
                    p.vacasOrdeno?.let { put("vacas_ordeno", it) }
                },
            )
        }
        cliente.push(opsProv).resultados.zip(proveedores).forEach { (r, p) ->
            if (r.id != null && r.estado != "ERROR") idServidor[p.id] = r.id
            else errores += "${p.nombre}: ${r.error}"
        }
    }

    // 2) entregas del día
    val ahora = ahoraPeru()
    val entregas = porEnviar.filterKeys { it in idServidor }
    val opsEnt = entregas.map { (provId, e) ->
        OperacionPendiente(
            uuid = uuidPorEntrega.getOrPut(e.uuid) { nuevoUuid() },
            tipo = TipoOperacion.ENTREGA,
            cuerpo = buildJsonObject {
                put("proveedor_id", idServidor.getValue(provId))
                put("fecha", (HoraRegistro.de(e) ?: ahora).take(10))
                put("litros", e.litros)
                put("ausente", e.ausente)
                put("registrado_en", HoraRegistro.de(e) ?: ahora)
            },
        )
    }
    var ok = 0
    if (opsEnt.isNotEmpty()) {
        cliente.push(opsEnt).resultados.zip(entregas.keys.toList()).forEach { (r, provId) ->
            if (r.estado == "APLICADO" || r.estado == "DUPLICADO") {
                estado.enviados.add(provId); ok++
            } else if (r.definitivo) {
                aceptarRechazoDefinitivo(estado, provId, r)
                errores += r.error ?: "No se pudo cambiar la entrega"
            } else {
                errores += "${Demo.proveedores.first { it.id == provId }.nombre}: ${r.error}"
            }
        }
    }
    return ok to errores
}

/** Franja con el resultado del último envío. */
@Composable
fun AvisoSync() {
    val msg = ResultadoSync.mensaje ?: return
    Surface(
        modifier = Modifier.fillMaxWidth(),
        color = if (ResultadoSync.huboError) Altiplano.AvisoFondo else Altiplano.VerdeMarcaSuave,
        onClick = { if (!ResultadoSync.enviando) ResultadoSync.mensaje = null },
    ) {
        Row(
            Modifier.padding(horizontal = Medidas.Pantalla, vertical = 9.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(9.dp),
        ) {
            if (ResultadoSync.enviando) {
                CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp, color = Altiplano.Exito)
            }
            Text(
                msg, style = Texto.Cuerpo,
                color = if (ResultadoSync.huboError) Altiplano.Aviso else Altiplano.Texto,
            )
        }
    }
}
