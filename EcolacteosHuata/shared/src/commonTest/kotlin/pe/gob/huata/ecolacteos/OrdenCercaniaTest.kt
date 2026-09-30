package pe.gob.huata.ecolacteos

import pe.gob.huata.ecolacteos.datos.*
import pe.gob.huata.ecolacteos.ui.acopiador.FilaProveedor
import pe.gob.huata.ecolacteos.ui.acopiador.ordenarPorCercania
import kotlin.test.Test
import kotlin.test.assertEquals

/** La lista del acopiador empieza por su ruta y sigue con los más cercanos. */
class OrdenCercaniaTest {
    // croquis: Huatta (0,0) — Carata (10,0) — Kapi (100,0) — Lluco (110,0)
    private val comunidades = listOf(
        Comunidad(1, "Huatta", 0f, 0f), Comunidad(2, "Carata", 10f, 0f),
        Comunidad(3, "Kapi", 100f, 0f), Comunidad(4, "Lluco", 110f, 0f),
    )
    private val vehiculos = listOf(
        Vehiculo(1, "C-01", TipoVehiculo.CARRO, ordenRuta = listOf(2, 1)),  // Carata → Huatta
        Vehiculo(2, "M-01", TipoVehiculo.MOTOCAR, ordenRuta = listOf(4, 3)), // Lluco → Kapi
    )

    private fun fila(id: Long, nombre: String, comunidad: Long, vehiculo: Long) = FilaProveedor(
        Proveedor(id, "p$id", nombre, "4000000$id", comunidad, vehiculo),
        comunidades.first { it.id == comunidad }.nombre, vehiculos.first { it.id == vehiculo }.codigo, null,
    )

    private val filas = listOf(
        fila(1, "Ana (Huatta, C-01)", 1, 1),
        fila(2, "Beto (Kapi, M-01)", 3, 2),
        fila(3, "Carla (Carata, C-01)", 2, 1),
        fila(4, "Dora (Lluco, M-01)", 4, 2),
    )

    private fun orden(vehiculo: Long?) = ordenarPorCercania(filas, vehiculo, vehiculos, comunidades).map { it.proveedor.nombre.substringBefore(' ') }

    @Test
    fun sin_vehiculo_la_lista_queda_igual() {
        assertEquals(listOf("Ana", "Beto", "Carla", "Dora"), orden(null))
    }

    @Test
    fun con_C01_primero_su_ruta_en_orden_y_luego_lo_mas_cercano() {
        // ruta C-01: Carata y luego Huatta; después Kapi (más cerca de Carata) y Lluco
        assertEquals(listOf("Carla", "Ana", "Beto", "Dora"), orden(1))
    }

    @Test
    fun al_cambiar_de_carro_cambia_la_lista() {
        // ruta M-01: Lluco y luego Kapi; después Carata (más cerca de Kapi) y Huatta
        assertEquals(listOf("Dora", "Beto", "Carla", "Ana"), orden(2))
    }

    @Test
    fun vecino_de_otra_ruta_en_comunidad_de_mi_ruta_va_justo_despues_de_los_mios() {
        val conVecino = filas + fila(5, "Eva (Huatta, M-01)", 1, 2)
        val r = ordenarPorCercania(conVecino, 1, vehiculos, comunidades).map { it.proveedor.nombre.substringBefore(' ') }
        assertEquals(listOf("Carla", "Ana", "Eva", "Beto", "Dora"), r)
    }
}
