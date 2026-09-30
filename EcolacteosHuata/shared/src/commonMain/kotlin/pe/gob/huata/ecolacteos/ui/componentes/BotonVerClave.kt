package pe.gob.huata.ecolacteos.ui.componentes

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.runtime.Composable
import pe.gob.huata.ecolacteos.tema.Altiplano

/** Ojito al final del campo de contraseña para mostrarla u ocultarla. */
@Composable
fun BotonVerClave(visible: Boolean, onCambiar: () -> Unit) {
    IconButton(onClick = onCambiar) {
        Icon(
            if (visible) Icons.Filled.VisibilityOff else Icons.Filled.Visibility,
            contentDescription = if (visible) "Ocultar contraseña" else "Mostrar contraseña",
            tint = Altiplano.TextoTerciario,
        )
    }
}
