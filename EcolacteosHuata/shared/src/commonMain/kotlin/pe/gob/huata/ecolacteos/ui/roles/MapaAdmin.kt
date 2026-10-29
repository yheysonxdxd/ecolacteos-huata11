package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.jsonPrimitive
import pe.gob.huata.ecolacteos.datos.Comunidad
import pe.gob.huata.ecolacteos.datos.EstadoPin
import pe.gob.huata.ecolacteos.datos.TipoVehiculo
import pe.gob.huata.ecolacteos.datos.Vehiculo
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

/**
 * Mapa de Huata del admin (GET /admin/mapa): el mismo croquis del acopiador con todas las
 * rutas, cada comunidad con su avance del día y, al tocarla, su gente con lo que entregó.
 * Un botón por carro resalta su ruta. Para mover comunidades está el mapa de la web.
 */
@Composable
internal fun MapaAdmin(fecha: String) {
    CargaServidor("/api/v1/admin/mapa?fecha=$fecha") { d, _ ->
        val datos = d.lista("comunidades")
        val comunidades = datos.map { c ->
            Comunidad(c.num("id").toLong(), c.txt("nombre"), c.num("croquis_x").toFloat(), c.num("croquis_y").toFloat(), c.txt("vias"))
        }
        val vehiculos = d.lista("vehiculos").map { v ->
            Vehiculo(
                id = v.num("id").toLong(), codigo = v.txt("codigo"),
                tipo = if (v.txt("tipo") == "MOTOCAR") TipoVehiculo.MOTOCAR else TipoVehiculo.CARRO,
                conductor = v.txt("acopiador").ifEmpty { null }, rutaCircular = v.txt("circular") == "true",
                ordenRuta = (v["ruta"] as? JsonArray)?.map { it.jsonPrimitive.content.toLong() } ?: emptyList(),
            )
        }
        val pines = datos.filter { it.num("proveedores") > 0 }.map { c ->
            val hechos = c.num("registrados").toInt(); val total = c.num("proveedores").toInt()
            PinComunidad(
                comunidades.first { it.id == c.num("id").toLong() },
                when { hechos >= total -> EstadoPin.REGISTRADO; hechos > 0 -> EstadoPin.SIN_ENVIAR; else -> EstadoPin.FALTA },
                hechos, total,
            )
        }
        var carro by remember { mutableStateOf<Long?>(null) }
        var elegida by remember { mutableStateOf<Long?>(null) }
        val ruta = vehiculos.firstOrNull { it.id == carro }?.ordenRuta.orEmpty()

        PantallaScroll {
            Surface(shape = RoundedCornerShape(18.dp), color = Altiplano.Superficie, border = BorderStroke(1.dp, Altiplano.Borde)) {
                Column {
                    Column(Modifier.padding(start = 16.dp, end = 12.dp, top = 14.dp, bottom = 10.dp)) {
                        Text("DISTRITO DE HUATA · ${fechaCorta(fecha)}", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
                        Text(
                            "${formatear(datos.sumOf { it.num("litros") }, 0)} L · ${pines.sumOf { it.hechos }} de ${pines.sumOf { it.total }} proveedores registrados",
                            style = Texto.Fila, color = Altiplano.Texto,
                        )
                    }
                    MapaRuta(
                        comunidades, vehiculos, pines,
                        resaltado = carro, acercar = carro != null, elegida = elegida,
                        onToque = { c -> elegida = if (elegida == c.id) null else c.id },
                    )
                    Row(Modifier.padding(horizontal = 16.dp, vertical = 12.dp), horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                        Punto("Todos", colorPin(EstadoPin.REGISTRADO))
                        Punto("Algunos", colorPin(EstadoPin.SIN_ENVIAR))
                        Punto("Nadie aún", colorPin(EstadoPin.FALTA))
                    }
                }
            }

            // un botón por carro: resalta y acerca su ruta
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                (listOf<Vehiculo?>(null) + vehiculos).forEach { v ->
                    val activo = carro == v?.id
                    val color = v?.color ?: Altiplano.TextoSecundario
                    Surface(
                        onClick = { carro = v?.id; elegida = null }, modifier = Modifier.weight(1f),
                        shape = RoundedCornerShape(50), color = if (activo) color.copy(alpha = 0.2f) else Altiplano.Fondo,
                        border = BorderStroke(1.dp, if (activo) color else Altiplano.Borde),
                    ) {
                        Text(v?.codigo ?: "Todos", style = Texto.Apoyo, color = if (activo) color else Altiplano.TextoSecundario,
                            modifier = Modifier.padding(vertical = 8.dp).wrapContentWidth(Alignment.CenterHorizontally))
                    }
                }
            }
            vehiculos.firstOrNull { it.id == carro }?.let { v ->
                Text(
                    "${v.codigo} · ${v.conductor ?: "sin acopiador"} · " +
                        ruta.mapNotNull { id -> comunidades.firstOrNull { it.id == id }?.nombre }.joinToString(" → "),
                    style = Texto.Apoyo, color = Altiplano.TextoSecundario,
                )
            }

            // la gente de la comunidad tocada, con lo que entregó ese día
            elegida?.let { id -> datos.firstOrNull { it.num("id").toLong() == id } }?.let { c ->
                val pin = pines.first { it.comunidad.id == c.num("id").toLong() }
                Tarjeta(borde = colorPin(pin.estado)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(c.txt("nombre"), style = Texto.Fila, color = Altiplano.Texto)
                            Text("${pin.hechos} de ${pin.total} registrados · ${formatear(c.num("litros"))} L", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                        }
                        TextButton(onClick = { elegida = null }) { Text("Cerrar", color = Altiplano.TextoSecundario) }
                    }
                    c.lista("gente").forEach { p ->
                        val e = p.obj("entrega")
                        Spacer(Modifier.height(8.dp))
                        Row(
                            Modifier.fillMaxWidth().background(Altiplano.Fondo, RoundedCornerShape(12.dp)).padding(horizontal = 12.dp, vertical = 9.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Column(Modifier.weight(1f)) {
                                Text(p.txt("nombre"), style = Texto.Cuerpo, color = Altiplano.Texto)
                                Text(p.txt("vehiculo").ifEmpty { "sin carro" }, style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                            }
                            Text(
                                when { e == null -> "Falta"; e.txt("ausente") == "true" -> "No entregó"; else -> "${formatear(e.num("litros"))} L" },
                                style = Texto.Fila,
                                color = when { e == null -> Altiplano.TextoTerciario; e.txt("ausente") == "true" -> ROSA; else -> Altiplano.Texto },
                            )
                        }
                    }
                }
            } ?: Text("Toca una comunidad para ver a su gente. Para mover una comunidad de lugar usa el mapa de la web.",
                style = Texto.Apoyo, color = Altiplano.TextoTerciario)
        }
    }
}

/** 2026-10-28 → 28/10 */
private fun fechaCorta(iso: String) = iso.take(10).split("-").let { if (it.size == 3) "${it[2]}/${it[1]}" else iso }

@Composable
private fun Punto(t: String, c: Color) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(9.dp).background(c, CircleShape))
        Spacer(Modifier.width(6.dp))
        Text(t, style = Texto.Apoyo, color = Altiplano.TextoSecundario)
    }
}
