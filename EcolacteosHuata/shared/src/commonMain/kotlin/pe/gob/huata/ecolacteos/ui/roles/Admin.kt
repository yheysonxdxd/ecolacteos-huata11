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
import pe.gob.huata.ecolacteos.ui.acopiador.Demo

data class Solicitud(val proveedor: String, val de: String, val a: String, val motivo: String, val estado: Estado)

class EstadoAdmin {
    var periodo by mutableStateOf(1)
    val solicitudes = mutableStateListOf(
        Solicitud("Elena Choque Tito", "Buena Vista", "Huatta", "Rotación de pastos: llevo las vacas a Huatta.", Estado.PENDIENTE),
        Solicitud("Gregorio Yana Ccama", "Collana Primero", "Lluco", "Quiero entregar con mi hermano.", Estado.PENDIENTE),
    )
}

@Composable
fun ContenidoAdmin(ruta: String, e: EstadoAdmin) {
    when (ruta) {
        "admin/negocio" -> NegocioDemo()
        "admin/totales" -> {
            val litros = listOf(1214.0, 8460.0, 34120.0)[e.periodo]
            PantallaScroll {
                Segmentos(listOf("Día", "Semana", "Mes"), e.periodo) { e.periodo = it }
                TarjetaDato("LITROS ACOPIADOS", formatear(litros, 0), "L", "${soles(litros * .94 * PRECIO_LECHE)} a pagar a proveedores", destacada = true)
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    Tarjeta(Modifier.weight(1f)) { MiniCifra("Aceptados", "94 %", Altiplano.Exito) }
                    Tarjeta(Modifier.weight(1f)) { MiniCifra("Rechazados", "4 %", Altiplano.Alerta) }
                    Tarjeta(Modifier.weight(1f)) { MiniCifra("Adulterada", "2 %", Altiplano.Alerta) }
                }
                Seccion("Por vehículo")
                listOf(Triple("C-01 · Vidal Ticona", "Huatta, Yanamocco, Moro", 2840.0), Triple("C-02 · Nélida Pari", "Buena Vista, Canchi", 2410.0),
                    Triple("C-03 · Aurelio Callata", "Collana, Lluco", 1960.0), Triple("M-01 · Silvia Mamani", "Circuito cercano", 1250.0)).forEach { (n, z, l) ->
                    Tarjeta {
                        Row { Text(n, style = Texto.Fila, color = Altiplano.Texto, modifier = Modifier.weight(1f)); Text("${formatear(l, 0)} L", style = Texto.Fila, color = Altiplano.Texto) }
                        Spacer(Modifier.height(8.dp)); BarraNivel((l / 2840).toFloat()); Spacer(Modifier.height(5.dp))
                        Text(z, style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                    }
                }
            }
        }
        "admin/padron" -> PantallaScroll {
            Demo.proveedores.forEach { p ->
                FilaLista(p.nombre, "DNI ${p.dni} · ${Demo.comunidad(p.comunidadId).nombre} · ${Demo.vehiculo(p.vehiculoId).codigo}" +
                    (p.promedioLitros?.let { " · prom. ${formatear(it)} L" } ?: " · sin promedio")) { ChipEstado(Estado.ACEPTADO) }
            }
            BotonPrimario("Registrar proveedor o trabajador") {}
        }
        "admin/calidad" -> PantallaScroll {
            listOf(listOf("C-01 · Vidal Ticona", "3,6", "1,031", "0", "3 %"), listOf("C-02 · Nélida Pari", "3,5", "1,030", "1", "4 %"),
                listOf("C-03 · Aurelio Callata", "3,3", "1,029", "3", "11 %"), listOf("M-01 · Silvia Mamani", "3,7", "1,032", "0", "2 %")).forEach { r ->
                val malo = r[4].removeSuffix(" %").toInt() > 8
                Tarjeta(borde = if (malo) Altiplano.AlertaBorde else Altiplano.Borde) {
                    Row { Text(r[0], style = Texto.Fila, color = Altiplano.Texto, modifier = Modifier.weight(1f)); ChipEstado(if (malo) Estado.RECHAZADO else Estado.ACEPTADO) }
                    Spacer(Modifier.height(10.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(18.dp)) {
                        MiniCifra("Grasa", r[1] + " %"); MiniCifra("Densidad", r[2]); MiniCifra("Adult.", r[3]); MiniCifra("Rechazo", r[4], if (malo) Altiplano.Alerta else Altiplano.Texto)
                    }
                }
            }
        }
        "admin/solicitudes" -> PantallaScroll {
            e.solicitudes.forEachIndexed { i, s ->
                Tarjeta {
                    Row { Text(s.proveedor, style = Texto.Fila, color = Altiplano.Texto, modifier = Modifier.weight(1f)); ChipEstado(s.estado) }
                    Spacer(Modifier.height(6.dp))
                    Text("${s.de}  →  ${s.a}", style = Texto.Cuerpo, color = Altiplano.TextoSecundario)
                    Text("“${s.motivo}”", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                    if (s.estado == Estado.PENDIENTE) {
                        Spacer(Modifier.height(12.dp))
                        BotonesDecision("Rechazar", "Aprobar", { e.solicitudes[i] = s.copy(estado = Estado.RECHAZADO) }, { e.solicitudes[i] = s.copy(estado = Estado.ACEPTADO) })
                    } else if (s.estado == Estado.ACEPTADO) {
                        Spacer(Modifier.height(8.dp)); Text("Vigente en 3 días · se avisa al proveedor por la app", style = Texto.Apoyo, color = Altiplano.Exito)
                    }
                }
            }
        }
        "admin/conciliacion" -> PantallaScroll {
            AvisoCampo("Fase 2: requiere caudalímetro en la tina de recepción. Por ahora se compara con el pesaje manual de planta.")
            listOf(Triple("C-01", 312.5, 309.0), Triple("C-02", 268.0, 267.0), Triple("C-03", 201.0, 188.5), Triple("M-01", 96.0, 95.5)).forEach { (v, campo, planta) ->
                val dif = (planta - campo) / campo * 100
                Tarjeta(borde = if (dif < -3) Altiplano.AlertaBorde else Altiplano.Borde) {
                    Text(v, style = Texto.Fila, color = Altiplano.Texto)
                    Spacer(Modifier.height(8.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(20.dp)) {
                        MiniCifra("Campo", "${formatear(campo)} L"); MiniCifra("Planta", "${formatear(planta)} L")
                        MiniCifra("Diferencia", formatear(dif) + " %", if (dif < -3) Altiplano.Alerta else Altiplano.Exito)
                    }
                }
            }
        }
        else -> PantallaScroll {
            TarjetaDato("COSTO POR LITRO PROCESADO", "S/ 2,34", destacada = true) {
                Spacer(Modifier.height(10.dp))
                FilaValor("Leche", "S/ 1,80"); FilaValor("Insumos", "S/ 0,31"); FilaValor("Mano de obra", "S/ 0,18"); FilaValor("Energía y envases", "S/ 0,05")
            }
            Tarjeta {
                Seccion("Rendimiento de queso")
                FilaValor("Esperado por 100 L", "12,5 kg"); FilaValor("Real (promedio mes)", "11,9 kg", Altiplano.Aviso)
            }
            Tarjeta {
                Seccion("Precios de venta · queso")
                CANALES.forEach { (c, p) -> FilaValor(c, soles(p)) }
            }
        }
    }
}
