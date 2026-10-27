package pe.gob.huata.ecolacteos.ui.componentes

import androidx.compose.runtime.Composable

/**
 * El botón "atrás" del celular. Mientras [habilitado], lo atrapa la app y llama a [alPresionar];
 * si no, hace lo de siempre (salir). En iPhone no hay botón atrás: no hace nada.
 */
@Composable
expect fun BotonAtras(habilitado: Boolean, alPresionar: () -> Unit)
