package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.foundation.layout.*
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import kotlinx.serialization.json.JsonObject
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

/**
 * Pestaña "Ventas" del admin: lo que pasa después del acopio.
 * GET /admin/negocio?periodo=… → ventas, producción e inventario.
 * Usa el mismo Día / Semana / Mes que Totales.
 */
@Composable
fun NegocioReal(e: EstadoAdmin) {
    val periodo = listOf("dia", "semana", "mes")[e.periodo]
    CargaServidor("/api/v1/admin/negocio?periodo=$periodo") { d, _ ->
        val v = d.obj("ventas") ?: JsonObject(emptyMap())
        val prod = d.obj("produccion") ?: JsonObject(emptyMap())
        val inv = d.obj("inventario") ?: JsonObject(emptyMap())
        val rango = if (d.txt("desde") == d.txt("hasta")) "hoy, ${fechaCorta(d.txt("desde"))}"
                    else "${fechaCorta(d.txt("desde"))} – ${fechaCorta(d.txt("hasta"))}"

        PantallaScroll {
            Segmentos(listOf("Día", "Semana", "Mes"), e.periodo) { e.periodo = it }

            // ------------------------------------------------ ventas
            val nVentas = v.num("cantidad").toInt()
            TarjetaDato(
                "VENTAS", soles(v.num("total")),
                apoyo = "$nVentas ${if (nVentas == 1) "venta" else "ventas"} · $rango",
                destacada = true,
            ) {
                val barras = v.lista("barras").map { it.txt("etiqueta") to it.num("total") }
                if (barras.isNotEmpty()) {
                    Spacer(Modifier.height(14.dp))
                    Barras(barras, maxOf(barras.maxOf { it.second }, 1.0))
                }
            }

            v.lista("por_producto").forEach { p ->
                FilaLista(
                    p.txt("nombre"),
                    "${formatear(p.num("cantidad"), 0)} ${p.txt("unidad")}",
                ) { Text(soles(p.num("total")), style = Texto.Fila, color = Altiplano.Texto) }
            }
            if (nVentas == 0) AvisoCampo("No hubo ventas en este periodo.")

            val tipos = v.obj("por_tipo") ?: JsonObject(emptyMap())
            if (nVentas > 0) {
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    Tarjeta(Modifier.weight(1f)) { MiniCifra("Mayorista", soles(tipos.num("MAYORISTA"))) }
                    Tarjeta(Modifier.weight(1f)) { MiniCifra("Vecinos", soles(tipos.num("DIRECTO"))) }
                    Tarjeta(Modifier.weight(1f)) { MiniCifra("Proveedores", soles(tipos.num("PLANTA"))) }
                }
            }

            val clientes = v.lista("clientes")
            if (clientes.isNotEmpty()) {
                Seccion("Mejores clientes")
                val max = maxOf(clientes.maxOf { it.num("total") }, 1.0)
                clientes.forEach { c ->
                    Tarjeta {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(c.txt("nombre"), style = Texto.Fila, color = Altiplano.Texto, modifier = Modifier.weight(1f))
                            Text(soles(c.num("total")), style = Texto.Fila, color = Altiplano.Texto)
                        }
                        Spacer(Modifier.height(8.dp)); BarraNivel((c.num("total") / max).toFloat()); Spacer(Modifier.height(5.dp))
                        Text("${tipoCliente(c.txt("tipo"))} · ${c.num("ventas").toInt()} compras", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                    }
                }
            }

            val ultimas = v.lista("ultimas")
            if (ultimas.isNotEmpty()) {
                Seccion("Últimas ventas")
                ultimas.forEach { u ->
                    FilaLista(
                        "${u.txt("cliente")} · ${formatear(u.num("cantidad"), 0)} ${u.txt("producto").lowercase()}",
                        "${fechaCorta(u.txt("vendida_en"))} ${u.txt("vendida_en").drop(11).take(5)}" +
                            (u.txt("vendedor").takeIf { it.isNotEmpty() }?.let { " · $it" } ?: ""),
                    ) { Text(soles(u.num("total")), style = Texto.Fila, color = Altiplano.Texto) }
                }
            }

            // ------------------------------------------------ producción
            Seccion("Producción")
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Tarjeta(Modifier.weight(1f)) { MiniCifra("Lotes", "${prod.num("lotes").toInt()}") }
                Tarjeta(Modifier.weight(1f)) { MiniCifra("Leche usada", "${formatear(prod.num("litros"), 0)} L") }
                Tarjeta(Modifier.weight(1f)) {
                    MiniCifra("Fuera de rango", "${prod.num("fuera_referencia").toInt()}",
                        if (prod.num("fuera_referencia") > 0) Altiplano.Aviso else Altiplano.Texto)
                }
            }
            prod.lista("por_producto").forEach { p ->
                FilaLista(
                    p.txt("nombre"),
                    "${p.num("lotes").toInt()} lotes · ${formatear(p.num("litros"), 0)} L de leche",
                ) { Text("${formatear(p.num("obtenido"), 0)} ${p.txt("unidad").substringBefore(' ')}", style = Texto.Fila, color = Altiplano.Texto) }
            }
            val enCurso = prod.num("en_curso").toInt()
            if (enCurso > 0) AvisoCampo("$enCurso ${if (enCurso == 1) "lote sigue" else "lotes siguen"} en proceso.")

            // ------------------------------------------------ inventario (siempre al día de hoy)
            Seccion("Inventario de hoy")
            inv.lista("productos").forEach { p ->
                val porVencer = p.num("lotes_por_vencer").toInt()
                FilaLista(
                    p.txt("nombre"),
                    if (porVencer > 0) "$porVencer ${if (porVencer == 1) "lote vence" else "lotes vencen"} esta semana" else "en stock",
                    borde = if (porVencer > 0) Altiplano.Aviso else Altiplano.Borde,
                ) { Text("${formatear(p.num("stock"), 0)} ${p.txt("unidad").substringBefore(' ')}", style = Texto.Fila, color = Altiplano.Texto) }
            }
            inv.lista("insumos_bajo_minimo").forEach { i ->
                AvisoCampo(
                    "${i.txt("nombre")} bajo el mínimo: quedan ${formatear(i.num("stock"), 2)} ${i.txt("unidad")} " +
                        "(mínimo ${formatear(i.num("stock_minimo"), 2)}).",
                    esAlerta = true,
                )
            }
            val ordenes = inv.num("ordenes_por_recibir").toInt()
            if (ordenes > 0) {
                FilaLista("Órdenes de compra por recibir", "$ordenes ${if (ordenes == 1) "orden emitida" else "órdenes emitidas"}") {
                    Text(soles(inv.num("ordenes_monto")), style = Texto.Fila, color = Altiplano.Texto)
                }
            }
        }
    }
}

private fun tipoCliente(t: String) = when (t) {
    "MAYORISTA" -> "Mayorista"
    "DIRECTO" -> "Vecino de Huata"
    "PLANTA" -> "Proveedor de leche"
    else -> t
}

/** En modo demo no hay ventas reales que mostrar. */
@Composable
fun NegocioDemo() {
    PantallaScroll {
        AvisoCampo("Ventas, producción e inventario se ven con datos reales: inicia sesión con tu DNI.")
    }
}
