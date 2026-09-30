package pe.gob.huata.ecolacteos.tema

import androidx.compose.material3.Typography
import androidx.compose.runtime.Composable
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp

/**
 * Inter para todo dato funcional: tiene que ser clarísima a las cinco de la
 * mañana. Bricolage Grotesque solo en títulos de pantalla y splash, para que
 * la app se sienta de marca y no de formulario.
 *
 * Cuando agregues los .ttf en composeResources/font/, reemplaza estos
 * FontFamily.Default por FontFamily(Font(Res.font.inter_medium), ...).
 */
val Inter: FontFamily = FontFamily.Default
val Bricolage: FontFamily = FontFamily.Default

object Texto {
    /** El número grande: litros, soles, kilos. Domina la pantalla. */
    val Dato = TextStyle(
        fontFamily = Inter, fontWeight = FontWeight.Medium,
        fontSize = 56.sp, lineHeight = 58.sp, letterSpacing = (-1.4).sp,
    )
    val DatoMedio = TextStyle(
        fontFamily = Inter, fontWeight = FontWeight.Medium,
        fontSize = 34.sp, lineHeight = 36.sp, letterSpacing = (-0.7).sp,
    )
    val TituloPantalla = TextStyle(
        fontFamily = Bricolage, fontWeight = FontWeight.SemiBold,
        fontSize = 23.sp, lineHeight = 26.sp, letterSpacing = (-0.5).sp,
    )
    val Fila = TextStyle(
        fontFamily = Inter, fontWeight = FontWeight.Medium,
        fontSize = 16.5f.sp, lineHeight = 20.sp,
    )
    val Cuerpo = TextStyle(fontFamily = Inter, fontSize = 13.sp, lineHeight = 19.sp)
    val Apoyo  = TextStyle(fontFamily = Inter, fontSize = 12.5f.sp, lineHeight = 18.sp)
    /** Etiqueta de sección: mayúsculas con tracking abierto. */
    val Eyebrow = TextStyle(
        fontFamily = Inter, fontWeight = FontWeight.Medium,
        fontSize = 11.sp, letterSpacing = 1.4.sp,
    )
}

internal val TipografiaAltiplano = Typography(
    headlineMedium = Texto.TituloPantalla,
    titleMedium    = Texto.Fila,
    bodyMedium     = Texto.Cuerpo,
    labelSmall     = Texto.Eyebrow,
)
