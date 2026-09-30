package pe.gob.huata.ecolacteos.tema

import androidx.compose.ui.graphics.Color

/**
 * Identidad Altiplano. Un solo color dominante — el verde institucional del
 * camión — y los semánticos aparte para que una alerta nunca se confunda con
 * un detalle de marca.
 */
object Altiplano {
    // marca
    val VerdeMarca      = Color(0xFF1E6E3E)
    val VerdeMarcaSuave = Color(0xFF153A25) // relleno de botón sobre fondo oscuro
    val VerdeBorde      = Color(0xFF2A6B45)
    val CelesteAcento   = Color(0xFF7FD4E0) // detalles y avisos informativos

    // superficies
    val Fondo          = Color(0xFF0F1A24)
    val Superficie     = Color(0xFF182838)
    val SuperficieAlta = Color(0xFF1C3A4E)
    val Borde          = Color(0xFF243447)
    val BordeSuave     = Color(0xFF213243)

    // texto
    val Texto          = Color(0xFFFFFFFF)
    val TextoSecundario= Color(0xFFCDD8E2)
    val TextoTerciario = Color(0xFF8593A0)
    val TextoApagado   = Color(0xFF5A6673)

    // semánticos: no se tocan por estética
    val Exito       = Color(0xFF4FC47A) // aceptado, pagado, sincronizado
    val ExitoFondo  = Color(0xFF123322)
    val Aviso       = Color(0xFFE8C88A) // pendiente de envío, stock bajo
    val AvisoFondo  = Color(0xFF2C2519)
    val AvisoBorde  = Color(0xFF6F5C38)
    val Alerta      = Color(0xFFE08A86) // rechazo, adulteración
    val AlertaFondo = Color(0xFF2A1E1E)
    val AlertaBorde = Color(0xFF7A4442)

    // rutas del croquis: el mismo verde en intensidades, el motocar por trazo
    val RutaC01 = Color(0xFF4FC47A)
    val RutaC02 = Color(0xFF7FD39A)
    val RutaC03 = Color(0xFFB6DCC0)
    val RutaM01 = Color(0xFFE4F7EA)
}
