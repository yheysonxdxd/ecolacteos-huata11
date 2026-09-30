package pe.gob.huata.ecolacteos.ui.componentes

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.layout.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.*
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.unit.dp
import pe.gob.huata.ecolacteos.datos.Comunidad
import pe.gob.huata.ecolacteos.datos.EstadoPin
import pe.gob.huata.ecolacteos.datos.Vehiculo
import pe.gob.huata.ecolacteos.tema.Altiplano

/**
 * Croquis dibujado a mano del distrito, en Canvas. Google Maps no cubre los
 * caminos rurales de Huata, así que las comunidades traen coordenadas relativas
 * al dibujo (croquis_x, croquis_y sobre un lienzo de 340×400), no GPS.
 *
 * Como se dibuja desde datos y no desde una imagen, mover un proveedor de
 * comunidad actualiza su pin sin tocar el binario de la app.
 */
private const val LIENZO_W = 340f
private const val LIENZO_H = 400f

@Composable
fun CroquisHuata(
    comunidades: List<Comunidad>,
    vehiculos: List<Vehiculo>,
    pines: List<Pair<Comunidad, EstadoPin>>,
    modifier: Modifier = Modifier,
) {
    Canvas(modifier.fillMaxWidth().aspectRatio(LIENZO_W / LIENZO_H)) {
        val ex = size.width / LIENZO_W
        val ey = size.height / LIENZO_H
        fun pt(x: Float, y: Float) = Offset(x * ex, y * ey)

        // lago Titicaca al sur
        drawPath(
            Path().apply {
                moveTo(0f, 330f * ey)
                cubicTo(58f * ex, 316f * ey, 104f * ex, 336f * ey, 158f * ex, 346f * ey)
                cubicTo(216f * ex, 356f * ey, 276f * ex, 372f * ey, size.width, 356f * ey)
                lineTo(size.width, size.height); lineTo(0f, size.height); close()
            },
            color = Color(0xFF12222C),
        )

        // PE-3S asfaltada bordeando el lago, y PU-118 atravesando el distrito
        drawPath(
            Path().apply {
                moveTo(4f * ex, 318f * ey)
                cubicTo(64f * ex, 304f * ey, 112f * ex, 322f * ey, 166f * ex, 332f * ey)
                cubicTo(222f * ex, 342f * ey, 282f * ex, 358f * ey, 338f * ex, 344f * ey)
            },
            color = Color(0xFF404E5C),
            style = Stroke(width = 5f * ex, cap = StrokeCap.Round),
        )

        // una polilínea suave por vehículo
        vehiculos.forEach { v ->
            val puntos = v.ordenRuta.mapNotNull { id ->
                comunidades.firstOrNull { it.id == id }?.let { pt(it.croquisX, it.croquisY) }
            }
            if (puntos.size < 2) return@forEach

            val camino = Path().apply {
                moveTo(puntos.first().x, puntos.first().y)
                // el motocar cierra el circuito volviendo al primer punto
                val seq = if (v.rutaCircular) puntos + puntos.first() else puntos
                for (i in 1 until seq.size) {
                    val a = seq[i - 1]; val b = seq[i]
                    quadraticBezierTo(
                        (a.x + b.x) / 2 + (b.y - a.y) * 0.16f,
                        (a.y + b.y) / 2 - (b.x - a.x) * 0.16f,
                        b.x, b.y,
                    )
                }
            }
            drawPath(
                camino, color = v.color,
                style = Stroke(
                    width = if (v.rutaCircular) 3.6f * ex else 2.6f * ex,
                    cap = StrokeCap.Round,
                    // el motocar se distingue por trazo, no por otro color
                    pathEffect = if (v.rutaCircular)
                        PathEffect.dashPathEffect(floatArrayOf(7f * ex, 5f * ex)) else null,
                ),
            )
        }

        // nodos de comunidad
        comunidades.forEach { c ->
            drawCircle(Color(0xFF456A5C), radius = 3f * ex, center = pt(c.croquisX, c.croquisY))
        }

        // pines de estado: círculo para furgón, cuadrado para motocar,
        // rombo para "no entregó"
        pines.forEach { (c, estado) ->
            val centro = pt(c.croquisX - 6f, c.croquisY - 7f)
            val color = when (estado) {
                EstadoPin.REGISTRADO -> Altiplano.Exito
                EstadoPin.SIN_ENVIAR -> Altiplano.Aviso
                EstadoPin.NO_ENTREGO -> Altiplano.Alerta
                EstadoPin.FALTA -> Color(0xFF4E6577)
            }
            drawCircle(color.copy(alpha = 0.13f), radius = 11f * ex, center = centro)
            drawCircle(color, radius = 5.4f * ex, center = centro)
        }
    }
}
