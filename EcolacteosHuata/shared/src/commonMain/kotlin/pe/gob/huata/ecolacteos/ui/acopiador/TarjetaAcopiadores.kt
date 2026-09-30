package pe.gob.huata.ecolacteos.ui.acopiador

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.LocalShipping
import androidx.compose.material.icons.outlined.TwoWheeler
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import pe.gob.huata.ecolacteos.datos.*
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*

/** Acopiador a cargo de cada vehículo (datos de prueba hasta conectar el backend). */
object AcopiadoresDemo {
    private val porVehiculo = mapOf(
        "C-01" to "Juan Mamani Quispe",
        "C-02" to "Pedro Condori Apaza",
        "C-03" to "Luis Ccama Huanca",
        "M-01" to "Marcos Tito Choque",
    )
    fun de(v: Vehiculo): String =
        v.conductor ?: (if (Sesion.desdeServidor) null else porVehiculo[v.codigo]) ?: "Sin asignar"
}

/** Tarjeta con los carros y el motocar, su acopiador, ruta y avance del día. */
@Composable
fun TarjetaAcopiadores(filas: List<FilaProveedor>) {
    Text("ACOPIADORES POR VEHÍCULO", style = Texto.Eyebrow, color = Altiplano.TextoTerciario)
    Demo.vehiculos.forEach { v ->
        val suyos = filas.filter { it.proveedor.vehiculoId == v.id }
        val hechos = suyos.count { it.entrega != null }
        val litros = suyos.sumOf { it.entrega?.litros ?: 0.0 }
        val esMotocar = v.tipo == TipoVehiculo.MOTOCAR
        val ruta = v.ordenRuta.joinToString(" → ") { Demo.comunidad(it).nombre }

        Surface(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(14.dp), color = Altiplano.Superficie,
            border = BorderStroke(1.dp, Altiplano.Borde),
        ) {
            Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                Box(
                    Modifier.size(40.dp).background(v.color.copy(alpha = 0.18f), CircleShape),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(
                        if (esMotocar) Icons.Outlined.TwoWheeler else Icons.Outlined.LocalShipping,
                        contentDescription = if (esMotocar) "Motocar" else "Carro",
                        tint = v.color,
                    )
                }
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(
                        "${v.codigo} · ${if (esMotocar) "Motocar" else "Carro"}",
                        style = Texto.Apoyo, color = Altiplano.TextoTerciario,
                    )
                    Text(AcopiadoresDemo.de(v), style = Texto.Fila, color = Altiplano.Texto)
                    if (ruta.isNotEmpty()) {
                        Text(
                            if (v.rutaCircular) "$ruta ↺" else ruta,
                            style = Texto.Apoyo, color = Altiplano.TextoTerciario,
                        )
                    }
                }
                Column(horizontalAlignment = Alignment.End) {
                    Text("$hechos/${suyos.size}", style = Texto.Fila, color = Altiplano.TextoSecundario)
                    Text("${formatear(litros)} L", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                }
            }
        }
    }
}
