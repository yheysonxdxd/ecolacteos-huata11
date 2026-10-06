package pe.gob.huata.ecolacteos.ui.componentes

import androidx.compose.runtime.Composable

/** En iPhone todavía no: la foto del comprobante se puede subir desde la web de oficina. */
@Composable
actual fun rememberElegirFoto(alElegir: (String?) -> Unit): ElegirFoto =
    ElegirFoto(camara = { alElegir(null) }, galeria = { alElegir(null) })
