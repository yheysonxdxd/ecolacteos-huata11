package pe.gob.huata.ecolacteos.tema

import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp

/**
 * Objetivos táctiles por encima del mínimo de Material (48 dp): la app se usa
 * con guantes, de madrugada y con frío.
 */
object Medidas {
    val FilaProveedor: Dp = 76.dp
    val BotonPrimario: Dp = 62.dp
    val Stepper: Dp = 64.dp
    val StepperCompacto: Dp = 56.dp
    val ItemNav: Dp = 54.dp
    val Chip: Dp = 44.dp

    val Pantalla: Dp = 18.dp   // padding lateral estándar
    val Tarjeta: Dp = 16.dp
    val Hueco: Dp = 12.dp
}

private val FormasAltiplano = Shapes(
    small = RoundedCornerShape(12.dp),
    medium = RoundedCornerShape(14.dp),
    large = RoundedCornerShape(16.dp),
)

@Composable
fun TemaAltiplano(contenido: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = darkColorScheme(
            primary          = Altiplano.VerdeMarca,
            onPrimary        = Altiplano.Texto,
            primaryContainer = Altiplano.VerdeMarcaSuave,
            secondary        = Altiplano.CelesteAcento,
            background       = Altiplano.Fondo,
            onBackground     = Altiplano.Texto,
            surface          = Altiplano.Superficie,
            onSurface        = Altiplano.Texto,
            surfaceVariant   = Altiplano.SuperficieAlta,
            outline          = Altiplano.Borde,
            error            = Altiplano.Alerta,
            errorContainer   = Altiplano.AlertaFondo,
        ),
        typography = TipografiaAltiplano,
        shapes = FormasAltiplano,
        content = contenido,
    )
}
