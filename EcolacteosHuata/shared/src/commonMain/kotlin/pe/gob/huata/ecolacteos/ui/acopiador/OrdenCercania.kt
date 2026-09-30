package pe.gob.huata.ecolacteos.ui.acopiador

import pe.gob.huata.ecolacteos.datos.Comunidad
import pe.gob.huata.ecolacteos.datos.Vehiculo
import kotlin.math.hypot

/**
 * Ordena la lista de proveedores según el vehículo que eligió el acopiador:
 *
 * 1. Primero los de SU vehículo, en el orden en que recorre la ruta
 *    (primera comunidad de la ruta arriba).
 * 2. Después los demás, del más cercano a la ruta al más lejano. La distancia
 *    sale de la posición de cada comunidad en el croquis de Huata (no es GPS,
 *    pero respeta qué comunidad queda al lado de cuál).
 *
 * Sin vehículo elegido la lista queda como viene.
 */
fun List<FilaProveedor>.cercaDeMiRuta(): List<FilaProveedor> =
    ordenarPorCercania(this, VehiculoElegido.id, Demo.vehiculos, Demo.comunidades)

internal fun ordenarPorCercania(
    filas: List<FilaProveedor>,
    vehiculoId: Long?,
    vehiculos: List<Vehiculo>,
    comunidades: List<Comunidad>,
): List<FilaProveedor> {
    val v = vehiculos.firstOrNull { it.id == vehiculoId } ?: return filas
    val porId = comunidades.associateBy { it.id }

    // comunidades de la ruta; si el vehículo no tiene ruta cargada, las de sus proveedores
    val ruta = v.ordenRuta.ifEmpty {
        filas.filter { it.proveedor.vehiculoId == v.id }.map { it.proveedor.comunidadId }.distinct()
    }
    val puntosRuta = ruta.mapNotNull { porId[it] }

    fun distancia(comunidadId: Long): Float {
        if (comunidadId in ruta) return 0f
        val c = porId[comunidadId] ?: return Float.MAX_VALUE
        return puntosRuta.minOfOrNull { r -> hypot(c.croquisX - r.croquisX, c.croquisY - r.croquisY) } ?: Float.MAX_VALUE
    }

    return filas.sortedWith(
        compareBy<FilaProveedor>(
            { if (it.proveedor.vehiculoId == v.id) 0 else 1 },         // los míos primero
            { distancia(it.proveedor.comunidadId) },                   // luego, los más cercanos
            { ruta.indexOf(it.proveedor.comunidadId).let { i -> if (i < 0) Int.MAX_VALUE else i } }, // orden de la ruta
            { it.comunidad },
            { it.proveedor.nombre },
        )
    )
}
