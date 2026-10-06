package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import kotlinx.serialization.json.*
import pe.gob.huata.ecolacteos.datos.Sesion
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

/** Estado de Compras con datos reales. */
object ComprasReal {
    var productoSel by mutableStateOf(0)
    var clienteSel by mutableStateOf(0)
    var cantidadVenta by mutableStateOf(10.0)
    var mensaje by mutableStateOf<String?>(null)
    var version by mutableStateOf(0)
    var pestanaVentas by mutableStateOf(0)  // Nueva venta · Últimas ventas
    var pestanaAlmacen by mutableStateOf(0) // Stock y avisos · Pérdidas
}

private val NOMBRE_CANAL = mapOf("MAYORISTA" to "Mayorista", "DIRECTO" to "Vecino de Huata", "PLANTA" to "Proveedor de leche")

/**
 * Compras con la base, como en la web de oficina: Fórmulas · Ventas (nueva y
 * últimas con su nota) · Almacén (stock y pérdidas) · Kardex · Compras de insumos.
 * [irA] cambia de opción del menú; desde Almacén, tocar algo abre su kardex (opción 3).
 */
@Composable
fun ContenidoComprasReal(ruta: String, irA: (Int) -> Unit) {
    val c = ComprasReal
    when (ruta) {
        "compras/formulas" -> FormulasReal(puedeAceptar = false)
        "compras/ventas" -> ConPestanas(listOf("Nueva venta", "Últimas ventas"), c.pestanaVentas, { c.pestanaVentas = it }) {
            if (c.pestanaVentas == 0) VentaReal { c.pestanaVentas = 1 } else MovimientosReal()
        }
        "compras/almacen" -> ConPestanas(listOf("Stock y avisos", "Pérdidas"), c.pestanaAlmacen, { c.pestanaAlmacen = it }) {
            if (c.pestanaAlmacen == 0) InventarioReal { irA(3) } else PerdidasReal("compras")
        }
        "compras/kardex" -> KardexCompleto()
        "compras/ventas-nueva" -> VentaReal { irA(0) }
        "compras/inventario" -> InventarioReal { irA(0) }
        "compras/orden" -> ComprasInsumosReal()
        else -> MovimientosReal()
    }
}

/** Stock de insumos y productos; tocar uno abre su kardex ([alKardex]). */
@Composable
private fun InventarioReal(alKardex: () -> Unit) {
    CargaServidor("/api/v1/compras/inventario", clave = ComprasReal.version + Perdidas.version * 1000) { d, _ ->
        PantallaScroll {
            // "falta azúcar para el yogurt", "cuajo bajo el mínimo"…
            d.lista("avisos").forEach { a ->
                AvisoCampo(a.txt("texto") + " Comprar al menos ${formatear(a.num("comprar"), 2)} ${a.txt("unidad")}.", esAlerta = a.txt("nivel") == "FALTA")
            }
            Seccion("Insumos · toca uno para ver su kardex")
            d.lista("insumos").forEach { i ->
                val bajo = i.num("stock") < i.num("minimo")
                Tarjeta(borde = if (bajo) Altiplano.AlertaBorde else Altiplano.Borde, onClick = {
                    KardexElegido.tipo = "insumo"; KardexElegido.id = i.num("id").toLong(); alKardex()
                }) {
                    Row {
                        Text(i.txt("nombre"), style = Texto.Fila, color = Altiplano.Texto, modifier = Modifier.weight(1f))
                        Text("${formatear(i.num("stock"), 2)} ${i.txt("unidad")}", style = Texto.Fila, color = if (bajo) Altiplano.Alerta else Altiplano.Texto)
                    }
                    Spacer(Modifier.height(9.dp))
                    BarraNivel((i.num("stock") / (i.num("minimo") * 2).coerceAtLeast(0.001)).toFloat(), if (bajo) Altiplano.Alerta else Altiplano.Exito)
                    Spacer(Modifier.height(6.dp))
                    Text(
                        "mínimo ${formatear(i.num("minimo"), 2)} ${i.txt("unidad")} · ${soles(i.num("costo"))} c/u" + if (bajo) " · reponer" else "",
                        style = Texto.Apoyo, color = if (bajo) Altiplano.Alerta else Altiplano.TextoTerciario,
                    )
                }
            }
            Seccion("Producto terminado · toca uno para ver su kardex")
            d.lista("productos").forEach { p ->
                FilaLista(p.txt("nombre"), p.txt("unidad"), onClick = {
                    KardexElegido.tipo = "producto"; KardexElegido.id = p.num("id").toLong(); alKardex()
                }) {
                    Text(formatear(p.num("stock"), 0), style = Texto.Fila, color = Altiplano.Texto)
                }
            }
        }
    }
}

@Composable
private fun VentaReal(irA: (Int) -> Unit) {
    val scope = rememberCoroutineScope()
    var enviando by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    CargaServidor("/api/v1/compras/ventas-datos", clave = ComprasReal.version) { d, _ ->
        val prods = d.lista("productos")
        val clientes = d.lista("clientes")
        val p = prods.getOrNull(ComprasReal.productoSel) ?: return@CargaServidor
        val c = clientes.getOrNull(ComprasReal.clienteSel)
        val precio = c?.let { p.obj("precios")?.num(it.txt("tipo")) } ?: 0.0
        val cant = ComprasReal.cantidadVenta
        val sinStock = cant > p.num("stock")

        PantallaScroll {
            Segmentos(prods.map { it.txt("nombre") }, ComprasReal.productoSel) { ComprasReal.productoSel = it }
            Text("En stock: ${formatear(p.num("stock"), 0)} ${p.txt("unidad")}", style = Texto.Cuerpo, color = Altiplano.TextoSecundario)
            Seccion("Cliente")
            clientes.forEachIndexed { k, x ->
                Opcion(x.txt("nombre"), NOMBRE_CANAL[x.txt("tipo")], ComprasReal.clienteSel == k,
                    derecha = p.obj("precios")?.num(x.txt("tipo"))?.let { soles(it) }) { ComprasReal.clienteSel = k }
            }
            TarjetaDato("TOTAL", soles(cant * precio), apoyo = "${formatear(cant, 0)} × ${soles(precio)} · ${p.txt("unidad")}", destacada = true) {
                Spacer(Modifier.height(12.dp))
                StepperGrande(cant, { ComprasReal.cantidadVenta = it ?: 1.0 }, paso = 1.0, minimo = 1.0, decimales = 0, unidad = p.txt("unidad"), fueraDeRango = sinStock, compacto = true)
            }
            if (sinStock) AvisoCampo("No hay suficiente stock para esa cantidad.", esAlerta = true)
            CampoCorreoVenta(c)
            error?.let { AvisoCampo("No se pudo registrar: $it", esAlerta = true) }
            BotonPrimario(if (enviando) "Registrando…" else "Registrar venta", c != null && !sinStock && !enviando) {
                val cli = c ?: return@BotonPrimario
                scope.launch {
                    enviando = true; error = null
                    try {
                        val r = Sesion.enviar("/api/v1/compras/ventas", buildJsonObject {
                            put("cliente_id", cli.num("id").toLong())
                            put("producto_id", p.num("id").toLong())
                            put("cantidad", cant)
                            correoParaVenta()?.let { put("correo", it) } // opcional: mandar la nota
                        })
                        ComprasReal.mensaje = "✓ Venta registrada: ${soles(r.num("total"))} a ${cli.txt("nombre")}" + textoCorreo(r)
                        NotaVenta.ultima = UltimaNota(r.num("id").toLong(), r.txt("numero"), r.num("total"), cli.txt("nombre"), r.txt("correo"))
                        ComprasReal.version++
                        irA(3)
                    } catch (t: Throwable) { error = t.message }
                    enviando = false
                }
            }
        }
    }
}

@Composable
private fun MovimientosReal() {
    val abrirNota = rememberAbrirNota()
    CargaServidor("/api/v1/compras/movimientos", clave = ComprasReal.version) { d, _ ->
        PantallaScroll {
            ComprasReal.mensaje?.let { AvisoCampo(it) }
            TarjetaUltimaNota()
            d.lista("movimientos").forEach { m ->
                // las ventas se tocan para ver/imprimir su nota
                val venta = m.txt("venta_id").toLongOrNull()
                FilaLista(m.txt("que"), "${m.txt("ref")} · ${fechaCorta(m.txt("fecha"))}" + (venta?.let { " · ${m.txt("numero")} (tocar para ver)" } ?: ""),
                    onClick = venta?.let { id -> { abrirNota(id) } }) {
                    Text(m.txt("delta"), style = Texto.Fila, color = if (m.txt("entrada") == "true") Altiplano.Exito else Altiplano.TextoSecundario)
                }
            }
        }
    }
}
