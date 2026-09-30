package pe.gob.huata.ecolacteos.ui.roles

import androidx.compose.animation.animateColorAsState
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.launch
import kotlinx.serialization.json.*
import pe.gob.huata.ecolacteos.datos.*
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*
import kotlin.time.Clock
import kotlin.time.Duration.Companion.hours
import kotlin.time.ExperimentalTime

/** Estado de Calidad con datos reales; vive mientras dure la sesión. */
object CalidadReal {
    var seleccion by mutableStateOf<JsonObject?>(null)
    var muestraPlanta by mutableStateOf(false)
    var m by mutableStateOf(Medicion(3.4, 3.1, 1.031, 4.5, 0.0, 6.7))
    var parametros by mutableStateOf(ParametrosCalidad())
    var mensaje by mutableStateOf<String?>(null)
    var mensajeGrave by mutableStateOf(false)
    var version by mutableStateOf(0) // sube tras cada diagnóstico para recargar listas
    var enviando by mutableStateOf(false)

    fun limpiar() { seleccion = null; m = Medicion(3.4, 3.1, 1.031, 4.5, 0.0, 6.7); muestraPlanta = false }
}

/**
 * Calidad con la base de datos: cola de entregas pendientes, diagnóstico que
 * se guarda con /sync/push (el servidor fija veredicto y sanción), infractores
 * e historial.
 */
@Composable
fun ContenidoCalidadReal(ruta: String, irA: (Int) -> Unit) {
    // rangos vigentes del servidor para el veredicto en vivo
    LaunchedEffect(Unit) {
        runCatching { Sesion.consultar("/api/v1/calidad/parametros") }.getOrNull()?.let { p ->
            CalidadReal.parametros = ParametrosCalidad(
                grasaMin = p.num("grasa_min"), proteinaMin = p.num("proteina_min"),
                densidadMin = p.num("densidad_min"), densidadMax = p.num("densidad_max"),
                temperaturaMax = p.num("temperatura_max"), phMin = p.num("ph_min"), phMax = p.num("ph_max"),
                aguaAdulteracion = p.num("agua_adulter"),
            )
        }
    }
    when (ruta) {
        "calidad/cola" -> ColaReal(irA)
        "calidad/diagnostico" -> DiagnosticoReal(irA)
        "calidad/infractores" -> InfractoresReal()
        else -> HistorialReal()
    }
}

@Composable
private fun MensajeResultado() {
    CalidadReal.mensaje?.let { AvisoCampo(it, esAlerta = CalidadReal.mensajeGrave) }
}

@Composable
private fun ColaReal(irA: (Int) -> Unit) {
    CargaServidor("/api/v1/calidad/pendientes", clave = CalidadReal.version) { d, recargar ->
        val cola = d.lista("entregas")
        PantallaScroll {
            MensajeResultado()
            Text(
                "${cola.size} entregas esperan diagnóstico. Toca una para medir con el lactoscan.",
                style = Texto.Cuerpo, color = Altiplano.TextoTerciario,
            )
            cola.forEach { x ->
                val prom = x.num("promedio")
                val desvio = if (prom > 0) (x.num("litros") - prom) / prom * 100 else 0.0
                FilaLista(
                    x.txt("proveedor"),
                    "${x.txt("comunidad")} · ${x.txt("vehiculo")} · ${fechaCorta(x.txt("fecha"))} ${x.txt("hora")}" +
                        if (kotlin.math.abs(desvio) >= 20) " · ${if (desvio > 0) "+" else ""}${formatear(desvio, 0)} % vs. promedio" else "",
                    onClick = { CalidadReal.limpiar(); CalidadReal.seleccion = x; CalidadReal.mensaje = null; irA(1) },
                    borde = if (kotlin.math.abs(desvio) >= 20) Altiplano.AvisoBorde else Altiplano.VerdeBorde,
                ) {
                    Text("${formatear(x.num("litros"))} L", style = Texto.Fila, color = Altiplano.Texto)
                }
            }
            if (cola.isEmpty()) AvisoCampo("Sin muestras pendientes. Las entregas aparecen aquí cuando el acopiador sincroniza.")
            TextButton(onClick = recargar) { Text("Actualizar", color = Altiplano.TextoTerciario) }
        }
    }
}

@OptIn(ExperimentalTime::class)
private fun ahoraPeru(): String = (Clock.System.now() - 5.hours).toString().take(19).replace('T', ' ')

@Composable
private fun DiagnosticoReal(irA: (Int) -> Unit) {
    val x = CalidadReal.seleccion
    val p = CalidadReal.parametros
    val m = CalidadReal.m
    val v = m.evaluar(p)
    val colorV by animateColorAsState(if (v.resultado == "ACEPTADO") Altiplano.Exito else Altiplano.Alerta)
    val scope = rememberCoroutineScope()
    var error by remember { mutableStateOf<String?>(null) }

    fun resolver(aceptar: Boolean) {
        val e = x ?: return
        scope.launch {
            CalidadReal.enviando = true; error = null
            try {
                val r = Sesion.enviarOperacion(TipoOperacion.ANALISIS, buildJsonObject {
                    put("entrega_id", e.num("id").toLong())
                    put("muestra", if (CalidadReal.muestraPlanta) "PLANTA" else "CAMPO")
                    m.grasa?.let { put("grasa", it) }
                    m.proteina?.let { put("proteina", it) }
                    m.densidad?.let { put("densidad", it) }
                    m.temperatura?.let { put("temperatura", it) }
                    m.aguaAnadida?.let { put("agua_anadida", it) }
                    m.ph?.let { put("ph", it) }
                    put("significada", m.significada)
                    put("fuente", "MANUAL")
                    put("tomada_en", ahoraPeru())
                    put("aceptar", aceptar)
                })
                val veredicto = r.txt("veredicto").ifEmpty { v.resultado }
                val sancion = r.obj("sancion")
                CalidadReal.mensajeGrave = sancion != null || veredicto != "ACEPTADO"
                CalidadReal.mensaje = "${e.txt("proveedor")}: $veredicto" + (sancion?.let {
                    " · sanción ${it.txt("tipo").lowercase()} (${it.txt("nivel")}ª vez): " + when (it.txt("medida")) {
                        "PRECIO_REDUCIDO" -> "precio reducido"
                        "BAJA_DEFINITIVA" -> "BAJA DEFINITIVA"
                        else -> "capacitación"
                    }
                } ?: " · guardado en la base de datos")
                CalidadReal.limpiar()
                CalidadReal.version++
                irA(0)
            } catch (t: Throwable) {
                error = t.message
            } finally {
                CalidadReal.enviando = false
            }
        }
    }

    PantallaScroll {
        if (x == null) {
            AvisoCampo("No hay muestra seleccionada. Vuelve a la cola y toca una entrega.")
            BotonPrimario("Ir a la cola") { irA(0) }
            return@PantallaScroll
        }
        FilaLista(x.txt("proveedor"), "${x.txt("comunidad")} · ${x.txt("vehiculo")} · ${fechaCorta(x.txt("fecha"))}") {
            Text("${formatear(x.num("litros"))} L", style = Texto.Fila, color = Altiplano.Texto)
        }
        Segmentos(listOf("Muestra de ruta", "Muestra de planta"), if (CalidadReal.muestraPlanta) 1 else 0) {
            CalidadReal.muestraPlanta = it == 1
        }
        ParametroReal("Grasa", m.grasa, 0.1, 1, "% · mín. ${p.grasaMin}", (m.grasa ?: 9.0) < p.grasaMin) { CalidadReal.m = m.copy(grasa = it) }
        ParametroReal("Proteína", m.proteina, 0.1, 1, "% · mín. ${p.proteinaMin}", (m.proteina ?: 9.0) < p.proteinaMin) { CalidadReal.m = m.copy(proteina = it) }
        ParametroReal("Densidad", m.densidad, 0.001, 3, "g/mL · ${p.densidadMin}–${p.densidadMax}", m.densidad?.let { it < p.densidadMin || it > p.densidadMax } ?: false) { CalidadReal.m = m.copy(densidad = it) }
        ParametroReal("Temperatura", m.temperatura, 0.5, 1, "°C · máx. ${p.temperaturaMax}", (m.temperatura ?: 0.0) > p.temperaturaMax) { CalidadReal.m = m.copy(temperatura = it) }
        ParametroReal("Agua añadida", m.aguaAnadida, 0.5, 1, "% · adulterada desde ${p.aguaAdulteracion}", (m.aguaAnadida ?: 0.0) >= p.aguaAdulteracion) { CalidadReal.m = m.copy(aguaAnadida = it) }
        ParametroReal("pH", m.ph, 0.05, 2, "${p.phMin}–${p.phMax}", m.ph?.let { it < p.phMin || it > p.phMax } ?: false) { CalidadReal.m = m.copy(ph = it) }
        Tarjeta {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("Leche significada", style = Texto.Fila, color = Altiplano.Texto)
                    Text("Expuesta al sol antes del recojo", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
                }
                Switch(m.significada, { CalidadReal.m = m.copy(significada = it) },
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
        error?.let { AvisoCampo("No se pudo guardar: $it", esAlerta = true) }
        if (CalidadReal.enviando) {
            Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Altiplano.Exito) }
        } else {
            BotonesDecision("Rechazar", "Aceptar", { resolver(false) }, { resolver(true) })
        }
        Text("El servidor recalcula el veredicto y aplica la sanción que corresponda.", style = Texto.Apoyo, color = Altiplano.TextoTerciario)
    }
}

@Composable
private fun ParametroReal(nombre: String, valor: Double?, paso: Double, dec: Int, rango: String, fuera: Boolean, onCambio: (Double?) -> Unit) {
    Tarjeta(borde = if (fuera) Altiplano.AlertaBorde else Altiplano.Borde) {
        Text(nombre, style = Texto.Fila.copy(fontSize = 15.sp), color = Altiplano.Texto)
        Spacer(Modifier.height(8.dp))
        StepperGrande(valor, onCambio, paso = paso, decimales = dec, unidad = rango, fueraDeRango = fuera, compacto = true)
    }
}

@Composable
private fun InfractoresReal() {
    CargaServidor("/api/v1/calidad/infractores", clave = CalidadReal.version) { d, _ ->
        val lista = d.lista("data")
        PantallaScroll {
            Text("Registro en tiempo real desde la base de datos.", style = Texto.Cuerpo, color = Altiplano.TextoTerciario)
            if (lista.isEmpty()) AvisoCampo("No hay infractores registrados.")
            lista.forEach { s ->
                val prov = s.obj("proveedor")
                val grave = s.txt("tipo") == "ADULTERACION"
                val baja = s.txt("medida") == "BAJA_DEFINITIVA" || prov?.txt("estado") == "BAJA"
                Tarjeta(borde = if (grave) Altiplano.AlertaBorde else Altiplano.Borde) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(prov?.txt("nombre") ?: "—", style = Texto.Fila, color = Altiplano.Texto)
                            prov?.obj("comunidad")?.let { Text(it.txt("nombre"), style = Texto.Apoyo, color = Altiplano.TextoTerciario) }
                        }
                        ChipEstado(if (grave) Estado.ADULTERADA else Estado.PENDIENTE)
                    }
                    Spacer(Modifier.height(6.dp))
                    val tipo = when (s.txt("tipo")) { "ADULTERACION" -> "Adulteración"; "SIGNIFICADA" -> "Leche significada"; else -> "Acidez" }
                    Text("$tipo · ${s.txt("nivel")}ª vez · ${fechaCorta(s.txt("created_at"))}", style = Texto.Apoyo, color = Altiplano.TextoSecundario)
                    Text(
                        when (s.txt("medida")) {
                            "PRECIO_REDUCIDO" -> "Precio reducido en esa entrega" + (s.txt("precio_aplicado").takeIf { it.isNotEmpty() }?.let { " (S/ $it por litro)" } ?: "")
                            "BAJA_DEFINITIVA" -> "Baja definitiva"
                            else -> "Capacitación en buenas prácticas"
                        },
                        style = Texto.Apoyo, color = if (baja) Altiplano.Alerta else Altiplano.Aviso,
                    )
                    s.txt("detalle").takeIf { it.isNotEmpty() }?.let { Text(it, style = Texto.Apoyo, color = Altiplano.TextoTerciario) }
                }
            }
            Seccion("Política")
            Tarjeta {
                FilaValor("Adulteración 1ª vez", "Precio reducido")
                FilaValor("Adulteración 2ª vez", "Baja definitiva", Altiplano.Alerta)
                FilaValor("Acidez / significada", "Capacitación")
            }
        }
    }
}

@Composable
private fun HistorialReal() {
    CargaServidor("/api/v1/calidad/historial", clave = CalidadReal.version) { d, _ ->
        val lista = d.lista("analisis")
        PantallaScroll {
            Text("Últimos ${lista.size} análisis", style = Texto.Cuerpo, color = Altiplano.TextoTerciario)
            lista.forEach { a ->
                val causas = (a["causas"] as? JsonArray)?.mapNotNull { (it as? JsonPrimitive)?.content } ?: emptyList()
                FilaLista(
                    a.txt("proveedor"),
                    "${fechaCorta(a.txt("fecha"))} · ${a.txt("vehiculo")} · ${formatear(a.num("litros"))} L · " +
                        (causas.firstOrNull() ?: "grasa ${formatear(a.num("grasa"))} % · agua ${formatear(a.num("agua"))} %"),
                ) {
                    ChipEstado(when (a.txt("veredicto")) { "ACEPTADO" -> Estado.ACEPTADO; "ADULTERADA" -> Estado.ADULTERADA; else -> Estado.RECHAZADO })
                }
            }
        }
    }
}
