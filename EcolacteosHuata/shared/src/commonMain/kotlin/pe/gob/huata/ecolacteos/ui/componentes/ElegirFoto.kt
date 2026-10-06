package pe.gob.huata.ecolacteos.ui.componentes

import androidx.compose.runtime.Composable

/** Abrir la cámara o la galería para sacar/elegir una foto (ej. del comprobante de una compra). */
class ElegirFoto(val camara: () -> Unit, val galeria: () -> Unit)

/**
 * [alElegir] recibe la foto ya achicada (lado mayor 1600 px, JPG) como
 * "data:image/jpeg;base64,…", lista para mandar al servidor; o null si se canceló.
 */
@Composable
expect fun rememberElegirFoto(alElegir: (String?) -> Unit): ElegirFoto
