package pe.gob.huata.ecolacteos.ui.componentes

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import pe.gob.huata.ecolacteos.tema.*

/** Piezas repetidas en todas las pantallas de rol. */
@Composable
fun PantallaScroll(contenido: @Composable ColumnScope.() -> Unit) {
    Column(
        Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(Medidas.Pantalla),
        verticalArrangement = Arrangement.spacedBy(Medidas.Hueco),
        content = contenido,
    )
}

@Composable
fun Tarjeta(
    modifier: Modifier = Modifier,
    borde: Color = Altiplano.Borde,
    fondo: Color = Altiplano.Superficie,
    onClick: (() -> Unit)? = null,
    contenido: @Composable ColumnScope.() -> Unit,
) {
    val forma = RoundedCornerShape(14.dp)
    if (onClick != null) {
        Surface(onClick = onClick, modifier = modifier.fillMaxWidth(), shape = forma, color = fondo, border = BorderStroke(1.dp, borde)) {
            Column(Modifier.padding(15.dp), content = contenido)
        }
    } else {
        Surface(modifier = modifier.fillMaxWidth(), shape = forma, color = fondo, border = BorderStroke(1.dp, borde)) {
            Column(Modifier.padding(15.dp), content = contenido)
        }
    }
}

@Composable
fun Seccion(texto: String) {
    Text(texto.uppercase(), style = Texto.Eyebrow, color = Altiplano.TextoTerciario, modifier = Modifier.padding(top = 4.dp))
}

@Composable
fun FilaValor(etiqueta: String, valor: String, colorValor: Color = Altiplano.Texto) {
    Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), horizontalArrangement = Arrangement.SpaceBetween) {
        Text(etiqueta, style = Texto.Cuerpo, color = Altiplano.TextoSecundario, modifier = Modifier.weight(1f))
        Text(valor, style = Texto.Cuerpo.copy(fontSize = 14.sp), color = colorValor)
    }
}

@Composable
fun FilaLista(
    titulo: String,
    subtitulo: String? = null,
    onClick: (() -> Unit)? = null,
    borde: Color = Altiplano.Borde,
    derecha: @Composable () -> Unit = {},
) {
    Tarjeta(borde = borde, onClick = onClick) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(titulo, style = Texto.Fila, color = Altiplano.Texto)
                subtitulo?.let {
                    Spacer(Modifier.height(3.dp))
                    Text(it, style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                }
            }
            Spacer(Modifier.width(10.dp))
            derecha()
        }
    }
}

@Composable
fun BotonPrimario(texto: String, habilitado: Boolean = true, onClick: () -> Unit) {
    Surface(
        onClick = { if (habilitado) onClick() },
        modifier = Modifier.fillMaxWidth().height(Medidas.BotonPrimario),
        shape = RoundedCornerShape(15.dp),
        color = if (habilitado) Altiplano.VerdeMarcaSuave else Altiplano.Superficie,
        border = BorderStroke(1.dp, if (habilitado) Altiplano.VerdeMarca else Altiplano.Borde),
    ) {
        Box(contentAlignment = Alignment.Center) {
            Text(texto, style = Texto.Fila, color = if (habilitado) Altiplano.Texto else Altiplano.TextoApagado)
        }
    }
}

/** Par Rechazar / Aceptar. Los colores son semánticos, no de marca. */
@Composable
fun BotonesDecision(izq: String, der: String, onIzq: () -> Unit, onDer: () -> Unit) {
    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        Surface(onClick = onIzq, modifier = Modifier.weight(1f).height(56.dp), shape = RoundedCornerShape(14.dp),
            color = Altiplano.AlertaFondo, border = BorderStroke(1.dp, Altiplano.AlertaBorde)) {
            Box(contentAlignment = Alignment.Center) { Text(izq, style = Texto.Fila, color = Altiplano.Alerta) }
        }
        Surface(onClick = onDer, modifier = Modifier.weight(1f).height(56.dp), shape = RoundedCornerShape(14.dp),
            color = Altiplano.ExitoFondo, border = BorderStroke(1.dp, Altiplano.Exito.copy(alpha = .5f))) {
            Box(contentAlignment = Alignment.Center) { Text(der, style = Texto.Fila, color = Altiplano.Exito) }
        }
    }
}

@Composable
fun Opcion(texto: String, detalle: String? = null, seleccionado: Boolean, derecha: String? = null, onClick: () -> Unit) {
    Surface(
        onClick = onClick,
        modifier = Modifier.fillMaxWidth().heightIn(min = 56.dp),
        shape = RoundedCornerShape(12.dp),
        color = if (seleccionado) Altiplano.VerdeMarcaSuave else Altiplano.Fondo,
        border = BorderStroke(1.dp, if (seleccionado) Altiplano.VerdeMarca else Altiplano.Borde),
    ) {
        Row(Modifier.padding(horizontal = 14.dp, vertical = 11.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(texto, style = Texto.Fila.copy(fontSize = 15.sp), color = Altiplano.Texto)
                detalle?.let { Text(it, style = Texto.Apoyo, color = Altiplano.TextoTerciario) }
            }
            derecha?.let { Text(it, style = Texto.Fila, color = Altiplano.Texto) }
        }
    }
}

@Composable
fun Segmentos(opciones: List<String>, seleccion: Int, onSeleccion: (Int) -> Unit) {
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        opciones.forEachIndexed { i, o ->
            val sel = i == seleccion
            Surface(
                onClick = { onSeleccion(i) },
                modifier = Modifier.weight(1f).height(48.dp),
                shape = RoundedCornerShape(12.dp),
                color = if (sel) Altiplano.VerdeMarcaSuave else Altiplano.Superficie,
                border = BorderStroke(1.dp, if (sel) Altiplano.VerdeMarca else Altiplano.Borde),
            ) {
                Box(contentAlignment = Alignment.Center) {
                    Text(o, style = Texto.Cuerpo.copy(fontSize = 14.sp), color = if (sel) Altiplano.Texto else Altiplano.TextoSecundario)
                }
            }
        }
    }
}

@Composable
fun Barras(dias: List<Pair<String, Double>>, maximo: Double) {
    Row(Modifier.fillMaxWidth().height(116.dp), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.Bottom) {
        dias.forEach { (d, v) ->
            Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
                Text(formatear(v, 0), style = Texto.Apoyo.copy(fontSize = 10.sp), color = Altiplano.TextoTerciario)
                Spacer(Modifier.height(4.dp))
                Box(Modifier.fillMaxWidth().height((maxOf(v, 0.0) / maximo * 76 + 3).dp).background(Altiplano.VerdeMarca, RoundedCornerShape(6.dp)))
                Spacer(Modifier.height(6.dp))
                Text(d, style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            }
        }
    }
}

@Composable
fun BarraNivel(fraccion: Float, color: Color = Altiplano.Exito) {
    Box(Modifier.fillMaxWidth().height(7.dp).background(Altiplano.Fondo, RoundedCornerShape(99.dp))) {
        Box(Modifier.fillMaxWidth(fraccion.coerceIn(0f, 1f)).fillMaxHeight().background(color, RoundedCornerShape(99.dp)))
    }
}

fun soles(v: Double): String {
    val c = kotlin.math.round(v * 100).toLong()
    val d = (kotlin.math.abs(c) % 100).toString().padStart(2, '0')
    return "S/ " + formatear((c / 100).toDouble(), 0) + "," + d
}

const val PRECIO_LECHE = 1.80
