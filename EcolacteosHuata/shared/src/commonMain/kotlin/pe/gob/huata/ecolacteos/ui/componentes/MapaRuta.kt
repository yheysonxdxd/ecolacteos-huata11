package pe.gob.huata.ecolacteos.ui.componentes

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clipToBounds
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.*
import androidx.compose.ui.graphics.drawscope.DrawScope
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.input.pointer.pointerInput
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
import pe.gob.huata.ecolacteos.datos.Vehiculo
import pe.gob.huata.ecolacteos.tema.Altiplano
import kotlin.math.hypot
import kotlin.math.max

/**
 * Croquis del distrito de Huata, dibujado en Canvas. Google Maps no cubre los
 * caminos rurales, así que las comunidades traen coordenadas del dibujo
 * (croquis_x, croquis_y sobre un lienzo de 340×400), no GPS. Como no baja
 * nada de internet, funciona igual sin señal.
 *
 * - Un pin por comunidad: anillo que se llena con lo registrado y "2/3" adentro.
 * - La ruta del acopiador va gruesa y numerada; las demás, apagadas.
 * - Con [acercar], el dibujo se acerca a la ruta del acopiador.
 */
private const val LIENZO_W = 340f
private const val LIENZO_H = 400f

/** Avance de una comunidad en el día. */
data class PinComunidad(val comunidad: Comunidad, val estado: EstadoPin, val hechos: Int, val total: Int)

fun colorPin(p: EstadoPin): Color = when (p) {
    EstadoPin.REGISTRADO -> Altiplano.Exito
    EstadoPin.SIN_ENVIAR -> Altiplano.Aviso
    EstadoPin.NO_ENTREGO -> Altiplano.Alerta
    EstadoPin.FALTA -> Color(0xFF8A9BAA)
}

private val FONDO_PIN = Color(0xFF0E1A24)
private val LAGO = Color(0xFF16304A)
private val ORILLA = Color(0xFF2B5170)
private val CARRETERA = Color(0xFF3B4A58)

/** Parte del lienzo que se ve: todo Huata o solo alrededor de la ruta. */
private fun vista(comunidades: List<Comunidad>, ruta: List<Long>, acercar: Boolean): Rect {
    val puntos = ruta.mapNotNull { id -> comunidades.firstOrNull { it.id == id } }
    if (!acercar || puntos.isEmpty()) return Rect(0f, 0f, LIENZO_W, LIENZO_H)
    val margen = 46f
    var x0 = puntos.minOf { it.croquisX } - margen
    var x1 = puntos.maxOf { it.croquisX } + margen
    var y0 = puntos.minOf { it.croquisY } - margen
    var y1 = puntos.maxOf { it.croquisY } + margen
    // misma forma que el lienzo y no tan cerca que se pierda la referencia
    val w = max(max(x1 - x0, (y1 - y0) * LIENZO_W / LIENZO_H), 170f)
    val h = w * LIENZO_H / LIENZO_W
    val cx = (x0 + x1) / 2; val cy = (y0 + y1) / 2
    x0 = (cx - w / 2).coerceIn(0f, max(0f, LIENZO_W - w)); y0 = (cy - h / 2).coerceIn(0f, max(0f, LIENZO_H - h))
    return Rect(x0, y0, x0 + w, y0 + h)
}

@Composable
fun MapaRuta(
    comunidades: List<Comunidad>,
    vehiculos: List<Vehiculo>,
    pines: List<PinComunidad>,
    modifier: Modifier = Modifier,
    resaltado: Long? = null,
    acercar: Boolean = false,
    elegida: Long? = null,
    onToque: (Comunidad) -> Unit = {},
) {
    val medidor = rememberTextMeasurer()
    val mio = vehiculos.firstOrNull { it.id == resaltado }
    val ruta = mio?.ordenRuta.orEmpty()
    val v = remember(comunidades, ruta, acercar) { vista(comunidades, ruta, acercar) }

    Canvas(
        modifier.fillMaxWidth().aspectRatio(LIENZO_W / LIENZO_H).clipToBounds()
            .pointerInput(pines, v) {
                detectTapGestures { p ->
                    val k = size.width / v.width
                    val radio = 30.dp.toPx()
                    pines.minByOrNull { hypot((it.comunidad.croquisX - v.left) * k - p.x, (it.comunidad.croquisY - v.top) * k - p.y) }
                        ?.takeIf { hypot((it.comunidad.croquisX - v.left) * k - p.x, (it.comunidad.croquisY - v.top) * k - p.y) < radio }
                        ?.let { onToque(it.comunidad) }
                }
            }
    ) {
        val k = size.width / v.width
        fun pt(x: Float, y: Float) = Offset((x - v.left) * k, (y - v.top) * k)
        fun de(c: Comunidad) = pt(c.croquisX, c.croquisY)

        // lago Titicaca al sur, con su orilla
        fun Path.borde() {
            moveTo(pt(-20f, 330f).x, pt(-20f, 330f).y)
            pt(58f, 316f).let { a -> pt(104f, 336f).let { b -> pt(158f, 346f).let { c -> cubicTo(a.x, a.y, b.x, b.y, c.x, c.y) } } }
            pt(216f, 356f).let { a -> pt(276f, 372f).let { b -> pt(360f, 356f).let { c -> cubicTo(a.x, a.y, b.x, b.y, c.x, c.y) } } }
        }
        val orilla = Path().apply { borde() }
        val lago = Path().apply {
            borde()
            lineTo(pt(360f, 420f).x, pt(360f, 420f).y); lineTo(pt(-20f, 420f).x, pt(-20f, 420f).y); close()
        }
        drawPath(lago, LAGO)
        drawPath(orilla, ORILLA, style = Stroke(1.5.dp.toPx()))
        texto(medidor, "Lago Titicaca", pt(200f, 385f),
            TextStyle(color = Color(0xFF6E9CC0), fontSize = 12.sp, fontStyle = FontStyle.Italic))

        // carretera PE-3S bordeando el lago
        val carretera = Path().apply {
            pt(4f, 318f).let { moveTo(it.x, it.y) }
            pt(64f, 304f).let { a -> pt(112f, 322f).let { b -> pt(166f, 332f).let { c -> cubicTo(a.x, a.y, b.x, b.y, c.x, c.y) } } }
            pt(222f, 342f).let { a -> pt(282f, 358f).let { b -> pt(338f, 344f).let { c -> cubicTo(a.x, a.y, b.x, b.y, c.x, c.y) } } }
        }
        drawPath(carretera, CARRETERA, style = Stroke(4.dp.toPx(), cap = StrokeCap.Round))
        texto(medidor, "PE-3S", pt(36f, 300f), TextStyle(color = Color(0xFF7D8C99), fontSize = 9.sp, fontWeight = FontWeight.Bold))

        // rutas: las ajenas finas y apagadas, la del acopiador gruesa con halo
        vehiculos.sortedBy { it.id == resaltado }.forEach { ve ->
            val puntos = ve.ordenRuta.mapNotNull { id -> comunidades.firstOrNull { it.id == id }?.let(::de) }
            if (puntos.size < 2) return@forEach
            val camino = curva(if (ve.rutaCircular) puntos + puntos.first() else puntos)
            val esMia = ve.id == resaltado
            val trazo = if (ve.rutaCircular) PathEffect.dashPathEffect(floatArrayOf(9.dp.toPx(), 6.dp.toPx())) else null
            if (esMia) {
                drawPath(camino, ve.color.copy(alpha = 0.18f), style = Stroke(12.dp.toPx(), cap = StrokeCap.Round, join = StrokeJoin.Round))
                drawPath(camino, ve.color, style = Stroke(4.dp.toPx(), cap = StrokeCap.Round, join = StrokeJoin.Round, pathEffect = trazo))
            } else {
                drawPath(camino, ve.color.copy(alpha = if (resaltado == null) 0.75f else 0.2f),
                    style = Stroke(2.dp.toPx(), cap = StrokeCap.Round, pathEffect = trazo))
            }
        }

        // comunidades sin proveedores: un punto chico
        val conPin = pines.map { it.comunidad.id }.toSet()
        comunidades.filter { it.id !in conPin }.forEach { drawCircle(Color(0xFF3E5566), 3.dp.toPx(), de(it)) }

        // pines con anillo de avance
        val radio = 15.dp.toPx()
        val anillo = 3.5.dp.toPx()
        pines.sortedBy { it.comunidad.id == elegida }.forEach { p ->
            val c = de(p.comunidad)
            val color = colorPin(p.estado)
            val apagado = resaltado != null && ruta.isNotEmpty() && p.comunidad.id !in ruta
            val a = if (apagado) 0.45f else 1f
            if (p.comunidad.id == elegida) drawCircle(Color.White, radio + 6.dp.toPx(), c, style = Stroke(2.dp.toPx()))
            drawCircle(FONDO_PIN.copy(alpha = 0.95f), radio, c)
            val caja = Size(radio * 2 - anillo, radio * 2 - anillo)
            val esquina = Offset(c.x - caja.width / 2, c.y - caja.height / 2)
            drawArc(color.copy(alpha = 0.22f * a), 0f, 360f, false, esquina, caja, style = Stroke(anillo))
            val parte = if (p.total == 0) 0f else p.hechos / p.total.toFloat()
            if (parte > 0f) drawArc(color.copy(alpha = a), -90f, 360f * parte, false, esquina, caja, style = Stroke(anillo, cap = StrokeCap.Round))
            texto(medidor, "${p.hechos}/${p.total}", c,
                TextStyle(color = Altiplano.Texto.copy(alpha = a), fontSize = 9.sp, fontWeight = FontWeight.Bold))
        }

        // nombres debajo de cada pin; en la ruta del acopiador con su número de paso
        pines.forEach { p ->
            val c = de(p.comunidad)
            val paso = ruta.indexOf(p.comunidad.id)
            val apagado = resaltado != null && ruta.isNotEmpty() && paso < 0
            etiqueta(
                medidor, if (paso >= 0) "${paso + 1} · ${p.comunidad.nombre}" else p.comunidad.nombre,
                Offset(c.x, c.y + radio + 10.dp.toPx()),
                TextStyle(
                    color = if (apagado) Altiplano.TextoTerciario else Altiplano.Texto, fontSize = 10.sp,
                    fontWeight = if (paso >= 0) FontWeight.SemiBold else FontWeight.Normal,
                ),
                fondo = Color(0xE00E1A24),
            )
        }

        // norte
        val n = Offset(size.width - 18.dp.toPx(), 22.dp.toPx())
        val u = 1.dp.toPx()
        drawPath(Path().apply {
            moveTo(n.x, n.y - 9 * u); lineTo(n.x - 5 * u, n.y + 4 * u); lineTo(n.x, n.y + u); lineTo(n.x + 5 * u, n.y + 4 * u); close()
        }, Color(0xFF93A1AD))
        texto(medidor, "N", Offset(n.x, n.y + 13 * u), TextStyle(color = Color(0xFF93A1AD), fontSize = 9.sp, fontWeight = FontWeight.Bold))
    }
}

/** Curva suave que pasa por los puntos (se arquea un poco entre cada par). */
private fun curva(puntos: List<Offset>) = Path().apply {
    moveTo(puntos.first().x, puntos.first().y)
    for (i in 1 until puntos.size) {
        val a = puntos[i - 1]; val b = puntos[i]
        quadraticBezierTo((a.x + b.x) / 2 + (b.y - a.y) * 0.16f, (a.y + b.y) / 2 - (b.x - a.x) * 0.16f, b.x, b.y)
    }
}

/** Texto centrado en [centro]. */
private fun DrawScope.texto(m: TextMeasurer, t: String, centro: Offset, estilo: TextStyle) {
    val r = m.measure(t, estilo)
    drawText(r, topLeft = Offset(centro.x - r.size.width / 2f, centro.y - r.size.height / 2f))
}

/** Texto con fondo redondeado centrado en [centro]; si cae fuera de lo visible no se dibuja. */
private fun DrawScope.etiqueta(m: TextMeasurer, t: String, centro: Offset, estilo: TextStyle, fondo: Color) {
    if (centro.x < 0 || centro.x > size.width || centro.y < 0 || centro.y > size.height + 20f) return
    val r = m.measure(t, estilo)
    val padX = 6.dp.toPx(); val padY = 2.dp.toPx()
    val w = r.size.width + padX * 2
    val h = r.size.height + padY * 2
    val x = (centro.x - w / 2).coerceIn(2f, size.width - w - 2f)
    val y = (centro.y - h / 2).coerceIn(2f, size.height - h - 2f)
    drawRoundRect(fondo, topLeft = Offset(x, y), size = Size(w, h), cornerRadius = CornerRadius(h / 2))
    drawText(r, topLeft = Offset(x + padX, y + padY))
}
