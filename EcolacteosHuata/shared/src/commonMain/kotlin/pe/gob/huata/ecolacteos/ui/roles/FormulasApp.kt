package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.serialization.json.*
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

/** Para recargar la pantalla de Producir después de iniciar lotes. */
object FormulasApp {
    var version by mutableStateOf(0)
}

/**
 * Producir y recetas, como en la web: arriba "¿Qué vas a producir hoy?" (uno o
 * varios) y abajo las recetas, compactas. Con [puedeAceptar] (administrador)
 * se inician los lotes; Compras solo mira.
 */
@Composable
fun FormulasReal(puedeAceptar: Boolean, onLoteIniciado: () -> Unit = {}) {
    CargaServidor("/api/v1/compras/formulas", clave = FormulasApp.version) { d, recargar ->
        val tanque = d.num("tanque")
        val productos = d.lista("productos")
        val avisos: @Composable ColumnScope.() -> Unit = {
            Notificacion(Produccion.noti)
            d.lista("avisos").filter { it.txt("nivel") == "FALTA" }.forEach { AvisoCampo(it.txt("texto"), esAlerta = true) }
        }
        if (productos.isEmpty()) PantallaScroll { avisos(); AvisoCampo("No hay productos que se estén produciendo.") }
        else PantallaProducir(productos, tanque, puedeAceptar, arriba = avisos, abajo = { RecetasCompactas(productos) },
            onListo = { n ->
                Produccion.noti = n
                OperarioReal.version++
                recargar(); onLoteIniciado()
            })
    }
}
