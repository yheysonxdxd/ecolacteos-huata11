package pe.gob.huata.ecolacteos

import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.*
import pe.gob.huata.ecolacteos.datos.*
import kotlin.test.*

/**
 * Quien cerró sesión sin señal (o por error en el campo) vuelve a entrar con
 * su DNI y contraseña, si ya había ingresado antes con conexión en el celular.
 * (En la PC el almacén local usa memoria.)
 */
class EntrarSinConexionTest {
    private val antes = ConfigServidor.baseUrl

    @BeforeTest
    fun ingresoConConexion() = runBlocking {
        ConfigServidor.baseUrl = "http://127.0.0.1:1" // nadie escucha ahí: como estar sin señal
        // lo que deja un login correcto: sesión, datos del servidor y el acceso recordado
        Sesion.restaurar("token-de-prueba", 7, "Acopiador de prueba")
        SesionGuardada.guardarDatos(buildJsonObject {
            putJsonArray("comunidades") {}
            putJsonArray("vehiculos") {}
            putJsonArray("proveedores") {}
        })
        SesionGuardada.guardar(Rol.ACOPIADOR)
        SesionGuardada.recordarAcceso("40000001", "huata2026")
        SesionGuardada.cerrarSesion()
        Sesion.cerrar()
    }

    @AfterTest
    fun limpiar() {
        ConfigServidor.baseUrl = antes
        AlmacenLocal.borrar("accesos_sin_conexion")
        AlmacenLocal.borrar("bootstrap")
        AlmacenLocal.borrar("sesion")
        Sesion.cerrar()
    }

    @Test
    fun conLaContrasenaCorrectaEntraSinSenal() {
        val rol = SesionGuardada.entrarSinConexion("40000001", "huata2026")
        assertEquals(Rol.ACOPIADOR, rol)
        assertEquals("token-de-prueba", Sesion.token)
        assertEquals("Acopiador de prueba", Sesion.nombre)
    }

    @Test
    fun conOtraContrasenaNoEntra() {
        val e = assertFails { SesionGuardada.entrarSinConexion("40000001", "otra-clave") }
        assertEquals("Contraseña incorrecta", e.message)
        assertNull(Sesion.token)
    }

    @Test
    fun quienNuncaEntroEnEsteCelularNoEntraSinSenal() {
        val e = assertFails { SesionGuardada.entrarSinConexion("40000003", "huata2026") }
        assertTrue(e.message!!.contains("debe haber ingresado antes"))
    }

    @Test
    fun laContrasenaNoQuedaGuardadaTalCual() {
        val guardado = AlmacenLocal.leer("accesos_sin_conexion")!!
        assertFalse(guardado.contains("huata2026"))
    }
}
