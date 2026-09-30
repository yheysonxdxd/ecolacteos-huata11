package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.foundation.layout.*
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
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
        val todas = d.lista("entregas")
        val texto = buscar.trim()
        val lista = if (texto.isEmpty()) todas else todas.filter {
            it.txt("proveedor").contains(texto, ignoreCase = true) ||
                it.txt("comunidad").contains(texto, ignoreCase = true) ||
                it.txt("vehiculo").contains(texto, ignoreCase = true)
        }
        val analizadas = d.num("analizadas_hoy").toInt()

        PantallaScroll {
            CalidadReal.mensaje?.let { AvisoCampo(it, esAlerta = CalidadReal.mensajeGrave) }
            Text(
                "No hace falta analizar a todos: elige a cualquier proveedor. " +
                    "Las entregas ya cuentan como aceptadas; si la muestra sale mal, el diagnóstico la corrige.",
                style = Texto.Cuerpo, color = Altiplano.TextoTerciario,
            )
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Tarjeta(Modifier.weight(1f)) { MiniCifra("Analizadas hoy", "$analizadas", Altiplano.Exito) }
                Tarjeta(Modifier.weight(1f)) { MiniCifra("Sin analizar", "${todas.size}") }
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
                    val prom = x.num("promedio")
                    val desvio = if (prom > 0) (x.num("litros") - prom) / prom * 100 else 0.0
                    FilaLista(
                        x.txt("proveedor"),
                        "${x.txt("comunidad")} · ${x.txt("vehiculo")} · ${x.txt("hora")}" +
                            if (abs(desvio) >= 20) " · ${if (desvio > 0) "+" else ""}${formatear(desvio, 0)} % vs. promedio" else "",
                        onClick = { CalidadReal.limpiar(); CalidadReal.seleccion = x; CalidadReal.mensaje = null; irA(1) },
                        // un desvío grande no obliga, pero ayuda a decidir a quién muestrear
                        borde = if (abs(desvio) >= 20) Altiplano.AvisoBorde else Altiplano.Borde,
                    ) {
                        Text("${formatear(x.num("litros"))} L", style = Texto.Fila, color = Altiplano.Texto)
                    }
                }
            }
            if (todas.isEmpty()) AvisoCampo("No hay entregas de hoy ni de ayer sin analizar.")
            else if (lista.isEmpty()) AvisoCampo("Nadie coincide con \"$texto\".")
            TextButton(onClick = recargar) { Text("Actualizar", color = Altiplano.TextoTerciario) }
        }
    }
}
