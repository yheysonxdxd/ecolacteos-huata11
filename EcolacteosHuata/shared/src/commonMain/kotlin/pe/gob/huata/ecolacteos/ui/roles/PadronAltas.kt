package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import pe.gob.huata.ecolacteos.datos.Sesion
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.acopiador.Demo
import pe.gob.huata.ecolacteos.ui.componentes.*

/**
 * Altas del Padrón para el admin: nuevo proveedor (con acceso opcional a la
 * app como productor), nuevo trabajador y activar/desactivar trabajadores.
 * Comunidades y vehículos salen de Demo.* (ya cargados del servidor al entrar).
 */
object TrabajadorElegido {
    var valor by mutableStateOf<JsonObject?>(null)
}

/** Botón "Nuevo …" arriba de la lista del Padrón, sus formularios y el diálogo de activar. */
@Composable
fun AltasPadron(trabajadores: Boolean, recargar: () -> Unit) {
    var abierto by remember { mutableStateOf(false) }
    var aviso by remember { mutableStateOf<String?>(null) }

    BotonPrimario(if (trabajadores) "+ Nuevo trabajador" else "+ Nuevo proveedor") { abierto = true; aviso = null }
    aviso?.let { AvisoCampo(it) }

    val listo = { texto: String -> abierto = false; aviso = texto; recargar() }
    if (abierto) {
        if (trabajadores) HojaTrabajador(onCerrar = { abierto = false }, onListo = listo)
        else HojaProveedor(onCerrar = { abierto = false }, onListo = listo)
    }
    TrabajadorElegido.valor?.let { u ->
        DialogoActivo(u, onCerrar = { TrabajadorElegido.valor = null }, onListo = { TrabajadorElegido.valor = null; aviso = it; recargar() })
    }
}

// ---------------------------------------------------------------- proveedor

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
private fun HojaProveedor(onCerrar: () -> Unit, onListo: (String) -> Unit) {
    var nombre by remember { mutableStateOf("") }
    var dni by remember { mutableStateOf("") }
    var comunidadId by remember { mutableStateOf<Long?>(null) }
    var vehiculoId by remember { mutableStateOf<Long?>(null) }
    var promedio by remember { mutableStateOf("") }
    var vacas by remember { mutableStateOf("") }
    var conAcceso by remember { mutableStateOf(false) }
    var clave by remember { mutableStateOf("") }
    var enviando by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()

    Hoja("Nuevo proveedor", onCerrar) {
        Campo(nombre, { nombre = it.take(120) }, "Nombre y apellidos", palabras = true)
        Campo(dni, { dni = it.filter(Char::isDigit).take(8) }, "DNI", teclado = KeyboardType.NumberPassword)

        Text("COMUNIDAD", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Demo.comunidades.sortedBy { it.nombre }.forEach { c ->
                Chip(c.nombre, c.id == comunidadId) {
                    comunidadId = c.id
                    // el vehículo que pasa por esa comunidad
                    vehiculoId = Demo.vehiculos.firstOrNull { c.id in it.ordenRuta }?.id ?: vehiculoId
                }
            }
        }
        Text("VEHÍCULO", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Demo.vehiculos.forEach { v -> Chip(v.codigo, v.id == vehiculoId) { vehiculoId = v.id } }
        }

        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Campo(promedio, { promedio = it.filter { ch -> ch.isDigit() || ch == '.' }.take(5) }, "Litros/día (opcional)",
                teclado = KeyboardType.Decimal, modifier = Modifier.weight(1f))
            Campo(vacas, { vacas = it.filter(Char::isDigit).take(3) }, "Vacas (opcional)",
                teclado = KeyboardType.Number, modifier = Modifier.weight(1f))
        }

        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text("Acceso a la app", style = Texto.Fila, color = Altiplano.Texto)
                Text("Para que vea sus entregas y pagos con su DNI", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            }
            Switch(conAcceso, { conAcceso = it }, colors = SwitchDefaults.colors(checkedTrackColor = Altiplano.VerdeMarca))
        }
        if (conAcceso) CampoClave(clave) { clave = it }

        error?.let { AvisoCampo(it, esAlerta = true) }
        BotonPrimario(if (enviando) "Guardando…" else "Agregar proveedor", habilitado = !enviando) {
            val c = comunidadId
            error = when {
                nombre.trim().length < 5 || ' ' !in nombre.trim() -> "Escribe nombre y apellidos"
                dni.length != 8 -> "El DNI debe tener 8 dígitos"
                c == null -> "Elige la comunidad"
                conAcceso && clave.length < 6 -> "La clave debe tener al menos 6 caracteres"
                else -> null
            }
            if (error != null || c == null) return@BotonPrimario
            scope.launch {
                enviando = true
                try {
                    Sesion.enviar("/api/v1/admin/proveedores", buildJsonObject {
                        put("nombre", nombre.trim()); put("dni", dni); put("comunidad_id", c)
                        vehiculoId?.let { put("vehiculo_id", it) }
                        promedio.toDoubleOrNull()?.let { put("promedio_litros", it) }
                        vacas.toIntOrNull()?.let { put("vacas_ordeno", it) }
                        put("con_acceso", conAcceso)
                        if (conAcceso) put("clave", clave)
                    })
                    onListo("✓ ${nombre.trim()} agregado al padrón" + if (conAcceso) " · entra a la app con su DNI" else "")
                } catch (t: Throwable) {
                    error = t.message ?: "sin conexión"
                }
                enviando = false
            }
        }
    }
}

// ---------------------------------------------------------------- trabajador

private val ROLES = listOf("ACOPIADOR" to "Acopiador", "CALIDAD" to "Calidad", "OPERARIO" to "Operario", "COMPRAS" to "Compras", "ADMIN" to "Administrador")

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
private fun HojaTrabajador(onCerrar: () -> Unit, onListo: (String) -> Unit) {
    var nombre by remember { mutableStateOf("") }
    var dni by remember { mutableStateOf("") }
    var rol by remember { mutableStateOf<String?>(null) }
    var clave by remember { mutableStateOf("") }
    var telefono by remember { mutableStateOf("") }
    var enviando by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()

    Hoja("Nuevo trabajador", onCerrar) {
        Campo(nombre, { nombre = it.take(120) }, "Nombre y apellidos", palabras = true)
        Campo(dni, { dni = it.filter(Char::isDigit).take(8) }, "DNI (con él entra a la app)", teclado = KeyboardType.NumberPassword)
        Text("ROL", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            ROLES.forEach { (k, t) -> Chip(t, rol == k) { rol = k } }
        }
        CampoClave(clave) { clave = it }
        Campo(telefono, { telefono = it.filter { ch -> ch.isDigit() || ch == '+' || ch == ' ' }.take(20) }, "Teléfono (opcional)", teclado = KeyboardType.Phone)
        Text("Pásale su DNI y esta clave. Los productores se agregan desde Proveedores.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)

        error?.let { AvisoCampo(it, esAlerta = true) }
        BotonPrimario(if (enviando) "Guardando…" else "Agregar trabajador", habilitado = !enviando) {
            val r = rol
            error = when {
                nombre.trim().length < 5 || ' ' !in nombre.trim() -> "Escribe nombre y apellidos"
                dni.length != 8 -> "El DNI debe tener 8 dígitos"
                r == null -> "Elige el rol"
                clave.length < 6 -> "La clave debe tener al menos 6 caracteres"
                else -> null
            }
            if (error != null || r == null) return@BotonPrimario
            scope.launch {
                enviando = true
                try {
                    Sesion.enviar("/api/v1/admin/trabajadores", buildJsonObject {
                        put("nombre", nombre.trim()); put("dni", dni); put("rol", r); put("clave", clave)
                        telefono.trim().takeIf { it.isNotEmpty() }?.let { put("telefono", it) }
                    })
                    onListo("✓ ${nombre.trim()} ya puede entrar como ${ROLES.first { it.first == r }.second.lowercase()}")
                } catch (t: Throwable) {
                    error = t.message ?: "sin conexión"
                }
                enviando = false
            }
        }
    }
}

// ---------------------------------------------------------------- activar

@Composable
private fun DialogoActivo(u: JsonObject, onCerrar: () -> Unit, onListo: (String) -> Unit) {
    val activo = u.txt("activo") == "true"
    val scope = rememberCoroutineScope()
    var error by remember { mutableStateOf<String?>(null) }
    AlertDialog(
        onDismissRequest = onCerrar,
        containerColor = Altiplano.Fondo,
        title = { Text(if (activo) "¿Desactivar a ${u.txt("nombre")}?" else "¿Activar a ${u.txt("nombre")}?", color = Altiplano.Texto) },
        text = {
            Column {
                Text(
                    if (activo) "No podrá entrar a la app y se cerrará en todos sus celulares. Lo que ya registró se conserva."
                    else "Podrá volver a entrar a la app con su DNI y su clave de siempre.",
                    color = Altiplano.TextoTerciario,
                )
                error?.let { Text(it, color = Altiplano.Alerta) }
            }
        },
        confirmButton = {
            TextButton(onClick = {
                scope.launch {
                    try {
                        Sesion.enviar("/api/v1/admin/trabajadores/${u.num("id").toLong()}/activo", buildJsonObject { put("activo", !activo) })
                        onListo(if (activo) "${u.txt("nombre")} quedó desactivado" else "✓ ${u.txt("nombre")} quedó activo")
                    } catch (t: Throwable) {
                        error = t.message ?: "sin conexión"
                    }
                }
            }) { Text(if (activo) "Desactivar" else "Activar", color = if (activo) Altiplano.Alerta else Altiplano.Exito) }
        },
        dismissButton = { TextButton(onClick = onCerrar) { Text("Cancelar", color = Altiplano.TextoTerciario) } },
    )
}

// ---------------------------------------------------------------- piezas

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun Hoja(titulo: String, onCerrar: () -> Unit, contenido: @Composable ColumnScope.() -> Unit) {
    ModalBottomSheet(
        onDismissRequest = onCerrar, containerColor = Color(0xFF142333),
        sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true),
    ) {
        Column(
            Modifier.imePadding().verticalScroll(rememberScrollState()).padding(horizontal = Medidas.Pantalla).padding(bottom = 22.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(titulo, style = Texto.TituloPantalla, color = Altiplano.Texto)
            contenido()
        }
    }
}

@Composable
private fun coloresCampo() = OutlinedTextFieldDefaults.colors(
    focusedTextColor = Altiplano.Texto, unfocusedTextColor = Altiplano.Texto,
    focusedBorderColor = Altiplano.VerdeMarca, unfocusedBorderColor = Altiplano.Borde,
    focusedLabelColor = Altiplano.TextoSecundario, unfocusedLabelColor = Altiplano.TextoTerciario,
    cursorColor = Altiplano.Exito,
)

@Composable
private fun Campo(
    valor: String, onCambio: (String) -> Unit, etiqueta: String,
    teclado: KeyboardType = KeyboardType.Text, palabras: Boolean = false,
    modifier: Modifier = Modifier.fillMaxWidth(),
) {
    OutlinedTextField(
        value = valor, onValueChange = onCambio, label = { Text(etiqueta) }, singleLine = true,
        keyboardOptions = KeyboardOptions(
            keyboardType = teclado,
            capitalization = if (palabras) KeyboardCapitalization.Words else KeyboardCapitalization.None,
        ),
        colors = coloresCampo(), modifier = modifier,
    )
}

@Composable
private fun CampoClave(valor: String, onCambio: (String) -> Unit) {
    var ver by remember { mutableStateOf(false) }
    OutlinedTextField(
        value = valor, onValueChange = onCambio, label = { Text("Clave inicial (mínimo 6)") }, singleLine = true,
        visualTransformation = if (ver) VisualTransformation.None else PasswordVisualTransformation(),
        trailingIcon = { BotonVerClave(ver) { ver = !ver } },
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
        colors = coloresCampo(), modifier = Modifier.fillMaxWidth(),
    )
}

@Composable
private fun Chip(texto: String, elegido: Boolean, onClick: () -> Unit) {
    Surface(
        onClick = onClick, shape = RoundedCornerShape(12.dp),
        color = if (elegido) Altiplano.VerdeMarcaSuave else Altiplano.Superficie,
        border = BorderStroke(1.dp, if (elegido) Altiplano.VerdeMarca else Altiplano.Borde),
    ) {
        Text(texto, style = Texto.Cuerpo, color = if (elegido) Altiplano.Texto else Altiplano.TextoSecundario,
            modifier = Modifier.padding(horizontal = 12.dp, vertical = 9.dp))
    }
}
