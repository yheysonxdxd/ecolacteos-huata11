package pe.gob.huata.ecolacteos.ui.acopiador

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import kotlinx.serialization.builtins.ListSerializer
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import pe.gob.huata.ecolacteos.datos.*
import pe.gob.huata.ecolacteos.tema.*
import kotlin.time.Clock
import kotlin.time.ExperimentalTime

/**
 * Alta de proveedor desde el campo, con o sin señal.
 *
 * Con datos del servidor: el proveedor entra con un id temporal negativo,
 * se guarda en el teléfono y ya se le pueden anotar litros. Al haber señal
 * se sube (operación PROVEEDOR con su uuid, idempotente) y el id temporal se
 * cambia por el real, también en sus entregas. Si el DNI ya existía, el
 * servidor devuelve ese proveedor y se usa ese.
 *
 * En modo demo solo se agrega a la lista: el envío demo ya crea los
 * proveedores en el servidor.
 */
object ProveedoresNuevos {
    private const val CLAVE_VIEJA = "proveedores_nuevos"
    // una por acopiador (celular compartido); la vieja pasa al primero que entre
    private val CLAVE: String get() {
        val clave = "proveedores_nuevos_${Sesion.usuarioId}"
        if (AlmacenLocal.leer(clave) == null) AlmacenLocal.leer(CLAVE_VIEJA)?.let {
            AlmacenLocal.guardar(clave, it); AlmacenLocal.borrar(CLAVE_VIEJA)
        }
        return clave
    }
    private val json = Json { ignoreUnknownKeys = true }

    /** Cambia cuando se agrega o se sube un proveedor (para refrescar la lista). */
    var version by mutableStateOf(0)
        private set
    var formularioAbierto by mutableStateOf(false)

    fun abrir() { formularioAbierto = true }

    private fun pendientes(): List<Proveedor> =
        AlmacenLocal.leer(CLAVE)
            ?.let { runCatching { json.decodeFromString(ListSerializer(Proveedor.serializer()), it) }.getOrNull() }
            ?: emptyList()

    private fun guardarPendientes(lista: List<Proveedor>) {
        if (lista.isEmpty()) AlmacenLocal.borrar(CLAVE)
        else AlmacenLocal.guardar(CLAVE, json.encodeToString(ListSerializer(Proveedor.serializer()), lista))
    }

    /** Devuelve un mensaje de error, o null si se agregó. */
    @OptIn(ExperimentalTime::class)
    fun agregar(
        nombre: String, dni: String, comunidadId: Long, vehiculoId: Long,
        promedioLitros: Double?, vacas: Int?,
    ): String? {
        val limpio = nombre.trim().replace(Regex("\\s+"), " ")
        if (limpio.length < 5 || ' ' !in limpio) return "Escribe nombre y apellidos"
        if (dni.length != 8) return "El DNI debe tener 8 dígitos"
        Demo.proveedores.firstOrNull { it.dni == dni }?.let { return "Ese DNI ya es de ${it.nombre}" }

        val p = Proveedor(
            id = if (Sesion.desdeServidor) -Clock.System.now().toEpochMilliseconds()
                 else (Demo.proveedores.maxOfOrNull { it.id } ?: 0) + 1,
            uuid = nuevoUuid(),
            nombre = limpio, dni = dni,
            comunidadId = comunidadId, vehiculoId = vehiculoId,
            promedioLitros = promedioLitros?.takeIf { it > 0 },
            vacasOrdeno = vacas?.takeIf { it > 0 },
        )
        Demo.proveedores = Demo.proveedores + p
        if (Sesion.desdeServidor) guardarPendientes(pendientes() + p)
        version++
        return null
    }

    /** Vuelve a poner en la lista los que aún no se subieron (tras cerrar la app). */
    fun restaurar() {
        val faltan = pendientes().filter { p -> Demo.proveedores.none { it.id == p.id } }
        if (faltan.isNotEmpty()) {
            Demo.proveedores = Demo.proveedores + faltan
            version++
        }
    }

    /**
     * Sube los proveedores pendientes y cambia su id temporal por el real.
     * Devuelve la lista de errores (vacía si todo bien). Lanza si no hay red.
     */
    suspend fun subirPendientes(estado: EstadoAcopio): List<String> {
        val lista = pendientes()
        if (lista.isEmpty()) return emptyList()
        val ops = lista.map { p ->
            OperacionPendiente(
                uuid = p.uuid, tipo = TipoOperacion.PROVEEDOR,
                cuerpo = buildJsonObject {
                    put("nombre", p.nombre)
                    put("dni", p.dni)
                    put("comunidad_id", p.comunidadId)
                    put("vehiculo_id", p.vehiculoId)
                    p.promedioLitros?.let { put("promedio_litros", it) }
                    p.vacasOrdeno?.let { put("vacas_ordeno", it) }
                },
            )
        }
        val r = ClienteSync(ConfigServidor.baseUrl) { Sesion.token }.push(ops)

        val errores = mutableListOf<String>()
        val quedan = mutableListOf<Proveedor>()
        r.resultados.zip(lista).forEach { (res, p) ->
            val idReal = res.id
            if (res.estado == "ERROR" || idReal == null) {
                errores += "${p.nombre}: ${res.error ?: "no se pudo registrar"}"
                quedan += p
                return@forEach
            }
            val yaExistia = Demo.proveedores.any { it.id == idReal }
            Demo.proveedores =
                if (yaExistia) Demo.proveedores.filter { it.id != p.id }
                else Demo.proveedores.map { if (it.id == p.id) it.copy(id = idReal) else it }
            estado.entregas.remove(p.id)?.let { estado.entregas[idReal] = it }
            if (estado.enviados.remove(p.id)) estado.enviados.add(idReal)
            if (yaExistia) {
                val nombre = Demo.proveedores.first { it.id == idReal }.nombre
                errores += "El DNI ${p.dni} ya estaba registrado como $nombre; se usó ese proveedor"
            }
        }
        guardarPendientes(quedan)
        version++
        return errores
    }
}

/**
 * Se pone al inicio de ContenidoAcopiador. No reinicia por su cuenta: así,
 * cuando cambia [ProveedoresNuevos.version], la lista de proveedores de la
 * pantalla se vuelve a armar.
 */
@Composable
@NonRestartableComposable
fun AltaProveedor(estado: EstadoAcopio) {
    ProveedoresNuevos.version // lectura a propósito
    val scope = rememberCoroutineScope()
    if (ProveedoresNuevos.formularioAbierto) {
        HojaNuevoProveedor(
            onCerrar = { ProveedoresNuevos.formularioAbierto = false },
            onAgregado = { nombre ->
                ProveedoresNuevos.formularioAbierto = false
                ResultadoSync.huboError = false
                ResultadoSync.mensaje = "✓ $nombre agregado. Ya puedes anotar sus litros."
                // si hay señal se sube de una vez; si no, se sube al enviar
                if (Sesion.desdeServidor) scope.launch {
                    val errores = runCatching { ProveedoresNuevos.subirPendientes(estado) }.getOrNull()
                    if (!errores.isNullOrEmpty()) {
                        ResultadoSync.huboError = true
                        ResultadoSync.mensaje = errores.first()
                    }
                }
            },
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
private fun HojaNuevoProveedor(onCerrar: () -> Unit, onAgregado: (String) -> Unit) {
    var nombre by remember { mutableStateOf("") }
    var dni by remember { mutableStateOf("") }
    val vehiculoInicial = VehiculoElegido.id ?: Demo.vehiculos.firstOrNull()?.id
    var vehiculoId by remember { mutableStateOf(vehiculoInicial) }
    var comunidadId by remember {
        mutableStateOf(Demo.vehiculos.firstOrNull { it.id == vehiculoInicial }?.ordenRuta?.firstOrNull())
    }
    var promedio by remember { mutableStateOf("") }
    var vacas by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }

    val colores = OutlinedTextFieldDefaults.colors(
        focusedTextColor = Altiplano.Texto, unfocusedTextColor = Altiplano.Texto,
        focusedBorderColor = Altiplano.VerdeMarca, unfocusedBorderColor = Altiplano.Borde,
        focusedLabelColor = Altiplano.TextoSecundario, unfocusedLabelColor = Altiplano.TextoTerciario,
        cursorColor = Altiplano.Exito,
    )

    ModalBottomSheet(onDismissRequest = onCerrar, containerColor = Color(0xFF142333)) {
        Column(
            Modifier.verticalScroll(rememberScrollState())
                .padding(horizontal = Medidas.Pantalla).padding(bottom = 22.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text("Nuevo proveedor", style = Texto.TituloPantalla, color = Altiplano.Texto)

            OutlinedTextField(
                value = nombre, onValueChange = { nombre = it.take(80) },
                label = { Text("Nombre y apellidos") }, singleLine = true,
                keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Words),
                colors = colores, modifier = Modifier.fillMaxWidth(),
            )
            OutlinedTextField(
                value = dni, onValueChange = { dni = it.filter(Char::isDigit).take(8) },
                label = { Text("DNI") }, singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                colors = colores, modifier = Modifier.fillMaxWidth(),
            )

            Text("VEHÍCULO", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Demo.vehiculos.forEach { v ->
                    Opcion(v.codigo, v.id == vehiculoId) {
                        vehiculoId = v.id
                        if (comunidadId !in v.ordenRuta) comunidadId = v.ordenRuta.firstOrNull() ?: comunidadId
                    }
                }
            }

            Text("COMUNIDAD", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
            val deRuta = Demo.vehiculos.firstOrNull { it.id == vehiculoId }?.ordenRuta.orEmpty()
            // primero las comunidades de la ruta del vehículo
            val comunidades = Demo.comunidades.sortedBy { c -> deRuta.indexOf(c.id).let { if (it < 0) 99 else it } }
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                comunidades.forEach { c -> Opcion(c.nombre, c.id == comunidadId) { comunidadId = c.id } }
            }

            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                OutlinedTextField(
                    value = promedio, onValueChange = { promedio = it.filter { ch -> ch.isDigit() || ch == '.' }.take(5) },
                    label = { Text("Litros/día (opcional)") }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                    colors = colores, modifier = Modifier.weight(1f),
                )
                OutlinedTextField(
                    value = vacas, onValueChange = { vacas = it.filter(Char::isDigit).take(3) },
                    label = { Text("Vacas (opcional)") }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                    colors = colores, modifier = Modifier.weight(1f),
                )
            }

            error?.let { Text(it, style = Texto.Apoyo, color = Altiplano.Aviso) }

            Surface(
                onClick = {
                    val v = vehiculoId
                    val c = comunidadId
                    error = when {
                        v == null -> "Elige el vehículo"
                        c == null -> "Elige la comunidad"
                        else -> ProveedoresNuevos.agregar(
                            nombre, dni, c, v, promedio.toDoubleOrNull(), vacas.toIntOrNull(),
                        )
                    }
                    if (error == null) onAgregado(nombre.trim())
                },
                modifier = Modifier.fillMaxWidth().height(Medidas.Stepper),
                shape = RoundedCornerShape(16.dp),
                color = Altiplano.VerdeMarcaSuave,
                border = BorderStroke(1.dp, Altiplano.VerdeMarca),
            ) {
                Box(contentAlignment = androidx.compose.ui.Alignment.Center) {
                    Text("Agregar proveedor", style = Texto.Fila, color = Altiplano.Texto)
                }
            }
        }
    }
}

@Composable
private fun Opcion(texto: String, elegida: Boolean, onClick: () -> Unit) {
    Surface(
        onClick = onClick,
        shape = RoundedCornerShape(12.dp),
        color = if (elegida) Altiplano.VerdeMarcaSuave else Altiplano.Superficie,
        border = BorderStroke(1.dp, if (elegida) Altiplano.VerdeMarca else Altiplano.Borde),
    ) {
        Text(
            texto, style = Texto.Cuerpo,
            color = if (elegida) Altiplano.Texto else Altiplano.TextoSecundario,
            modifier = Modifier.padding(horizontal = 12.dp, vertical = 9.dp),
        )
    }
}
