package pe.gob.huata.ecolacteos

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.*
import androidx.compose.material.icons.automirrored.outlined.Logout
import androidx.compose.ui.graphics.vector.ImageVector
import pe.gob.huata.ecolacteos.datos.Rol
import pe.gob.huata.ecolacteos.datos.Sesion
import pe.gob.huata.ecolacteos.datos.SesionGuardada
import pe.gob.huata.ecolacteos.ui.Splash
import pe.gob.huata.ecolacteos.ui.PantallaLogin
import pe.gob.huata.ecolacteos.ui.nombreRol
import pe.gob.huata.ecolacteos.ui.roles.*
import pe.gob.huata.ecolacteos.tema.*
import pe.gob.huata.ecolacteos.ui.componentes.*
import pe.gob.huata.ecolacteos.ui.acopiador.*

/**
 * El rol viene del login y define el grafo completo: un acopiador nunca ve las
 * pantallas de planta. La barra inferior cambia con el rol.
 */
data class Destino(val ruta: String, val etiqueta: String, val subtitulo: String = "", val icono: ImageVector = Icons.Outlined.Circle)

fun destinosDe(rol: Rol): List<Destino> = when (rol) {
    Rol.ACOPIADOR -> listOf(
        Destino("acopiador/hoy", "Hoy", "Ruta del día · 3 carros y 1 motocar", Icons.Outlined.WbSunny),
        Destino("acopiador/registrar", "Registrar", "Toca un proveedor para anotar litros", Icons.Outlined.AddCircleOutline),
        Destino("acopiador/mapa", "Mapa de ruta", "Comunidades y vehículos de Huata", Icons.Outlined.Map),
        Destino("acopiador/semana", "Semana", "Acumulado total y por proveedor", Icons.Outlined.BarChart),
    )
    Rol.CALIDAD -> listOf(
        Destino("calidad/cola", "Cola", "Entregas por diagnosticar", Icons.Outlined.Checklist),
        Destino("calidad/diagnostico", "Diagnóstico", "Lactoscan y veredicto en vivo", Icons.Outlined.Science),
        Destino("calidad/infractores", "Infractores", "Adulteración y leche significada", Icons.Outlined.ReportProblem),
        Destino("calidad/historial", "Historial", "Por proveedor y acopiador", Icons.Outlined.History),
    )
    Rol.PRODUCTOR -> listOf(
        Destino("productor/hoy", "Hoy", "Tu entrega y tu pago de la semana", Icons.Outlined.WbSunny),
        Destino("productor/semana", "Semana", "Litros por día y sello de calidad", Icons.Outlined.BarChart),
        Destino("productor/pagos", "Pagos", "Boleta semanal en soles", Icons.Outlined.AccountBalanceWallet),
        Destino("productor/comunidad", "Comunidad", "Ubicación y cambio de zona", Icons.Outlined.Place),
    )
    Rol.OPERARIO -> listOf(
        Destino("operario/recetas", "Recetas", "Queso y yogurt", Icons.Outlined.MenuBook),
        Destino("operario/nueva", "Nueva orden", "Insumos, rendimiento y vencimiento", Icons.Outlined.AddCircleOutline),
        Destino("operario/lotes", "Lotes", "Esperado vs. real", Icons.Outlined.Layers),
    )
    Rol.COMPRAS -> listOf(
        Destino("compras/inventario", "Inventario", "Stock contra mínimo", Icons.Outlined.Inventory2),
        Destino("compras/orden", "Orden", "Insumo, cantidad y proveedor", Icons.Outlined.ShoppingCart),
        Destino("compras/ventas", "Ventas", "Mayorista, mercados y planta", Icons.Outlined.Sell),
        Destino("compras/movimientos", "Movimientos", "Entradas y salidas", Icons.Outlined.SwapHoriz),
    )
    Rol.ADMIN -> listOf(
        Destino("admin/totales", "Totales", "Acopio, aceptación y ranking", Icons.Outlined.PieChart),
        Destino("admin/padron", "Padrón", "Proveedores y trabajadores", Icons.Outlined.People),
        Destino("admin/calidad", "Calidad", "Promedios por acopiador", Icons.Outlined.Science),
        Destino("admin/solicitudes", "Solicitudes", "Cambios de zona", Icons.Outlined.Notifications),
        Destino("admin/conciliacion", "Conciliación", "Campo vs. planta · Fase 2", Icons.Outlined.CompareArrows),
        Destino("admin/costos", "Costos", "Costo por litro y rendimiento", Icons.Outlined.Payments),
    )
}

@Composable
fun AppEcolacteos(rolInicial: Rol = Rol.ACOPIADOR, pendientes: Int = 0, hayRed: Boolean = false, sincronizando: Boolean = false, onSync: () -> Unit = {}) {
    var rol by remember { mutableStateOf<Rol?>(null) }
    val acopio = remember { EstadoAcopio() }
    val calidad = remember { EstadoCalidad() }
    val productor = remember { EstadoProductor() }
    val operario = remember { EstadoOperario() }
    val compras = remember { EstadoCompras() }
    val admin = remember { EstadoAdmin() }
    val scope = rememberCoroutineScope()

    TemaAltiplano {
        val r = rol
        if (r == null) {
            PantallaLogin(hayRed) { rol = it }
            return@TemaAltiplano
        }
        val destinos = destinosDe(r)
        var activo by remember(r) { mutableStateOf(0) }
        val pend = if (r == Rol.ACOPIADOR) acopio.pendientes else pendientes

        Scaffold(
            containerColor = Altiplano.Fondo,
            bottomBar = {
                NavigationBar(containerColor = Color(0xFF13202E), tonalElevation = 0.dp) {
                    destinos.forEachIndexed { i, d ->
                        NavigationBarItem(
                            selected = activo == i,
                            onClick = { activo = i },
                            icon = { Icon(d.icono, contentDescription = d.etiqueta) },
                            label = { Text(d.etiqueta.take(11), style = Texto.Apoyo.copy(fontSize = 11.sp)) },
                            colors = NavigationBarItemDefaults.colors(
                                selectedIconColor = Altiplano.Exito,
                                selectedTextColor = Altiplano.Texto,
                                unselectedIconColor = Altiplano.TextoTerciario,
                                unselectedTextColor = Altiplano.TextoTerciario,
                                indicatorColor = Altiplano.VerdeMarcaSuave,
                            ),
                        )
                    }
                }
            },
        ) { padding ->
            Column(Modifier.padding(padding)) {
                BarraSinConexion(pend, hayRed)
                CabeceraPantalla(
                    rolTexto = nombreRol(r).uppercase(),
                    titulo = destinos[activo].etiqueta,
                    subtitulo = destinos[activo].subtitulo,
                    pendientes = pend,
                    sincronizando = sincronizando,
                    onSync = { if (r == Rol.ACOPIADOR) sincronizarAcopio(acopio, scope) else onSync() },
                    onCambiarRol = { SesionGuardada.salidaPedida = true; rol = null },
                )
                if (r == Rol.ACOPIADOR) AvisoSync()
                HorizontalDivider(color = Altiplano.BordeSuave)
                when (r) {
                    Rol.ACOPIADOR -> ContenidoAcopiador(destinos[activo].ruta, acopio) { activo = it }
                    Rol.CALIDAD -> if (Sesion.desdeServidor) ContenidoCalidadReal(destinos[activo].ruta) { activo = it } else ContenidoCalidad(destinos[activo].ruta, calidad) { activo = it }
                    Rol.PRODUCTOR -> if (Sesion.desdeServidor) ContenidoProductorReal(destinos[activo].ruta, productor) else ContenidoProductor(destinos[activo].ruta, productor)
                    Rol.OPERARIO -> if (Sesion.desdeServidor) ContenidoOperarioReal(destinos[activo].ruta) { activo = it } else ContenidoOperario(destinos[activo].ruta, operario) { activo = it }
                    Rol.COMPRAS -> if (Sesion.desdeServidor) ContenidoComprasReal(destinos[activo].ruta) { activo = it } else ContenidoCompras(destinos[activo].ruta, compras) { activo = it }
                    Rol.ADMIN -> if (Sesion.desdeServidor) ContenidoAdminReal(destinos[activo].ruta, admin) else ContenidoAdmin(destinos[activo].ruta, admin)
                }
            }
        }
    }
}

@Composable
private fun CabeceraPantalla(
    rolTexto: String, titulo: String, subtitulo: String,
    pendientes: Int, sincronizando: Boolean, onSync: () -> Unit, onCambiarRol: () -> Unit,
) {
    var confirmarSalida by remember { mutableStateOf(false) }
    Row(
        Modifier.fillMaxWidth().padding(start = Medidas.Pantalla, end = Medidas.Pantalla, top = 14.dp, bottom = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        // tocar la marca vuelve al splash para cambiar de rol (solo demo)
        Surface(onClick = onCambiarRol, color = Color.Transparent) { MarcaHuata(38.dp) }
        Column(Modifier.weight(1f)) {
            Text(rolTexto, style = Texto.Eyebrow.copy(fontSize = 10.5.sp), color = Altiplano.TextoTerciario)
            Spacer(Modifier.height(2.dp))
            Text(titulo, style = Texto.TituloPantalla, color = Altiplano.Texto)
            if (subtitulo.isNotEmpty()) {
                Spacer(Modifier.height(2.dp))
                Text(subtitulo, style = Texto.Apoyo, color = Color(0xFF93A1AD))
            }
        }
        BotonSync(pendientes, sincronizando, onSync)
        IconButton(onClick = { confirmarSalida = true }) {
            Icon(Icons.AutoMirrored.Outlined.Logout, contentDescription = "Cerrar sesión", tint = Altiplano.TextoTerciario)
        }
    }

    if (confirmarSalida) {
        AlertDialog(
            onDismissRequest = { confirmarSalida = false },
            containerColor = Altiplano.Fondo,
            title = { Text("¿Cerrar sesión?", color = Altiplano.Texto) },
            text = { Text("Volverás a la pantalla de inicio para elegir otro rol.", color = Altiplano.TextoTerciario) },
            confirmButton = {
                TextButton(onClick = { confirmarSalida = false; onCambiarRol() }) {
                    Text("Cerrar sesión", color = Altiplano.Exito)
                }
            },
            dismissButton = {
                TextButton(onClick = { confirmarSalida = false }) {
                    Text("Cancelar", color = Altiplano.TextoTerciario)
                }
            },
        )
    }
}

