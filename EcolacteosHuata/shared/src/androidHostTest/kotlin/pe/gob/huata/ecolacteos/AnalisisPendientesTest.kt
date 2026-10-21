package pe.gob.huata.ecolacteos

import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import pe.gob.huata.ecolacteos.datos.*
import kotlin.test.*

/**
 * Análisis de calidad anotados sin conexión: quedan en el teléfono y no se
 * pierden si el envío falla. (En la PC el almacén local usa memoria.)
 */
class AnalisisPendientesTest {
    private val antes = ConfigServidor.baseUrl

    private fun pendiente(uuid: String, entrega: Long) = AnalisisPendientes.Pendiente(
        uuid, entrega, "Proveedor $entrega",
        buildJsonObject { put("entrega_id", entrega); put("grasa", 3.4); put("aceptar", true) },
    )

    @AfterTest
    fun limpiar() {
        ConfigServidor.baseUrl = antes
        AlmacenLocal.borrar("analisis_pendientes:0")
        AnalisisPendientes.refrescar()
    }

    @Test
    fun seGuardanYNoSeRepiten() {
        AnalisisPendientes.agregar(pendiente("a1", 10))
        AnalisisPendientes.agregar(pendiente("a2", 11))
        AnalisisPendientes.agregar(pendiente("a1", 10)) // el mismo otra vez
        assertEquals(2, AnalisisPendientes.cantidad)
        assertEquals(setOf(10L, 11L), AnalisisPendientes.entregas())
        assertEquals(3.4, AnalisisPendientes.todos().first().cuerpo["grasa"].toString().toDouble())
    }

    @Test
    fun sinConexionNoSePierdeNada() = runBlocking {
        AnalisisPendientes.agregar(pendiente("b1", 20))
        ConfigServidor.baseUrl = "http://127.0.0.1:1" // nadie escucha ahí: como estar sin señal

        val enviados = AnalisisPendientes.enviarTodos()

        assertTrue(enviados.isEmpty())
        assertEquals(1, AnalisisPendientes.cantidad)
        assertFalse(AnalisisPendientes.enviando)
    }

    @Test
    fun soloLaFaltaDeConexionSeGuardaParaDespues() {
        assertTrue(esFaltaDeConexion(java.net.ConnectException("sin red")))
        assertFalse(esFaltaDeConexion(RechazoServidor("ya fue analizada")))
        assertFalse(esFaltaDeConexion(IllegalStateException("la sesión venció")))
    }
}
