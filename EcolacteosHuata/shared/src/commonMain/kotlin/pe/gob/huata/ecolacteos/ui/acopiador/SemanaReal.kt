package pe.gob.huata.ecolacteos.ui.acopiador

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

/**
 * Semana del acopiador con datos de la base: litros de los últimos 7 días y
 * acumulado por proveedor. Si eligió vehículo, solo el de su ruta.
 */
@Composable
fun PantallaSemanaReal() {
    val vehiculoId = VehiculoElegido.id
    val ruta = "/api/v1/acopio/semana" + (vehiculoId?.let { "?vehiculo_id=$it" } ?: "")
    CargaServidor(ruta, clave = ResultadoSync.mensaje) { d, recargar ->
        val dias = d.lista("dias").map { it.txt("dia") to it.num("litros") }
        val provs = d.lista("proveedores")
        val codigo = vehiculoId?.let { id -> Demo.vehiculos.firstOrNull { it.id == id }?.codigo }

        PantallaScroll {
            TarjetaDato(
                eyebrow = "ACUMULADO · ÚLTIMOS 7 DÍAS",
                valor = formatear(d.num("total")), unidad = "L",
                apoyo = "${fechaCorta(d.txt("desde"))} – ${fechaCorta(d.txt("hasta"))} · " +
                    (codigo?.let { "solo $it" } ?: "todos los vehículos") +
                    " · ${soles(d.num("total") * PRECIO_LECHE)}",
                destacada = true,
            ) {
                Spacer(Modifier.height(14.dp))
                Barras(dias, maxOf(dias.maxOfOrNull { it.second } ?: 0.0, 1.0))
            }

            Seccion("Por proveedor · ${provs.size}")
            if (provs.isEmpty()) AvisoCampo("No hay entregas en estos días.")
            provs.forEach { p ->
                val rechazos = p.num("rechazos").toInt()
                FilaLista(
                    titulo = p.txt("nombre"),
                    subtitulo = "${p.txt("comunidad")} · ${p.num("entregas").toInt()} entregas" +
                        if (rechazos > 0) " · $rechazos rechazada${if (rechazos > 1) "s" else ""}" else "",
                ) {
                    Text("${formatear(p.num("litros"))} L", style = Texto.Fila, color = Altiplano.Texto)
                }
            }
            TextButton(onClick = recargar) { Text("Actualizar", color = Altiplano.TextoTerciario) }
        }
    }
}
