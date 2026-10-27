package pe.gob.huata.ecolacteos.ui.componentes

import androidx.activity.compose.BackHandler
import androidx.compose.runtime.Composable

@Composable
actual fun BotonAtras(habilitado: Boolean, alPresionar: () -> Unit) = BackHandler(habilitado, alPresionar)
