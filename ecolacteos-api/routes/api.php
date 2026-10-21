<?php

use App\Http\Controllers\Api;
use Illuminate\Support\Facades\Route;

/*
 * Autenticación: Laravel Sanctum con tokens de larga vida (la app de campo
 * puede pasar días sin llegar al servidor).
 *   POST /api/v1/login  { dni, password, dispositivo } -> { token, usuario, rol }
 */

Route::prefix('v1')->group(function () {
    Route::post('login', [Api\AuthController::class, 'login']);

    // PDF de la nota de venta: sin sesión pero con enlace firmado (ver NotaVentaController)
    Route::get('notas-venta/{id}.pdf', [Api\NotaVentaController::class, 'pdf'])
        ->whereNumber('id')->middleware('signed:relative')->name('nota-venta.pdf');
    // foto del comprobante de una compra: igual, con enlace firmado (ver OrdenesCompraController)
    Route::get('comprobantes/{id}/foto', [Api\OrdenesCompraController::class, 'verFoto'])
        ->whereNumber('id')->middleware('signed:relative')->name('comprobante.foto');

    Route::middleware('auth:sanctum')->group(function () {

        Route::post('logout', [Api\AuthController::class, 'logout']);
        Route::get('yo',      [Api\AuthController::class, 'yo']);

        // --- Sincronización offline (el corazón de la app de campo) ---
        Route::post('sync/push', [Api\SyncController::class, 'push']);
        Route::get('sync/pull',  [Api\SyncController::class, 'pull']);
        Route::get('sync/bootstrap', [Api\SyncController::class, 'bootstrap']);

        // --- Vehículo que el acopiador elige para el día ---
        Route::post('vehiculos/{vehiculo}/tomar', [Api\VehiculoController::class, 'tomar']);

        // --- Resúmenes para la app ---
        Route::get('acopio/semana',      [Api\ResumenController::class, 'semanaAcopio']);
        Route::get('productor/resumen',  [Api\ResumenController::class, 'productor']);
        Route::get('productor/comunidad',        [Api\ProductorZonaController::class, 'ver']);
        Route::post('productor/solicitud-zona',  [Api\ProductorZonaController::class, 'solicitar']);

        // --- Tablero del administrador en la app ---
        Route::get('admin/totales',      [Api\AdminAppController::class, 'totales']);
        Route::get('admin/negocio',      [Api\AdminNegocioController::class, 'resumen']);
        Route::get('admin/pagos',        [Api\AdminPagosController::class, 'semana']);
        Route::post('admin/pagos/pagar', [Api\AdminPagosController::class, 'pagar']);
        Route::get('admin/pagos/{id}',   [Api\AdminPagosController::class, 'detalle'])->whereNumber('id');
        Route::get('admin/padron',       [Api\AdminAppController::class, 'padron']);
        // descargas en Excel de la web de oficina (?mes=AAAA-MM o ?semana=AAAA-MM-DD)
        Route::get('admin/excel/pagos',    [Api\ExcelController::class, 'pagos']);
        Route::get('admin/excel/entregas', [Api\ExcelController::class, 'entregas']);
        Route::get('admin/excel/padron',   [Api\ExcelController::class, 'padron']);
        Route::get('admin/calidad',      [Api\AdminAppController::class, 'calidad']);
        Route::get('admin/adulteraciones', [Api\AdminAppController::class, 'adulteraciones']);
        // rechazos que no son adulteración (grasa, temperatura, pH…): ver y anotar la capacitación
        Route::get('admin/rechazos',     [Api\AdminRechazosController::class, 'semana']);
        Route::post('admin/rechazos/{id}/capacitado', [Api\AdminRechazosController::class, 'capacitado'])->whereNumber('id');
        Route::get('admin/solicitudes',  [Api\AdminAppController::class, 'solicitudes']);
        Route::post('admin/solicitudes/{id}/resolver', [Api\AdminAppController::class, 'resolverSolicitud']);
        // el productor avisó por teléfono o vino a Huata: el admin registra el cambio directamente
        Route::post('admin/proveedores/{id}/cambiar-comunidad', [Api\AdminAppController::class, 'cambiarComunidad'])->whereNumber('id');
        Route::get('admin/proveedores/{id}/historial-comunidad', [Api\AdminAppController::class, 'historialComunidad'])->whereNumber('id');
        Route::get('admin/proveedores/{id}/traslado',          [Api\AdminAppController::class, 'verTraslado'])->whereNumber('id');
        Route::post('admin/proveedores/{id}/traslado',         [Api\AdminAppController::class, 'editarTraslado'])->whereNumber('id');
        Route::post('admin/proveedores/{id}/traslado/anular',  [Api\AdminAppController::class, 'anularTraslado'])->whereNumber('id');
        Route::get('admin/conciliacion', [Api\AdminAppController::class, 'conciliacion']);
        Route::post('admin/conciliacion', [Api\AdminAppController::class, 'registrarConciliacion']);
        Route::get('admin/costos',       [Api\AdminAppController::class, 'costos']);
        Route::get('admin/mapa',         [Api\MapaController::class, 'ver']);
        Route::post('admin/comunidades/{id}/ubicacion', [Api\MapaController::class, 'ubicar'])->whereNumber('id');
        Route::get('admin/sesiones',     [Api\SesionesController::class, 'index']);
        Route::post('admin/usuarios/{id}/cerrar-sesiones', [Api\SesionesController::class, 'cerrar']);
        Route::post('admin/proveedores',               [Api\AdminPadronController::class, 'nuevoProveedor']);
        Route::post('admin/proveedores/{id}',          [Api\AdminPadronController::class, 'editarProveedor'])->whereNumber('id');
        Route::get('admin/proveedores/{id}/ficha',     [Api\AdminPadronController::class, 'ficha'])->whereNumber('id');
        Route::post('admin/proveedores/{id}/eliminar', [Api\AdminPadronController::class, 'eliminarProveedor'])->whereNumber('id');
        Route::post('admin/proveedores/{id}/baja',     [Api\AdminPadronController::class, 'darDeBaja'])->whereNumber('id');
        Route::post('admin/trabajadores',              [Api\AdminPadronController::class, 'nuevoTrabajador']);
        Route::post('admin/trabajadores/{id}/activo',  [Api\AdminPadronController::class, 'cambiarActivo']);
        Route::post('admin/formulas/{producto}',       [Api\FormulasController::class, 'guardar'])->whereNumber('producto');
        // configuración: empresa, precios, límites de calidad y tolerancias (con historial de cambios)
        Route::get('admin/configuracion',                       [Api\ConfiguracionController::class, 'index']);
        Route::post('admin/configuracion',                      [Api\ConfiguracionController::class, 'guardar']);
        Route::post('admin/configuracion/precio-leche',         [Api\ConfiguracionController::class, 'precioLeche']);
        Route::post('admin/configuracion/precio-leche/{id}/quitar', [Api\ConfiguracionController::class, 'quitarPrecioLeche'])->whereNumber('id');
        Route::post('admin/configuracion/precio-venta',         [Api\ConfiguracionController::class, 'precioVenta']);
        Route::get('admin/configuracion/maestros',              [Api\ConfigMaestrosController::class, 'index']);
        Route::post('admin/configuracion/productos',            [Api\ConfigMaestrosController::class, 'producto']);
        Route::post('admin/sabores',                            [Api\SaboresController::class, 'guardar']);
        Route::post('admin/sabores/{id}',                       [Api\SaboresController::class, 'guardar'])->whereNumber('id');
        Route::post('admin/configuracion/insumos',              [Api\ConfigMaestrosController::class, 'insumo']);
        Route::post('admin/configuracion/vehiculos',            [Api\ConfigMaestrosController::class, 'vehiculo']);
        Route::post('admin/configuracion/vehiculos/{id}/ruta',  [Api\ConfigMaestrosController::class, 'ordenRuta'])->whereNumber('id');
        Route::post('admin/configuracion/comunidades',          [Api\ConfigMaestrosController::class, 'comunidad']);

        // --- Planta (operario) en la app ---
        Route::get('planta/recetas',              [Api\PlantaAppController::class, 'recetasYTanque']);
        Route::get('planta/lotes',                [Api\PlantaAppController::class, 'lotes']);
        Route::post('planta/lotes',               [Api\PlantaAppController::class, 'iniciar']);
        Route::post('planta/lotes/varios',        [Api\PlantaAppController::class, 'iniciarVarios']); // varios productos a la vez
        Route::post('planta/lotes/{id}/cerrar',   [Api\PlantaAppController::class, 'cerrar']);

        // --- Compras y almacén en la app ---
        Route::get('compras/inventario',          [Api\ComprasAppController::class, 'inventario']);
        Route::get('compras/ordenes',             [Api\OrdenesCompraController::class, 'index']);
        Route::post('compras/ordenes',            [Api\OrdenesCompraController::class, 'emitir']);
        Route::post('compras/ordenes/directa',    [Api\OrdenesCompraController::class, 'directa']);
        Route::post('compras/ordenes/{id}/recibir', [Api\OrdenesCompraController::class, 'recibir'])->whereNumber('id');
        Route::post('compras/ordenes/{id}/anular', [Api\OrdenesCompraController::class, 'anular'])->whereNumber('id');
        Route::post('compras/ordenes/{id}/foto',[Api\OrdenesCompraController::class, 'subirFoto'])->whereNumber('id');
        Route::get('compras/ordenes/{id}/foto',   [Api\OrdenesCompraController::class, 'enlaceFoto'])->whereNumber('id');
        Route::get('compras/vendedores',          [Api\VendedoresInsumoController::class, 'index']);
        Route::post('compras/vendedores',         [Api\VendedoresInsumoController::class, 'guardar']);
        Route::post('compras/vendedores/{id}',    [Api\VendedoresInsumoController::class, 'editar'])->whereNumber('id');
        Route::get('compras/ventas-datos',        [Api\ComprasAppController::class, 'datosVenta']);
        Route::post('compras/clientes',           [Api\ComprasAppController::class, 'guardarCliente']);
        Route::post('compras/clientes/{id}',      [Api\ComprasAppController::class, 'guardarCliente'])->whereNumber('id');
        Route::post('compras/ventas',             [Api\ComprasAppController::class, 'vender']);
        Route::get('compras/movimientos',         [Api\ComprasAppController::class, 'movimientos']);
        Route::get('compras/formulas',            [Api\FormulasController::class, 'index']);
        Route::get('compras/kardex',              [Api\KardexController::class, 'ver']);
        Route::get('compras/excel/ventas',        [Api\ExcelController::class, 'ventas']);
        Route::get('compras/excel/compras',       [Api\ExcelController::class, 'compras']);
        Route::post('compras/ajustes',            [Api\KardexController::class, 'ajustar']);
        Route::get('compras/mermas',              [Api\MermasController::class, 'index']);
        Route::post('compras/mermas',             [Api\MermasController::class, 'guardar']);
        // el operario también registra la leche que se corta y lo que se malogra en planta
        Route::get('planta/mermas',               [Api\MermasController::class, 'index']);
        Route::post('planta/mermas',              [Api\MermasController::class, 'guardar']);
        Route::get('compras/ventas/{id}/nota',    [Api\NotaVentaController::class, 'enlace'])->whereNumber('id');
        Route::post('compras/ventas/{id}/enviar-nota', [Api\NotaVentaController::class, 'enviar'])->whereNumber('id');

        // --- Calidad ---
        Route::get('calidad/cola',        [Api\CalidadController::class, 'cola']);
        Route::get('calidad/parametros',  [Api\CalidadController::class, 'parametros']);
        Route::post('calidad/analisis',   [Api\CalidadController::class, 'registrar']);
        Route::post('calidad/lactoscan',  [Api\CalidadController::class, 'importarLactoscan']);
        Route::post('calidad/analisis/{analisis}/resolver', [Api\CalidadController::class, 'resolver']);
        Route::get('calidad/entregas/{entrega}/brecha',    [Api\CalidadController::class, 'brecha']);
        Route::get('calidad/infractores', [Api\CalidadController::class, 'infractores']);
        Route::get('calidad/pendientes',  [Api\CalidadAppController::class, 'pendientes']);
        Route::get('calidad/muestreo',    [Api\CalidadMuestreoController::class, 'entregas']);
        Route::get('calidad/historial',   [Api\CalidadAppController::class, 'historial']);
        Route::get('calidad/excel/analisis', [Api\ExcelController::class, 'analisis']);

        /*
         * PENDIENTE: estas rutas apuntan a controladores que todavía no
         * existen. Descomentar cada bloque cuando se cree su controlador.
         *
         * // --- Acopio ---
         * Route::get('rutas/{vehiculo}/hoy',    [Api\AcopioController::class, 'ruta']);
         * Route::get('rutas/{vehiculo}/cierre', [Api\AcopioController::class, 'cierre']);
         * Route::apiResource('entregas', Api\EntregaController::class)->only(['index', 'store', 'show']);
         * Route::post('entregas/{entrega}/ausente',   [Api\EntregaController::class, 'ausente']);
         * Route::post('entregas/{entrega}/confirmar', [Api\EntregaController::class, 'confirmar']);
         *
         * // --- Proveedores ---
         * Route::apiResource('proveedores', Api\ProveedorController::class);
         * Route::post('proveedores/{proveedor}/ubicacion', [Api\ProveedorController::class, 'moverUbicacion']);
         * Route::get('proveedores/{proveedor}/historial',   [Api\ProveedorController::class, 'historial']);
         *
         * // --- Producción ---
         * Route::get('produccion/disponible', [Api\LoteController::class, 'disponible']);
         * Route::apiResource('lotes', Api\LoteController::class)->only(['index', 'store', 'show']);
         * Route::post('lotes/{lote}/cerrar',      [Api\LoteController::class, 'cerrar']);
         * Route::get('lotes/{lote}/trazabilidad', [Api\LoteController::class, 'trazabilidad']);
         *
         * // --- Almacén y ventas ---
         * Route::apiResource('insumos', Api\InsumoController::class)->only(['index', 'update']);
         * Route::apiResource('ordenes-compra', Api\OrdenCompraController::class)->only(['index', 'store']);
         * Route::apiResource('ventas', Api\VentaController::class)->only(['index', 'store']);
         * Route::get('clientes', [Api\VentaController::class, 'clientes']);
         *
         * // --- Pagos ---
         * Route::get('pagos', [Api\PagoController::class, 'index']);
         * Route::get('pagos/{proveedor}/{semana}', [Api\PagoController::class, 'boleta']);
         * Route::post('pagos/generar-semana',      [Api\PagoController::class, 'generarSemana']);
         * Route::post('pagos/{pago}/marcar-pagado', [Api\PagoController::class, 'marcarPagado']);
         *
         * // --- Solicitudes de cambio de zona ---
         * Route::apiResource('solicitudes-zona', Api\SolicitudZonaController::class)->only(['index', 'store']);
         * Route::post('solicitudes-zona/{solicitud}/resolver', [Api\SolicitudZonaController::class, 'resolver']);
         *
         * // --- Conciliación campo vs. planta (Fase 2 con caudalímetro) ---
         * Route::get('conciliacion',  [Api\ConciliacionController::class, 'index']);
         * Route::post('conciliacion', [Api\ConciliacionController::class, 'registrar']);
         *
         * // --- Tablero del administrador ---
         * Route::get('tablero/totales', [Api\TableroController::class, 'totales']);
         * Route::get('tablero/calidad', [Api\TableroController::class, 'calidad']);
         * Route::get('tablero/costos',  [Api\TableroController::class, 'costos']);
         */
    });
});
