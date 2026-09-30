package pe.gob.huata.ecolacteos.ui.componentes

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.platform.LocalSoftwareKeyboardController
import androidx.compose.ui.text.TextRange
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.TextFieldValue
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import pe.gob.huata.ecolacteos.tema.*

/**
 * Litros con teclado numérico: al abrir la hoja el teclado aparece solo y el
 * número viene seleccionado, así se escribe directo ("18,5") y reemplaza lo
 * anterior. "Listo" en el teclado llama a [onListo] (guardar). Los botones
 * − / + siguen para quien prefiera tocar.
 */
@Composable
fun CampoLitros(
    valor: Double,
    onCambio: (Double) -> Unit,
    onListo: () -> Unit,
    paso: Double = 0.5,
    unidad: String? = null,
    abrirTeclado: Boolean = true,
) {
    val foco = remember { FocusRequester() }
    val teclado = LocalSoftwareKeyboardController.current
    var texto by remember { mutableStateOf(TextFieldValue(textoDe(valor), TextRange(0, textoDe(valor).length))) }

    // si cambia desde afuera (botones, atajos) se muestra el valor nuevo
    LaunchedEffect(valor) {
        if (leerLitros(texto.text) != valor) texto = TextFieldValue(textoDe(valor), TextRange(textoDe(valor).length))
    }
    if (abrirTeclado) LaunchedEffect(Unit) { foco.requestFocus(); teclado?.show() }

    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        Boton("−") { onCambio(maxOf(0.0, valor - paso)) }
        Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
            BasicTextField(
                value = texto,
                onValueChange = { nuevo ->
                    val limpio = nuevo.text.filter { it.isDigit() || it == ',' || it == '.' }.take(6)
                    texto = nuevo.copy(text = limpio)
                    leerLitros(limpio)?.let(onCambio)
                },
                singleLine = true,
                textStyle = Texto.Dato.copy(color = Altiplano.Texto, textAlign = TextAlign.Center),
                cursorBrush = SolidColor(Altiplano.Exito),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal, imeAction = ImeAction.Done),
                keyboardActions = KeyboardActions(onDone = { teclado?.hide(); onListo() }),
                modifier = Modifier.fillMaxWidth().focusRequester(foco),
            )
            unidad?.let {
                Spacer(Modifier.height(3.dp))
                Text(it, style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            }
        }
        Boton("+") { onCambio(valor + paso) }
    }
}

/** "18,5" o "18.5" → 18.5; vacío o inválido → null (no cambia nada). */
internal fun leerLitros(s: String): Double? = s.replace(',', '.').toDoubleOrNull()?.takeIf { it >= 0 }

private fun textoDe(v: Double): String = formatear(v, 1).replace(".", "") // sin punto de miles al escribir

@Composable
private fun Boton(signo: String, onClick: () -> Unit) {
    Surface(
        onClick = onClick, modifier = Modifier.size(Medidas.Stepper), shape = RoundedCornerShape(15.dp),
        color = Altiplano.VerdeMarcaSuave, border = BorderStroke(1.dp, Altiplano.VerdeBorde),
    ) {
        Box(contentAlignment = Alignment.Center) {
            Text(signo, style = Texto.DatoMedio.copy(fontSize = 27.sp), color = Altiplano.Texto)
        }
    }
}
