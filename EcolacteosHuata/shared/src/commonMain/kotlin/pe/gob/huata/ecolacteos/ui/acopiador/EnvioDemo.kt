package pe.gob.huata.ecolacteos.ui.acopiador

/**
 * Modo demo: "Enviar" solo simula el envío. Marca los registros como
 * enviados en el teléfono y NO toca el servidor ni la base de datos, para
 * que las prácticas no se mezclen con el acopio real.
 */
internal fun simularEnvioDemo(estado: EstadoAcopio) {
    val porEnviar = estado.entregas.keys.filter { it !in estado.enviados }
    estado.enviados.addAll(porEnviar)
    ResultadoSync.huboError = false
    ResultadoSync.mensaje =
        if (porEnviar.isEmpty()) "Modo demo: no hay registros nuevos"
        else "Modo demo: ${porEnviar.size} registros marcados como enviados (no se guardan en la base de datos)"
}
