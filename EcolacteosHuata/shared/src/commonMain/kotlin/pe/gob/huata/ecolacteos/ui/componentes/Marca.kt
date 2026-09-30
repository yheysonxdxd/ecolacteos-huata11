package pe.gob.huata.ecolacteos.ui.componentes

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.layout.size
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.graphics.*
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp

/** Isotipo: vaca frente al arco + totora y lago. Mismo dibujo que el prototipo (viewBox 76×72). */
@Composable
fun MarcaHuata(tamano: Dp = 40.dp, modifier: Modifier = Modifier) {
    Canvas(modifier.size(tamano, tamano * (72f / 76f))) {
        val s = size.width / 76f
        fun p(x: Float, y: Float) = Offset(x * s, y * s)
        fun arco(r: Float, color: Color, w: Float, a: Float = 1f) = drawArc(
            color.copy(alpha = a), 180f, 180f, false,
            topLeft = p(38f - r, 50f - r), size = androidx.compose.ui.geometry.Size(2 * r * s, 2 * r * s),
            style = Stroke(w * s, cap = StrokeCap.Round),
        )
        arco(33f, Color(0xFF7FD4E0), 1.5f, .75f)
        arco(27f, Color(0xFF2F8F4E), 2.6f, .8f)
        arco(21f, Color(0xFF4FC47A), 3.2f)
        val blanco = Color.White
        // orejas
        drawPath(Path().apply { moveTo(25f*s,32f*s); cubicTo(23.5f*s,26f*s,25f*s,21f*s,28f*s,19f*s); cubicTo(31f*s,21f*s,32.5f*s,26f*s,32.5f*s,31f*s); close() }, blanco)
        drawPath(Path().apply { moveTo(51f*s,32f*s); cubicTo(52.5f*s,26f*s,51f*s,21f*s,48f*s,19f*s); cubicTo(45f*s,21f*s,43.5f*s,26f*s,43.5f*s,31f*s); close() }, blanco)
        // cara
        drawPath(Path().apply {
            moveTo(27f*s,33f*s); cubicTo(27f*s,28f*s,32f*s,25f*s,38f*s,25f*s); cubicTo(44f*s,25f*s,49f*s,28f*s,49f*s,33f*s)
            cubicTo(49f*s,42f*s,45f*s,48f*s,38f*s,48f*s); cubicTo(31f*s,48f*s,27f*s,42f*s,27f*s,33f*s); close()
        }, blanco)
        val verde = Color(0xFF1E6E3E)
        drawCircle(verde, 2.1f*s, p(33.5f,34f)); drawCircle(verde, 2.1f*s, p(42.5f,34f))
        drawOval(verde.copy(alpha = .3f), p(32f,38.6f), androidx.compose.ui.geometry.Size(12f*s, 7.4f*s))
        drawCircle(verde, 1.1f*s, p(35.4f,41.6f)); drawCircle(verde, 1.1f*s, p(40.6f,41.6f))
        // lago
        drawPath(Path().apply { moveTo(4f*s,60f*s); cubicTo(18f*s,56f*s,28f*s,62f*s,38f*s,62f*s); cubicTo(48f*s,62f*s,58f*s,56f*s,72f*s,60f*s) },
            verde, style = Stroke(2.6f*s, cap = StrokeCap.Round))
        // totora
        val t = Color(0xFF4FC47A)
        drawPath(Path().apply { moveTo(12f*s,58f*s); cubicTo(12f*s,51f*s,15f*s,44f*s,15f*s,38f*s) }, t.copy(alpha=.85f), style = Stroke(2f*s, cap = StrokeCap.Round))
        drawPath(Path().apply { moveTo(17f*s,58f*s); cubicTo(17f*s,52f*s,20f*s,45f*s,21f*s,41f*s) }, t.copy(alpha=.6f), style = Stroke(2f*s, cap = StrokeCap.Round))
        drawPath(Path().apply { moveTo(60f*s,58f*s); cubicTo(60f*s,53f*s,62f*s,48f*s,62f*s,44f*s) }, t.copy(alpha=.5f), style = Stroke(1.8f*s, cap = StrokeCap.Round))
    }
}

/** Zigzag de aguayo, muy tenue. Solo detrás del splash. */
@Composable
fun FondoAguayo(modifier: Modifier = Modifier) {
    Canvas(modifier) {
        val w = 20.dp.toPx(); val h = 16.dp.toPx()
        val colores = listOf(Color(0xFF7FD4E0), Color(0xFF8FD0A6), Color(0xFF4FC47A))
        var y = 0f
        while (y < size.height) {
            colores.forEachIndexed { i, c ->
                val base = y + (i + 1) * h / 2f
                val path = Path().apply {
                    moveTo(0f, base); var x = 0f
                    while (x < size.width) { lineTo(x + w / 4, base - h / 2); lineTo(x + w / 2, base); x += w / 2 }
                }
                drawPath(path, c.copy(alpha = .07f), style = Stroke(1.dp.toPx()))
            }
            y += h
        }
    }
}
