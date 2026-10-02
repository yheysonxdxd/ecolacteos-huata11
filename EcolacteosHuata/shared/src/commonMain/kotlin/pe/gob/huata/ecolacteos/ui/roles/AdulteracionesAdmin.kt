package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import kotlinx.serialization.json.JsonObject
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

/**
 * Proveedores sancionados por adulteración (GET /api/v1/admin/adulteraciones), solo para ver:
 * dados de baja (2.ª vez) primero y luego los que tienen su 1.ª advertencia.
 * Tocar a alguien muestra cada vez que Calidad le detectó agua añadida.
 */
@Composable
internal fun AdulteracionesReal() {
    var filtro by remember { mutableStateOf(0) }
    var abierto by remember { mutableStateOf<String?>(null) }
    CargaServidor("/api/v1/admin/adulteraciones") { d, _ ->
        val todos = d.lista("proveedores")
        val lista = when (filtro) {
            1 -> todos.filter { it.txt("de_baja") == "true" }
            2 -> todos.filter { it.txt("de_baja") != "true" }
            else -> todos
        }
        PantallaScroll {
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Tarjeta(Modifier.weight(1f)) { MiniCifra("Dados de baja", d.num("de_baja").toInt().toString(), Altiplano.Alerta) }
            Tarjeta(Modifier.weight(1f)) { MiniCifra("Con advertencia", d.num("advertidos").toInt().toString(), Altiplano.Aviso) }
        }
        Text(
            "La 1.ª vez se paga esa entrega a precio reducido; la 2.ª vez se le da de baja.",
            style = Texto.Apoyo, color = Altiplano.TextoTerciario,
        )
        Segmentos(listOf("Todos", "De baja", "Advertidos"), filtro) { filtro = it }
        if (lista.isEmpty()) {
            AvisoCampo(if (todos.isEmpty()) "No hay proveedores sancionados por adulteración." else "Nadie en este grupo.")
        }
        lista.forEach { p ->
            val id = p.txt("id")
            ProveedorAdulterado(p, abierto == id) { abierto = if (abierto == id) null else id }
        }
        }
    }
}

@Composable
private fun ProveedorAdulterado(p: JsonObject, abierto: Boolean, onClick: () -> Unit) {
    val deBaja = p.txt("de_baja") == "true"
    val veces = p.lista("veces")
    Tarjeta(borde = if (deBaja) Altiplano.AlertaBorde else Altiplano.Borde, onClick = onClick) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(p.txt("nombre"), style = Texto.Fila, color = Altiplano.Texto)
                Spacer(Modifier.height(3.dp))
                Text(
                    "DNI ${p.txt("dni")} · ${p.txt("comunidad")} · ${p.txt("vehiculo")}",
                    style = Texto.Apoyo, color = Altiplano.TextoTerciario,
                )
                Text(
                    "${veces.size} ${if (veces.size == 1) "vez" else "veces"} · última el ${fechaCorta(p.txt("ultima"))}",
                    style = Texto.Apoyo, color = Altiplano.TextoTerciario,
                )
            }
            Spacer(Modifier.width(10.dp))
            if (deBaja) Chip("Dado de baja", Altiplano.Alerta, Altiplano.AlertaFondo)
            else Chip("Advertencia", Altiplano.Aviso, Altiplano.AvisoFondo)
        }
        if (abierto) {
            if (deBaja) {
                Spacer(Modifier.height(10.dp))
                AvisoCampo("Dado de baja: " + p.txt("motivo_baja").ifEmpty { "segunda adulteración" }, esAlerta = true)
            }
            veces.forEach { v ->
                Spacer(Modifier.height(10.dp))
                HorizontalDivider(color = Altiplano.BordeSuave)
                Spacer(Modifier.height(8.dp))
                val baja = v.txt("medida") == "BAJA_DEFINITIVA"
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        "${v.num("nivel").toInt()}.ª vez · ${fechaCorta(v.txt("fecha"))}" +
                            (v.txt("litros").takeIf { it.isNotEmpty() }?.let { " · ${formatear(v.num("litros"))} L" } ?: ""),
                        style = Texto.Cuerpo, color = Altiplano.Texto, modifier = Modifier.weight(1f),
                    )
                    Text(
                        v.txt("agua_anadida").takeIf { it.isNotEmpty() }?.let { "${formatear(v.num("agua_anadida"))} % agua" } ?: "",
                        style = Texto.Cuerpo, color = Altiplano.Alerta,
                    )
                }
                Text(
                    if (baja) "Baja definitiva"
                    else "Precio reducido" + (v.txt("precio_aplicado").takeIf { it.isNotEmpty() }?.let { " · ${soles(v.num("precio_aplicado"))}/L" } ?: ""),
                    style = Texto.Apoyo, color = if (baja) Altiplano.Alerta else Altiplano.Aviso,
                )
                v.txt("analista").takeIf { it.isNotEmpty() }?.let {
                    Text(
                        "Analizó: $it" + (v.txt("muestra").takeIf { m -> m.isNotEmpty() }?.let { m -> " · muestra de ${m.lowercase()}" } ?: ""),
                        style = Texto.Apoyo, color = Altiplano.TextoTerciario,
                    )
                }
            }
        } else {
            Spacer(Modifier.height(6.dp))
            Text("Toca para ver el detalle", style = Texto.Apoyo, color = Altiplano.TextoApagado)
        }
    }
}

/** Como ChipEstado, pero con texto propio ("Dado de baja" no es un estado de entrega). */
@Composable
private fun Chip(texto: String, color: Color, fondo: Color) {
    Surface(shape = RoundedCornerShape(999.dp), color = fondo, border = BorderStroke(1.dp, color.copy(alpha = 0.35f))) {
        Text(texto, style = Texto.Apoyo, color = color, modifier = Modifier.padding(horizontal = 9.dp, vertical = 3.dp))
    }
}
