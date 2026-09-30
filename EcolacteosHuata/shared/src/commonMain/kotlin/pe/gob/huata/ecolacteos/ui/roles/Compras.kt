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

data class Insumo(val nombre: String, val unidad: String, val stock: Double, val minimo: Double, val costo: Double)
data class Movimiento(val que: String, val ref: String, val delta: String, val entrada: Boolean)

class EstadoCompras {
    val insumos = mutableStateListOf(
        Insumo("Cultivo láctico", "kg", 1.2, 0.5, 180.0), Insumo("Cuajo líquido", "L", 0.8, 0.5, 95.0),
        Insumo("Sal industrial", "kg", 40.0, 15.0, 1.2), Insumo("Cloruro de calcio", "kg", 0.3, 0.4, 34.5),
        Insumo("Azúcar blanca", "kg", 60.0, 25.0, 3.8), Insumo("Envase 1 L", "un", 380.0, 200.0, 0.45),
        Insumo("Film termoencogible", "un", 90.0, 120.0, 0.3), Insumo("Queso Paria (terminado)", "kg", 186.0, 40.0, 0.0),
    )
    var ocSel by mutableStateOf(3)
    var ocCant by mutableStateOf(5.0)
    var producto by mutableStateOf(0)
    var canal by mutableStateOf(1)
    var cantidad by mutableStateOf(10.0)
    val movs = mutableStateListOf(
        Movimiento("Leche aceptada", "L-2418 · inicio de lote", "−400 L", false),
        Movimiento("Envase 1 L", "OC-118 · Lácteos Titicaca", "+200 un", true),
        Movimiento("Queso Paria", "Venta · Mayorista · S/ 950,00", "−50 kg", false),
    )
}

val CANALES = listOf("Mayorista" to 19.0, "Mercados / directo" to 20.0, "Público en planta" to 21.0)

@Composable
fun ContenidoCompras(ruta: String, e: EstadoCompras, irA: (Int) -> Unit) {
    when (ruta) {
        "compras/inventario" -> PantallaScroll {
            e.insumos.forEach { i ->
                val bajo = i.stock < i.minimo
                Tarjeta(borde = if (bajo) Altiplano.AlertaBorde else Altiplano.Borde) {
                    Row { Text(i.nombre, style = Texto.Fila, color = Altiplano.Texto, modifier = Modifier.weight(1f))
                        Text("${formatear(i.stock, 2)} ${i.unidad}", style = Texto.Fila, color = if (bajo) Altiplano.Alerta else Altiplano.Texto) }
                    Spacer(Modifier.height(9.dp))
                    BarraNivel((i.stock / (i.minimo * 2)).toFloat(), if (bajo) Altiplano.Alerta else Altiplano.Exito)
                    Spacer(Modifier.height(6.dp))
                    Text("mínimo ${formatear(i.minimo, 2)} ${i.unidad}" + if (bajo) " · reponer" else "", style = Texto.Apoyo, color = if (bajo) Altiplano.Alerta else Altiplano.TextoTerciario)
                }
            }
        }
        "compras/orden" -> PantallaScroll {
            Seccion("Insumo")
            e.insumos.dropLast(1).forEachIndexed { k, i -> Opcion(i.nombre, "stock ${formatear(i.stock, 2)} ${i.unidad}", e.ocSel == k) { e.ocSel = k } }
            val i = e.insumos[e.ocSel]
            Tarjeta {
                StepperGrande(e.ocCant, { e.ocCant = it ?: 1.0 }, paso = 1.0, minimo = 1.0, decimales = 0, unidad = i.unidad)
                HorizontalDivider(Modifier.padding(vertical = 12.dp), color = Altiplano.BordeSuave)
                FilaValor("Proveedor", "Lácteos Titicaca SAC")
                FilaValor("Costo estimado", soles(e.ocCant * i.costo))
            }
            BotonPrimario("Emitir orden de compra") {
                e.insumos[e.ocSel] = i.copy(stock = i.stock + e.ocCant)
                e.movs.add(0, Movimiento(i.nombre, "OC-119 · Lácteos Titicaca", "+${formatear(e.ocCant, 0)} ${i.unidad}", true)); irA(3)
            }
        }
        "compras/ventas" -> {
            val prods = listOf("Queso Paria" to "kg", "Yogurt" to "baldes 4 L")
            val precio = CANALES[e.canal].second
            PantallaScroll {
                Segmentos(prods.map { it.first }, e.producto) { e.producto = it }
                Seccion("Tipo de cliente")
                CANALES.forEachIndexed { k, (c, p) -> Opcion(c, seleccionado = e.canal == k, derecha = soles(p)) { e.canal = k } }
                TarjetaDato("TOTAL", soles(e.cantidad * precio), apoyo = "${formatear(e.cantidad, 0)} ${prods[e.producto].second} × ${soles(precio)}", destacada = true) {
                    Spacer(Modifier.height(12.dp))
                    StepperGrande(e.cantidad, { e.cantidad = it ?: 1.0 }, paso = 1.0, minimo = 1.0, decimales = 0, unidad = prods[e.producto].second, compacto = true)
                }
                BotonPrimario("Registrar venta") {
                    e.movs.add(0, Movimiento(prods[e.producto].first, "Venta · ${CANALES[e.canal].first} · ${soles(e.cantidad * precio)}", "−${formatear(e.cantidad, 0)}", false)); irA(3)
                }
            }
        }
        else -> PantallaScroll {
            e.movs.forEach { m -> FilaLista(m.que, m.ref) { Text(m.delta, style = Texto.Fila, color = if (m.entrada) Altiplano.Exito else Altiplano.TextoSecundario) } }
        }
    }
}
