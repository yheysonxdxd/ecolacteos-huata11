package pe.gob.huata.ecolacteos.ui.componentes

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import pe.gob.huata.ecolacteos.tema.*

/** Pestañas que se deslizan de lado: para cuando son más de 3 (Segmentos las aprieta). */
@Composable
fun PestanasScroll(opciones: List<String>, seleccion: Int, onSeleccion: (Int) -> Unit) {
    Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        opciones.forEachIndexed { i, o ->
            val sel = i == seleccion
            Surface(
                onClick = { onSeleccion(i) }, modifier = Modifier.height(42.dp), shape = RoundedCornerShape(12.dp),
                color = if (sel) Altiplano.VerdeMarcaSuave else Altiplano.Superficie,
                border = BorderStroke(1.dp, if (sel) Altiplano.VerdeMarca else Altiplano.Borde),
            ) {
                Box(Modifier.padding(horizontal = 14.dp), contentAlignment = androidx.compose.ui.Alignment.Center) {
                    Text(o, style = Texto.Cuerpo.copy(fontSize = 14.sp), color = if (sel) Altiplano.Texto else Altiplano.TextoSecundario)
                }
            }
        }
    }
}

/** Pestañas arriba y debajo el contenido de la elegida (cada contenido maneja su propio scroll). */
@Composable
fun ConPestanas(opciones: List<String>, seleccion: Int, onSeleccion: (Int) -> Unit, contenido: @Composable () -> Unit) {
    Column(Modifier.fillMaxSize()) {
        Box(Modifier.padding(start = Medidas.Pantalla, end = Medidas.Pantalla, top = 12.dp)) {
            if (opciones.size <= 3) Segmentos(opciones, seleccion, onSeleccion) else PestanasScroll(opciones, seleccion, onSeleccion)
        }
        Box(Modifier.weight(1f)) { contenido() }
    }
}
