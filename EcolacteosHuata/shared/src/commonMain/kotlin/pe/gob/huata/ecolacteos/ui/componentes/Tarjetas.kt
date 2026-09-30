package pe.gob.huata.ecolacteos.ui.componentes

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.unit.dp
import pe.gob.huata.ecolacteos.tema.*

/** Estados semánticos. Fijos: no se reasignan por criterio visual. */
enum class Estado(val texto: String) {
    ACEPTADO("Aceptado"), PAGADO("Pagado"), SINCRONIZADO("Enviado"),
    PENDIENTE("Pendiente"), SIN_ENVIAR("Sin enviar"), FALTA("Falta"),
    RECHAZADO("Rechazado"), ADULTERADA("Adulterada"), NO_ENTREGO("No entregó"),
}

@Composable
fun ChipEstado(estado: Estado, modifier: Modifier = Modifier) {
    val (color, fondo) = when (estado) {
        Estado.ACEPTADO, Estado.PAGADO, Estado.SINCRONIZADO ->
            Altiplano.Exito to Altiplano.ExitoFondo
        Estado.PENDIENTE, Estado.SIN_ENVIAR, Estado.FALTA ->
            Altiplano.Aviso to Altiplano.AvisoFondo
        else -> Altiplano.Alerta to Altiplano.AlertaFondo
    }
    Surface(
        modifier = modifier, shape = RoundedCornerShape(999.dp),
        color = fondo, border = BorderStroke(1.dp, color.copy(alpha = 0.35f)),
    ) {
        Text(
            estado.texto, style = Texto.Apoyo, color = color,
            modifier = Modifier.padding(horizontal = 9.dp, vertical = 3.dp),
        )
    }
}

/**
 * El dato grande de la pantalla. [destacada] le pone el degradado y el glow del
 * acento: se reserva para litros del día, pago semanal y rendimiento de queso.
 */
@Composable
fun TarjetaDato(
    eyebrow: String,
    valor: String,
    unidad: String? = null,
    apoyo: String? = null,
    destacada: Boolean = false,
    modifier: Modifier = Modifier,
    extra: @Composable (ColumnScope.() -> Unit)? = null,
) {
    Surface(
        modifier = modifier.fillMaxWidth(),
        shape = RoundedCornerShape(16.dp),
        color = if (destacada) Altiplano.Superficie else Altiplano.Superficie,
        border = BorderStroke(1.dp, if (destacada) Altiplano.VerdeBorde else Altiplano.Borde),
    ) {
        Box {
            if (destacada) {
                Box(
                    Modifier.matchParentSize().background(
                        Brush.linearGradient(
                            listOf(Altiplano.SuperficieAlta, Altiplano.Superficie)
                        )
                    )
                )
                // línea de acento a la izquierda, nunca relleno grande
                Box(
                    Modifier.fillMaxHeight().width(3.dp)
                        .background(Altiplano.VerdeMarca)
                )
            }
            Column(Modifier.padding(20.dp)) {
                Text(eyebrow, style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
                Spacer(Modifier.height(8.dp))
                Row(verticalAlignment = Alignment.Bottom) {
                    Text(valor, style = Texto.Dato, color = Altiplano.Texto)
                    unidad?.let {
                        Spacer(Modifier.width(9.dp))
                        Text(
                            it, style = Texto.Fila, color = Altiplano.TextoSecundario,
                            modifier = Modifier.padding(bottom = 8.dp),
                        )
                    }
                }
                apoyo?.let {
                    Spacer(Modifier.height(9.dp))
                    Text(it, style = Texto.Cuerpo, color = Altiplano.TextoSecundario)
                }
                extra?.invoke(this)
            }
        }
    }
}

/** Aviso de campo: desvío del promedio, stock bajo, brecha del doble muestreo. */
@Composable
fun AvisoCampo(texto: String, esAlerta: Boolean = false, modifier: Modifier = Modifier) {
    val color = if (esAlerta) Altiplano.Alerta else Altiplano.Aviso
    Surface(
        modifier = modifier.fillMaxWidth(),
        shape = RoundedCornerShape(13.dp),
        color = if (esAlerta) Altiplano.AlertaFondo else Altiplano.AvisoFondo,
        border = BorderStroke(1.dp, if (esAlerta) Altiplano.AlertaBorde else Altiplano.AvisoBorde),
    ) {
        Text(texto, style = Texto.Cuerpo, color = color, modifier = Modifier.padding(13.dp))
    }
}
