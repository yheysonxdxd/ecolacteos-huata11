package pe.gob.huata.ecolacteos

import pe.gob.huata.ecolacteos.ui.acopiador.ResultadoSync
import kotlin.test.*

/** Los litros se envían solos 10 s después del último registro, no antes. */
class EnvioAutomaticoTest {
    @AfterTest
    fun limpiar() { ResultadoSync.huboError = false; ResultadoSync.enviando = false }

    @Test
    fun recienAnotadoEsperaDiezSegundos() {
        ResultadoSync.huboCambio()
        assertFalse(ResultadoSync.tocaEnvioAutomatico())
    }

    @Test
    fun mientrasEnviaNoSeRepite() {
        ResultadoSync.enviando = true
        assertFalse(ResultadoSync.tocaEnvioAutomatico())
    }
}
