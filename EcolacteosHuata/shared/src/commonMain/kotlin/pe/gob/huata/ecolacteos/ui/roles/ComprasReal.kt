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
    var insumoSel by mutableStateOf(0)
    var cantidadOc by mutableStateOf(1.0)
    var proveedorSel by mutableStateOf(0)
    var productoSel by mutableStateOf(0)
    var clienteSel by mutableStateOf(0)
    var cantidadVenta by mutableStateOf(10.0)
    var mensaje by mutableStateOf<String?>(null)
    var version by mutableStateOf(0)
}

private val NOMBRE_CANAL = mapOf("MAYORISTA" to "Mayorista", "DIRECTO" to "Mercados / directo", "PLANTA" to "Público en planta")

/** Compras con la base: inventario, órdenes de compra, ventas y movimientos. */
@Composable
fun ContenidoComprasReal(ruta: String, irA: (Int) -> Unit) {
    when (ruta) {
        "compras/inventario" -> InventarioReal()
        "compras/orden" -> OrdenReal(irA)
        "compras/ventas" -> VentaReal(irA)
        else -> MovimientosReal()
    }
}

@Composable
private fun InventarioReal() {
    CargaServidor("/api/v1/compras/inventario", clave = ComprasReal.version) { d, _ ->
        PantallaScroll {
            val bajos = d.lista("insumos").count { it.num("stock") < it.num("minimo") }
            if (bajos > 0) AvisoCampo("$bajos insumos bajo el mínimo. Emite una orden de compra.", esAlerta = true)
            Seccion("Insumos")
            d.lista("insumos").forEach { i ->
                val bajo = i.num("stock") < i.num("minimo")
                Tarjeta(borde = if (bajo) Altiplano.AlertaBorde else Altiplano.Borde) {
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
            Seccion("Producto terminado")
            d.lista("productos").forEach { p ->
                FilaLista(p.txt("nombre"), p.txt("unidad")) {
                    Text(formatear(p.num("stock"), 0), style = Texto.Fila, color = Altiplano.Texto)
                }
            }
        }
    }
}

@Composable
private fun OrdenReal(irA: (Int) -> Unit) {
    val scope = rememberCoroutineScope()
    var enviando by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    CargaServidor("/api/v1/compras/inventario", clave = ComprasReal.version) { inv, _ ->
        CargaServidor("/api/v1/compras/ordenes", clave = ComprasReal.version) { ords, _ ->
            val insumos = inv.lista("insumos")
            val proveedores = (ords["proveedores"] as? JsonArray)?.map { it.jsonPrimitive.content } ?: emptyList()
            val i = insumos.getOrNull(ComprasReal.insumoSel) ?: return@CargaServidor
            val proveedor = proveedores.getOrNull(ComprasReal.proveedorSel) ?: "Sin proveedor"

            fun recibir(id: String) {
                scope.launch {
                    try {
                        val r = Sesion.enviar("/api/v1/compras/ordenes/$id/recibir", JsonObject(emptyMap()))
                        ComprasReal.mensaje = "✓ ${r.txt("codigo")} recibida: el stock ya subió"
                        ComprasReal.version++
                    } catch (t: Throwable) { error = t.message }
                }
            }

            PantallaScroll {
                ComprasReal.mensaje?.let { AvisoCampo(it) }
                val pendientes = ords.lista("ordenes").filter { it.txt("estado") == "EMITIDA" }
                if (pendientes.isNotEmpty()) {
                    Seccion("Órdenes por recibir")
                    pendientes.forEach { o ->
                        Tarjeta(borde = Altiplano.VerdeBorde) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.weight(1f)) {
                                    Text("${o.txt("codigo")} · ${o.txt("insumo")}", style = Texto.Fila, color = Altiplano.Texto)
                                    Text("${formatear(o.num("cantidad"), 2)} ${o.txt("unidad")} · ${o.txt("proveedor_comercial")} · ${soles(o.num("costo_estimado"))}", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                                }
                                TextButton(onClick = { recibir(o.txt("id")) }) { Text("Recibir", color = Altiplano.Exito) }
                            }
                        }
                    }
                }
                Seccion("Nueva orden · insumo")
                insumos.forEachIndexed { k, x ->
                    val bajo = x.num("stock") < x.num("minimo")
                    Opcion(x.txt("nombre"), "stock ${formatear(x.num("stock"), 2)} ${x.txt("unidad")}" + if (bajo) " · bajo el mínimo" else "", ComprasReal.insumoSel == k) { ComprasReal.insumoSel = k }
                }
                Seccion("Proveedor")
                proveedores.forEachIndexed { k, p -> Opcion(p, seleccionado = ComprasReal.proveedorSel == k) { ComprasReal.proveedorSel = k } }
                Tarjeta {
                    StepperGrande(ComprasReal.cantidadOc, { ComprasReal.cantidadOc = it ?: 1.0 }, paso = 1.0, minimo = 1.0, decimales = 0, unidad = i.txt("unidad"))
                    HorizontalDivider(Modifier.padding(vertical = 12.dp), color = Altiplano.BordeSuave)
                    FilaValor("Proveedor", proveedor)
                    FilaValor("Costo estimado", soles(ComprasReal.cantidadOc * i.num("costo")))
                }
                error?.let { AvisoCampo("No se pudo guardar: $it", esAlerta = true) }
                BotonPrimario(if (enviando) "Emitiendo…" else "Emitir orden de compra", !enviando && proveedores.isNotEmpty()) {
                    scope.launch {
                        enviando = true; error = null
                        try {
                            val r = Sesion.enviar("/api/v1/compras/ordenes", buildJsonObject {
                                put("insumo_id", i.num("id").toLong())
                                put("cantidad", ComprasReal.cantidadOc)
                                put("proveedor_comercial", proveedor)
                            })
                            ComprasReal.mensaje = "✓ ${r.txt("codigo")} emitida por ${soles(r.num("costo_estimado"))}. Márcala como recibida cuando llegue."
                            ComprasReal.version++
                        } catch (t: Throwable) { error = t.message }
                        enviando = false
                    }
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
                        })
                        ComprasReal.mensaje = "✓ Venta registrada: ${soles(r.num("total"))} a ${cli.txt("nombre")}"
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
    CargaServidor("/api/v1/compras/movimientos", clave = ComprasReal.version) { d, _ ->
        PantallaScroll {
            ComprasReal.mensaje?.let { AvisoCampo(it) }
            d.lista("movimientos").forEach { m ->
                FilaLista(m.txt("que"), "${m.txt("ref")} · ${fechaCorta(m.txt("fecha"))}") {
                    Text(m.txt("delta"), style = Texto.Fila, color = if (m.txt("entrada") == "true") Altiplano.Exito else Altiplano.TextoSecundario)
                }
            }
        }
    }
}
