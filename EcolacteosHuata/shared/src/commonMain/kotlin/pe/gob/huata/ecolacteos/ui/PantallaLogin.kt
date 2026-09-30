package pe.gob.huata.ecolacteos.ui

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.launch
import pe.gob.huata.ecolacteos.datos.*
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

/**
 * Login con DNI y contraseña contra la tabla users. El rol sale del servidor y,
 * al entrar, se cargan vehículos, comunidades y proveedores reales.
 * "Modo demo" abre el Splash de siempre, con los datos de prueba.
 */
@Composable
fun PantallaLogin(hayRed: Boolean, onEntrar: (Rol) -> Unit) {
    var modoDemo by remember { mutableStateOf(false) }
    if (modoDemo) {
        Splash(hayRed, onEntrar)
        return
    }
    if (RestaurandoSesion(onEntrar)) return

    var dni by remember { mutableStateOf("") }
    var clave by remember { mutableStateOf("") }
    var verClave by remember { mutableStateOf(false) }
    var servidor by remember { mutableStateOf(ConfigServidor.baseUrl) }
    var verServidor by remember { mutableStateOf(false) }
    var cargando by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf(SesionGuardada.tomarAviso()) }
    val scope = rememberCoroutineScope()

    // volver aquí equivale a cerrar sesión
    LaunchedEffect(Unit) { Sesion.cerrar() }

    fun ingresar() {
        if (dni.length != 8) { error = "El DNI debe tener 8 dígitos"; return }
        if (clave.isEmpty()) { error = "Escribe tu contraseña"; return }
        ConfigServidor.baseUrl = servidor.trim().trimEnd('/')
        scope.launch {
            cargando = true; error = null
            try {
                val rol = Sesion.login(dni, clave)
                Sesion.cargarDatos()
                SesionGuardada.guardar(rol)
                onEntrar(rol)
            } catch (e: Throwable) {
                error = e.message ?: "No se pudo conectar con ${ConfigServidor.baseUrl}"
            } finally {
                cargando = false
            }
        }
    }

    val colores = OutlinedTextFieldDefaults.colors(
        focusedTextColor = Altiplano.Texto, unfocusedTextColor = Altiplano.Texto,
        focusedBorderColor = Altiplano.VerdeMarca, unfocusedBorderColor = Altiplano.Borde,
        focusedLabelColor = Altiplano.TextoSecundario, unfocusedLabelColor = Altiplano.TextoTerciario,
        cursorColor = Altiplano.Exito,
    )

    Box(
        Modifier.fillMaxSize().background(
            Brush.verticalGradient(listOf(Color(0xFF17492F), Color(0xFF143327), Color(0xFF10202A), Altiplano.Fondo))
        )
    ) {
        FondoAguayo(Modifier.fillMaxWidth().fillMaxHeight(0.45f))
        Column(
            Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 30.dp, vertical = 40.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            Spacer(Modifier.height(24.dp))
            MarcaHuata(110.dp)
            Text(
                buildAnnotatedString {
                    append("Ecolácteos ")
                    withStyle(SpanStyle(color = Altiplano.CelesteAcento, fontStyle = FontStyle.Italic)) { append("Huata") }
                },
                style = Texto.TituloPantalla.copy(fontSize = 29.sp, lineHeight = 31.sp),
                color = Altiplano.Texto, textAlign = TextAlign.Center,
            )
            Text("INICIAR SESIÓN", style = Texto.Eyebrow.copy(fontSize = 13.sp), color = Color(0xFF5FAA78))
            Spacer(Modifier.height(8.dp))

            OutlinedTextField(
                value = dni, onValueChange = { dni = it.filter(Char::isDigit).take(8) },
                label = { Text("DNI") }, singleLine = true, enabled = !cargando,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                colors = colores, modifier = Modifier.fillMaxWidth(),
            )
            OutlinedTextField(
                value = clave, onValueChange = { clave = it },
                label = { Text("Contraseña") }, singleLine = true, enabled = !cargando,
                visualTransformation = if (verClave) VisualTransformation.None else PasswordVisualTransformation(),
                trailingIcon = { BotonVerClave(verClave) { verClave = !verClave } },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                colors = colores, modifier = Modifier.fillMaxWidth(),
            )

            error?.let { Text(it, style = Texto.Apoyo, color = Altiplano.Aviso, textAlign = TextAlign.Center) }

            Surface(
                onClick = { if (!cargando) ingresar() },
                modifier = Modifier.fillMaxWidth().height(Medidas.BotonPrimario),
                shape = RoundedCornerShape(16.dp),
                color = Altiplano.VerdeMarca.copy(alpha = .24f),
                border = BorderStroke(1.dp, Altiplano.VerdeMarca),
            ) {
                Box(contentAlignment = Alignment.Center) {
                    if (cargando) CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp, color = Altiplano.Exito)
                    else Text("Ingresar", style = Texto.Fila.copy(fontSize = 17.5.sp), color = Altiplano.Texto)
                }
            }

            TextButton(onClick = { verServidor = !verServidor }) {
                Text("Servidor: $servidor", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            }
            if (verServidor) {
                OutlinedTextField(
                    value = servidor, onValueChange = { servidor = it },
                    label = { Text("Dirección del servidor") }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
                    colors = colores, modifier = Modifier.fillMaxWidth(),
                )
            }
            TextButton(onClick = { modoDemo = true }) {
                Text("Entrar en modo demo (sin servidor)", style = Texto.Apoyo, color = Altiplano.TextoSecundario)
            }
        }
    }
}
