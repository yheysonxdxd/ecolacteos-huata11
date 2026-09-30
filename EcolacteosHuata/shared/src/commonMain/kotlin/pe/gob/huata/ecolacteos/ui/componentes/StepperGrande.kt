package pe.gob.huata.ecolacteos.ui.componentes

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import pe.gob.huata.ecolacteos.tema.*

/**
 * El control más usado de la app: litros, parámetros del lactoscan, cantidades
 * de lote y de venta.
 *
 * [valor] nullable a propósito: los campos opcionales del alta de proveedor
 * (vacas en ordeño, promedio de litros) muestran "—" porque en campo no se
 * saben con precisión, y bajar desde el mínimo vuelve a dejarlos sin dato.
 */
@Composable
fun StepperGrande(
    valor: Double?,
    onCambio: (Double?) -> Unit,
    paso: Double = 0.5,
    minimo: Double = 0.0,
    maximo: Double? = null,
    permiteSinDato: Boolean = false,
    decimales: Int = 1,
    unidad: String? = null,
    fueraDeRango: Boolean = false,
    compacto: Boolean = false,
    modifier: Modifier = Modifier,
) {
    val lado = if (compacto) Medidas.StepperCompacto else Medidas.Stepper

    Row(
        modifier = modifier.fillMaxWidth(),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        BotonStepper("−", lado) {
            when {
                valor == null -> Unit
                permiteSinDato && valor <= minimo + paso / 2 -> onCambio(null)
                else -> onCambio(maxOf(minimo, redondear(valor - paso, decimales)))
            }
        }

        Column(
            modifier = Modifier.weight(1f),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(
                text = valor?.let { formatear(it, decimales) } ?: "—",
                style = if (compacto) Texto.DatoMedio else Texto.Dato,
                color = when {
                    valor == null -> Altiplano.TextoApagado
                    fueraDeRango -> Altiplano.Alerta
                    else -> Altiplano.Texto
                },
                textAlign = TextAlign.Center,
            )
            unidad?.let {
                Spacer(Modifier.height(3.dp))
                Text(it, style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            }
        }

        BotonStepper("+", lado) {
            val nuevo = redondear((valor ?: minimo) + paso, decimales)
            onCambio(if (maximo != null) minOf(maximo, nuevo) else nuevo)
        }
    }
}

@Composable
private fun BotonStepper(signo: String, lado: androidx.compose.ui.unit.Dp, onClick: () -> Unit) {
    Surface(
        onClick = onClick,
        modifier = Modifier.size(lado),
        shape = RoundedCornerShape(15.dp),
        color = Altiplano.VerdeMarcaSuave,
        border = androidx.compose.foundation.BorderStroke(1.dp, Altiplano.VerdeBorde),
    ) {
        Box(contentAlignment = Alignment.Center) {
            Text(signo, style = Texto.DatoMedio.copy(fontSize = 27.sp), color = Altiplano.Texto)
        }
    }
}

/** Atajos de la hoja de registro: sumar de golpe sin repetir toques. */
@Composable
fun AtajosLitros(onSumar: (Double) -> Unit, modifier: Modifier = Modifier) {
    Row(modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        listOf(10.0, 20.0, 30.0, 50.0).forEach { n ->
            Surface(
                onClick = { onSumar(n) },
                modifier = Modifier.weight(1f).height(54.dp),
                shape = RoundedCornerShape(13.dp),
                color = Altiplano.Superficie,
                border = androidx.compose.foundation.BorderStroke(1.dp, Altiplano.VerdeBorde),
            ) {
                Box(contentAlignment = Alignment.Center) {
                    Text("+${n.toInt()}", style = Texto.Fila, color = Altiplano.Texto)
                }
            }
        }
    }
}

private fun redondear(v: Double, dec: Int): Double {
    var f = 1.0
    repeat(dec) { f *= 10 }
    return kotlin.math.round(v * f) / f
}

/** Coma decimal y punto de miles: es lo que se lee en Perú. */
fun formatear(v: Double, dec: Int = 1): String {
    val negativo = v < 0
    val s = kotlin.math.abs(v).toString()
    val partes = s.split(".")
    val entero = partes[0].reversed().chunked(3).joinToString(".").reversed()
    val frac = partes.getOrNull(1)?.take(dec)?.trimEnd('0')
    return (if (negativo) "-" else "") + entero + if (!frac.isNullOrEmpty()) ",$frac" else ""
}
