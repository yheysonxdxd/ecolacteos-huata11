package pe.gob.huata.ecolacteos.ui.acopiador

import pe.gob.huata.ecolacteos.datos.Entrega

/** Marca de pantalla para una entrega que ya llegó al servidor (chip "Enviado"). */
internal const val ENVIADA = "ENVIADA"

/**
 * La entrega tal como se muestra en las listas: si ya se envió, lleva la marca
 * ENVIADA para que el chip diga "Enviado" y no "Sin enviar". Solo es para
 * mostrar: lo guardado en el teléfono y lo que se envía no cambian.
 */
internal fun EstadoAcopio.entregaParaMostrar(proveedorId: Long): Entrega? {
    val e = entregas[proveedorId] ?: return null
    return if (proveedorId in enviados && e.estadoCalidad == "PENDIENTE") e.copy(estadoCalidad = ENVIADA) else e
}
