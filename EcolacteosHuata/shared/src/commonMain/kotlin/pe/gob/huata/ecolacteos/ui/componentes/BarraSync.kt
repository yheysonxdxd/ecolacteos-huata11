package pe.gob.huata.ecolacteos.ui.componentes

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.slideInVertically
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import pe.gob.huata.ecolacteos.tema.*

/**
 * Franja de estado de red. Siempre visible cuando hay algo sin enviar: el
 * acopiador nunca debe quedarse con la duda de si lo que registró ya salió.
 */
@Composable
fun BarraSinConexion(pendientes: Int, hayRed: Boolean, modifier: Modifier = Modifier) {
    AnimatedVisibility(
        visible = !hayRed || pendientes > 0,
        enter = slideInVertically { -it },
    ) {
        Surface(
            modifier = modifier.fillMaxWidth(),
            color = Altiplano.AvisoFondo,
        ) {
            Row(
                Modifier.padding(horizontal = Medidas.Pantalla, vertical = 9.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(9.dp),
            ) {
                Text(if (hayRed) "◒" else "⃠", color = Altiplano.Aviso, style = Texto.Fila)
                Text(
                    text = if (hayRed) "Enviando $pendientes registros…"
                           else "Sin conexión · $pendientes registros sin enviar",
                    style = Texto.Cuerpo, color = Altiplano.Aviso,
                )
            }
        }
    }
}

/** Contador del outbox en la cabecera. Tocarlo dispara el push manual. */
@Composable
fun BotonSync(pendientes: Int, sincronizando: Boolean, onSync: () -> Unit) {
    val hayPendientes = pendientes > 0
    Surface(
        onClick = onSync,
        modifier = Modifier.height(48.dp),
        shape = RoundedCornerShape(12.dp),
        color = if (hayPendientes) Altiplano.AvisoFondo else Altiplano.Superficie,
        border = BorderStroke(1.dp, if (hayPendientes) Altiplano.AvisoBorde else Altiplano.Borde),
    ) {
        Row(
            Modifier.padding(horizontal = 13.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(7.dp),
        ) {
            if (sincronizando) {
                CircularProgressIndicator(
                    Modifier.size(16.dp), strokeWidth = 2.dp,
                    color = Altiplano.Aviso,
                )
            }
            Text(
                "$pendientes", style = Texto.Fila,
                color = if (hayPendientes) Altiplano.Aviso else Altiplano.Exito,
            )
        }
    }
}
