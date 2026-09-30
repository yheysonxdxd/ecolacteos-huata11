package pe.gob.huata.ecolacteos.ui.acopiador

import pe.gob.huata.ecolacteos.datos.ResultadoOperacion

/**
 * El servidor rechazó para siempre una corrección (calidad ya la analizó o
 * planta ya usó esa leche). Reintentar daría el mismo error cada vez, así que:
 * se pone en el teléfono lo que quedó en el servidor, se marca como enviada y
 * el mensaje se muestra una sola vez.
 */
internal fun aceptarRechazoDefinitivo(estado: EstadoAcopio, proveedorId: Long, r: ResultadoOperacion) {
    val e = estado.entregas[proveedorId] ?: return
    estado.entregas[proveedorId] = e.copy(
        litros = r.litros ?: e.litros,
        ausente = r.ausente ?: e.ausente,
    )
    estado.enviados.add(proveedorId)
}
