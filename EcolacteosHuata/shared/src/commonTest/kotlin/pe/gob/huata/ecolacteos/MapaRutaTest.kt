package pe.gob.huata.ecolacteos

import pe.gob.huata.ecolacteos.datos.EstadoPin.*
import pe.gob.huata.ecolacteos.ui.acopiador.estadoComunidad
import kotlin.test.Test
import kotlin.test.assertEquals

/** Color del pin de cada comunidad en el mapa de ruta. */
class MapaRutaTest {
    @Test fun siFaltaAlguienQuedaGris() = assertEquals(FALTA, estadoComunidad(listOf(REGISTRADO, FALTA, SIN_ENVIAR)))
    @Test fun todosAnotadosPeroSinEnviarQuedaAmarillo() = assertEquals(SIN_ENVIAR, estadoComunidad(listOf(REGISTRADO, SIN_ENVIAR)))
    @Test fun todoEnviadoQuedaVerde() = assertEquals(REGISTRADO, estadoComunidad(listOf(REGISTRADO, NO_ENTREGO)))
    @Test fun nadieEntregoQuedaRojo() = assertEquals(NO_ENTREGO, estadoComunidad(listOf(NO_ENTREGO, NO_ENTREGO)))
}
