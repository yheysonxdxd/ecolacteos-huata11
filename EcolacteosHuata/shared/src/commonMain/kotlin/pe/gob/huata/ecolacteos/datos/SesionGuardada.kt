package pe.gob.huata.ecolacteos.datos

import io.ktor.client.request.*
import io.ktor.http.*
import io.ktor.util.hex
import io.ktor.util.sha1
import kotlinx.serialization.Serializable
import kotlinx.serialization.builtins.ListSerializer
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonPrimitive

/**
 * La sesión queda guardada en el teléfono: al abrir la app se entra directo,
 * sin volver a escribir DNI y contraseña. Solo "Cerrar sesión" la borra.
 *
 * También se guarda la última copia de los datos del servidor (proveedores,
 * vehículos, comunidades), para que el acopiador pueda abrir la app y
 * registrar aunque no tenga señal.
 */
object SesionGuardada {
    private const val CLAVE_SESION = "sesion"
    private const val CLAVE_DATOS = "bootstrap"
    private const val CLAVE_SERVIDOR = "servidor"

    @Serializable
    private data class Guardada(
        val token: String,
        val usuarioId: Long? = null,
        val nombre: String = "",
        val rol: String,
        val servidor: String,
    )

    sealed interface Resultado {
        /** Entró con la sesión guardada. [sinConexion]: con la copia local de los datos. */
        data class Entro(val rol: Rol, val sinConexion: Boolean) : Resultado
        /** El servidor dijo que el token ya no vale: hay que volver a ingresar. */
        data object Vencida : Resultado
        /** No hay sesión guardada, o no se pudo entrar sin señal. */
        data object Nada : Resultado
    }

    private val json = Json { ignoreUnknownKeys = true }

    /** Dirección del último servidor usado (para no volver a escribirla). */
    fun servidor(): String? = AlmacenLocal.leer(CLAVE_SERVIDOR)

    /** Llamar después de un login correcto contra el servidor. */
    fun guardar(rol: Rol) {
        val token = Sesion.token ?: return
        AlmacenLocal.guardar(CLAVE_SERVIDOR, ConfigServidor.baseUrl)
        AlmacenLocal.guardar(
            CLAVE_SESION,
            json.encodeToString(
                Guardada.serializer(),
                Guardada(token, Sesion.usuarioId, Sesion.nombre, rol.name, ConfigServidor.baseUrl),
            ),
        )
    }

    fun guardarDatos(bootstrap: JsonObject) {
        AlmacenLocal.guardar(CLAVE_DATOS, bootstrap.toString())
    }

    /** true cuando el usuario tocó "Cerrar sesión" (no volver a entrar solo). */
    var salidaPedida = false

    /** Mensaje para la pantalla de login (ej. sesión vencida); se lee una vez. */
    var aviso: String? = null
    fun tomarAviso(): String? = aviso.also { aviso = null }

    /** Intenta entrar con la sesión guardada. */
    suspend fun restaurar(): Resultado {
        servidor()?.let { ConfigServidor.baseUrl = it }
        val g = AlmacenLocal.leer(CLAVE_SESION)
            ?.let { runCatching { json.decodeFromString(Guardada.serializer(), it) }.getOrNull() }
            ?: return Resultado.Nada
        ConfigServidor.baseUrl = g.servidor
        Sesion.restaurar(g.token, g.usuarioId, g.nombre)

        return try {
            // /yo confirma que el token sigue vigente y trae el rol actual
            val yo = Sesion.consultar("/api/v1/yo")
            val rol = Rol.entries.firstOrNull { it.name == yo["rol"]?.jsonPrimitive?.content }
                ?: return Resultado.Nada.also { borrar() }
            Sesion.cargarDatos()
            guardar(rol)
            Resultado.Entro(rol, sinConexion = false)
        } catch (e: Throwable) {
            if (Sesion.token == null) {
                // 401: el token fue revocado o venció (tampoco sirve para entrar sin conexión)
                olvidarAcceso(g.token)
                borrar()
                return Resultado.Vencida
            }
            // sin señal: se entra con la última copia de los datos
            val copia = AlmacenLocal.leer(CLAVE_DATOS)
                ?.let { runCatching { json.decodeFromString(JsonObject.serializer(), it) }.getOrNull() }
                ?: return Resultado.Nada
            val rol = Rol.entries.firstOrNull { it.name == g.rol } ?: return Resultado.Nada
            runCatching { Sesion.aplicarDatos(copia) }.getOrElse { return Resultado.Nada }
            Resultado.Entro(rol, sinConexion = true)
        }
    }

    /**
     * Cerrar sesión: la app ya no entra sola, pero el acceso sin conexión
     * (ver [recordarAcceso]) y los datos guardados se quedan, para que quien
     * salió por error en el campo pueda volver a entrar sin señal.
     */
    fun cerrarSesion() {
        AlmacenLocal.borrar(CLAVE_SESION)
    }

    // --- volver a entrar sin conexión ---

    /** Quién ya entró con conexión en este teléfono; su contraseña solo como huella (con sal). */
    @Serializable
    private data class Acceso(val dni: String, val sal: String, val huella: String, val sesion: Guardada)

    private const val CLAVE_ACCESOS = "accesos_sin_conexion"
    private const val MAX_ACCESOS = 5 // un celular lo pueden compartir varios acopiadores

    private fun accesos(): List<Acceso> =
        AlmacenLocal.leer(CLAVE_ACCESOS)
            ?.let { runCatching { json.decodeFromString(ListSerializer(Acceso.serializer()), it) }.getOrNull() }
            ?: emptyList()

    private fun huella(sal: String, clave: String): String {
        var h = "$sal:$clave".encodeToByteArray()
        repeat(5_000) { h = sha1(h + sal.encodeToByteArray()) } // lento a propósito
        return hex(h)
    }

    /** Llamar después de un login correcto contra el servidor (tras [guardar]). */
    suspend fun recordarAcceso(dni: String, clave: String) {
        val g = AlmacenLocal.leer(CLAVE_SESION)
            ?.let { runCatching { json.decodeFromString(Guardada.serializer(), it) }.getOrNull() } ?: return
        val antes = accesos()
        // el token anterior de esta persona ya no se usará: se cierra en el servidor
        antes.firstOrNull { it.dni == dni && it.sesion.token != g.token }?.let { viejo ->
            runCatching {
                Sesion.http.post("${viejo.sesion.servidor}/api/v1/logout") {
                    accept(ContentType.Application.Json)
                    bearerAuth(viejo.sesion.token)
                }
            }
        }
        val sal = nuevoUuid()
        val nuevo = Acceso(dni, sal, huella(sal, clave), g)
        val lista = (listOf(nuevo) + antes.filter { it.dni != dni }).take(MAX_ACCESOS)
        AlmacenLocal.guardar(CLAVE_ACCESOS, json.encodeToString(ListSerializer(Acceso.serializer()), lista))
    }

    private fun olvidarAcceso(token: String) {
        val lista = accesos().filter { it.sesion.token != token }
        AlmacenLocal.guardar(CLAVE_ACCESOS, json.encodeToString(ListSerializer(Acceso.serializer()), lista))
    }

    /**
     * Entrar sin conexión con DNI y contraseña: vale solo para quien ya entró
     * antes con conexión en este teléfono. Usa los datos guardados.
     */
    fun entrarSinConexion(dni: String, clave: String): Rol {
        val a = accesos().firstOrNull { it.dni == dni }
            ?: error("Sin conexión. Para entrar sin señal, este usuario debe haber ingresado antes con conexión en este celular.")
        if (huella(a.sal, clave) != a.huella) error("Contraseña incorrecta")
        val copia = AlmacenLocal.leer(CLAVE_DATOS)
            ?.let { runCatching { json.decodeFromString(JsonObject.serializer(), it) }.getOrNull() }
            ?: error("Sin conexión y sin datos guardados en este celular. Entra una vez con conexión.")
        val rol = Rol.entries.firstOrNull { it.name == a.sesion.rol } ?: error("Rol desconocido: ${a.sesion.rol}")
        ConfigServidor.baseUrl = a.sesion.servidor
        Sesion.restaurar(a.sesion.token, a.sesion.usuarioId, a.sesion.nombre)
        Sesion.aplicarDatos(copia)
        AlmacenLocal.guardar(CLAVE_SESION, json.encodeToString(Guardada.serializer(), a.sesion))
        return rol
    }

    private fun borrar() {
        AlmacenLocal.borrar(CLAVE_SESION)
        AlmacenLocal.borrar(CLAVE_DATOS)
        CopiaLecturas.borrarTodo()
    }
}
