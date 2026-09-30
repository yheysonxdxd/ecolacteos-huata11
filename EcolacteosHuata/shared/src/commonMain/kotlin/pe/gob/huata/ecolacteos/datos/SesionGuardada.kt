package pe.gob.huata.ecolacteos.datos

import io.ktor.client.request.*
import io.ktor.http.*
import kotlinx.serialization.Serializable
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
                // 401: el token fue revocado o venció
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

    /** Cerrar sesión: se borra del teléfono y se avisa al servidor si hay señal. */
    suspend fun cerrarSesion() {
        val g = AlmacenLocal.leer(CLAVE_SESION)
            ?.let { runCatching { json.decodeFromString(Guardada.serializer(), it) }.getOrNull() }
        borrar()
        if (g != null) runCatching {
            Sesion.http.post("${g.servidor}/api/v1/logout") {
                accept(ContentType.Application.Json)
                bearerAuth(g.token)
            }
        }
    }

    private fun borrar() {
        AlmacenLocal.borrar(CLAVE_SESION)
        AlmacenLocal.borrar(CLAVE_DATOS)
    }
}
