package pe.gob.huata.ecolacteos

import pe.gob.huata.ecolacteos.ui.roles.limitarLitros
import pe.gob.huata.ecolacteos.ui.roles.litrosPara
import pe.gob.huata.ecolacteos.ui.roles.quesosCon
import kotlin.test.*

/** Nueva orden: los quesos pedidos nunca pasan lo que da la leche disponible. */
class OrdenPorQuesosTest {
    private val min = 12.0 // Queso Paria: 12 quesos por 100 L (rendimiento mínimo)

    @Test
    fun quesos_que_salen_seguro() {
        assertEquals(12, quesosCon(100.0, min))
        assertEquals(77, quesosCon(643.5, min))
        assertEquals(0, quesosCon(5.0, min))
    }

    @Test
    fun litros_para_los_quesos_pedidos() {
        assertEquals(84.0, litrosPara(10, min, 1000.0)) // 83,3 L → 84 L para asegurar 10
        assertTrue(quesosCon(litrosPara(10, min, 1000.0), min) >= 10)
    }

    @Test
    fun los_litros_nunca_pasan_el_tanque() {
        // tanque 641,9 L: el máximo son 77 quesos; 77 quesos pedirían 642 L → se queda en 641,9
        val max = quesosCon(641.9, min)
        assertEquals(77, max)
        assertEquals(641.9, litrosPara(max, min, 641.9))
    }

    @Test
    fun pasarse_de_litros_avisa_y_baja_a_lo_disponible() {
        val (litros, aviso) = limitarLitros(700.0, 643.5)
        assertEquals(643.5, litros)
        assertContains(aviso!!, "No se puede")
    }

    @Test
    fun litros_dentro_del_tanque_no_avisan() {
        assertEquals(600.0 to null, limitarLitros(600.0, 643.5))
    }

    @Test
    fun con_poca_leche_no_obliga_a_50_litros() {
        // mínimo de la pantalla: 50 L, pero si solo hay 30 L se usa lo que hay
        assertEquals(30.0 to null, limitarLitros(10.0, 30.0))
    }
}
