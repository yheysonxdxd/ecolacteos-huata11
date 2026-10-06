package pe.gob.huata.ecolacteos.ui.componentes

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
 * Franja de conexión, siempre visible: "Conectado" o "Desconectado" según
 * si el servidor responde. Lo que falta enviar se ve en el contador de la
 * cabecera (BotonSync).
 */
@Composable
fun BarraSinConexion(hayRed: Boolean, modifier: Modifier = Modifier) {
    val color = if (hayRed) Altiplano.Exito else Altiplano.Alerta
    Surface(
        modifier = modifier.fillMaxWidth(),
        color = if (hayRed) Altiplano.ExitoFondo else Altiplano.AlertaFondo,
    ) {
        Row(
            Modifier.padding(horizontal = Medidas.Pantalla, vertical = 6.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Text("●", color = color, style = Texto.Apoyo)
            Text(if (hayRed) "Conectado" else "Desconectado", style = Texto.Cuerpo, color = color)
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
