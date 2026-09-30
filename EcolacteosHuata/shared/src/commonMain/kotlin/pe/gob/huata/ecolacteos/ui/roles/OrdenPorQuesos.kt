package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.foundation.layout.*
import androidx.compose.material3.Text
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import kotlinx.serialization.json.JsonObject
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*
import kotlin.math.ceil
import kotlin.math.floor

/**
 * Nueva orden por cantidad de quesos: el operario dice cuántos quiere y la app
 * calcula los litros. Nunca deja pasarse de la leche disponible: si pide más,
 * avisa "No se puede" y baja al máximo que alcanza.
 *
 * Se calcula con el rendimiento MÍNIMO de la receta (quesos por 100 L), así
 * esa cantidad sale aunque el día rinda poco.
 */
object LimiteOrden {
    var aviso by mutableStateOf<String?>(null)
}

/** Quesos que salen seguro con [litros] (rendimiento mínimo). */
internal fun quesosCon(litros: Double, min100l: Double): Int =
    if (min100l <= 0) 0 else floor(litros * min100l / 100 + 1e-9).toInt()

/** Litros necesarios para [quesos], sin pasar de lo que hay en el tanque. */
internal fun litrosPara(quesos: Int, min100l: Double, tanque: Double): Double =
    if (min100l <= 0) 0.0 else minOf(ceil(quesos * 100 / min100l - 1e-9), tanque)

/** Litros pedidos, recortados a la leche disponible. Devuelve (litros, aviso). */
internal fun limitarLitros(pedido: Double, tanque: Double): Pair<Double, String?> {
    val valor = pedido.coerceAtLeast(minOf(50.0, tanque))
    return if (valor > tanque) tanque to "No se puede: solo hay ${formatear(tanque)} L de leche disponible. Se bajó a ${formatear(tanque)} L."
    else valor to null
}

/** Tarjeta "¿Cuántos quesos quieres hacer?" que va arriba de los litros en Nueva orden. */
@Composable
fun ElegirQuesos(receta: JsonObject, tanque: Double) {
    val min = receta.num("min_100l")
    val u = receta.txt("unidad").substringBefore(' ').ifEmpty { "unidades" } // "quesos", "baldes"
    val maximo = quesosCon(tanque, min)
    val actuales = quesosCon(OperarioReal.litros, min)

    Tarjeta {
        Text("¿Cuántos $u quieres hacer?", style = Texto.Fila, color = Altiplano.Texto)
        Text(
            if (maximo >= 1) "Con ${formatear(tanque)} L disponibles salen hasta $maximo $u"
            else "No alcanza la leche para hacer $u",
            style = Texto.Apoyo, color = Altiplano.TextoTerciario,
        )
        if (maximo >= 1) {
            Spacer(Modifier.height(10.dp))
            StepperGrande(
                valor = actuales.coerceAtLeast(1).toDouble(),
                onCambio = { q ->
                    val pedido = (q ?: 1.0).toInt().coerceAtLeast(1)
                    if (pedido > maximo) {
                        LimiteOrden.aviso = "No se puede hacer $pedido $u: con la leche disponible salen como máximo $maximo. Se bajó a $maximo."
                        OperarioReal.litros = litrosPara(maximo, min, tanque)
                    } else {
                        LimiteOrden.aviso = null
                        OperarioReal.litros = litrosPara(pedido, min, tanque)
                    }
                },
                paso = 1.0, minimo = 1.0, decimales = 0,
                unidad = "$u · los litros se calculan solos",
            )
        }
    }
    LimiteOrden.aviso?.let { AvisoCampo(it, esAlerta = true) }
}
