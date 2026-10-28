package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.serialization.json.JsonObject
import pe.gob.huata.ecolacteos.datos.Rol
import pe.gob.huata.ecolacteos.indiceDe
import pe.gob.huata.ecolacteos.datos.AnalisisPendientes
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*
import kotlin.math.abs

/**
 * Pestaña "Muestreo" de Calidad. El analista NO analiza a todos: va a donde
 * quiera y elige a cualquier proveedor. Las entregas ya están aceptadas; si
 * la muestra sale mal, el diagnóstico la rechaza o baja el precio.
 * GET /calidad/muestreo → entregas de hoy y ayer sin análisis.
 */
@Composable
fun MuestreoReal(irA: (Int) -> Unit) {
    var buscar by remember { mutableStateOf("") }

    CargaServidor("/api/v1/calidad/muestreo", clave = CalidadReal.version) { d, recargar ->
        // las analizadas sin conexión ya no se muestran (esperan en el teléfono)
        val guardadas = remember(d, AnalisisPendientes.cantidad) { AnalisisPendientes.entregas() }
        val todas = d.lista("entregas").filter { it.num("id").toLong() !in guardadas }
        val texto = buscar.trim()
        val lista = if (texto.isEmpty()) todas else todas.filter {
            it.txt("proveedor").contains(texto, ignoreCase = true) ||
                it.txt("comunidad").contains(texto, ignoreCase = true) ||
                it.txt("vehiculo").contains(texto, ignoreCase = true)
        }
        val analizadas = d.num("analizadas_hoy").toInt()

        PantallaScroll {
            CalidadReal.mensaje?.let { AvisoCampo(it, esAlerta = CalidadReal.mensajeGrave) }
            AnalisisPendientes.cantidad.takeIf { it > 0 }?.let { n ->
                AvisoCampo(
                    if (AnalisisPendientes.enviando) "Enviando $n análisis guardados en el celular…"
                    else "$n análisis guardados en el celular esperan conexión. Se envían solos."
                )
            }
            Text(
                "No hace falta analizar a todos: toca a cualquier proveedor y anota lo que marca el lactoscan. " +
                    "Las entregas ya cuentan como aceptadas; si la muestra sale mal, el diagnóstico la corrige.",
                style = Texto.Cuerpo, color = Altiplano.TextoTerciario,
            )
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Cifra(0, "✅", "Analizadas hoy", analizadas.toDouble(), "", "con el lactoscan", VERDE, Modifier.weight(1f)) {}
                Cifra(1, "🧪", "Sin analizar", todas.size.toDouble(), "", "de hoy y ayer", if (todas.isEmpty()) VERDE else AMBAR, Modifier.weight(1f)) {}
            }
            OutlinedTextField(
                value = buscar, onValueChange = { buscar = it.take(40) },
                label = { Text("Buscar proveedor, comunidad o vehículo") },
                leadingIcon = { Icon(Icons.Outlined.Search, contentDescription = null, tint = Altiplano.TextoTerciario) },
                singleLine = true,
                colors = OutlinedTextFieldDefaults.colors(
                    focusedTextColor = Altiplano.Texto, unfocusedTextColor = Altiplano.Texto,
                    focusedBorderColor = Altiplano.VerdeMarca, unfocusedBorderColor = Altiplano.Borde,
                    focusedLabelColor = Altiplano.TextoSecundario, unfocusedLabelColor = Altiplano.TextoTerciario,
                    cursorColor = Altiplano.Exito,
                ),
                modifier = Modifier.fillMaxWidth(),
            )

            val (hoy, ayer) = lista.partition { it.txt("es_hoy") == "true" }
            listOf("Hoy" to hoy, "Ayer" to ayer).forEach { (titulo, grupo) ->
                if (grupo.isEmpty()) return@forEach
                Seccion("$titulo · ${grupo.size}")
                grupo.forEach { x ->
                    // se abre el diagnóstico aquí mismo
                    FilaMuestreo(x) { CalidadReal.limpiar(); CalidadReal.seleccion = x; CalidadReal.mensaje = null; irA(indiceDe(Rol.CALIDAD, "calidad/cola")) }
                }
            }
            if (todas.isEmpty()) AvisoCampo("No hay entregas de hoy ni de ayer sin analizar.")
            else if (lista.isEmpty()) AvisoCampo("Nadie coincide con \"$texto\".")
            TextButton(onClick = recargar) { Text("Actualizar", color = Altiplano.TextoTerciario) }
        }
    }
}

/** Color de cada carro, el mismo de su ruta en Acopio. */
private fun colorCarro(codigo: String): Color = when (codigo) {
    "C-01" -> CELESTE; "C-02" -> ARENA; "C-03" -> LILA; "M-01" -> VERDE; else -> Color(0xFF9FB0C0)
}

/**
 * Un proveedor por analizar: la franja y el círculo con sus iniciales del color de su carro, los litros grandes
 * y, si trajo mucho más o menos que su promedio, un aviso ámbar (no obliga, pero ayuda a decidir a quién muestrear).
 */
@Composable
private fun FilaMuestreo(x: JsonObject, onClick: () -> Unit) {
    val color = colorCarro(x.txt("vehiculo"))
    val prom = x.num("promedio")
    val desvio = if (prom > 0) (x.num("litros") - prom) / prom * 100 else 0.0
    val raro = abs(desvio) >= 20
    val iniciales = x.txt("proveedor").split(' ').filter { it.isNotEmpty() }.take(2).joinToString("") { it.take(1).uppercase() }
    Tocable(if (raro) AMBAR else color, Modifier.fillMaxWidth(), onClick) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(
                Modifier.size(42.dp).clip(CircleShape).background(color.copy(alpha = 0.22f)).border(2.dp, color, CircleShape),
                contentAlignment = Alignment.Center,
            ) { Text(iniciales, style = Texto.Fila.copy(fontWeight = FontWeight.Bold, fontSize = 15.sp), color = color) }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(x.txt("proveedor"), style = Texto.Fila, color = Altiplano.Texto, maxLines = 1)
                Text("${x.txt("vehiculo")} · ${x.txt("hora")} · ${x.txt("comunidad")}", style = Texto.Apoyo, color = Altiplano.TextoTerciario, maxLines = 1)
                if (raro) Text(
                    "${if (desvio > 0) "▲ +" else "▼ "}${formatear(desvio, 0)} % vs. su promedio (${formatear(prom)} L)",
                    style = Texto.Apoyo, color = AMBAR,
                )
            }
            Spacer(Modifier.width(8.dp))
            Column(horizontalAlignment = Alignment.End) {
                Text(formatear(x.num("litros")), style = Texto.Dato.copy(fontSize = 22.sp), color = Altiplano.Texto)
                Text("litros", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            }
            Text("  ›", fontSize = 22.sp, color = color)
        }
    }
}
