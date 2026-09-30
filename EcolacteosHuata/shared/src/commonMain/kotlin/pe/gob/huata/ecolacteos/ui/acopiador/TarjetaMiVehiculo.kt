package pe.gob.huata.ecolacteos.ui.acopiador

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.CheckCircle
import androidx.compose.material.icons.outlined.LocalShipping
import androidx.compose.material.icons.outlined.Place
import androidx.compose.material.icons.outlined.TwoWheeler
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch
import pe.gob.huata.ecolacteos.datos.*
import pe.gob.huata.ecolacteos.tema.*

/** Vehículo que el acopiador eligió para hoy (en memoria hasta tener login). */
object VehiculoElegido {
    var id by mutableStateOf<Long?>(null)
}

/** Elige el vehículo y, si hay sesión con el servidor, lo guarda en la base de datos. */
private fun elegirVehiculo(v: Vehiculo, scope: CoroutineScope) {
    if (!Sesion.desdeServidor) { VehiculoElegido.id = v.id; return }
    // el acopiador que sale con el vehículo queda como su conductor
    Demo.vehiculos = Demo.vehiculos.map {
        when {
            it.id == v.id -> it.copy(conductor = Sesion.nombre)
            it.conductor == Sesion.nombre -> it.copy(conductor = null)
            else -> it
        }
    }
    VehiculoElegido.id = v.id
    scope.launch {
        try {
            Sesion.tomarVehiculo(v.id)
            ResultadoSync.huboError = false
            ResultadoSync.mensaje = "✓ Vehículo ${v.codigo} guardado en la base de datos"
        } catch (e: Throwable) {
            ResultadoSync.huboError = true
            ResultadoSync.mensaje = "No se pudo guardar el vehículo: ${e.message}"
        }
    }
}

/**
 * Primero se elige el vehículo del día; después se muestra la ruta en orden y
 * a qué comunidad ir ahora (la primera que todavía tiene proveedores sin registrar).
 */
@Composable
fun TarjetaMiVehiculo(filas: List<FilaProveedor>, irARegistrar: () -> Unit) {
    val v = VehiculoElegido.id?.let { id -> Demo.vehiculos.firstOrNull { it.id == id } }
    if (v == null) ElegirVehiculo() else MiRuta(v, filas, irARegistrar)
}

@Composable
private fun ElegirVehiculo() {
    val scope = rememberCoroutineScope()
    Surface(
        shape = RoundedCornerShape(16.dp), color = Altiplano.Superficie,
        border = BorderStroke(1.dp, Altiplano.VerdeBorde),
    ) {
        Column(Modifier.fillMaxWidth().padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("¿QUÉ VEHÍCULO VAS A UTILIZAR HOY?", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
            Demo.vehiculos.forEach { v ->
                val esMotocar = v.tipo == TipoVehiculo.MOTOCAR
                Surface(
                    onClick = { elegirVehiculo(v, scope) },
                    modifier = Modifier.fillMaxWidth(),
                    shape = RoundedCornerShape(12.dp), color = Altiplano.Fondo,
                    border = BorderStroke(1.dp, Altiplano.Borde),
                ) {
                    Row(Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                        Box(
                            Modifier.size(40.dp).background(v.color.copy(alpha = 0.18f), CircleShape),
                            contentAlignment = Alignment.Center,
                        ) {
                            Icon(
                                if (esMotocar) Icons.Outlined.TwoWheeler else Icons.Outlined.LocalShipping,
                                contentDescription = null, tint = v.color,
                            )
                        }
                        Spacer(Modifier.width(12.dp))
                        Column(Modifier.weight(1f)) {
                            Text("${v.codigo} · ${if (esMotocar) "Motocar" else "Carro"}", style = Texto.Fila, color = Altiplano.Texto)
                            Text(AcopiadoresDemo.de(v), style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                        }
                        Text("Elegir", style = Texto.Apoyo, color = Altiplano.Exito)
                    }
                }
            }
        }
    }
}

@Composable
private fun MiRuta(v: Vehiculo, filas: List<FilaProveedor>, irARegistrar: () -> Unit) {
    val esMotocar = v.tipo == TipoVehiculo.MOTOCAR
    val suyos = filas.filter { it.proveedor.vehiculoId == v.id }
    // paradas en el orden de la ruta, con sus proveedores
    val paradas = v.ordenRuta.map { cid ->
        Demo.comunidad(cid) to suyos.filter { it.proveedor.comunidadId == cid }
    }
    val siguiente = paradas.firstOrNull { (_, provs) -> provs.any { it.entrega == null } }

    Surface(
        shape = RoundedCornerShape(16.dp), color = Altiplano.Superficie,
        border = BorderStroke(1.dp, Altiplano.VerdeBorde),
    ) {
        Column(Modifier.fillMaxWidth().padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(
                    if (esMotocar) Icons.Outlined.TwoWheeler else Icons.Outlined.LocalShipping,
                    contentDescription = null, tint = v.color,
                )
                Spacer(Modifier.width(8.dp))
                Column(Modifier.weight(1f)) {
                    Text("TU VEHÍCULO HOY", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
                    Text(
                        "${v.codigo} · ${if (esMotocar) "Motocar" else "Carro"} · ${AcopiadoresDemo.de(v)}",
                        style = Texto.Fila, color = Altiplano.Texto,
                    )
                }
                TextButton(onClick = { VehiculoElegido.id = null }) {
                    Text("Cambiar", color = Altiplano.TextoTerciario)
                }
            }

            // a dónde ir ahora
            Surface(
                onClick = { if (siguiente != null) irARegistrar() },
                shape = RoundedCornerShape(12.dp), color = Altiplano.VerdeMarcaSuave,
            ) {
                Row(Modifier.fillMaxWidth().padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(
                        if (siguiente != null) Icons.Outlined.Place else Icons.Outlined.CheckCircle,
                        contentDescription = null, tint = Altiplano.Exito,
                    )
                    Spacer(Modifier.width(10.dp))
                    Column(Modifier.weight(1f)) {
                        if (siguiente != null) {
                            val faltan = siguiente.second.count { it.entrega == null }
                            Text("VE A ACOPIAR EN", style = Texto.Eyebrow, color = Altiplano.TextoSecundario)
                            Text(siguiente.first.nombre, style = Texto.Fila.copy(fontWeight = FontWeight.Bold), color = Altiplano.Texto)
                            Text(
                                "$faltan ${if (faltan == 1) "proveedor" else "proveedores"} por registrar · toca para registrar",
                                style = Texto.Apoyo, color = Altiplano.TextoSecundario,
                            )
                        } else {
                            Text("RUTA COMPLETA", style = Texto.Eyebrow, color = Altiplano.TextoSecundario)
                            Text(
                                if (v.rutaCircular) "Cierra el circuito y regresa a la planta" else "Regresa a la planta",
                                style = Texto.Fila, color = Altiplano.Texto,
                            )
                        }
                    }
                }
            }

            // la ruta completa en orden
            paradas.forEachIndexed { i, (com, provs) ->
                val hechos = provs.count { it.entrega != null }
                val completa = hechos == provs.size
                val esSiguiente = com.id == siguiente?.first?.id
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(
                        Modifier.size(26.dp).background(
                            if (completa) Altiplano.Exito else if (esSiguiente) v.color else Altiplano.Fondo,
                            CircleShape,
                        ),
                        contentAlignment = Alignment.Center,
                    ) {
                        Text("${i + 1}", style = Texto.Apoyo, color = if (completa || esSiguiente) Altiplano.Fondo else Altiplano.TextoTerciario)
                    }
                    Spacer(Modifier.width(10.dp))
                    Column(Modifier.weight(1f)) {
                        Text(com.nombre, style = Texto.Fila, color = if (completa) Altiplano.TextoTerciario else Altiplano.Texto)
                        Text(
                            if (provs.isEmpty()) "Sin proveedores asignados"
                            else provs.joinToString(", ") { it.proveedor.nombre.substringBefore(' ') },
                            style = Texto.Apoyo, color = Altiplano.TextoTerciario,
                        )
                    }
                    Text("$hechos/${provs.size}", style = Texto.Apoyo, color = Altiplano.TextoSecundario)
                }
            }
            if (v.rutaCircular && paradas.isNotEmpty()) {
                Text("↺ Circuito: vuelve a ${paradas.first().first.nombre}", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
            }
        }
    }
}
