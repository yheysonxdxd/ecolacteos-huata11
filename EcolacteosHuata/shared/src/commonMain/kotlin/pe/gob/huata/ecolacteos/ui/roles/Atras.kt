package pe.gob.huata.ecolacteos.ui.roles

/**
 * Qué cierra el botón "atrás" del celular dentro de cada opción del menú de abajo:
 * primero lo más adentro (un paso de la venta, una tarjeta abierta, el análisis de calidad).
 * Si no hay nada abierto, Navegacion vuelve a Inicio y, en Inicio, avisa antes de salir.
 */
object Atras {
    /** true si dentro de [ruta] hay algo abierto que "atrás" debe cerrar primero. */
    fun hayAbierto(ruta: String): Boolean = cerrar(ruta, soloMirar = true)

    /** Cierra un nivel dentro de [ruta]; false si ya no había nada que cerrar. */
    fun cerrar(ruta: String): Boolean = cerrar(ruta, soloMirar = false)

    private fun cerrar(ruta: String, soloMirar: Boolean): Boolean {
        val a = AdminPestanas
        val v = ComprasReal
        val ci = CompraInsumos
        // en la venta paso a paso, "atrás" vuelve al paso anterior
        val enVenta = (ruta == "admin/planta" && a.planta == 5) || (ruta == "compras/ventas" && v.pestanaVentas == 0)
        val cierre: (() -> Unit)? = when {
            enVenta && v.pasoVenta > 1 -> { { v.pasoVenta-- } }
            ruta == "admin/planta" && a.planta >= 0 -> { { a.planta = -1 } }
            ruta == "admin/mas" && a.mas >= 0 -> { { a.mas = -1 } }
            ruta == "admin/dinero" && a.dinero >= 0 -> { { a.dinero = -1 } }
            ruta == "admin/acopio" && AcopioCalendario.conciliar -> { { AcopioCalendario.conciliar = false } }
            ruta == "admin/acopio" && AcopioCalendario.activo -> { { AcopioCalendario.activo = false } }
            ruta == "compras/ventas" && v.pestanaVentas >= 0 -> { { v.pestanaVentas = -1 } }
            ruta == "compras/almacen" && v.pestanaAlmacen >= 0 -> { { v.pestanaAlmacen = -1 } }
            ruta == "compras/orden" && (ci.recibiendo != null || ci.anulando != null) -> { { ci.recibiendo = null; ci.anulando = null } }
            ruta == "compras/orden" && ci.pestana >= 0 -> { { ci.pestana = -1 } }
            ruta == "compras/mas" && ComprasMas.abierta >= 0 -> { { ComprasMas.abierta = -1 } }
            ruta == "calidad/cola" && CalidadReal.seleccion != null -> { { CalidadReal.limpiar() } }
            else -> null
        }
        if (cierre == null) return false
        if (!soloMirar) cierre()
        return true
    }
}
