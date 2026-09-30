package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.animation.animateColorAsState
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
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

data class Muestra(val nombre: String, val meta: String, val litros: Double)
data class Resultado(val nombre: String, val resultado: String, val detalle: String)
data class Infractor(val nombre: String, val tipo: String, val veces: Int, val accion: String, val fecha: String)

class EstadoCalidad {
    val cola = mutableStateListOf(
        Muestra("Julián Apaza Condori", "Yanamocco · C-01 · sin enviar", 12.0),
        Muestra("Mario Huanca Paredes", "Canchi · C-02 · enviado", 21.5),
        Muestra("Gregorio Yana Ccama", "Collana Primero · C-03 · sin enviar", 9.0),
    )
    var sel by mutableStateOf(0)
    var origen by mutableStateOf(0)
    var m by mutableStateOf(Medicion(3.4, 3.1, 1.031, 4.5, 0.0, 6.7))
    val historial = mutableStateListOf(
        Resultado("Rosa Quispe Mamani", "ACEPTADO", "Hoy 6:05 · 18,5 L · grasa 3,6 % · agua 0 %"),
        Resultado("Gregorio Yana Ccama", "RECHAZADO", "Ayer · 9 L · temperatura 8,4 °C"),
        Resultado("Elena Choque Tito", "ADULTERADA", "Lunes · 16 L · agua añadida 6,2 %"),
    )
    val infractores = mutableStateListOf(
        Infractor("Elena Choque Tito", "Adulteración", 1, "Precio reducido en esa entrega", "14 set"),
        Infractor("Gregorio Yana Ccama", "Leche significada", 2, "Aviso registrado", "18 set"),
        Infractor("Julián Apaza Condori", "Acidez", 1, "Capacitación en buenas prácticas", "9 set"),
    )

    fun resolver(aceptar: Boolean) {
        val x = cola.getOrNull(sel) ?: return
        val v = m.evaluar(ParametrosCalidad())
        val res = if (!aceptar && v.adulterada) "ADULTERADA" else if (aceptar) "ACEPTADO" else "RECHAZADO"
        historial.add(0, Resultado(x.nombre, res, "Hoy · ${formatear(x.litros)} L · " + (v.causas.firstOrNull() ?: "todo en rango")))
        if (res == "ADULTERADA") {
            val i = infractores.indexOfFirst { it.nombre == x.nombre && it.tipo == "Adulteración" }
            if (i >= 0) infractores[i] = infractores[i].copy(veces = 2, accion = "Baja definitiva", fecha = "hoy")
            else infractores.add(0, Infractor(x.nombre, "Adulteración", 1, "Precio reducido en esa entrega", "hoy"))
        }
        if (m.significada) infractores.add(0, Infractor(x.nombre, "Leche significada", 1, "Aviso registrado", "hoy"))
        cola.removeAt(sel); sel = 0
        m = Medicion(3.4, 3.1, 1.031, 4.5, 0.0, 6.7)
    }
}

@Composable
fun ContenidoCalidad(ruta: String, e: EstadoCalidad, irA: (Int) -> Unit) {
    when (ruta) {
        "calidad/cola" -> PantallaScroll {
            Text("${e.cola.size} entregas esperan diagnóstico. Toca una para medir con el lactoscan.", style = Texto.Cuerpo, color = Altiplano.TextoTerciario)
            e.cola.forEachIndexed { i, x ->
                FilaLista(x.nombre, x.meta, onClick = { e.sel = i; irA(1) }, borde = Altiplano.VerdeBorde) {
                    Text("${formatear(x.litros)} L", style = Texto.Fila, color = Altiplano.Texto)
                }
            }
            if (e.cola.isEmpty()) AvisoCampo("Sin muestras pendientes. Buen trabajo.")
        }
        "calidad/diagnostico" -> Diagnostico(e, irA)
        "calidad/infractores" -> PantallaScroll {
            Text("Registro en tiempo real. Reemplaza la lista impresa de la reunión semestral.", style = Texto.Cuerpo, color = Altiplano.TextoTerciario)
            e.infractores.forEach { x ->
                val grave = x.tipo == "Adulteración"
                Tarjeta(borde = if (grave) Altiplano.AlertaBorde else Altiplano.Borde) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(x.nombre, style = Texto.Fila, color = Altiplano.Texto, modifier = Modifier.weight(1f))
                        ChipEstado(if (grave) Estado.ADULTERADA else Estado.PENDIENTE)
                    }
                    Spacer(Modifier.height(6.dp))
                    Text("${x.tipo} · ${x.veces}ª vez · ${x.fecha}", style = Texto.Apoyo, color = Altiplano.TextoSecundario)
                    Text(x.accion, style = Texto.Apoyo, color = if (x.accion.startsWith("Baja")) Altiplano.Alerta else Altiplano.Aviso)
                }
            }
            Seccion("Política")
            Tarjeta {
                FilaValor("Adulteración 1ª vez", "Precio reducido")
                FilaValor("Adulteración 2ª vez", "Baja definitiva", Altiplano.Alerta)
                FilaValor("Acidez", "Capacitación")
            }
        }
        else -> PantallaScroll {
            e.historial.forEach { h ->
                FilaLista(h.nombre, h.detalle) {
                    ChipEstado(when (h.resultado) { "ACEPTADO" -> Estado.ACEPTADO; "ADULTERADA" -> Estado.ADULTERADA; else -> Estado.RECHAZADO })
                }
            }
        }
    }
}

@Composable
private fun Diagnostico(e: EstadoCalidad, irA: (Int) -> Unit) {
    val x = e.cola.getOrNull(e.sel)
    val p = ParametrosCalidad()
    val v = e.m.evaluar(p)
    val colorV by animateColorAsState(if (v.resultado == "ACEPTADO") Altiplano.Exito else Altiplano.Alerta)
    PantallaScroll {
        if (x == null) { AvisoCampo("No hay muestra seleccionada. Vuelve a la cola."); return@PantallaScroll }
        FilaLista(x.nombre, x.meta) { Text("${formatear(x.litros)} L", style = Texto.Fila, color = Altiplano.Texto) }
        Segmentos(listOf("Muestra de ruta", "Muestra de planta"), e.origen) { e.origen = it }
        Surface(onClick = {}, shape = RoundedCornerShape(12.dp), color = Altiplano.Superficie, border = BorderStroke(1.dp, Altiplano.Borde),
            modifier = Modifier.fillMaxWidth().height(52.dp)) {
            Box(contentAlignment = Alignment.Center) { Text("Leer ticket del lactoscan", style = Texto.Cuerpo, color = Altiplano.CelesteAcento) }
        }
        Parametro("Grasa", e.m.grasa, 0.1, 1, "% · mín. ${p.grasaMin}", (e.m.grasa ?: 9.0) < p.grasaMin) { e.m = e.m.copy(grasa = it) }
        Parametro("Proteína", e.m.proteina, 0.1, 1, "% · mín. ${p.proteinaMin}", (e.m.proteina ?: 9.0) < p.proteinaMin) { e.m = e.m.copy(proteina = it) }
        Parametro("Densidad", e.m.densidad, 0.001, 3, "g/mL · 1,028–1,034", e.m.densidad?.let { it < p.densidadMin || it > p.densidadMax } ?: false) { e.m = e.m.copy(densidad = it) }
        Parametro("Temperatura", e.m.temperatura, 0.5, 1, "°C · máx. 6", (e.m.temperatura ?: 0.0) > p.temperaturaMax) { e.m = e.m.copy(temperatura = it) }
        Parametro("Agua añadida", e.m.aguaAnadida, 0.5, 1, "% · adulterada desde 5", (e.m.aguaAnadida ?: 0.0) >= p.aguaAdulteracion) { e.m = e.m.copy(aguaAnadida = it) }
        Parametro("pH", e.m.ph, 0.05, 2, "6,6–6,8", e.m.ph?.let { it < p.phMin || it > p.phMax } ?: false) { e.m = e.m.copy(ph = it) }
        Tarjeta {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("Leche significada", style = Texto.Fila, color = Altiplano.Texto)
                    Text("Expuesta al sol antes del recojo", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                }
                Switch(e.m.significada, { e.m = e.m.copy(significada = it) },
                    colors = SwitchDefaults.colors(checkedTrackColor = Altiplano.Alerta))
            }
        }
        Surface(shape = RoundedCornerShape(16.dp), color = colorV.copy(alpha = .12f), border = BorderStroke(1.dp, colorV.copy(alpha = .6f)), modifier = Modifier.fillMaxWidth()) {
            Column(Modifier.padding(18.dp)) {
                Text("VEREDICTO EN VIVO", style = Texto.Eyebrow, color = colorV)
                Spacer(Modifier.height(6.dp))
                Text(v.resultado, style = Texto.DatoMedio, color = colorV)
                Spacer(Modifier.height(6.dp))
                Text(if (v.causas.isEmpty()) "Todos los parámetros dentro de rango." else v.causas.joinToString(" · "), style = Texto.Cuerpo, color = Altiplano.TextoSecundario)
            }
        }
        BotonesDecision("Rechazar", "Aceptar", { e.resolver(false); irA(0) }, { e.resolver(true); irA(0) })
    }
}

@Composable
private fun Parametro(nombre: String, valor: Double?, paso: Double, dec: Int, rango: String, fuera: Boolean, onCambio: (Double?) -> Unit) {
    Tarjeta(borde = if (fuera) Altiplano.AlertaBorde else Altiplano.Borde) {
        Text(nombre, style = Texto.Fila.copy(fontSize = 15.sp), color = Altiplano.Texto)
        Spacer(Modifier.height(8.dp))
        StepperGrande(valor, onCambio, paso = paso, decimales = dec, unidad = rango, fueraDeRango = fuera, compacto = true)
    }
}
