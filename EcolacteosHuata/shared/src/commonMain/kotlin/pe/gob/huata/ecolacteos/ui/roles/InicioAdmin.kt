package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.animation.*
import androidx.compose.animation.core.*
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.delay
import kotlinx.serialization.json.JsonObject
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*
import kotlin.time.Clock

/**
 * Inicio del administrador: lo de hoy en cuatro cifras grandes y botones de colores para lo que más
 * se hace (vender, pagar, producir…). Así no hay que buscar entre pestañas: se toca y se llega.
 * Planta y "Más" también son tarjetas grandes en vez de una fila de pestañas.
 */

/** Una acción grande de Inicio / Planta / Más: emoji, nombre, para qué sirve y su color. */
internal data class Accion(val emoji: String, val titulo: String, val detalle: String, val color: Color, val ir: () -> Unit)

internal val VERDE = Color(0xFF4FC47A)
internal val AMBAR = Color(0xFFE8B44C)
internal val CELESTE = Color(0xFF6FC3E8)
internal val LILA = Color(0xFFB79CF2)
internal val ROSA = Color(0xFFEF8F8B)
internal val ARENA = Color(0xFFD9C6A0)

/** [irA] cambia de opción del menú de abajo por su ruta (admin/dinero, admin/planta…). */
@Composable
fun InicioAdmin(irA: (String) -> Unit) {
    val a = AdminPestanas
    val abrirPlanta = { paso: Int -> a.planta = paso; irA("admin/planta") }
    val abrirMas = { cual: Int -> a.mas = cual; irA("admin/mas") }

    CargaServidor("/api/v1/admin/inicio") { d, _ ->
        PantallaScroll {
            Aparece(0) {
                Column {
                    Text(saludo() + ", ${d.txt("nombre").substringBefore(' ')} 👋", style = Texto.TituloPantalla.copy(fontSize = 24.sp), color = Altiplano.Texto)
                    Text("Esto es lo de hoy en la planta.", style = Texto.Cuerpo, color = Altiplano.TextoTerciario)
                }
            }
            // cuatro cifras grandes; tocar una lleva a su pantalla
            val leche = d.obj("leche")
            val debe = d.obj("se_debe")
            val vendido = d.obj("vendido_hoy")
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Cifra(1, "🥛", "Leche", leche?.num("litros") ?: 0.0, " L",
                    leche?.txt("fecha")?.takeIf { it.isNotEmpty() }?.let { "${fechaCorta(it)} · ${leche.num("entregas").toInt()} entregas" } ?: "sin acopio aún",
                    CELESTE, Modifier.weight(1f)) { a.acopio = 0; irA("admin/acopio") }
                Cifra(2, "💵", "Se debe", debe?.num("total") ?: 0.0, "", "a ${(debe?.num("proveedores") ?: 0.0).toInt()} proveedores",
                    AMBAR, Modifier.weight(1f), dinero = true) { DineroAdmin.vista = 2; a.dinero = 0; irA("admin/dinero") }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Cifra(3, "🧀", "Produciendo", d.num("lotes_en_proceso"), " lotes", "toca para cerrarlos",
                    LILA, Modifier.weight(1f)) { abrirPlanta(4) }
                Cifra(4, "🛒", "Vendido hoy", vendido?.num("total") ?: 0.0, "", "${(vendido?.num("notas") ?: 0.0).toInt()} notas de venta",
                    VERDE, Modifier.weight(1f), dinero = true) { abrirPlanta(6) }
            }
            // lo que pide atención, solo si hay algo
            val bajos = d.num("insumos_bajos").toInt()
            if (bajos > 0) Aparece(5) {
                Aviso("⚠", "$bajos ${if (bajos == 1) "insumo está" else "insumos están"} bajo el mínimo", "Tocar para comprar", AMBAR) { abrirPlanta(0) }
            }
            val sinAnalizar = d.num("sin_analizar").toInt()
            if (sinAnalizar > 0) Aparece(6) {
                Aviso("🔬", "$sinAnalizar ${if (sinAnalizar == 1) "entrega espera" else "entregas esperan"} el análisis de Calidad", "Se ven en Acopio", CELESTE) { a.acopio = 0; irA("admin/acopio") }
            }

            Aparece(6) { Text("¿QUÉ QUIERES HACER?", style = Texto.Eyebrow, color = Altiplano.TextoTerciario, modifier = Modifier.padding(top = 6.dp)) }
            Botonera(7, listOf(
                Accion("🏷️", "Vender", "Queso y yogurt a un cliente", VERDE) { ComprasReal.pestanaVentas = 0; abrirPlanta(5) },
                Accion("💵", "Pagar", "A los proveedores de leche", AMBAR) { DineroAdmin.vista = 2; irA("admin/dinero") },
                Accion("🧀", "Producir", "¿Qué vas a hacer hoy?", LILA) { abrirPlanta(3) },
                Accion("🛒", "Comprar", "Insumos que faltan", ARENA) { abrirPlanta(0) },
                Accion("🚚", "Acopio", "Leche por día y por carro", CELESTE) { a.acopio = 0; irA("admin/acopio") },
                Accion("🔬", "Calidad", "Rechazos y adulteraciones", ROSA) { abrirMas(0) },
            ))
        }
    }
}

/**
 * Planta en pasos, en el orden del día: comprar → guardar → producir → vender → revisar.
 * Se elige una tarjeta y se entra; "← Planta" vuelve aquí.
 */
@Composable
fun PlantaMenu() {
    val a = AdminPestanas
    val nombres = listOf("Compras", "Almacén", "Pérdidas", "Producir", "Lotes", "Vender", "Ventas y notas", "Kardex")
    AnimatedContent(a.planta, transitionSpec = { transicionMenu(targetState >= 0) }, label = "planta") { paso ->
        if (paso < 0) PantallaScroll {
            Aparece(0) { Text("Toca lo que vas a hacer. Están en el orden del día.", style = Texto.Cuerpo, color = Altiplano.TextoTerciario) }
            Paso(1, "1 · COMPRAR")
            Botonera(1, listOf(Accion("🛒", "Compras", "Pedir y recibir insumos", ARENA) { a.planta = 0 }))
            Paso(2, "2 · GUARDAR")
            Botonera(2, listOf(
                Accion("📦", "Almacén", "Lo que hay y lo que falta", CELESTE) { a.planta = 1 },
                Accion("⚠️", "Pérdidas", "Lo que se malogró o venció", ROSA) { a.planta = 2 },
            ))
            Paso(4, "3 · PRODUCIR")
            Botonera(4, listOf(
                Accion("🧀", "Producir", "Recetas y qué hacer hoy", LILA) { a.planta = 3 },
                Accion("⏳", "Lotes", "Los que están en proceso", AMBAR) { a.planta = 4 },
            ))
            Paso(6, "4 · VENDER")
            Botonera(6, listOf(
                Accion("🏷️", "Vender", "Nueva venta paso a paso", VERDE) { a.planta = 5 },
                Accion("🧾", "Ventas y notas", "Lo vendido en el mes", CELESTE) { a.planta = 6 },
            ))
            Paso(8, "5 · REVISAR")
            Botonera(8, listOf(Accion("📋", "Kardex", "Entradas y salidas", Color(0xFF9FB0C0)) { a.planta = 7 }))
        } else DentroDe("Planta", nombres[paso], { a.planta = -1 }) {
            when (paso) {
                0 -> ContenidoComprasReal("compras/orden") {}
                1 -> ContenidoComprasReal("compras/inventario") { a.planta = 7 } // tocar un insumo: su kardex
                2 -> PerdidasReal("compras")
                3 -> FormulasReal(puedeAceptar = true) { a.planta = 4 } // al iniciar un lote, a Lotes
                4 -> LotesReal()
                5 -> ContenidoComprasReal("compras/ventas-nueva") { a.planta = 6 } // después de vender: la nota
                6 -> ContenidoComprasReal("compras/ventas-mes") {}
                else -> KardexCompleto()
            }
        }
    }
}

/** Lo que se usa menos (Calidad, Padrón, Ajustes) en tarjetas grandes. */
@Composable
fun MasMenu() {
    val a = AdminPestanas
    val nombres = listOf("Calidad", "Padrón", "Ajustes")
    AnimatedContent(a.mas, transitionSpec = { transicionMenu(targetState >= 0) }, label = "mas") { cual ->
        if (cual < 0) PantallaScroll {
            Botonera(0, listOf(
                Accion("🔬", "Calidad", "Adulteraciones, rechazos y capacitación", ROSA) { a.mas = 0 },
                Accion("👥", "Padrón", "Proveedores, trabajadores y cambios de comunidad", CELESTE) { a.mas = 1 },
                Accion("⚙️", "Ajustes", "Precios, calidad, almacén, vehículos y rutas", Color(0xFF9FB0C0)) { a.mas = 2 },
            ), columnas = 1)
        } else DentroDe("Más", nombres[cual], { a.mas = -1 }) {
            when (cual) {
                0 -> ConPestanas(listOf("Adulteraciones", "Rechazos"), a.calidad, { a.calidad = it }) {
                    if (a.calidad == 0) AdulteracionesReal() else RechazosReal()
                }
                1 -> ConPestanas(listOf("Proveedores", "Trabajadores", "Cambios"), a.padron, { a.padron = it }) {
                    if (a.padron == 2) SolicitudesReal() else PadronReal(a.padron == 1)
                }
                else -> ConfiguracionReal()
            }
        }
    }
}

// ------------------------------------------------------------------ piezas

/** Entrar a una opción: se desliza desde la derecha; volver al menú, desde la izquierda. */
internal fun transicionMenu(entrando: Boolean): ContentTransform {
    val lado = if (entrando) 1 else -1
    return (slideInHorizontally(tween(260)) { it / 4 * lado } + fadeIn(tween(220))) togetherWith
        (slideOutHorizontally(tween(200)) { -it / 6 * lado } + fadeOut(tween(160)))
}

/** Barra "← Planta · Vender" encima de la pantalla elegida. */
@Composable
internal fun DentroDe(menu: String, titulo: String, volver: () -> Unit, contenido: @Composable () -> Unit) {
    Column(Modifier.fillMaxSize()) {
        Row(Modifier.fillMaxWidth().padding(horizontal = 6.dp, vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
            TextButton(onClick = volver) { Text("← $menu", style = Texto.Fila, color = Altiplano.CelesteAcento) }
            Text("·  $titulo", style = Texto.Fila, color = Altiplano.Texto)
        }
        Box(Modifier.weight(1f)) { contenido() }
    }
}

/** Aparece suave y desde abajo, uno detrás de otro según su [orden]. */
@Composable
internal fun Aparece(orden: Int, contenido: @Composable () -> Unit) {
    var visible by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { delay(orden * 55L); visible = true }
    AnimatedVisibility(
        visible,
        enter = fadeIn(tween(320)) + slideInVertically(tween(380, easing = FastOutSlowInEasing)) { it / 3 } + scaleIn(tween(380), initialScale = 0.94f),
    ) { contenido() }
}

/** Se achica un poco al tocarlo (responde al dedo). */
@Composable
internal fun Tocable(color: Color, modifier: Modifier = Modifier, onClick: () -> Unit, contenido: @Composable ColumnScope.() -> Unit) {
    val toque = remember { MutableInteractionSource() }
    val apretado by toque.collectIsPressedAsState()
    val escala by animateFloatAsState(if (apretado) 0.95f else 1f, spring(dampingRatio = 0.5f, stiffness = 600f), label = "toque")
    Surface(
        onClick = onClick, interactionSource = toque,
        modifier = modifier.graphicsLayer { scaleX = escala; scaleY = escala },
        shape = RoundedCornerShape(18.dp), color = color.copy(alpha = 0.13f), border = BorderStroke(1.dp, color.copy(alpha = 0.45f)),
    ) { Column(Modifier.padding(14.dp), content = contenido) }
}

/** Cifra grande que cuenta desde 0 hasta su valor. */
@Composable
internal fun Cifra(orden: Int, emoji: String, titulo: String, valor: Double, unidad: String, apoyo: String, color: Color,
                  modifier: Modifier, dinero: Boolean = false, onClick: () -> Unit) {
    var meta by remember { mutableStateOf(0.0) }
    LaunchedEffect(valor) { delay(orden * 55L + 120); meta = valor }
    val actual by animateFloatAsState(meta.toFloat(), tween(900, easing = FastOutSlowInEasing), label = "cifra")
    Box(modifier) {
        Aparece(orden) {
            Tocable(color, Modifier.fillMaxWidth(), onClick) {
                Text("$emoji  $titulo", style = Texto.Apoyo, color = color)
                Spacer(Modifier.height(6.dp))
                Text(if (dinero) soles(actual.toDouble()) else formatear(actual.toDouble(), 0) + unidad,
                    style = Texto.Dato.copy(fontSize = 26.sp), color = Altiplano.Texto, maxLines = 1)
                Text(apoyo, style = Texto.Apoyo, color = Altiplano.TextoTerciario, maxLines = 2)
            }
        }
    }
}

/** Aviso que se toca para ir a resolverlo; el ícono late suave para que se note. */
@Composable
internal fun Aviso(emoji: String, texto: String, accion: String, color: Color, onClick: () -> Unit) {
    val latido = rememberInfiniteTransition(label = "latido")
    val escala by latido.animateFloat(1f, 1.18f, infiniteRepeatable(tween(700), RepeatMode.Reverse), label = "latido")
    Tocable(color, Modifier.fillMaxWidth(), onClick) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Text(emoji, fontSize = 22.sp, modifier = Modifier.graphicsLayer { scaleX = escala; scaleY = escala })
            Column(Modifier.weight(1f)) {
                Text(texto, style = Texto.Fila, color = Altiplano.Texto)
                Text(accion, style = Texto.Apoyo, color = color)
            }
            Text("›", fontSize = 24.sp, color = color)
        }
    }
}

@Composable
internal fun Paso(orden: Int, texto: String) {
    Aparece(orden) { Text(texto, style = Texto.Eyebrow, color = Altiplano.TextoTerciario, modifier = Modifier.padding(top = 4.dp)) }
}

/** Botones grandes de colores en 2 columnas (o 1), cada uno aparece un poco después del anterior. */
@Composable
internal fun Botonera(desde: Int, acciones: List<Accion>, columnas: Int = 2) {
    acciones.chunked(columnas).forEachIndexed { fila, par ->
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            par.forEachIndexed { k, ac ->
                Box(Modifier.weight(1f)) {
                    Aparece(desde + fila * columnas + k) {
                        Tocable(ac.color, Modifier.fillMaxWidth().heightIn(min = if (columnas == 1) 0.dp else 118.dp), ac.ir) {
                            if (columnas == 1) Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                                Text(ac.emoji, fontSize = 30.sp)
                                Column(Modifier.weight(1f)) {
                                    Text(ac.titulo, style = Texto.Fila.copy(fontSize = 17.sp, fontWeight = FontWeight.SemiBold), color = Altiplano.Texto)
                                    Text(ac.detalle, style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                                }
                                Text("›", fontSize = 26.sp, color = ac.color)
                            } else {
                                Text(ac.emoji, fontSize = 32.sp)
                                Spacer(Modifier.height(8.dp))
                                Text(ac.titulo, style = Texto.Fila.copy(fontSize = 17.sp, fontWeight = FontWeight.SemiBold), color = Altiplano.Texto)
                                Text(ac.detalle, style = Texto.Apoyo, color = Altiplano.TextoTerciario, maxLines = 2)
                            }
                        }
                    }
                }
            }
            if (par.size < columnas) Spacer(Modifier.weight(1f))
        }
    }
}

/** Buenos días / tardes / noches según la hora de Puno (UTC−5). */
internal fun saludo(): String {
    val hora = ((Clock.System.now().epochSeconds / 3600 - 5) % 24 + 24) % 24
    return when (hora) { in 5..11 -> "Buenos días"; in 12..18 -> "Buenas tardes"; else -> "Buenas noches" }
}
