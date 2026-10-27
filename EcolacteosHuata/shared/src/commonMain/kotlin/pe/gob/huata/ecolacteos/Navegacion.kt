package pe.gob.huata.ecolacteos

import androidx.compose.animation.*
import androidx.compose.animation.core.tween
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
import pe.gob.huata.ecolacteos.datos.AnalisisPendientes
import pe.gob.huata.ecolacteos.datos.Conexion
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

/**
 * Con el servidor (datos reales) el menú es como el de la web de oficina: pocas
 * opciones abajo y pestañas arriba. El modo demo sigue con su menú de siempre.
 */
fun destinosDe(rol: Rol, real: Boolean = Sesion.desdeServidor): List<Destino> = if (!real) destinosDemo(rol) else when (rol) {
    // todos empiezan en Inicio: lo de hoy y botones grandes para lo que hace cada uno
    Rol.OPERARIO -> listOf(
        Destino("inicio", "Inicio", "Lo de hoy en la producción", Icons.Outlined.Home),
        Destino("operario/nueva", "Producir", "Cuántos hacer, insumos y pasos", Icons.Outlined.AddCircleOutline),
        Destino("operario/lotes", "Lotes", "Esperado vs. real", Icons.Outlined.Layers),
        Destino("operario/recetas", "Recetas", "Queso y yogurt", Icons.Outlined.MenuBook),
        Destino("operario/perdidas", "Pérdidas", "Leche cortada, queso vencido…", Icons.Outlined.ReportProblem),
    )
    Rol.COMPRAS -> listOf(
        Destino("inicio", "Inicio", "Lo de hoy en compras y ventas", Icons.Outlined.Home),
        Destino("compras/orden", "Compras", "Órdenes, comprobantes y vendedores", Icons.Outlined.ShoppingCart),
        Destino("compras/almacen", "Almacén", "Stock, avisos y pérdidas", Icons.Outlined.Inventory2),
        Destino("compras/ventas", "Ventas", "Vender, clientes y notas de venta", Icons.Outlined.Sell),
        // fórmulas y kardex se revisan de vez en cuando: en tarjetas dentro de "Más"
        Destino("compras/mas", "Más", "Fórmulas y kardex", Icons.Outlined.GridView),
    )
    Rol.CALIDAD -> listOf(
        Destino("inicio", "Inicio", "Lo de hoy en calidad", Icons.Outlined.Home),
        // elegir a quién y analizarlo es un solo paso: tocar en la lista abre el diagnóstico
        Destino("calidad/cola", "Muestreo", "Toca a un proveedor para analizar su leche", Icons.Outlined.Science),
        Destino("calidad/infractores", "Infractores", "Adulteración y leche significada", Icons.Outlined.ReportProblem),
        Destino("calidad/historial", "Historial", "Por proveedor y acopiador", Icons.Outlined.History),
    )
    Rol.ADMIN -> listOf(
        // pocas opciones para no confundirse: lo de todos los días a la mano y lo demás en "Más"
        Destino("admin/inicio", "Inicio", "Lo de hoy y lo que más se hace", Icons.Outlined.Home),
        Destino("admin/acopio", "Acopio", "Leche por día y por carro", Icons.Outlined.LocalShipping),
        Destino("admin/dinero", "Dinero", "Ventas, pagos y costos", Icons.Outlined.Savings),
        Destino("admin/planta", "Planta", "Comprar, guardar, producir y vender", Icons.Outlined.Factory),
        Destino("admin/mas", "Más", "Calidad, padrón y ajustes", Icons.Outlined.GridView),
    )
    else -> destinosDemo(rol)
}

/** Posición de una opción del menú de abajo por su ruta (0 si no está). */
fun indiceDe(rol: Rol, ruta: String): Int = destinosDe(rol).indexOfFirst { it.ruta == ruta }.coerceAtLeast(0)

private fun destinosDemo(rol: Rol): List<Destino> = when (rol) {
    Rol.ACOPIADOR -> listOf(
        Destino("acopiador/hoy", "Hoy", "Ruta del día · 3 carros y 1 motocar", Icons.Outlined.WbSunny),
        Destino("acopiador/registrar", "Registrar", "Toca un proveedor para anotar litros", Icons.Outlined.AddCircleOutline),
        Destino("acopiador/mapa", "Mapa de ruta", "Comunidades y vehículos de Huata", Icons.Outlined.Map),
        Destino("acopiador/semana", "Semana", "Acumulado total y por proveedor", Icons.Outlined.BarChart),
    )
    Rol.CALIDAD -> listOf(
        Destino("calidad/cola", "Muestreo", "Elige a cualquier proveedor para analizar", Icons.Outlined.Checklist),
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
        Destino("compras/orden", "Compras", "Órdenes, comprobantes y vendedores", Icons.Outlined.ShoppingCart),
        Destino("compras/ventas", "Ventas", "Mayorista, mercados y planta", Icons.Outlined.Sell),
        Destino("compras/movimientos", "Movimientos", "Entradas y salidas", Icons.Outlined.SwapHoriz),
    )
    Rol.ADMIN -> listOf(
        Destino("admin/totales", "Totales", "Acopio, aceptación y ranking", Icons.Outlined.PieChart),
        Destino("admin/negocio", "Dinero", "Ventas y pagos a proveedores", Icons.Outlined.Savings),
        Destino("admin/padron", "Padrón", "Proveedores y trabajadores", Icons.Outlined.People),
        Destino("admin/calidad", "Calidad", "Promedios y adulteraciones", Icons.Outlined.Science),
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
        // estado real del servidor (en modo demo no hay token y queda "sin conexión")
        var conectado by remember(r) { mutableStateOf(hayRed) }
        LaunchedEffect(r) {
            AnalisisPendientes.refrescar() // pudo quedar alguno de antes de cerrar la app
            while (Sesion.token != null) {
                val antes = conectado
                conectado = Sesion.servidorResponde()
                if (conectado && !antes) Conexion.volvio() // las pantallas con datos guardados se actualizan
                // análisis de calidad anotados sin conexión
                if (conectado && AnalisisPendientes.cantidad > 0) enviarAnalisisPendientes()
                if (conectado && r == Rol.ACOPIADOR) {
                    // volvió la señal: se borra el aviso viejo y se envía lo anotado sin señal
                    if (ResultadoSync.huboError && ResultadoSync.mensaje?.startsWith("No se pudo conectar") == true) ResultadoSync.mensaje = null
                    // lo registrado se envía solo, sin tocar el botón de arriba
                    if (acopio.pendientes > 0 && (!antes || ResultadoSync.tocaEnvioAutomatico())) sincronizarAcopio(acopio, scope)
                }
                kotlinx.coroutines.delay(5_000)
            }
        }

        Scaffold(
            containerColor = Altiplano.Fondo,
            bottomBar = {
                NavigationBar(containerColor = Color(0xFF13202E), tonalElevation = 0.dp) {
                    destinos.forEachIndexed { i, d ->
                        NavigationBarItem(
                            selected = activo == i,
                            // tocar otra vez la misma opción vuelve a su menú de tarjetas
                            onClick = {
                                if (activo == i && r == Rol.ADMIN) AdminPestanas.volverAlMenu(d.ruta)
                                if (activo == i && d.ruta == "compras/mas") ComprasMas.abierta = -1
                                activo = i
                            },
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
                BarraSinConexion(conectado)
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
                // al cambiar de opción, la pantalla nueva entra suave (todos los roles)
                AnimatedContent(activo, transitionSpec = { fadeIn(tween(240)) + slideInVertically(tween(260)) { it / 24 } togetherWith fadeOut(tween(140)) }, label = "pantalla") { i ->
                    val ruta = destinos[i].ruta
                    val ir = { n: Int -> activo = n }
                    when {
                        // Inicio de operario, compras y calidad (el admin tiene el suyo)
                        ruta == "inicio" && Sesion.desdeServidor -> InicioRol(r, ir)
                        r == Rol.ACOPIADOR -> ContenidoAcopiador(ruta, acopio, ir)
                        r == Rol.CALIDAD -> if (Sesion.desdeServidor) ContenidoCalidadReal(ruta, ir) else ContenidoCalidad(ruta, calidad, ir)
                        r == Rol.PRODUCTOR -> if (Sesion.desdeServidor) ContenidoProductorReal(ruta, productor) else ContenidoProductor(ruta, productor)
                        r == Rol.OPERARIO -> if (Sesion.desdeServidor) ContenidoOperarioReal(ruta, ir) else ContenidoOperario(ruta, operario, ir)
                        r == Rol.COMPRAS -> if (Sesion.desdeServidor) ContenidoComprasReal(ruta, ir) else ContenidoCompras(ruta, compras, ir)
                        else -> if (Sesion.desdeServidor) {
                            ContenidoAdminReal(ruta, admin) { destino -> destinos.indexOfFirst { it.ruta == destino }.takeIf { it >= 0 }?.let { activo = it } }
                        } else ContenidoAdmin(ruta, admin)
                    }
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

