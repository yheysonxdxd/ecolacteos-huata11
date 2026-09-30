package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.material3.*
import androidx.compose.runtime.*
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import pe.gob.huata.ecolacteos.datos.Sesion
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

/**
 * Sesiones abiertas (celulares con la app iniciada) por trabajador, para el
 * admin. "Cerrar sesiones" saca a esa persona de todos sus celulares: sirve
 * si perdió el teléfono o dejó de trabajar. Va debajo de la lista de
 * trabajadores del Padrón.
 */
@Composable
fun SesionesAbiertas() {
    var clave by remember { mutableStateOf(0) }
    var confirmar by remember { mutableStateOf<JsonObject?>(null) }
    var mensaje by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()

    // sin CargaServidor: su pantalla de error tiene scroll propio y aquí ya
    // estamos dentro de la pantalla con scroll del Padrón
    var usuarios by remember { mutableStateOf<List<JsonObject>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(clave) {
        error = null
        try { usuarios = Sesion.consultar("/api/v1/admin/sesiones").lista("usuarios") }
        catch (t: Throwable) { error = t.message ?: "sin conexión" }
    }

    Seccion("Sesiones abiertas")
    mensaje?.let { AvisoCampo(it) }
    error?.let { AvisoCampo("No se pudo leer las sesiones: $it", esAlerta = true) }
    val lista = usuarios ?: return
    run {
        if (lista.isEmpty()) Text("Nadie tiene la app abierta.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
        lista.forEach { u ->
            val n = u.num("sesiones").toInt()
            FilaLista(
                u.txt("nombre"),
                "${u.txt("rol").lowercase().replaceFirstChar { it.uppercase() }} · " +
                    "$n ${if (n == 1) "celular" else "celulares"} · último uso ${fechaCorta(u.txt("ultimo_uso"))} ${u.txt("ultimo_uso").drop(11).take(5)}",
            ) {
                TextButton(onClick = { confirmar = u }) { Text("Cerrar", color = Altiplano.Alerta) }
            }
        }
    }

    confirmar?.let { u ->
        val propia = u.num("id").toLong() == Sesion.usuarioId
        AlertDialog(
            onDismissRequest = { confirmar = null },
            containerColor = Altiplano.Fondo,
            title = { Text("¿Cerrar sesiones de ${u.txt("nombre")}?", color = Altiplano.Texto) },
            text = {
                Text(
                    if (propia) "Son tus propias sesiones: también se cerrará la de este celular."
                    else "Tendrá que volver a ingresar con su DNI y contraseña en todos sus celulares. " +
                        "Lo que tenga guardado sin enviar se conserva en su teléfono.",
                    color = Altiplano.TextoTerciario,
                )
            },
            confirmButton = {
                TextButton(onClick = {
                    confirmar = null
                    scope.launch {
                        mensaje = try {
                            val r = Sesion.enviar("/api/v1/admin/usuarios/${u.num("id").toLong()}/cerrar-sesiones", JsonObject(emptyMap()))
                            "✓ Se cerraron ${r.txt("cerradas")} sesiones de ${u.txt("nombre")}"
                        } catch (t: Throwable) {
                            "No se pudo cerrar: ${t.message}"
                        }
                        clave++
                    }
                }) { Text("Cerrar sesiones", color = Altiplano.Alerta) }
            },
            dismissButton = {
                TextButton(onClick = { confirmar = null }) { Text("Cancelar", color = Altiplano.TextoTerciario) }
            },
        )
    }
}
