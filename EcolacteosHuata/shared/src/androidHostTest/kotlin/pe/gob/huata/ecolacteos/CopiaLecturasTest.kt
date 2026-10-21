package pe.gob.huata.ecolacteos

import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import pe.gob.huata.ecolacteos.datos.ConfigServidor
import pe.gob.huata.ecolacteos.datos.CopiaLecturas
import pe.gob.huata.ecolacteos.datos.Sesion
import kotlin.test.*

/**
 * Sin conexión las pantallas muestran lo último que se vio.
 * (En la PC el almacén local usa memoria; en el celular, SharedPreferences cifradas.)
 */
class CopiaLecturasTest {
    private val ruta = "/api/v1/admin/totales?periodo=prueba"
    private val antes = ConfigServidor.baseUrl

    @AfterTest
    fun limpiar() {
        ConfigServidor.baseUrl = antes
        CopiaLecturas.borrarTodo()
    }

    @Test
    fun sinConexionDevuelveLaUltimaCopiaConSuHora() = runBlocking {
        CopiaLecturas.guardar(ruta, buildJsonObject { put("litros", 1234) })
        ConfigServidor.baseUrl = "http://127.0.0.1:1" // nadie escucha ahí: como estar sin señal

        val c = Sesion.consultarConCopia(ruta)

        assertEquals("1234", c.datos["litros"].toString())
        assertTrue(Regex("""\d{1,2} [a-z]{3} \d{2}:\d{2}""").matches(c.hora), "hora: ${c.hora}")
    }

    @Test
    fun sinConexionYSinCopiaSigueElError() {
        ConfigServidor.baseUrl = "http://127.0.0.1:1"
        assertFails { runBlocking { Sesion.consultarConCopia("/api/v1/nunca-vista") } }
    }

    @Test
    fun cerrarSesionBorraLasCopias() = runBlocking {
        CopiaLecturas.guardar(ruta, buildJsonObject { put("litros", 1) })
        CopiaLecturas.borrarTodo()
        assertNull(CopiaLecturas.leer(ruta))
    }

    @Test
    fun laNotaDeVentaNoSeGuarda() = runBlocking {
        CopiaLecturas.guardar("/api/v1/compras/ventas/5/nota", buildJsonObject { put("pdf", "x") })
        assertNull(CopiaLecturas.leer("/api/v1/compras/ventas/5/nota"))
    }
}
