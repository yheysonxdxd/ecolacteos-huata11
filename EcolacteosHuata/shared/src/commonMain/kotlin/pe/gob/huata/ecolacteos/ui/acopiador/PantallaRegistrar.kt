package pe.gob.huata.ecolacteos.ui.acopiador

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
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

/**
 * Pantalla "Registrar" del acopiador. Sirve de patrón para las demás: estado
 * arriba, lista con filas de 76 dp y la hoja modal para el detalle.
 */
data class FilaProveedor(
    val proveedor: Proveedor,
    val comunidad: String,
    val vehiculo: String,
    val entrega: Entrega?,
)

@Composable
fun PantallaRegistrar(
    filas: List<FilaProveedor>,
    parametros: ParametrosCalidad,
    onGuardar: (Proveedor, Double) -> Unit,
    onNoEntrego: (Proveedor) -> Unit,
    onNuevoProveedor: () -> Unit,
) {
    var abierta by remember { mutableStateOf<FilaProveedor?>(null) }

    LazyColumn(
        contentPadding = PaddingValues(Medidas.Pantalla),
        verticalArrangement = Arrangement.spacedBy(9.dp),
    ) {
        item {
            Text(
                "Toca el nombre para anotar litros. Usa No entregó si el proveedor no salió hoy.",
                style = Texto.Cuerpo, color = Altiplano.TextoTerciario,
                modifier = Modifier.padding(bottom = 4.dp),
            )
        }

        items(filas, key = { it.proveedor.id }) { fila ->
            Row(horizontalArrangement = Arrangement.spacedBy(9.dp)) {
                Surface(
                    onClick = { abierta = fila },
                    modifier = Modifier.weight(1f).heightIn(min = Medidas.FilaProveedor),
                    shape = RoundedCornerShape(14.dp),
                    color = Altiplano.Superficie,
                    border = BorderStroke(
                        1.dp,
                        if (fila.entrega == null) Altiplano.VerdeBorde else Altiplano.Borde,
                    ),
                ) {
                    Row(
                        Modifier.padding(horizontal = 14.dp, vertical = 13.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.SpaceBetween,
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text(fila.proveedor.nombre, style = Texto.Fila, color = Altiplano.Texto)
                            Spacer(Modifier.height(3.dp))
                            Text(
                                "${fila.comunidad} · ${fila.vehiculo}",
                                style = Texto.Apoyo, color = Altiplano.TextoTerciario,
                            )
                            // sin promedio no se muestra aviso: un aviso falso a
                            // las cinco de la mañana enseña a ignorarlos
                            fila.entrega?.takeIf { !it.ausente }?.let { e ->
                                fila.proveedor.desvio(e.litros, parametros)?.let { pct ->
                                    Spacer(Modifier.height(4.dp))
                                    Text(
                                        if (pct > 0) "${pct.toInt()} % más que su promedio"
                                        else "${-pct.toInt()} % menos que su promedio",
                                        style = Texto.Apoyo, color = Altiplano.Aviso,
                                    )
                                }
                            }
                        }
                        Column(horizontalAlignment = Alignment.End) {
                            Text(
                                fila.entrega?.let {
                                    if (it.ausente) "—" else "${formatear(it.litros)} L"
                                } ?: "—",
                                style = Texto.Fila, color = Altiplano.Texto,
                            )
                            Spacer(Modifier.height(4.dp))
                            ChipEstado(estadoDe(fila.entrega))
                        }
                    }
                }

                // atajo sin abrir la hoja completa
                Surface(
                    onClick = { onNoEntrego(fila.proveedor) },
                    modifier = Modifier.width(68.dp).heightIn(min = Medidas.FilaProveedor),
                    shape = RoundedCornerShape(14.dp),
                    color = if (fila.entrega?.ausente == true) Altiplano.AlertaFondo else Altiplano.Superficie,
                    border = BorderStroke(
                        1.dp,
                        if (fila.entrega?.ausente == true) Altiplano.AlertaBorde else Altiplano.Borde,
                    ),
                ) {
                    Box(contentAlignment = Alignment.Center) {
                        Text(
                            "No\nentregó", style = Texto.Apoyo.copy(fontSize = 10.sp),
                            color = if (fila.entrega?.ausente == true) Altiplano.Alerta
                                    else Altiplano.TextoTerciario,
                        )
                    }
                }
            }
        }

        item {
            Spacer(Modifier.height(4.dp))
            Surface(
                onClick = onNuevoProveedor,
                modifier = Modifier.fillMaxWidth().height(Medidas.BotonPrimario),
                shape = RoundedCornerShape(15.dp),
                color = Altiplano.Superficie,
                border = BorderStroke(1.dp, Altiplano.VerdeBorde),
            ) {
                Box(contentAlignment = Alignment.Center) {
                    Text("Registrar nuevo proveedor", style = Texto.Fila, color = Altiplano.Exito)
                }
            }
        }
    }

    abierta?.let { fila ->
        HojaRegistro(
            fila = fila,
            parametros = parametros,
            onCerrar = { abierta = null },
            onGuardar = { litros -> onGuardar(fila.proveedor, litros); abierta = null },
            onNoEntrego = { onNoEntrego(fila.proveedor); abierta = null },
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun HojaRegistro(
    fila: FilaProveedor,
    parametros: ParametrosCalidad,
    onCerrar: () -> Unit,
    onGuardar: (Double) -> Unit,
    onNoEntrego: () -> Unit,
) {
    var litros by remember {
        mutableStateOf(fila.entrega?.takeIf { !it.ausente }?.litros
            ?: fila.proveedor.promedioLitros ?: 12.0)
    }
    val desvio = fila.proveedor.desvio(litros, parametros)

    ModalBottomSheet(onDismissRequest = onCerrar, containerColor = Color(0xFF142333)) {
        Column(Modifier.padding(horizontal = Medidas.Pantalla).padding(bottom = 22.dp)) {
            Text(fila.proveedor.nombre, style = Texto.TituloPantalla, color = Altiplano.Texto)
            Spacer(Modifier.height(3.dp))
            Text(
                "${fila.comunidad} · ${fila.vehiculo} · hoy",
                style = Texto.Apoyo, color = Altiplano.TextoTerciario,
            )

            Spacer(Modifier.height(20.dp))
            StepperGrande(
                valor = litros,
                onCambio = { litros = it ?: 0.0 },
                paso = 0.5,
                unidad = fila.proveedor.promedioLitros
                    ?.let { "litros · promedio ${formatear(it)} L" }
                    ?: "litros · sin promedio todavía",
            )

            Spacer(Modifier.height(16.dp))
            AtajosLitros(onSumar = { litros += it })

            desvio?.let { pct ->
                Spacer(Modifier.height(14.dp))
                AvisoCampo(
                    if (pct > 0) "${pct.toInt()} % más que su promedio. Verifica el bidón antes de guardar."
                    else "${-pct.toInt()} % menos que su promedio. Verifica el bidón antes de guardar."
                )
            }

            Spacer(Modifier.height(16.dp))
            Surface(
                onClick = { onGuardar(litros) },
                modifier = Modifier.fillMaxWidth().height(Medidas.Stepper),
                shape = RoundedCornerShape(16.dp),
                color = Altiplano.VerdeMarcaSuave,
                border = BorderStroke(1.dp, Altiplano.VerdeMarca),
            ) {
                Box(contentAlignment = Alignment.Center) {
                    Text("Guardar entrega", style = Texto.Fila, color = Altiplano.Texto)
                }
            }

            Spacer(Modifier.height(10.dp))
            TextButton(onClick = onNoEntrego, modifier = Modifier.fillMaxWidth()) {
                Text("Hoy no entregó", style = Texto.Cuerpo, color = Altiplano.TextoTerciario)
            }
        }
    }
}

private fun estadoDe(e: Entrega?): Estado = when {
    e == null -> Estado.FALTA
    e.ausente -> Estado.NO_ENTREGO
    e.estadoCalidad == "ACEPTADO" -> Estado.ACEPTADO
    e.estadoCalidad == "RECHAZADO" -> Estado.RECHAZADO
    else -> Estado.SIN_ENVIAR
}

