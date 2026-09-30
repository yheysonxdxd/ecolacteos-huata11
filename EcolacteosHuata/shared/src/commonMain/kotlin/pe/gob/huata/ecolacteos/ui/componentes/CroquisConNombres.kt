package pe.gob.huata.ecolacteos.ui.componentes

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.drawscope.DrawScope
import androidx.compose.ui.text.TextMeasurer
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.drawText
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.rememberTextMeasurer
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import pe.gob.huata.ecolacteos.datos.Comunidad
import pe.gob.huata.ecolacteos.datos.EstadoPin
import pe.gob.huata.ecolacteos.datos.TipoVehiculo
import pe.gob.huata.ecolacteos.datos.Vehiculo
import pe.gob.huata.ecolacteos.tema.*

// mismo lienzo que CroquisHuata
private const val LIENZO_W = 340f
private const val LIENZO_H = 400f

/**
 * El croquis de siempre con referencias encima: nombre de cada comunidad,
 * lago Titicaca, carretera PE-3S, norte y el código de vehículo al inicio de
 * cada ruta. Debajo, la leyenda de colores por vehículo.
 */
@Composable
fun CroquisConNombres(
    comunidades: List<Comunidad>,
    vehiculos: List<Vehiculo>,
    pines: List<Pair<Comunidad, EstadoPin>>,
    modifier: Modifier = Modifier,
) {
    val medidor = rememberTextMeasurer()
    Column(modifier, verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Box {
            CroquisHuata(comunidades, vehiculos, pines)
            Canvas(Modifier.matchParentSize()) {
                val ex = size.width / LIENZO_W
                val ey = size.height / LIENZO_H

                // lago y carretera
                texto(medidor, "Lago Titicaca", Offset(170f * ex, 380f * ey),
                    TextStyle(color = Color(0xFF5E8BA8), fontSize = 12.sp, fontStyle = FontStyle.Italic))
                texto(medidor, "PE-3S", Offset(40f * ex, 304f * ey),
                    TextStyle(color = Color(0xFF8795A3), fontSize = 9.sp, fontWeight = FontWeight.Bold))

                // norte
                val n = Offset(size.width - 16f * ex, 20f * ey)
                drawPath(Path().apply {
                    moveTo(n.x, n.y - 9f * ey); lineTo(n.x - 5f * ex, n.y + 4f * ey)
                    lineTo(n.x, n.y + 1f * ey); lineTo(n.x + 5f * ex, n.y + 4f * ey); close()
                }, Color(0xFF93A1AD))
                texto(medidor, "N", Offset(n.x, n.y + 13f * ey),
                    TextStyle(color = Color(0xFF93A1AD), fontSize = 9.sp, fontWeight = FontWeight.Bold))

                // nombre de cada comunidad, debajo de su punto
                comunidades.forEach { c ->
                    etiqueta(
                        medidor, c.nombre, Offset(c.croquisX * ex, c.croquisY * ey + 12f * ey),
                        TextStyle(color = Altiplano.Texto, fontSize = 9.sp),
                        fondo = Color(0xCC10202A),
                    )
                }

                // código del vehículo donde arranca su ruta
                vehiculos.forEach { v ->
                    val inicio = v.ordenRuta.firstOrNull()?.let { id -> comunidades.firstOrNull { it.id == id } } ?: return@forEach
                    etiqueta(
                        medidor, v.codigo, Offset(inicio.croquisX * ex + 16f * ex, inicio.croquisY * ey - 16f * ey),
                        TextStyle(color = Color(0xFF10202A), fontSize = 8.5.sp, fontWeight = FontWeight.Bold),
                        fondo = v.color,
                    )
                }
            }
        }

        // leyenda de rutas
        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            vehiculos.forEach { v ->
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(
                        Modifier.size(width = 14.dp, height = 4.dp)
                            .background(v.color, if (v.tipo == TipoVehiculo.MOTOCAR) RoundedCornerShape(0.dp) else CircleShape)
                    )
                    Spacer(Modifier.width(5.dp))
                    Text(
                        "${v.codigo}${if (v.tipo == TipoVehiculo.MOTOCAR) " ↺" else ""}",
                        style = Texto.Apoyo, color = Altiplano.TextoSecundario,
                    )
                }
            }
        }
    }
}

/** Texto centrado en [centro]. */
private fun DrawScope.texto(m: TextMeasurer, t: String, centro: Offset, estilo: TextStyle) {
    val r = m.measure(t, estilo)
    drawText(r, topLeft = Offset(centro.x - r.size.width / 2f, centro.y - r.size.height / 2f))
}

/** Texto con fondo redondeado, centrado en [centro] y sin salirse del lienzo. */
private fun DrawScope.etiqueta(m: TextMeasurer, t: String, centro: Offset, estilo: TextStyle, fondo: Color) {
    val r = m.measure(t, estilo)
    val padX = 4f; val padY = 1.5f
    val w = r.size.width + padX * 2
    val h = r.size.height + padY * 2
    val x = (centro.x - w / 2).coerceIn(0f, size.width - w)
    val y = (centro.y - h / 2).coerceIn(0f, size.height - h)
    drawRoundRect(fondo, topLeft = Offset(x, y), size = Size(w, h), cornerRadius = CornerRadius(h / 2))
    drawText(r, topLeft = Offset(x + padX, y + padY))
}
