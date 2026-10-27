package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.animation.AnimatedContent
import androidx.compose.foundation.layout.*
import androidx.compose.material3.Text
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import pe.gob.huata.ecolacteos.datos.Rol
import pe.gob.huata.ecolacteos.indiceDe
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

/**
 * Inicio del operario, compras y calidad: igual que el del admin (InicioAdmin), lo de hoy en cifras
 * grandes y botones de colores para lo que hace cada uno. [irA] va a una opción del menú de abajo.
 */
@Composable
fun InicioRol(rol: Rol, irA: (Int) -> Unit) {
    val ir = { ruta: String -> irA(indiceDe(rol, ruta)) }
    CargaServidor("/api/v1/inicio") { d, _ ->
        PantallaScroll {
            Aparece(0) {
                Column {
                    Text(saludo() + ", ${d.txt("nombre").substringBefore(' ')} 👋", style = Texto.TituloPantalla.copy(fontSize = 24.sp), color = Altiplano.Texto)
                    Text(
                        when (rol) {
                            Rol.OPERARIO -> "Esto es lo de hoy en la producción."
                            Rol.COMPRAS -> "Esto es lo de hoy en compras y ventas."
                            else -> "Esto es lo de hoy en calidad."
                        },
                        style = Texto.Cuerpo, color = Altiplano.TextoTerciario,
                    )
                }
            }
            when (rol) {
                Rol.OPERARIO -> {
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        Cifra(1, "🥛", "En el tanque", d.num("leche_tanque"), " L", "leche aceptada sin usar", CELESTE, Modifier.weight(1f)) { ir("operario/nueva") }
                        Cifra(2, "⏳", "Produciendo", d.num("lotes_en_proceso"), " lotes", "toca para cerrarlos", AMBAR, Modifier.weight(1f)) { ir("operario/lotes") }
                    }
                    avisoInsumos(d.num("insumos_bajos").toInt(), 3, "Avisa a compras") { ir("operario/recetas") }
                    Aparece(4) { Text("¿QUÉ QUIERES HACER?", style = Texto.Eyebrow, color = Altiplano.TextoTerciario, modifier = Modifier.padding(top = 6.dp)) }
                    Botonera(5, listOf(
                        Accion("🧀", "Producir", "¿Qué vas a hacer hoy?", LILA) { ir("operario/nueva") },
                        Accion("⏳", "Cerrar lotes", "Anota cuánto salió", AMBAR) { ir("operario/lotes") },
                        Accion("📖", "Recetas", "Insumos y pasos", CELESTE) { ir("operario/recetas") },
                        Accion("⚠️", "Pérdidas", "Leche cortada, queso vencido", ROSA) { ir("operario/perdidas") },
                    ))
                }
                Rol.COMPRAS -> {
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        Cifra(1, "🛒", "Vendido hoy", d.num("vendido_hoy"), "", "${d.num("notas_hoy").toInt()} notas de venta", VERDE, Modifier.weight(1f), dinero = true) {
                            ComprasReal.pestanaVentas = 1; ir("compras/ventas")
                        }
                        Cifra(2, "🚚", "Por llegar", d.num("por_llegar"), " compras", "toca cuando lleguen", ARENA, Modifier.weight(1f)) { ir("compras/orden") }
                    }
                    avisoInsumos(d.num("insumos_bajos").toInt(), 3, "Tocar para comprar") { ir("compras/orden") }
                    Aparece(4) { Text("¿QUÉ QUIERES HACER?", style = Texto.Eyebrow, color = Altiplano.TextoTerciario, modifier = Modifier.padding(top = 6.dp)) }
                    Botonera(5, listOf(
                        Accion("🏷️", "Vender", "Queso y yogurt a un cliente", VERDE) { ComprasReal.pestanaVentas = 0; ir("compras/ventas") },
                        Accion("🛒", "Comprar", "Insumos que faltan", ARENA) { ir("compras/orden") },
                        Accion("📦", "Almacén", "Lo que hay y lo que falta", CELESTE) { ComprasReal.pestanaAlmacen = 0; ir("compras/almacen") },
                        Accion("🧾", "Ventas y notas", "Lo vendido en el mes", LILA) { ComprasReal.pestanaVentas = 1; ir("compras/ventas") },
                    ))
                }
                else -> {
                    val porAnalizar = d.num("por_analizar")
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        Cifra(1, "🧪", "Por analizar", porAnalizar, "", "entregas de hoy y ayer", if (porAnalizar > 0) AMBAR else VERDE, Modifier.weight(1f)) { ir("calidad/cola") }
                        Cifra(2, "✅", "Analizadas hoy", d.num("analizados_hoy"), "", "${d.num("rechazados_hoy").toInt()} rechazadas", CELESTE, Modifier.weight(1f)) { ir("calidad/historial") }
                    }
                    val capacitar = d.num("por_capacitar").toInt()
                    if (capacitar > 0) Aparece(3) {
                        Aviso("👩‍🏫", "$capacitar ${if (capacitar == 1) "proveedor espera" else "proveedores esperan"} capacitación", "Se ven en Infractores", ROSA) { ir("calidad/infractores") }
                    }
                    Aparece(4) { Text("¿QUÉ QUIERES HACER?", style = Texto.Eyebrow, color = Altiplano.TextoTerciario, modifier = Modifier.padding(top = 6.dp)) }
                    Botonera(5, listOf(
                        Accion("🔬", "Analizar leche", "Toca a un proveedor de la lista", VERDE) { ir("calidad/cola") },
                        Accion("🚫", "Infractores", "Adulteración y rechazos", ROSA) { ir("calidad/infractores") },
                        Accion("📚", "Historial", "Por proveedor y acopiador", CELESTE) { ir("calidad/historial") },
                    ))
                }
            }
        }
    }
}

@Composable
private fun avisoInsumos(bajos: Int, orden: Int, accion: String, onClick: () -> Unit) {
    if (bajos > 0) Aparece(orden) {
        Aviso("⚠", "$bajos ${if (bajos == 1) "insumo está" else "insumos están"} bajo el mínimo", accion, AMBAR, onClick)
    }
}

/** Compras → Más: Fórmulas y Kardex (se revisan, no se usan todos los días) en tarjetas grandes. */
object ComprasMas {
    var abierta by mutableStateOf(-1) // -1 = el menú de tarjetas · 0 Fórmulas · 1 Kardex
}

@Composable
fun ComprasMasMenu() {
    val m = ComprasMas
    AnimatedContent(m.abierta, transitionSpec = { transicionMenu(targetState >= 0) }, label = "comprasMas") { cual ->
        if (cual < 0) PantallaScroll {
            Botonera(0, listOf(
                Accion("📖", "Fórmulas", "Cuánto hacer y qué insumos faltan", LILA) { m.abierta = 0 },
                Accion("📋", "Kardex", "Entradas y salidas de cada cosa", Color(0xFF9FB0C0)) { m.abierta = 1 },
            ), columnas = 1)
        } else DentroDe("Más", if (cual == 0) "Fórmulas" else "Kardex", { m.abierta = -1 }) {
            if (cual == 0) FormulasReal(puedeAceptar = false) else KardexCompleto()
        }
    }
}
