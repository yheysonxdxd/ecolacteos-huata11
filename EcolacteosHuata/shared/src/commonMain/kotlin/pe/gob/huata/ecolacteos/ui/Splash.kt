package pe.gob.huata.ecolacteos.ui

import androidx.compose.animation.core.*
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import pe.gob.huata.ecolacteos.datos.Rol
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

/**
 * Bienvenida. El selector de rol es solo para la demo: en producción el rol
 * sale del login (tabla usuarios.rol).
 */
@Composable
fun Splash(hayRed: Boolean, onEntrar: (Rol) -> Unit) {
    var rol by remember { mutableStateOf(Rol.ACOPIADOR) }
    val aparece = remember { Animatable(0f) }
    LaunchedEffect(Unit) { aparece.animateTo(1f, tween(600, easing = FastOutSlowInEasing)) }

    Box(
        Modifier.fillMaxSize().background(
            Brush.verticalGradient(listOf(Color(0xFF17492F), Color(0xFF143327), Color(0xFF10202A), Altiplano.Fondo))
        )
    ) {
        FondoAguayo(Modifier.fillMaxWidth().fillMaxHeight(0.45f))
        Column(
            Modifier.fillMaxSize().padding(horizontal = 30.dp, vertical = 40.dp),
            verticalArrangement = Arrangement.SpaceBetween,
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Column(
                Modifier.padding(top = 56.dp).alpha(aparece.value).offset(y = ((1 - aparece.value) * -20).dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                MarcaHuata(142.dp)
                Spacer(Modifier.height(26.dp))
                Text(
                    buildAnnotatedString {
                        append("Ecolácteos ")
                        withStyle(SpanStyle(color = Altiplano.CelesteAcento, fontStyle = FontStyle.Italic)) { append("Huata") }
                    },
                    style = Texto.TituloPantalla.copy(fontSize = 31.sp, lineHeight = 33.sp),
                    color = Altiplano.Texto, textAlign = TextAlign.Center,
                )
                Spacer(Modifier.height(12.dp))
                Text("LECHE Y QUESOS DEL ALTIPLANO", style = Texto.Eyebrow.copy(fontSize = 13.sp), color = Color(0xFF5FAA78))
                Spacer(Modifier.height(24.dp))
                Text(
                    "3 carros y 1 motocar · 14 comunidades\nFunciona sin señal y sincroniza después",
                    style = Texto.Apoyo, color = Color(0xFFA4B1BC), textAlign = TextAlign.Center,
                )
            }

            Column(Modifier.fillMaxWidth().alpha(aparece.value), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Text("ENTRAR COMO", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
                val roles = Rol.entries
                roles.chunked(3).forEach { fila ->
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        fila.forEach { r ->
                            val sel = r == rol
                            Surface(
                                onClick = { rol = r },
                                modifier = Modifier.weight(1f).height(44.dp),
                                shape = RoundedCornerShape(12.dp),
                                color = if (sel) Altiplano.VerdeMarcaSuave else Color.Transparent,
                                border = BorderStroke(1.dp, if (sel) Altiplano.VerdeMarca else Altiplano.Borde),
                            ) {
                                Box(contentAlignment = Alignment.Center) {
                                    Text(nombreRol(r), style = Texto.Apoyo, color = if (sel) Altiplano.Texto else Altiplano.TextoSecundario)
                                }
                            }
                        }
                    }
                }
                Spacer(Modifier.height(4.dp))
                Surface(
                    onClick = { onEntrar(rol) },
                    modifier = Modifier.fillMaxWidth().height(Medidas.BotonPrimario),
                    shape = RoundedCornerShape(16.dp),
                    color = Altiplano.VerdeMarca.copy(alpha = .24f),
                    border = BorderStroke(1.dp, Altiplano.VerdeMarca),
                ) {
                    Box(contentAlignment = Alignment.Center) {
                        Text("Iniciar jornada", style = Texto.Fila.copy(fontSize = 17.5.sp), color = Altiplano.Texto)
                    }
                }
                Text(
                    if (hayRed) "Con conexión · listo para sincronizar" else "Sin conexión · modo local activo",
                    style = Texto.Apoyo, color = Color(0xFF93A1AD),
                    modifier = Modifier.align(Alignment.CenterHorizontally),
                )
            }
        }
    }
}

fun nombreRol(r: Rol) = when (r) {
    Rol.ACOPIADOR -> "Acopiador"; Rol.CALIDAD -> "Calidad"; Rol.PRODUCTOR -> "Productor"
    Rol.OPERARIO -> "Operario"; Rol.COMPRAS -> "Compras"; Rol.ADMIN -> "Admin"
}
