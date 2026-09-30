package pe.gob.huata.ecolacteos.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import pe.gob.huata.ecolacteos.datos.Rol
import pe.gob.huata.ecolacteos.datos.SesionGuardada
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.acopiador.ResultadoSync
import pe.gob.huata.ecolacteos.ui.componentes.MarcaHuata

/**
 * Se pone al inicio de PantallaLogin. Devuelve true mientras intenta entrar
 * con la sesión guardada (muestra "Ingresando…"); false para mostrar el login.
 * Si el usuario tocó "Cerrar sesión", borra la sesión guardada.
 */
@Composable
fun RestaurandoSesion(onEntrar: (Rol) -> Unit): Boolean {
    val salir = remember { SesionGuardada.salidaPedida }
    var restaurando by remember { mutableStateOf(!salir) }

    LaunchedEffect(Unit) {
        if (salir) {
            SesionGuardada.salidaPedida = false
            SesionGuardada.cerrarSesion()
            return@LaunchedEffect
        }
        when (val r = SesionGuardada.restaurar()) {
            is SesionGuardada.Resultado.Entro -> {
                if (r.sinConexion) {
                    ResultadoSync.huboError = true
                    ResultadoSync.mensaje = "Sin señal: usando los datos guardados en el teléfono"
                }
                onEntrar(r.rol)
            }
            SesionGuardada.Resultado.Vencida -> {
                SesionGuardada.aviso = "Tu sesión venció. Vuelve a ingresar."
                restaurando = false
            }
            SesionGuardada.Resultado.Nada -> restaurando = false
        }
    }

    if (restaurando) {
        Box(Modifier.fillMaxSize().background(Altiplano.Fondo), contentAlignment = Alignment.Center) {
            Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(16.dp)) {
                MarcaHuata(90.dp)
                CircularProgressIndicator(Modifier.size(26.dp), strokeWidth = 2.dp, color = Altiplano.Exito)
                Text("Ingresando…", style = Texto.Apoyo, color = Altiplano.TextoSecundario)
            }
        }
    }
    return restaurando
}
