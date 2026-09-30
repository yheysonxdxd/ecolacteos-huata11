package pe.gob.huata.ecolacteos.datos

import io.ktor.client.*
import io.ktor.client.call.*
import io.ktor.client.plugins.contentnegotiation.*
import io.ktor.client.request.*
import io.ktor.http.*
import io.ktor.serialization.kotlinx.json.*
import kotlinx.serialization.json.*
import pe.gob.huata.ecolacteos.ui.acopiador.Demo
import pe.gob.huata.ecolacteos.ui.acopiador.VehiculoElegido

/**
 * Conexión con ecolacteos-api (Laravel). Levantar el servidor con:
 *   php artisan serve --host=0.0.0.0 --port=8000
 * En el emulador de Android la PC es 10.0.2.2. En un celular real, poner la IP
 * de la PC en la red local (ej. http://192.168.1.50:8000).
 */
object ConfigServidor {
    var baseUrl = "http://10.0.2.2:8000"
    // usuario de prueba del DatabaseSeeder, usado solo en el modo demo
    var dni = "40000001"
    var password = "huata2026"
}

/** Sesión del usuario contra el servidor: token, rol y datos reales. */
object Sesion {
    var token: String? = null
        private set
    var usuarioId: Long? = null
        private set
    var nombre: String = ""
        private set
    /** true cuando Demo.* se llenó con datos de la base de datos. */
    var desdeServidor = false
        private set

    val http by lazy {
        HttpClient { install(ContentNegotiation) { json(Json { ignoreUnknownKeys = true; isLenient = true }) } }
    }

    /** POST /api/v1/login. Devuelve el rol del usuario. */
    suspend fun login(dni: String, password: String): Rol {
        val r: JsonObject = http.post("${ConfigServidor.baseUrl}/api/v1/login") {
            contentType(ContentType.Application.Json)
            accept(ContentType.Application.Json)
            setBody(buildJsonObject {
                put("dni", dni)
                put("password", password)
                put("dispositivo", "app-ecolacteos")
            })
        }.body()
        token = r.texto("token") ?: error(r.texto("message") ?: "No se pudo iniciar sesión")
        val u = r["usuario"]?.jsonObject
        usuarioId = u?.get("id")?.jsonPrimitive?.longOrNull
        nombre = u?.texto("nombre") ?: ""
        val rol = r.texto("rol") ?: "ACOPIADOR"
        return Rol.entries.firstOrNull { it.name == rol } ?: error("Rol desconocido: $rol")
    }

    /** GET /sync/bootstrap y reemplaza los datos de prueba por los reales. */
    suspend fun cargarDatos() {
        val b = get("/api/v1/sync/bootstrap")
        SesionGuardada.guardarDatos(b)
        aplicarDatos(b)
    }

    /** Llena Demo.* con el bootstrap (recién bajado o la copia guardada en el teléfono). */
    fun aplicarDatos(b: JsonObject) {
        Demo.comunidades = b["comunidades"]!!.jsonArray.map { it.jsonObject }.map { c ->
            Comunidad(
                id = c.long("id"), nombre = c.texto("nombre")!!,
                croquisX = c.double("croquis_x").toFloat(), croquisY = c.double("croquis_y").toFloat(),
                vias = c.texto("vias"),
            )
        }
        Demo.vehiculos = b["vehiculos"]!!.jsonArray.map { it.jsonObject }.map { v ->
            Vehiculo(
                id = v.long("id"), codigo = v.texto("codigo")!!,
                tipo = if (v.texto("tipo") == "MOTOCAR") TipoVehiculo.MOTOCAR else TipoVehiculo.CARRO,
                conductor = (v["acopiador"] as? JsonObject)?.texto("name"),
                rutaCircular = v["ruta_circular"]?.jsonPrimitive?.booleanOrNull ?: false,
                ordenRuta = (v["orden_ruta"] as? JsonArray)?.map { it.jsonPrimitive.content.toLong() } ?: emptyList(),
            )
        }
        Demo.proveedores = b["proveedores"]!!.jsonArray.map { it.jsonObject }.map { p ->
            Proveedor(
                id = p.long("id"), uuid = p.texto("uuid") ?: "", nombre = p.texto("nombre")!!,
                dni = p.texto("dni") ?: "", comunidadId = p.long("comunidad_id"), vehiculoId = p.long("vehiculo_id"),
                promedioLitros = p.texto("promedio_litros")?.toDoubleOrNull(),
                vacasOrdeno = p.texto("vacas_ordeno")?.toIntOrNull(),
                estado = p.texto("estado") ?: "ACTIVO",
            )
        }
        desdeServidor = true
    }

    /** POST /vehiculos/{id}/tomar: el acopiador sale hoy con este vehículo. */
    suspend fun tomarVehiculo(vehiculoId: Long) {
        val r = http.post("${ConfigServidor.baseUrl}/api/v1/vehiculos/$vehiculoId/tomar") {
            accept(ContentType.Application.Json)
            token?.let { bearerAuth(it) }
        }
        if (!r.status.isSuccess()) error("el servidor respondió ${r.status.value}")
    }

    /** POST con cuerpo JSON a una ruta de la API; devuelve la respuesta. */
    suspend fun enviar(ruta: String, cuerpo: JsonObject): JsonObject {
        val r = http.post("${ConfigServidor.baseUrl}$ruta") {
            contentType(ContentType.Application.Json)
            accept(ContentType.Application.Json)
            token?.let { bearerAuth(it) }
            setBody(cuerpo)
        }
        if (!r.status.isSuccess()) {
            val msg = runCatching { r.body<JsonObject>().texto("message") }.getOrNull()
            error(msg ?: "el servidor respondió ${r.status.value}")
        }
        return r.body()
    }

    /** GET de solo lectura para las pantallas (resúmenes, semana, pagos). */
    suspend fun consultar(ruta: String): JsonObject = get(ruta)

    /**
     * Envía una operación por /sync/push y devuelve su resultado completo
     * (veredicto, sanción, etc.). Lanza error si el servidor la rechaza.
     */
    suspend fun enviarOperacion(tipo: TipoOperacion, cuerpo: JsonObject): JsonObject {
        val r = http.post("${ConfigServidor.baseUrl}/api/v1/sync/push") {
            contentType(ContentType.Application.Json)
            accept(ContentType.Application.Json)
            token?.let { bearerAuth(it) }
            setBody(buildJsonObject {
                putJsonArray("operaciones") {
                    addJsonObject {
                        put("uuid", nuevoUuid())
                        put("tipo", tipo.name)
                        cuerpo.forEach { (k, v) -> put(k, v) }
                    }
                }
            })
        }
        if (!r.status.isSuccess()) {
            val msg = runCatching { r.body<JsonObject>().texto("message") }.getOrNull()
            error(msg ?: "el servidor respondió ${r.status.value}")
        }
        val res = r.body<JsonObject>()["resultados"]?.jsonArray?.firstOrNull()?.jsonObject
            ?: error("sin respuesta del servidor")
        if (res.texto("estado") == "ERROR") error(res.texto("error") ?: "error en el servidor")
        return res
    }

    /** El productor confirma que los litros de su entrega son correctos. */
    suspend fun confirmarEntrega(entregaId: Long) {
        enviarOperacion(TipoOperacion.CONFIRMACION, buildJsonObject { put("entrega_id", entregaId) })
    }

    /** Vuelve a poner la sesión guardada en el teléfono (ver SesionGuardada). */
    fun restaurar(token: String, usuarioId: Long?, nombre: String) {
        this.token = token; this.usuarioId = usuarioId; this.nombre = nombre
    }

    /** Solo el token para sincronizar en modo demo (no cambia los datos). */
    suspend fun asegurarToken() {
        if (token == null) login(ConfigServidor.dni, ConfigServidor.password)
    }

    fun cerrar() {
        token = null; usuarioId = null; nombre = ""
        VehiculoElegido.id = null
        pe.gob.huata.ecolacteos.ui.roles.CalidadReal.limpiar()
        pe.gob.huata.ecolacteos.ui.roles.CalidadReal.mensaje = null
    }

    private suspend fun get(ruta: String): JsonObject {
        val r = http.get("${ConfigServidor.baseUrl}$ruta") {
            accept(ContentType.Application.Json)
            token?.let { bearerAuth(it) }
        }
        if (r.status == HttpStatusCode.Unauthorized) { token = null; error("la sesión venció, vuelve a ingresar") }
        if (!r.status.isSuccess()) {
            val msg = runCatching { r.body<JsonObject>().texto("message") }.getOrNull()
            error(msg ?: "el servidor respondió ${r.status.value}")
        }
        return r.body()
    }
}

private fun JsonObject.texto(k: String): String? = (this[k] as? JsonPrimitive)?.takeIf { it !is JsonNull }?.content
private fun JsonObject.long(k: String): Long = texto(k)!!.toDouble().toLong()
private fun JsonObject.double(k: String): Double = texto(k)?.toDoubleOrNull() ?: 0.0
