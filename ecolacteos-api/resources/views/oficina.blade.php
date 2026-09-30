<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ecolácteos Huata · Oficina</title>
<link rel="stylesheet" href="/oficina-web/estilos.css">
<script src="/oficina-web/app.js"></script>
<script defer src="/oficina-web/vendor/alpine.min.js"></script>
</head>
<body x-data="oficina()" x-init="iniciar()">

{{-- ============================================================ LOGIN --}}
<div class="login" x-show="!usuario" x-cloak>
    <form @submit.prevent="entrar()">
        <div class="marca">Ecolácteos <em>Huata</em></div>
        <div class="marca-sub">OFICINA · ADMINISTRACIÓN Y COMPRAS</div>
        <label>DNI</label>
        <input x-model="login.dni" inputmode="numeric" maxlength="8" autocomplete="username" autofocus
               @input="login.dni = login.dni.replace(/\D/g, '')">
        <label>Contraseña</label>
        <div class="fila">
            <input :type="login.ver ? 'text' : 'password'" x-model="login.clave" autocomplete="current-password" style="flex:1">
            <button type="button" class="btn gris" @click="login.ver = !login.ver" x-text="login.ver ? 'Ocultar' : 'Ver'"></button>
        </div>
        <div class="aviso error" x-show="login.error" x-text="login.error"></div>
        <button class="btn ancho" :disabled="login.enviando" x-text="login.enviando ? 'Ingresando…' : 'Ingresar'"></button>
    </form>
</div>

{{-- ============================================================ APP --}}
<div class="app" x-show="usuario" x-cloak>
    <nav class="menu">
        <div class="marca">Ecolácteos <em>Huata</em></div>
        <div class="rol" x-text="(usuario?.nombre || '') + ' · ' + (ROLES[usuario?.rol] || '')"></div>
        <template x-for="(m, i) in menu()" :key="m.id">
            <div>
                <div class="grupo" x-show="i === 0 || menu()[i - 1].grupo !== m.grupo" x-text="m.grupo"></div>
                <button :class="{ activo: seccion === m.id }" @click="ir(m.id)" x-text="m.nombre"></button>
            </div>
        </template>
        <button class="salir" @click="salir()">Cerrar sesión</button>
    </nav>

    <main>
        <div class="aviso" :class="aviso?.error ? 'error' : 'bien'" x-show="aviso">
            <span x-text="aviso?.texto"></span>
            <template x-if="aviso?.nota">
                <span> · <a href="#" @click.prevent="verNota(aviso.nota.id)">Ver / imprimir nota en PDF</a>
                    · <a href="#" @click.prevent="abrirCorreo(aviso.nota.id, aviso.nota.numero)">Enviar por correo</a></span>
            </template>
        </div>
        <div class="aviso error" x-show="error" x-text="'No se pudo leer la base de datos: ' + error"></div>
        <div class="cargando" x-show="cargando">Cargando…</div>

        {{-- ---------------------------------------------------- TOTALES --}}
        <section x-show="seccion === 'totales' && !cargando && !error">
            <h1>Totales de acopio</h1>
            <p class="sub" x-text="d.desde === d.hasta ? fechaCorta(d.desde) : fechaCorta(d.desde) + ' – ' + fechaCorta(d.hasta)"></p>
            <div class="pestanas">
                <template x-for="p in [['dia','Día'],['semana','Semana'],['mes','Mes']]">
                    <button :class="{ activo: periodo === p[0] }" @click="cambiar('periodo', p[0])" x-text="p[1]"></button>
                </template>
            </div>
            <div class="tarjetas">
                <div class="tarjeta destacada"><div class="eti">Litros acopiados</div><div class="valor" x-text="num(d.litros, 0) + ' L'"></div>
                    <div class="apoyo" x-text="soles(d.a_pagar) + ' a pagar a proveedores'"></div></div>
                <div class="tarjeta"><div class="eti">Aceptados</div><div class="valor ok" x-text="num(d.aceptados_pct) + ' %'"></div></div>
                <div class="tarjeta"><div class="eti">Rechazados</div><div class="valor mal" x-text="num(d.rechazados_pct) + ' %'"></div></div>
                <div class="tarjeta"><div class="eti">Adulterada</div><div class="valor mal" x-text="num(d.adulterada_pct) + ' %'"></div></div>
            </div>
            <h2>Por vehículo</h2>
            <table class="lista">
                <tr><th>Vehículo</th><th>Acopiador</th><th>Ruta</th><th class="num">Litros</th><th style="width:28%"></th></tr>
                <template x-for="v in d.vehiculos || []" :key="v.codigo">
                    <tr><td x-text="v.codigo"></td><td x-text="v.acopiador || 'sin acopiador'"></td><td x-text="v.ruta"></td>
                        <td class="num" x-text="num(v.litros, 0) + ' L'"></td>
                        <td><div class="barra"><span :style="'width:' + (v.litros / maximo(d.vehiculos, 'litros') * 100) + '%'"></span></div></td></tr>
                </template>
            </table>
        </section>

        {{-- ---------------------------------------------------- DINERO --}}
        <section x-show="seccion === 'dinero' && !cargando && !error">
            <h1>Dinero</h1>
            <p class="sub">Lo que entra por ventas y lo que sale en pagos a proveedores.</p>
            <div class="pestanas">
                <button :class="{ activo: dinero === 'ventas' }" @click="cambiar('dinero', 'ventas')">Ventas, producción e inventario</button>
                <button :class="{ activo: dinero === 'pagos' }" @click="cambiar('dinero', 'pagos')">Pagos a proveedores</button>
            </div>

            {{-- ventas --}}
            <div x-show="dinero === 'ventas'">
                <div class="pestanas">
                    <template x-for="p in [['dia','Hoy'],['semana','Semana'],['mes','Mes']]">
                        <button :class="{ activo: periodo === p[0] }" @click="cambiar('periodo', p[0])" x-text="p[1]"></button>
                    </template>
                </div>
                <template x-if="d.ventas">
                    <div>
                        <div class="tarjetas">
                            <div class="tarjeta destacada"><div class="eti">Ventas</div><div class="valor" x-text="soles(d.ventas.total)"></div>
                                <div class="apoyo" x-text="d.ventas.cantidad + ' ventas'"></div>
                                <div class="barras" x-show="d.ventas.barras.length">
                                    <template x-for="b in d.ventas.barras"><div><i :style="'height:' + (b.total / maximo(d.ventas.barras, 'total') * 90) + 'px'"></i><span x-text="b.etiqueta"></span></div></template>
                                </div></div>
                            <div class="tarjeta"><div class="eti">Mayoristas</div><div class="valor" x-text="soles(d.ventas.por_tipo.MAYORISTA)"></div></div>
                            <div class="tarjeta"><div class="eti">Vecinos de Huata</div><div class="valor" x-text="soles(d.ventas.por_tipo.DIRECTO)"></div></div>
                            <div class="tarjeta"><div class="eti">Proveedores</div><div class="valor" x-text="soles(d.ventas.por_tipo.PLANTA)"></div></div>
                        </div>
                        <h2>Por producto</h2>
                        <table class="lista"><tr><th>Producto</th><th class="num">Cantidad</th><th class="num">Total</th></tr>
                            <template x-for="p in d.ventas.por_producto"><tr><td x-text="p.nombre"></td><td class="num" x-text="num(p.cantidad, 0) + ' ' + p.unidad"></td><td class="num" x-text="soles(p.total)"></td></tr></template>
                            <tr x-show="!d.ventas.por_producto.length"><td colspan="3">No hubo ventas en este periodo.</td></tr>
                        </table>
                        <h2>Últimas ventas</h2>
                        <table class="lista"><tr><th>Fecha</th><th>Cliente</th><th>Producto</th><th>Atendió</th><th class="num">Total</th><th></th></tr>
                            <template x-for="u in d.ventas.ultimas" :key="u.id"><tr>
                                <td x-text="fechaCorta(u.vendida_en) + ' ' + hora(u.vendida_en)"></td><td x-text="u.cliente"></td>
                                <td x-text="num(u.cantidad, 0) + ' · ' + u.producto"></td><td x-text="u.vendedor || ''"></td>
                                <td class="num" x-text="soles(u.total)"></td>
                                <td class="num"><button class="btn chico gris" @click="verNota(u.id)">Nota PDF</button></td></tr></template>
                        </table>
                        <h2>Producción</h2>
                        <div class="tarjetas">
                            <div class="tarjeta"><div class="eti">Lotes</div><div class="valor" x-text="d.produccion.lotes"></div><div class="apoyo" x-show="d.produccion.en_curso" x-text="d.produccion.en_curso + ' en proceso'"></div></div>
                            <div class="tarjeta"><div class="eti">Leche usada</div><div class="valor" x-text="num(d.produccion.litros, 0) + ' L'"></div></div>
                            <div class="tarjeta"><div class="eti">Fuera de rango</div><div class="valor" :class="d.produccion.fuera_referencia ? 'ojo' : ''" x-text="d.produccion.fuera_referencia"></div></div>
                        </div>
                        <h2>Inventario de hoy</h2>
                        <table class="lista"><tr><th>Producto</th><th class="num">Stock</th><th></th></tr>
                            <template x-for="p in d.inventario.productos"><tr><td x-text="p.nombre"></td><td class="num" x-text="num(p.stock, 0) + ' ' + p.unidad"></td>
                                <td><span class="chip ojo" x-show="p.lotes_por_vencer" x-text="p.lotes_por_vencer + ' lote(s) vencen esta semana'"></span></td></tr></template>
                        </table>
                        <template x-for="i in d.inventario.insumos_bajo_minimo"><div class="aviso error" x-text="i.nombre + ' bajo el mínimo: quedan ' + num(i.stock, 2) + ' ' + i.unidad + ' (mínimo ' + num(i.stock_minimo, 2) + ')'"></div></template>
                        <div class="aviso" x-show="d.inventario.ordenes_por_recibir" x-text="d.inventario.ordenes_por_recibir + ' órdenes de compra por recibir · ' + soles(d.inventario.ordenes_monto)"></div>
                    </div>
                </template>
            </div>

            {{-- pagos --}}
            <div x-show="dinero === 'pagos'">
                <template x-if="d.boletas">
                    <div>
                        <div class="opciones" style="margin-bottom: 14px">
                            <template x-for="s in d.semanas" :key="s.inicio">
                                <button :class="{ activo: s.inicio === d.inicio }" @click="cambiar('semana', s.inicio)"
                                        x-text="fechaCorta(s.inicio) + ' – ' + fechaCorta(s.fin) + (s.pendientes ? ' · ' + s.pendientes : ' ✓')"></button>
                            </template>
                        </div>
                        <div class="tarjetas">
                            <div class="tarjeta destacada"><div class="eti">Por pagar</div><div class="valor" x-text="soles(d.pendiente)"></div>
                                <div class="apoyo" x-text="'Semana ' + fechaCorta(d.inicio) + ' – ' + fechaCorta(d.fin)"></div></div>
                            <div class="tarjeta"><div class="eti">Total de la semana</div><div class="valor" x-text="soles(d.total)"></div></div>
                            <div class="tarjeta"><div class="eti">Ya pagado</div><div class="valor ok" x-text="soles(d.pagado)"></div></div>
                        </div>
                        <div class="fila" style="margin: 16px 0">
                            <button class="btn" x-show="d.pendientes" @click="pagar(d.boletas.filter(b => b.estado === 'PENDIENTE').map(b => b.id), 'Pagar a los ' + d.pendientes + ' pendientes (' + soles(d.pendiente) + ')')"
                                    x-text="'Pagar a los ' + d.pendientes + ' pendientes (' + soles(d.pendiente) + ')'"></button>
                            <span class="chip ok" x-show="!d.pendientes && d.boletas.length">Semana pagada completa</span>
                        </div>
                        <table class="lista"><tr><th>Proveedor</th><th>Comunidad</th><th class="num">Litros</th><th class="num">Monto</th><th>Estado</th><th></th></tr>
                            <template x-for="b in d.boletas" :key="b.id"><tr class="clic" @click="abrirBoleta(b)">
                                <td x-text="b.proveedor"></td><td x-text="b.comunidad + ' · ' + b.vehiculo"></td>
                                <td class="num" x-text="num(b.litros) + ' L'"></td><td class="num" x-text="soles(b.monto)"></td>
                                <td><span class="chip" :class="b.estado === 'PAGADO' ? 'ok' : 'ojo'"
                                          x-text="b.estado === 'PAGADO' ? 'Pagado ' + fechaCorta(b.pagado_el) + ' · ' + (b.metodo === 'YAPE' ? 'Yape' : 'Efectivo') : 'Pendiente'"></span></td>
                                <td class="num"><button class="btn chico" x-show="b.estado === 'PENDIENTE'" @click.stop="pagar([b.id], 'Pagar a ' + b.proveedor + ' (' + soles(b.monto) + ')')">Pagar</button></td></tr></template>
                            <tr x-show="!d.boletas.length"><td colspan="6">No hay nada que pagar en esta semana.</td></tr>
                        </table>
                    </div>
                </template>
            </div>
        </section>

        {{-- ---------------------------------------------------- PADRÓN --}}
        <section x-show="seccion === 'padron' && !cargando && !error">
            <h1>Padrón</h1>
            <div class="fila" style="margin-bottom: 14px">
                <div class="pestanas" style="margin: 0">
                    <button :class="{ activo: padron === 'proveedores' }" @click="padron = 'proveedores'" x-text="'Proveedores · ' + (d.proveedores || []).length"></button>
                    <button :class="{ activo: padron === 'trabajadores' }" @click="padron = 'trabajadores'" x-text="'Trabajadores · ' + (d.trabajadores || []).length"></button>
                </div>
                <div class="espacio"></div>
                <button class="btn" x-show="padron === 'proveedores'" @click="abrirNuevoProveedor()">+ Nuevo proveedor</button>
                <button class="btn" x-show="padron === 'trabajadores'" @click="abrirNuevoTrabajador()">+ Nuevo trabajador</button>
            </div>
            <div x-show="padron === 'proveedores'">
                <input x-model="buscar" placeholder="Buscar por nombre, DNI, comunidad o vehículo" style="margin-bottom: 12px">
                <table class="lista"><tr><th>Nombre</th><th>DNI</th><th>Comunidad</th><th>Vehículo</th><th class="num">Promedio</th><th>Estado</th></tr>
                    <template x-for="p in proveedoresFiltrados()" :key="p.id"><tr>
                        <td x-text="p.nombre"></td><td x-text="p.dni"></td><td x-text="p.comunidad"></td><td x-text="p.vehiculo"></td>
                        <td class="num" x-text="p.promedio ? num(p.promedio) + ' L' : '—'"></td>
                        <td><span class="chip" :class="p.estado === 'ACTIVO' ? 'ok' : 'mal'" x-text="p.estado === 'ACTIVO' ? 'Activo' : p.estado" :title="p.motivo_baja || ''"></span></td></tr></template>
                </table>
            </div>
            <div x-show="padron === 'trabajadores'">
                <table class="lista"><tr><th>Nombre</th><th>DNI</th><th>Rol</th><th>Estado</th><th></th></tr>
                    <template x-for="t in d.trabajadores || []" :key="t.id"><tr>
                        <td x-text="t.nombre"></td><td x-text="t.dni"></td><td x-text="ROLES[t.rol] || t.rol"></td>
                        <td><span class="chip" :class="t.activo ? 'ok' : 'mal'" x-text="t.activo ? 'Activo' : 'Desactivado'"></span></td>
                        <td class="num"><button class="btn chico" :class="t.activo ? 'rojo' : ''" @click="cambiarActivo(t)" x-text="t.activo ? 'Desactivar' : 'Activar'"></button></td></tr></template>
                </table>
            </div>
        </section>

        {{-- ---------------------------------------------------- CALIDAD --}}
        <section x-show="seccion === 'calidad' && !cargando && !error">
            <h1>Calidad por vehículo</h1>
            <p class="sub">Promedios de los últimos 30 días.</p>
            <table class="lista"><tr><th>Vehículo</th><th>Acopiador</th><th class="num">Análisis</th><th class="num">Grasa</th><th class="num">Densidad</th><th class="num">Temperatura</th><th class="num">Rechazo</th><th></th></tr>
                <template x-for="v in d.vehiculos || []" :key="v.codigo"><tr>
                    <td x-text="v.codigo"></td><td x-text="v.acopiador || 'sin acopiador'"></td>
                    <td class="num" x-text="v.analisis"></td>
                    <td class="num" x-text="v.analisis ? num(v.grasa, 2) + ' %' : '—'"></td>
                    <td class="num" x-text="v.analisis ? Number(v.densidad).toFixed(4) : '—'"></td>
                    <td class="num" x-text="v.analisis ? num(v.temperatura) + ' °C' : '—'"></td>
                    <td class="num" x-text="num(v.rechazo_pct) + ' %'"></td>
                    <td><span class="chip mal" x-show="v.adulteradas" x-text="v.adulteradas + ' adulterada(s)'"></span></td></tr></template>
            </table>
        </section>

        {{-- ---------------------------------------------------- CAMBIOS DE ZONA --}}
        <section x-show="seccion === 'solicitudes' && !cargando && !error">
            <h1>Cambios de zona</h1>
            <p class="sub">Al aprobar, el proveedor pasa a la comunidad destino y al vehículo que la recorre.</p>
            <table class="lista"><tr><th>Proveedor</th><th>Cambio</th><th>Motivo</th><th>Para el</th><th>Estado</th><th></th></tr>
                <template x-for="s in d.solicitudes || []" :key="s.id"><tr>
                    <td x-text="s.proveedor"></td><td x-text="s.origen + ' → ' + s.destino"></td><td x-text="s.motivo"></td>
                    <td x-text="fechaCorta(s.solicitada_para)"></td>
                    <td><span class="chip" :class="s.estado === 'APROBADA' ? 'ok' : (s.estado === 'PENDIENTE' ? 'ojo' : 'mal')"
                              x-text="s.estado === 'APROBADA' ? 'Aprobada · desde ' + fechaCorta(s.vigente_desde) : (s.estado === 'PENDIENTE' ? 'Pendiente' : 'Rechazada')"></span></td>
                    <td class="num"><span x-show="s.estado === 'PENDIENTE'">
                        <button class="btn chico" @click="resolverSolicitud(s, true)">Aprobar</button>
                        <button class="btn chico rojo" @click="resolverSolicitud(s, false)">Rechazar</button></span></td></tr></template>
                <tr x-show="!(d.solicitudes || []).length"><td colspan="6">No hay solicitudes de cambio de zona.</td></tr>
            </table>
        </section>

        {{-- ---------------------------------------------------- CONCILIACIÓN --}}
        <section x-show="seccion === 'conciliacion' && !cargando && !error">
            <h1>Conciliación campo vs. planta</h1>
            <p class="sub" x-text="d.fecha ? 'Último día registrado: ' + fechaCorta(d.fecha) + ' · tolerancia ±' + num(d.tolerancia) + ' %' : 'Todavía no hay recepciones registradas en planta.'"></p>
            <table class="lista" x-show="d.fecha"><tr><th>Vehículo</th><th class="num">Litros en campo</th><th class="num">Litros en planta</th><th class="num">Diferencia</th><th></th></tr>
                <template x-for="v in d.vehiculos || []" :key="v.codigo"><tr>
                    <td x-text="v.codigo"></td><td class="num" x-text="num(v.litros_campo) + ' L'"></td><td class="num" x-text="num(v.litros_planta) + ' L'"></td>
                    <td class="num" :class="v.fuera_tolerancia ? 'mal' : ''" x-text="num(v.diferencia_pct, 2) + ' %'"></td>
                    <td><span class="chip" :class="v.fuera_tolerancia ? 'mal' : 'ok'" x-text="v.fuera_tolerancia ? 'Fuera de tolerancia' : 'Dentro'"></span></td></tr></template>
            </table>
        </section>

        {{-- ---------------------------------------------------- COSTOS --}}
        <section x-show="seccion === 'costos' && !cargando && !error">
            <h1>Costos y rendimiento</h1>
            <p class="sub" x-text="'Desde el ' + fechaCorta(d.desde) + ' (últimos 30 días).'"></p>
            <div class="tarjetas">
                <div class="tarjeta destacada"><div class="eti">Costo por litro procesado</div>
                    <div class="valor" x-text="'S/ ' + (Number(d.costo_leche_litro || 0) + Number(d.costo_insumos_litro || 0)).toFixed(3)"></div>
                    <div class="apoyo" x-text="'Leche S/ ' + Number(d.costo_leche_litro || 0).toFixed(2) + ' + insumos S/ ' + Number(d.costo_insumos_litro || 0).toFixed(3)"></div></div>
                <div class="tarjeta"><div class="eti">Leche procesada</div><div class="valor" x-text="num(d.litros_procesados, 0) + ' L'"></div></div>
                <div class="tarjeta"><div class="eti">Ventas</div><div class="valor" x-text="soles(d.ventas_total)"></div><div class="apoyo" x-text="(d.ventas_cantidad || 0) + ' ventas'"></div></div>
            </div>
            <h2>Rendimiento por cada 100 L de leche</h2>
            <table class="lista"><tr><th>Producto</th><th class="num">Lotes</th><th class="num">Esperado</th><th class="num">Real</th></tr>
                <template x-for="r in d.rendimiento || []" :key="r.producto"><tr><td x-text="r.producto"></td><td class="num" x-text="r.lotes"></td>
                    <td class="num" x-text="num(r.esperado_100l)"></td>
                    <td class="num" :class="r.real_100l < r.esperado_100l ? 'ojo' : 'ok'" x-text="num(r.real_100l)"></td></tr></template>
                <tr x-show="!(d.rendimiento || []).length"><td colspan="4">No hay lotes terminados en este periodo.</td></tr>
            </table>
            <h2>Precios de venta vigentes</h2>
            <table class="lista"><tr><th>Producto</th><th>Tipo de cliente</th><th class="num">Precio</th></tr>
                <template x-for="p in d.precios || []"><tr><td x-text="p.producto"></td><td x-text="TIPO_CLIENTE[p.tipo_cliente] || p.tipo_cliente"></td><td class="num" x-text="soles(p.precio)"></td></tr></template>
            </table>
        </section>

        {{-- ---------------------------------------------------- SESIONES --}}
        <section x-show="seccion === 'sesiones' && !cargando && !error">
            <h1>Sesiones abiertas</h1>
            <p class="sub">Quién tiene la app o esta web abierta. Cierra las sesiones de quien perdió el celular o dejó de trabajar.</p>
            <table class="lista"><tr><th>Nombre</th><th>Rol</th><th class="num">Sesiones</th><th>Último uso</th><th></th></tr>
                <template x-for="u in d.usuarios || []" :key="u.id"><tr>
                    <td x-text="u.nombre"></td><td x-text="ROLES[u.rol] || u.rol"></td><td class="num" x-text="u.sesiones"></td>
                    <td x-text="fechaCorta(u.ultimo_uso) + ' ' + hora(u.ultimo_uso)"></td>
                    <td class="num"><button class="btn chico rojo" @click="cerrarSesiones(u)">Cerrar sesiones</button></td></tr></template>
                <tr x-show="!(d.usuarios || []).length"><td colspan="5">Nadie tiene sesiones abiertas.</td></tr>
            </table>
        </section>

        {{-- ---------------------------------------------------- VENDER --}}
        <section x-show="seccion === 'vender' && !cargando && !error">
            <h1>Vender</h1>
            <p class="sub">El precio sale solo según el tipo de cliente.</p>
            <div class="dos">
                <div>
                    <label>Producto</label>
                    <div class="opciones">
                        <template x-for="p in d.productos || []" :key="p.id">
                            <button :class="{ activo: f.producto === p.id }" @click="f.producto = p.id"
                                    x-text="p.nombre + ' · stock ' + num(p.stock, 0) + ' ' + p.unidad"></button>
                        </template>
                    </div>
                    <label>Cliente</label>
                    <table class="lista">
                        <template x-for="c in d.clientes || []" :key="c.id"><tr class="clic" @click="elegirCliente(c)" :style="f.cliente === c.id ? 'outline: 2px solid var(--verde)' : ''">
                            <td x-text="c.nombre"></td><td x-text="TIPO_CLIENTE[c.tipo] || c.tipo"></td>
                            <td class="num" x-text="productoVenta() ? soles(productoVenta().precios?.[c.tipo]) : ''"></td></tr></template>
                    </table>
                </div>
                <div>
                    <div class="tarjeta destacada">
                        <div class="eti">Total</div>
                        <div class="valor" x-text="soles(precioVenta() * (Number(f.cantidad) || 0))"></div>
                        <div class="apoyo" x-text="num(f.cantidad, 2) + ' × ' + soles(precioVenta()) + (productoVenta() ? ' · ' + productoVenta().unidad : '')"></div>
                        <label>Cantidad</label>
                        <input type="number" min="0" step="1" x-model.number="f.cantidad">
                        <label>Enviar la nota al correo (opcional)</label>
                        <input type="email" x-model="f.correo" placeholder="cliente@gmail.com">
                        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
                        <button class="btn ancho" @click="registrarVenta()" :disabled="f.enviando" x-text="f.enviando ? 'Registrando…' : 'Registrar venta'"></button>
                    </div>
                </div>
            </div>
        </section>

        {{-- ---------------------------------------------------- INVENTARIO --}}
        <section x-show="seccion === 'inventario' && !cargando && !error">
            <h1>Inventario</h1>
            <h2>Productos terminados</h2>
            <table class="lista"><tr><th>Producto</th><th class="num">Stock</th><th class="num">Mayorista</th><th class="num">Vecino</th><th class="num">Proveedor</th></tr>
                <template x-for="p in d.productos || []" :key="p.id"><tr><td x-text="p.nombre"></td><td class="num" x-text="num(p.stock, 0) + ' ' + p.unidad"></td>
                    <td class="num" x-text="soles(p.precios?.MAYORISTA)"></td><td class="num" x-text="soles(p.precios?.DIRECTO)"></td><td class="num" x-text="soles(p.precios?.PLANTA)"></td></tr></template>
            </table>
            <h2>Insumos</h2>
            <table class="lista"><tr><th>Insumo</th><th class="num">Stock</th><th class="num">Mínimo</th><th class="num">Costo unit.</th><th></th></tr>
                <template x-for="i in d.insumos || []" :key="i.id"><tr><td x-text="i.nombre"></td>
                    <td class="num" :class="i.stock < i.minimo ? 'mal' : ''" x-text="num(i.stock, 3) + ' ' + i.unidad"></td>
                    <td class="num" x-text="num(i.minimo, 3)"></td><td class="num" x-text="soles(i.costo)"></td>
                    <td><span class="chip mal" x-show="i.stock < i.minimo">Reponer</span></td></tr></template>
            </table>
        </section>

        {{-- ---------------------------------------------------- ÓRDENES --}}
        <section x-show="seccion === 'ordenes' && !cargando && !error">
            <div class="fila"><h1>Órdenes de compra</h1><div class="espacio"></div><button class="btn" @click="abrirOrden()">+ Nueva orden</button></div>
            <table class="lista" style="margin-top: 14px"><tr><th>Código</th><th>Insumo</th><th class="num">Cantidad</th><th>Proveedor</th><th class="num">Costo est.</th><th>Estado</th><th></th></tr>
                <template x-for="o in d.ordenes || []" :key="o.id"><tr>
                    <td x-text="o.codigo"></td><td x-text="o.insumo"></td><td class="num" x-text="num(o.cantidad, 3) + ' ' + o.unidad"></td>
                    <td x-text="o.proveedor_comercial"></td><td class="num" x-text="soles(o.costo_estimado)"></td>
                    <td><span class="chip" :class="o.estado === 'RECIBIDA' ? 'ok' : (o.estado === 'EMITIDA' ? 'ojo' : 'mal')" x-text="o.estado.toLowerCase()"></span></td>
                    <td class="num"><button class="btn chico" x-show="o.estado === 'EMITIDA'" @click="recibirOrden(o)">Llegó</button></td></tr></template>
            </table>
        </section>

        {{-- ---------------------------------------------------- MOVIMIENTOS --}}
        <section x-show="seccion === 'movimientos' && !cargando && !error">
            <h1>Movimientos</h1>
            <p class="sub">Entradas y salidas de insumos, y ventas. En las ventas puedes ver o reenviar su nota.</p>
            <table class="lista"><tr><th>Fecha</th><th>Qué</th><th>Detalle</th><th class="num">Cantidad</th><th></th></tr>
                <template x-for="(m, k) in d.movimientos || []" :key="k"><tr>
                    <td x-text="fechaCorta(m.fecha) + ' ' + hora(m.fecha)"></td><td x-text="m.que"></td><td x-text="m.ref"></td>
                    <td class="num" :class="m.entrada ? 'ok' : ''" x-text="m.delta"></td>
                    <td class="num"><template x-if="m.venta_id"><span>
                        <button class="btn chico gris" @click="verNota(m.venta_id)" x-text="m.numero"></button>
                        <button class="btn chico gris" @click="abrirCorreo(m.venta_id, m.numero)">Correo</button></span></template></td></tr></template>
            </table>
        </section>
    </main>
</div>

{{-- ============================================================ VENTANAS --}}
<div class="velo" x-show="ventana" x-cloak @keydown.escape.window="ventana = null" @click.self="ventana = null">

    {{-- nuevo proveedor --}}
    <div class="ventana" x-show="ventana === 'proveedor'">
        <h3>Nuevo proveedor</h3>
        <label>Nombre y apellidos</label><input x-model="f.nombre">
        <label>DNI</label><input x-model="f.dni" maxlength="8" @input="f.dni = f.dni.replace(/\D/g, '')">
        <label>Comunidad</label>
        <div class="opciones"><template x-for="c in (f.comunidades || []).slice().sort((a, b) => a.nombre.localeCompare(b.nombre))" :key="c.id">
            <button :class="{ activo: f.comunidad_id === c.id }" @click="elegirComunidad(c)" x-text="c.nombre"></button></template></div>
        <label>Vehículo (se elige solo según la comunidad)</label>
        <div class="opciones"><template x-for="v in f.vehiculos || []" :key="v.id">
            <button :class="{ activo: f.vehiculo_id === v.id }" @click="f.vehiculo_id = v.id" x-text="v.codigo"></button></template></div>
        <div class="dos">
            <div><label>Litros por día (opcional)</label><input type="number" min="0" step="0.5" x-model="f.promedio"></div>
            <div><label>Vacas (opcional)</label><input type="number" min="0" step="1" x-model="f.vacas"></div>
        </div>
        <label style="display:flex; gap:8px; align-items:center"><input type="checkbox" x-model="f.con_acceso" style="width:auto"> Darle acceso a la app (para ver sus entregas y pagos)</label>
        <div x-show="f.con_acceso"><label>Clave inicial (mínimo 6)</label><input x-model="f.clave"></div>
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cancelar</button>
            <button class="btn" @click="guardarProveedor()" :disabled="f.enviando" x-text="f.enviando ? 'Guardando…' : 'Agregar proveedor'"></button></div>
    </div>

    {{-- nuevo trabajador --}}
    <div class="ventana" x-show="ventana === 'trabajador'">
        <h3>Nuevo trabajador</h3>
        <label>Nombre y apellidos</label><input x-model="f.nombre">
        <label>DNI (con él entra a la app)</label><input x-model="f.dni" maxlength="8" @input="f.dni = f.dni.replace(/\D/g, '')">
        <label>Rol</label>
        <div class="opciones"><template x-for="r in ['ACOPIADOR','CALIDAD','OPERARIO','COMPRAS','ADMIN']">
            <button :class="{ activo: f.rol === r }" @click="f.rol = r" x-text="ROLES[r]"></button></template></div>
        <label>Clave inicial (mínimo 6)</label>
        <div class="fila"><input :type="f.ver ? 'text' : 'password'" x-model="f.clave" style="flex:1">
            <button type="button" class="btn gris" @click="f.ver = !f.ver" x-text="f.ver ? 'Ocultar' : 'Ver'"></button></div>
        <label>Teléfono (opcional)</label><input x-model="f.telefono">
        <p class="sub" style="margin-top: 10px">Los productores se agregan desde Proveedores, con "acceso a la app".</p>
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cancelar</button>
            <button class="btn" @click="guardarTrabajador()" :disabled="f.enviando" x-text="f.enviando ? 'Guardando…' : 'Agregar trabajador'"></button></div>
    </div>

    {{-- detalle de boleta --}}
    <div class="ventana" x-show="ventana === 'boleta'">
        <h3 x-text="f.boleta?.proveedor"></h3>
        <p class="sub" x-text="'DNI ' + (f.boleta?.dni || '') + ' · ' + (f.boleta?.comunidad || '')"></p>
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <table class="lista"><tr><th>Día</th><th>Detalle</th><th class="num">Monto</th></tr>
            <template x-for="dia in f.dias || []"><tr><td x-text="fechaCorta(dia.fecha)"></td>
                <td :class="dia.nota === 'rechazado en calidad' ? 'mal' : ''" x-text="dia.nota || (num(dia.litros) + ' L')"></td>
                <td class="num" x-text="dia.monto > 0 ? soles(dia.monto) : '—'"></td></tr></template>
            <tr><td colspan="2"><strong x-text="'Total · ' + num(f.boleta?.litros) + ' L aceptados'"></strong></td><td class="num"><strong x-text="soles(f.boleta?.monto)"></strong></td></tr>
        </table>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cerrar</button>
            <button class="btn" x-show="f.boleta?.estado === 'PENDIENTE'" @click="pagar([f.boleta.id], 'Pagar a ' + f.boleta.proveedor + ' (' + soles(f.boleta.monto) + ')')">Pagar</button></div>
    </div>

    {{-- pagar: efectivo o Yape --}}
    <div class="ventana" x-show="ventana === 'pagar'">
        <h3 x-text="f.titulo"></h3>
        <p class="sub">¿Cómo se pagó?</p>
        <div class="dos"><button class="btn" :disabled="f.enviando" @click="confirmarPago('EFECTIVO')">Efectivo</button>
            <button class="btn" :disabled="f.enviando" @click="confirmarPago('YAPE')">Yape</button></div>
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cancelar</button></div>
    </div>

    {{-- enviar nota por correo --}}
    <div class="ventana" x-show="ventana === 'correo'">
        <h3 x-text="'Enviar nota ' + (f.numero || '')"></h3>
        <label>Correo del cliente</label><input type="email" x-model="f.correo" placeholder="cliente@gmail.com">
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cancelar</button>
            <button class="btn" @click="enviarCorreo()" :disabled="f.enviando" x-text="f.enviando ? 'Enviando…' : 'Enviar'"></button></div>
    </div>

    {{-- nueva orden de compra --}}
    <div class="ventana" x-show="ventana === 'orden'">
        <h3>Nueva orden de compra</h3>
        <label>Insumo</label>
        <select x-model.number="f.insumo_id">
            <option :value="null">Elige…</option>
            <template x-for="i in d.inv?.insumos || []" :key="i.id"><option :value="i.id" x-text="i.nombre + ' · stock ' + num(i.stock, 3) + ' ' + i.unidad"></option></template>
        </select>
        <label>Cantidad</label><input type="number" min="0" step="0.001" x-model="f.cantidad">
        <label>Proveedor</label><input x-model="f.proveedor" list="proveedores-comerciales">
        <datalist id="proveedores-comerciales"><template x-for="p in d.proveedores || []"><option :value="p"></option></template></datalist>
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cancelar</button>
            <button class="btn" @click="emitirOrden()" :disabled="f.enviando" x-text="f.enviando ? 'Emitiendo…' : 'Emitir orden'"></button></div>
    </div>
</div>

</body>
</html>
