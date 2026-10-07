package pe.gob.huata.ecolacteos.ui.componentes

import androidx.compose.foundation.layout.*
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.ChevronLeft
import androidx.compose.material.icons.outlined.ChevronRight
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextAlign
import pe.gob.huata.ecolacteos.tema.*
import kotlin.time.Duration.Companion.days
import kotlin.time.Instant

/**
 * "‹  Semana 29 set – 5 oct  ›", como las flechitas de la web de oficina.
 * Una flecha en null queda apagada (no hay antes / después).
 */
@Composable
fun Flechitas(texto: String, apoyo: String? = null, onAnterior: (() -> Unit)?, onSiguiente: (() -> Unit)?) {
    Tarjeta {
        Row(verticalAlignment = Alignment.CenterVertically) {
            IconButton(onClick = { onAnterior?.invoke() }, enabled = onAnterior != null) {
                Icon(Icons.Outlined.ChevronLeft, "Anterior", tint = if (onAnterior != null) Altiplano.Texto else Altiplano.TextoApagado)
            }
            Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
                Text(texto, style = Texto.Fila, color = Altiplano.Texto, textAlign = TextAlign.Center)
                apoyo?.let { Text(it, style = Texto.Apoyo, color = Altiplano.TextoTerciario, textAlign = TextAlign.Center) }
            }
            IconButton(onClick = { onSiguiente?.invoke() }, enabled = onSiguiente != null) {
                Icon(Icons.Outlined.ChevronRight, "Siguiente", tint = if (onSiguiente != null) Altiplano.Texto else Altiplano.TextoApagado)
            }
        }
    }
}

private val MESES_LARGOS = listOf("enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto",
    "setiembre", "octubre", "noviembre", "diciembre")

/** "2026-10" → "octubre 2026" */
fun nombreMes(ym: String): String {
    val p = ym.split("-")
    val m = p.getOrNull(1)?.toIntOrNull() ?: return ym
    return "${MESES_LARGOS[m - 1]} ${p[0]}"
}

/** "2026-10-06" + 1 → "2026-10-07" */
fun sumarDias(iso: String, dias: Int): String = (Instant.parse("${iso.take(10)}T12:00:00Z") + dias.days).toString().take(10)
