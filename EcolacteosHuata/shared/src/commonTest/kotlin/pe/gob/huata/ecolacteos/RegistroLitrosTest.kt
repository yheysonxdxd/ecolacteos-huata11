package pe.gob.huata.ecolacteos

import pe.gob.huata.ecolacteos.datos.Proveedor
import pe.gob.huata.ecolacteos.ui.acopiador.ENVIADA
import pe.gob.huata.ecolacteos.ui.acopiador.EstadoAcopio
import pe.gob.huata.ecolacteos.ui.acopiador.entregaParaMostrar
import pe.gob.huata.ecolacteos.ui.componentes.leerLitros
import kotlin.test.*

/** Registrar: el chip distingue enviado / sin enviar, y los litros se escriben con teclado. */
class RegistroLitrosTest {
    private val rosa = Proveedor(1, "p1", "Rosa Quispe", "40112233", 1, 1)

    @Test
    fun enviada_se_muestra_como_enviada() {
        val e = EstadoAcopio()
        e.guardar(rosa, 18.5)
        assertEquals("PENDIENTE", e.entregaParaMostrar(1)!!.estadoCalidad) // aún sin enviar

        e.enviados.add(1)
        assertEquals(ENVIADA, e.entregaParaMostrar(1)!!.estadoCalidad)
        assertEquals("PENDIENTE", e.entregas.getValue(1).estadoCalidad) // lo guardado no cambia
    }

    @Test
    fun corregir_una_enviada_vuelve_a_sin_enviar() {
        val e = EstadoAcopio()
        e.guardar(rosa, 18.5)
        e.enviados.add(1)
        e.guardar(rosa, 20.0)
        assertEquals("PENDIENTE", e.entregaParaMostrar(1)!!.estadoCalidad)
    }

    @Test
    fun sin_entrega_no_hay_nada_que_mostrar() {
        assertNull(EstadoAcopio().entregaParaMostrar(1))
    }

    @Test
    fun rechazo_definitivo_deja_lo_del_servidor_y_no_reintenta() {
        // se volvió a registrar a Rosa con 60 L, pero calidad ya había analizado sus 46 L
        val e = EstadoAcopio()
        e.guardar(rosa, 60.0)
        val r = pe.gob.huata.ecolacteos.datos.ResultadoOperacion(
            uuid = "x", estado = "ERROR", error = "No se cambió…", definitivo = true, litros = 46.0, ausente = false,
        )

        pe.gob.huata.ecolacteos.ui.acopiador.aceptarRechazoDefinitivo(e, 1, r)

        assertEquals(46.0, e.entregas.getValue(1).litros)
        assertEquals(0, e.pendientes) // ya no queda "sin enviar": no vuelve a dar error
    }

    @Test
    fun litros_con_coma_o_punto() {
        assertEquals(18.5, leerLitros("18,5"))
        assertEquals(18.5, leerLitros("18.5"))
        assertEquals(20.0, leerLitros("20"))
        assertNull(leerLitros(""))
        assertNull(leerLitros(","))
    }
}
