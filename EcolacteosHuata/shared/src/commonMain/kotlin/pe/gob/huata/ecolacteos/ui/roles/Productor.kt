package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.animation.animateColorAsState
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import pe.gob.huata.ecolacteos.datos.*
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

class EstadoProductor {
    var confirmado by mutableStateOf(false)
    var destino by mutableStateOf(1)
    var solicitud by mutableStateOf<String?>(null)
}

private val DIAS = listOf("Lu" to 18.0, "Ma" to 19.0, "Mi" to 17.5, "Ju" to 20.0, "Vi" to 19.5, "Sá" to 0.0, "Do" to 18.5)

@Composable
fun ContenidoProductor(ruta: String, e: EstadoProductor) {
    val semana = DIAS.sumOf { it.second }
    when (ruta) {
        "productor/hoy" -> PantallaScroll {
            TarjetaDato("TU ENTREGA DE HOY", "18,5", "L", "Registrada por Vidal Ticona · C-01 · 6:05", destacada = true) {
                Spacer(Modifier.height(12.dp)); ChipEstado(Estado.ACEPTADO)
            }
            if (e.confirmado) AvisoCampo("Confirmaste que la cantidad es correcta.")
            else BotonPrimario("Confirmar que es correcta") { e.confirmado = true }
            Text("Si no coincide con lo que entregaste, avisa hoy al acopiador. Así se evitan reclamos el viernes.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            TarjetaDato("PAGO DE ESTA SEMANA", soles(semana * PRECIO_LECHE), apoyo = "${formatear(semana)} L aceptados × S/ 1,80 · se paga el viernes", destacada = true)
        }
        "productor/semana" -> PantallaScroll {
            TarjetaDato("TUS LITROS ESTA SEMANA", formatear(semana), "L") { Spacer(Modifier.height(14.dp)); Barras(DIAS, 22.0) }
            listOf("Domingo" to "18,5", "Viernes" to "19,5", "Jueves" to "20", "Miércoles" to "17,5", "Martes" to "19").forEachIndexed { i, (d, l) ->
                FilaLista(d, "$l L") { ChipEstado(if (i == 4) Estado.RECHAZADO else Estado.ACEPTADO) }
            }
        }
        "productor/pagos" -> PantallaScroll {
            listOf(Triple("Semana en curso", semana, false), Triple("11 – 17 set", 128.0, true), Triple("4 – 10 set", 119.5, true), Triple("28 ago – 3 set", 124.0, true)).forEach { (s, l, pagado) ->
                Tarjeta(borde = if (pagado) Altiplano.Borde else Altiplano.VerdeBorde) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(s, style = Texto.Fila, color = Altiplano.Texto, modifier = Modifier.weight(1f))
                        ChipEstado(if (pagado) Estado.PAGADO else Estado.PENDIENTE)
                    }
                    Spacer(Modifier.height(8.dp))
                    Text(soles(l * PRECIO_LECHE), style = Texto.DatoMedio, color = Altiplano.Texto)
                    Text("${formatear(l)} L aceptados · S/ 1,80 por litro", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                }
            }
        }
        else -> PantallaScroll {
            Tarjeta {
                Seccion("Tu asignación actual")
                Spacer(Modifier.height(6.dp))
                Text("Huatta · furgón C-01", style = Texto.Fila, color = Altiplano.Texto)
                Text("Acopiador: Vidal Ticona Quispe · desde el 2 de setiembre", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            }
            Seccion("Pedir cambio de zona")
            val destinos = listOf("Yanamocco · C-01" to "Vidal Ticona", "Buena Vista · C-02" to "Nélida Pari", "Collana Primero · M-01" to "Silvia Mamani")
            destinos.forEachIndexed { i, (z, a) -> Opcion(z, "Acopiador: $a", e.destino == i) { e.destino = i } }
            AvisoCampo("Avisa con 2 o 3 días de anticipación. El administrador aprueba y el cambio rige desde la fecha que indique.")
            if (e.solicitud == null) BotonPrimario("Enviar solicitud") { e.solicitud = destinos[e.destino].first }
            else FilaLista("Traslado a ${e.solicitud}", "Enviada hoy · espera aprobación") { ChipEstado(Estado.PENDIENTE) }
        }
    }
}
