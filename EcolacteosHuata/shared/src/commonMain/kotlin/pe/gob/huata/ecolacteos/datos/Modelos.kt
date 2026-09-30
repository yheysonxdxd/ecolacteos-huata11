package pe.gob.huata.ecolacteos.datos

import androidx.compose.ui.graphics.Color
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import pe.gob.huata.ecolacteos.tema.Altiplano

enum class Rol { ACOPIADOR, CALIDAD, PRODUCTOR, OPERARIO, COMPRAS, ADMIN }
enum class TipoVehiculo { CARRO, MOTOCAR }
enum class EstadoPin { REGISTRADO, SIN_ENVIAR, NO_ENTREGO, FALTA }

@Serializable
data class Comunidad(
    val id: Long,
    val nombre: String,
    @SerialName("croquis_x") val croquisX: Float,
    @SerialName("croquis_y") val croquisY: Float,
    val vias: String? = null,
)

@Serializable
data class Vehiculo(
    val id: Long,
    val codigo: String,                              // C-01 … M-01
    val tipo: TipoVehiculo,
    val conductor: String? = null,
    @SerialName("ruta_circular") val rutaCircular: Boolean = false,
    @SerialName("orden_ruta") val ordenRuta: List<Long> = emptyList(),
) {
    val color: Color get() = when (codigo) {
        "C-01" -> Altiplano.RutaC01
        "C-02" -> Altiplano.RutaC02
        "C-03" -> Altiplano.RutaC03
        else   -> Altiplano.RutaM01
    }
}

@Serializable
data class Proveedor(
    val id: Long,
    val uuid: String,
    val nombre: String,
    val dni: String,
    @SerialName("comunidad_id") val comunidadId: Long,
    @SerialName("vehiculo_id") val vehiculoId: Long,
    // opcionales: en el alta de campo no se saben con precisión
    @SerialName("promedio_litros") val promedioLitros: Double? = null,
    @SerialName("vacas_ordeno") val vacasOrdeno: Int? = null,
    val estado: String = "ACTIVO",
) {
    val tienePromedio: Boolean get() = (promedioLitros ?: 0.0) > 0
    val deBaja: Boolean get() = estado == "BAJA"
}

@Serializable
data class Entrega(
    val uuid: String,
    @SerialName("proveedor_id") val proveedorId: Long,
    val fecha: String,
    val litros: Double,
    val ausente: Boolean = false,
    @SerialName("estado_calidad") val estadoCalidad: String = "PENDIENTE",
    @SerialName("registrado_en") val registradoEn: String,
)

/** Los seis parámetros del lactoscan. */
@Serializable
data class Medicion(
    val grasa: Double? = null,
    val proteina: Double? = null,
    val densidad: Double? = null,
    val temperatura: Double? = null,
    @SerialName("agua_anadida") val aguaAnadida: Double? = null,
    val ph: Double? = null,
    val significada: Boolean = false,
)

/** Rangos que vienen de /sync/bootstrap; el servidor puede cambiarlos. */
@Serializable
data class ParametrosCalidad(
    val grasaMin: Double = 3.0,
    val proteinaMin: Double = 2.9,
    val densidadMin: Double = 1.028,
    val densidadMax: Double = 1.034,
    val temperaturaMax: Double = 6.0,
    val phMin: Double = 6.6,
    val phMax: Double = 6.8,
    val aguaAdulteracion: Double = 5.0,
    val desvioPromedioPct: Double = 20.0,
)

data class Veredicto(
    val resultado: String,      // ACEPTADO | RECHAZADO | ADULTERADA
    val causas: List<String>,
    val adulterada: Boolean,
)

/**
 * Mismas reglas que CalidadService.php, replicadas para dar veredicto sin
 * señal. El servidor recalcula al recibir y su resultado manda: el cliente
 * nunca es autoridad sobre una sanción.
 */
fun Medicion.evaluar(p: ParametrosCalidad): Veredicto {
    val causas = mutableListOf<String>()
    val adulterada = (aguaAnadida ?: 0.0) >= p.aguaAdulteracion

    if (adulterada) causas += "agua añadida $aguaAnadida % (≥ ${p.aguaAdulteracion} %)"
    grasa?.let { if (it < p.grasaMin) causas += "grasa $it % bajo ${p.grasaMin} %" }
    proteina?.let { if (it < p.proteinaMin) causas += "proteína $it % bajo ${p.proteinaMin} %" }
    densidad?.let {
        if (it < p.densidadMin || it > p.densidadMax)
            causas += "densidad fuera de ${p.densidadMin}–${p.densidadMax}"
    }
    temperatura?.let {
        if (it > p.temperaturaMax) causas += "temperatura $it °C sobre ${p.temperaturaMax} °C"
    }
    ph?.let { if (it < p.phMin || it > p.phMax) causas += "pH $it fuera de ${p.phMin}–${p.phMax}" }
    if (significada) causas += "leche significada (expuesta al sol)"

    return Veredicto(
        resultado = if (adulterada) "ADULTERADA" else if (causas.isEmpty()) "ACEPTADO" else "RECHAZADO",
        causas = causas,
        adulterada = adulterada,
    )
}

/** Aviso de desvío. Null si el proveedor todavía no tiene promedio. */
fun Proveedor.desvio(litros: Double, p: ParametrosCalidad): Double? {
    val prom = promedioLitros ?: return null
    if (prom <= 0 || litros <= 0) return null
    val pct = ((litros - prom) / prom) * 100
    return if (kotlin.math.abs(pct) >= p.desvioPromedioPct) pct else null
}
