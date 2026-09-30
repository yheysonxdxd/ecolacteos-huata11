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
        Route::get('admin/padron',       [Api\AdminAppController::class, 'padron']);
        Route::get('admin/calidad',      [Api\AdminAppController::class, 'calidad']);
        Route::get('admin/solicitudes',  [Api\AdminAppController::class, 'solicitudes']);
        Route::post('admin/solicitudes/{id}/resolver', [Api\AdminAppController::class, 'resolverSolicitud']);
        Route::get('admin/conciliacion', [Api\AdminAppController::class, 'conciliacion']);
        Route::get('admin/costos',       [Api\AdminAppController::class, 'costos']);
        Route::get('admin/sesiones',     [Api\SesionesController::class, 'index']);
        Route::post('admin/usuarios/{id}/cerrar-sesiones', [Api\SesionesController::class, 'cerrar']);

        // --- Planta (operario) en la app ---
        Route::get('planta/recetas',              [Api\PlantaAppController::class, 'recetasYTanque']);
        Route::get('planta/lotes',                [Api\PlantaAppController::class, 'lotes']);
        Route::post('planta/lotes',               [Api\PlantaAppController::class, 'iniciar']);
        Route::post('planta/lotes/{id}/cerrar',   [Api\PlantaAppController::class, 'cerrar']);

        // --- Compras y almacén en la app ---
        Route::get('compras/inventario',          [Api\ComprasAppController::class, 'inventario']);
        Route::get('compras/ordenes',             [Api\ComprasAppController::class, 'ordenes']);
        Route::post('compras/ordenes',            [Api\ComprasAppController::class, 'emitir']);
        Route::post('compras/ordenes/{id}/recibir', [Api\ComprasAppController::class, 'recibir']);
        Route::get('compras/ventas-datos',        [Api\ComprasAppController::class, 'datosVenta']);
        Route::post('compras/ventas',             [Api\ComprasAppController::class, 'vender']);
        Route::get('compras/movimientos',         [Api\ComprasAppController::class, 'movimientos']);

        // --- Calidad ---
        Route::get('calidad/cola',        [Api\CalidadController::class, 'cola']);
        Route::get('calidad/parametros',  [Api\CalidadController::class, 'parametros']);
        Route::post('calidad/analisis',   [Api\CalidadController::class, 'registrar']);
        Route::post('calidad/lactoscan',  [Api\CalidadController::class, 'importarLactoscan']);
        Route::post('calidad/analisis/{analisis}/resolver', [Api\CalidadController::class, 'resolver']);
        Route::get('calidad/entregas/{entrega}/brecha',    [Api\CalidadController::class, 'brecha']);
        Route::get('calidad/infractores', [Api\CalidadController::class, 'infractores']);
        Route::get('calidad/pendientes',  [Api\CalidadAppController::class, 'pendientes']);
        Route::get('calidad/historial',   [Api\CalidadAppController::class, 'historial']);

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
