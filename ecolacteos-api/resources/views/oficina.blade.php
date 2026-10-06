<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ecolácteos Huata · Oficina</title>
<link rel="stylesheet" href="/oficina-web/vendor/leaflet/leaflet.css">
<script src="/oficina-web/vendor/leaflet/leaflet.js"></script>
<link rel="stylesheet" href="/oficina-web/estilos.css">
<script src="/oficina-web/app.js"></script>
<script defer src="/oficina-web/vendor/alpine.min.js"></script>
</head>
<body x-data="oficina()" x-init="iniciar()">

{{-- ============================================================ LOGIN --}}
<div class="login" x-show="!usuario" x-cloak>
    {{-- foto real: una productora recoge forraje para sus vacas (Samán, Puno) --}}
    <div class="escena" aria-hidden="true"></div>
    <a class="credito" href="https://commons.wikimedia.org/wiki/File:Recojo_de_forraje_para_las_vacas.jpg"
       target="_blank" rel="noopener">Foto: RutsíAndante · CC BY-SA 4.0</a>

    <div class="login-intro">
        <div class="marca grande">Ecolácteos <em>Huata</em></div>
        <p>De la vaca a la planta: el acopio de leche, su calidad, la producción y las ventas de Huata en un solo lugar.</p>
        <div class="chips">
            <span>🥛 Acopio en campo</span><span>🔬 Calidad de leche</span><span>🧀 Planta y ventas</span>
        </div>
    </div>

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
                <button :class="{ activo: m.pestanas.some((p) => p.id === seccion) }" @click="ir(m.pestanas[0].id)" x-text="m.nombre"></button>
            </div>
        </template>
        <button class="salir" @click="salir()">Cerrar sesión</button>
    </nav>

    <main>
        {{-- pestañas de la opción del menú (ej. Calidad → Por vehículo | Adulteraciones) --}}
        <div class="pestanas no-imprimir" x-show="(opcionActual()?.pestanas.length || 0) > 1">
            <template x-for="p in opcionActual()?.pestanas || []" :key="p.id">
                <button :class="{ activo: seccion === p.id }" @click="ir(p.id)" x-text="p.nombre"></button>
            </template>
        </div>
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

        {{-- ---------------------------------------------------- MAPA DE HUATA --}}
        {{-- x-show sin "cargando": el mapa no se borra ni se vuelve a medir al recargar --}}
        <section x-show="seccion === 'mapa' && !error">
            <div class="fila"><h1>Mapa de Huata</h1><div class="espacio"></div>
                <input type="date" x-model="mapaFecha" @change="cargar()" style="width:auto" title="Día">
                <button class="btn" :class="mapaEditar ? '' : 'gris'" @click="alternarEdicionMapa()"
                        x-text="mapaEditar ? '✓ Listo' : 'Corregir ubicaciones'"></button></div>
            <p class="sub" x-show="!mapaEditar">Avance del acopio por comunidad y la ruta de cada vehículo. Toca un pin para ver el detalle.</p>
            <div class="aviso" x-show="mapaEditar">Arrastra cada comunidad a su lugar real sobre el satélite. Se guarda al soltarla. Cuando termines, toca <strong>Listo</strong>.</div>
            <div class="aviso" x-show="!mapaEditar && resumenMapa().aproximadas"
                 x-text="resumenMapa().aproximadas + ' comunidades tienen ubicación aproximada (borde punteado). Usa «Corregir ubicaciones» para ponerlas en su lugar real.'"></div>
            <div class="tarjetas">
                <div class="tarjeta destacada"><div class="eti" x-text="mapaFecha ? 'Litros del ' + fechaCorta(mapaFecha) : 'Litros de hoy'"></div>
                    <div class="valor" x-text="num(resumenMapa().litros, 0) + ' L'"></div></div>
                <div class="tarjeta"><div class="eti">Proveedores registrados</div>
                    <div class="valor" x-text="resumenMapa().registrados + ' / ' + resumenMapa().proveedores"></div></div>
            </div>
            <div class="mapa-caja">
                <div id="mapa-huata"></div>
                <div class="mapa-leyenda">
                    <span><i class="pto ok"></i>Todos registrados</span>
                    <span><i class="pto parcial"></i>Faltan algunos</span>
                    <span><i class="pto nada"></i>Ninguno aún</span>
                    <template x-for="v in d.vehiculos || []" :key="v.id">
                        <span><i class="linea" :style="'background:' + colorRuta(v.codigo)"></i><span x-text="v.codigo"></span></span>
                    </template>
                </div>
            </div>
            <h2>Rutas</h2>
            <table class="lista"><tr><th>Vehículo</th><th>Acopiador</th><th>Recorrido</th></tr>
                <template x-for="v in d.vehiculos || []" :key="v.id"><tr>
                    <td><i class="linea" :style="'background:' + colorRuta(v.codigo)"></i> <span x-text="v.codigo"></span></td>
                    <td x-text="v.acopiador || 'sin acopiador'"></td><td x-text="rutaTexto(v)"></td></tr></template>
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
                <input x-model="buscar" placeholder="Buscar por nombre, DNI, comunidad o vehículo · toca un proveedor para ver todo" style="margin-bottom: 12px">
                <table class="lista"><tr><th>Nombre</th><th>DNI</th><th>Comunidad</th><th>Vehículo</th><th>Estado</th><th></th><th></th></tr>
                    <template x-for="p in proveedoresFiltrados()" :key="p.id"><tr class="clic" @click="abrirFicha(p.id)" title="Toca para ver todo">
                        <td x-text="p.nombre"></td><td x-text="p.dni"></td>
                        <td><span x-text="p.comunidad"></span>
                            {{-- cambio reciente (15 días) y traslado programado; al tocar la fila: ficha con su historial --}}
                            <div x-show="p.cambio_reciente"><span class="etiqueta reciente"
                                x-text="'🔄 Cambió el ' + fechaCorta(p.cambio_reciente?.desde) + ' · antes ' + p.cambio_reciente?.antes"></span></div>
                            <div x-show="p.programado"><span class="etiqueta programado"
                                x-text="'⏳ Pasa a ' + p.programado?.comunidad + ' el ' + fechaCorta(p.programado?.desde)"></span></div></td>
                        <td x-text="p.vehiculo"></td>
                        <td><span class="chip" :class="p.estado === 'ACTIVO' ? 'ok' : 'mal'" x-text="p.estado === 'ACTIVO' ? 'Activo' : p.estado" :title="p.motivo_baja || ''"></span></td>
                        <td class="num">
                            {{-- .stop: el botón no abre la ficha --}}
                            <button class="btn chico gris" x-show="p.estado !== 'BAJA' && (p.programado || p.cambio_reciente)" @click.stop="abrirCambio(p.id, true)">Editar traslado</button>
                            <button class="btn chico gris" x-show="p.estado !== 'BAJA' && !p.programado" @click.stop="abrirCambio(p.id)">Cambiar comunidad</button></td>
                        <td class="num" style="color: var(--texto-3)">›</td></tr></template>
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

        {{-- ---------------------------------------------------- ADULTERACIONES --}}
        <section x-show="seccion === 'adulteraciones' && !cargando && !error">
            <h1>Adulteraciones</h1>
            <p class="sub">Proveedores a quienes Calidad les detectó agua añadida. La 1.ª vez se paga esa entrega a precio reducido; la 2.ª vez se les da de baja.</p>
            <div class="tarjetas" style="margin-bottom: 14px">
                <div class="tarjeta"><div class="eti">Dados de baja</div><div class="valor" x-text="d.de_baja ?? 0"></div><div class="apoyo">2.ª adulteración</div></div>
                <div class="tarjeta"><div class="eti">Con advertencia</div><div class="valor" x-text="d.advertidos ?? 0"></div><div class="apoyo">1.ª adulteración, siguen entregando</div></div>
            </div>
            <div class="pestanas">
                <button :class="{ activo: adulteracion === 'todos' }" @click="adulteracion = 'todos'">Todos</button>
                <button :class="{ activo: adulteracion === 'baja' }" @click="adulteracion = 'baja'">Dados de baja</button>
                <button :class="{ activo: adulteracion === 'advertidos' }" @click="adulteracion = 'advertidos'">Con advertencia</button>
            </div>
            <input x-model="buscar" placeholder="Buscar por nombre, DNI, comunidad o vehículo" style="margin-bottom: 12px">
            <table class="lista"><tr><th>Nombre</th><th>DNI</th><th>Comunidad</th><th class="num">Veces</th><th>Última</th><th>Estado</th></tr>
                <template x-for="p in adulteradosFiltrados()" :key="p.id"><tr class="clic" @click="verAdulterado(p)">
                    <td x-text="p.nombre"></td><td x-text="p.dni"></td><td x-text="p.comunidad + ' · ' + p.vehiculo"></td>
                    <td class="num" x-text="p.veces.length"></td><td x-text="fechaCorta(p.ultima)"></td>
                    <td><span class="chip" :class="p.de_baja ? 'mal' : 'ojo'" x-text="p.de_baja ? 'Dado de baja' : 'Advertencia'"></span></td></tr></template>
                <tr x-show="!adulteradosFiltrados().length"><td colspan="6" x-text="(d.proveedores || []).length ? 'Nadie coincide con la búsqueda.' : 'No hay proveedores sancionados por adulteración.'"></td></tr>
            </table>
        </section>

        {{-- ---------------------------------------------------- CAMBIOS DE ZONA --}}
        <section x-show="seccion === 'solicitudes' && !cargando && !error">
            <div class="fila"><h1>Cambios de comunidad</h1><div class="espacio"></div>
                <button class="btn" @click="abrirCambio()">+ Registrar cambio (llamó o vino a Huata)</button></div>
            <p class="sub">Los productores casi no tienen internet: cuando llamen o vengan a Huata, registra aquí su cambio y queda hecho al momento.
                El vehículo se asigna solo según la ruta que pasa por la comunidad. Si alguno lo pidió por la app, apruébalo o recházalo abajo.</p>
            <table class="lista"><tr><th>Proveedor</th><th>Cambio</th><th>Cómo avisó</th><th>Motivo</th><th>Desde</th><th>Estado</th><th></th></tr>
                <template x-for="s in d.solicitudes || []" :key="s.id"><tr>
                    <td><span x-text="s.proveedor"></span><div class="sub" style="margin: 0; font-size: 12px" x-text="'DNI ' + s.dni"></div></td>
                    <td x-text="s.origen + ' → ' + s.destino"></td>
                    <td><span x-text="CANAL[s.canal] || s.canal"></span><div class="sub" style="margin: 0; font-size: 12px" x-show="s.registrada_por" x-text="'Registró: ' + s.registrada_por"></div></td>
                    <td x-text="s.motivo || '—'"></td>
                    <td x-text="fechaCorta(s.solicitada_para)"></td>
                    <td><span class="chip" :class="s.estado === 'APROBADA' ? 'ok' : (s.estado === 'PENDIENTE' ? 'ojo' : 'mal')"
                              x-text="s.estado === 'APROBADA' ? 'Aprobada · desde ' + fechaCorta(s.vigente_desde) : (s.estado === 'PENDIENTE' ? 'Pendiente' : 'Rechazada')"></span></td>
                    <td class="num"><span x-show="s.estado === 'PENDIENTE'">
                        <button class="btn chico" @click="resolverSolicitud(s, true)">Aprobar</button>
                        <button class="btn chico rojo" @click="resolverSolicitud(s, false)">Rechazar</button></span></td></tr></template>
                <tr x-show="!(d.solicitudes || []).length"><td colspan="7">Todavía no hay cambios de comunidad.</td></tr>
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
                        <button class="btn ancho" @click="registrarVenta()" :disabled="!!f.enviando" x-text="f.enviando ? 'Registrando…' : 'Registrar venta'"></button>
                    </div>
                </div>
            </div>
        </section>

        {{-- ---------------------------------------------------- INVENTARIO --}}
        <section x-show="seccion === 'inventario' && !cargando && !error">
            <div class="fila"><h1>Almacén</h1><div class="espacio"></div>
                <button class="btn gris" @click="ir('ordenes')">Órdenes de compra</button>
                <button class="btn rojo" @click="abrirMerma('PRODUCTO')">+ Registrar pérdida</button></div>
            <p class="sub">Lo que hay hoy y lo que falta comprar.</p>
            <div class="aviso bien" x-show="!(d.avisos || []).length">✓ No falta ningún insumo.</div>
            <template x-for="a in d.avisos || []" :key="a.nivel + a.insumo_id + a.producto">
                <div class="aviso" :class="a.nivel === 'FALTA' ? 'error' : ''">
                    <span x-text="a.texto"></span>
                    <span x-text="' Comprar al menos ' + num(a.comprar, 3) + ' ' + a.unidad + '.'"></span>
                </div>
            </template>
            <h2>Productos terminados</h2>
            <table class="lista"><tr><th>Producto</th><th class="num">Stock</th><th class="num">Mayorista</th><th class="num">Vecino</th><th class="num">Proveedor</th><th></th></tr>
                <template x-for="p in d.productos || []" :key="p.id"><tr><td x-text="p.nombre"></td><td class="num" x-text="num(p.stock, 0) + ' ' + p.unidad"></td>
                    <td class="num" x-text="soles(p.precios?.MAYORISTA)"></td><td class="num" x-text="soles(p.precios?.DIRECTO)"></td><td class="num" x-text="soles(p.precios?.PLANTA)"></td>
                    <td class="num"><button class="btn chico gris" @click="verKardex(p.id, 'producto')">Kardex</button></td></tr></template>
            </table>
            <h2>Insumos</h2>
            <table class="lista"><tr><th>Insumo</th><th class="num">Stock</th><th class="num">Mínimo</th><th class="num">Costo unit.</th><th></th></tr>
                <template x-for="i in d.insumos || []" :key="i.id"><tr><td x-text="i.nombre"></td>
                    <td class="num" :class="i.stock < i.minimo ? 'mal' : ''" x-text="num(i.stock, 3) + ' ' + i.unidad"></td>
                    <td class="num" x-text="num(i.minimo, 3)"></td><td class="num" x-text="soles(i.costo)"></td>
                    <td class="num"><span class="chip mal" x-show="i.stock < i.minimo">Reponer</span>
                        <button class="btn chico gris" @click="verKardex(i.id)">Kardex</button></td></tr></template>
            </table>
        </section>

        {{-- ---------------------------------------------------- KARDEX --}}
        <section x-show="seccion === 'kardex' && !cargando && !error">
            <div class="fila no-imprimir"><h1>Kardex</h1><div class="espacio"></div>
                <button class="btn gris" @click="window.print()">Imprimir</button>
                <button class="btn" @click="abrirAjuste()" x-show="d.item?.tipo === 'insumo'">+ Ajuste (merma, vencido, conteo)</button>
                <button class="btn rojo" @click="abrirMerma(kardex.tipo === 'leche' ? 'LECHE' : 'PRODUCTO', kardex.id)"
                        x-show="d.item && d.item.tipo !== 'insumo'" x-text="kardex.tipo === 'leche' ? '+ Leche perdida' : '+ Registrar pérdida'"></button></div>
            <h1 class="solo-imprimir" x-text="'Kardex · ' + (d.item?.nombre || '')"></h1>
            <div class="pestanas no-imprimir" style="margin-top: 10px">
                <template x-for="t in pestanasKardex()" :key="t.tipo + t.id">
                    <button :class="{ activo: pestanaKardexActiva(t) }" @click="tipoKardex(t.tipo, t.id)" x-text="t.nombre"></button>
                </template>
            </div>
            <p class="sub" x-show="kardex.tipo === 'insumo'">Entra cuando llega una compra. Sale cuando se usa para hacer un lote o se pierde (merma).</p>
            <p class="sub" x-show="kardex.tipo === 'producto'">Entra cuando se termina un lote. Sale cuando se vende o se pierde (se venció, se malogró).</p>
            <p class="sub" x-show="kardex.tipo === 'leche'">Entra la leche que Calidad acepta cada día. Sale la que se usa para hacer cada lote o se pierde (se cortó, se derramó).</p>
            <div class="fila no-imprimir" style="align-items: flex-end">
                <div style="flex: 2; min-width: 200px" x-show="kardex.tipo === 'insumo'"><label>Insumo</label>
                    <select x-model.number="kardex.id" @change="cargar()">
                        <template x-for="o in opcionesKardex()" :key="kardex.tipo + o.id"><option :value="o.id" :selected="o.id === kardex.id" x-text="o.nombre"></option></template>
                    </select></div>
                <div style="flex: 1; min-width: 140px"><label>Desde</label><input type="date" x-model="kardex.desde" @change="cargar()"></div>
                <div style="flex: 1; min-width: 140px"><label>Hasta</label><input type="date" x-model="kardex.hasta" @change="cargar()"></div>
                <button class="btn gris" x-show="kardex.desde || kardex.hasta" @click="kardex.desde = ''; kardex.hasta = ''; cargar()">Todo</button>
            </div>
            <div class="tarjetas" style="margin-top: 16px" x-show="d.item">
                <div class="tarjeta"><div class="eti">Entró</div><div class="valor ok" x-text="'+ ' + num(d.totales?.entradas.cantidad, 3) + ' ' + (d.item?.unidad || '')"></div>
                    <div class="apoyo" x-show="kardexContable" x-text="soles(d.totales?.entradas.total)"></div></div>
                <div class="tarjeta"><div class="eti">Salió</div><div class="valor" x-text="'− ' + num(d.totales?.salidas.cantidad, 3) + ' ' + (d.item?.unidad || '')"></div>
                    <div class="apoyo" x-show="kardexContable" x-text="soles(d.totales?.salidas.total)"></div></div>
                <div class="tarjeta destacada"><div class="eti">Queda</div><div class="valor" :class="d.item?.stock < d.item?.minimo ? 'mal' : ''" x-text="num(d.totales?.saldo.cantidad, 3) + ' ' + (d.item?.unidad || '')"></div>
                    <div class="apoyo" x-text="(kardexContable ? 'vale ' + soles(d.totales?.saldo.total) + ' · costo promedio ' + cu(d.totales?.saldo.costo) : '') + (d.item?.minimo ? (kardexContable ? ' · ' : '') + 'mínimo ' + num(d.item.minimo, 3) : '')"></div></div>
            </div>
            {{-- resumen en una frase: entró tanto (por compras…), salió tanto (en producción…), queda tanto --}}
            <div class="resumen-kardex" x-show="d.item" x-html="resumenKardex()"></div>
            <div class="fila no-imprimir" style="margin-top: 14px">
                <div class="pestanas" style="margin: 0">
                    <button :class="{ activo: !kardexContable }" @click="kardexContable = false">Vista sencilla</button>
                    <button :class="{ activo: kardexContable }" @click="kardexContable = true">Con costos (contable)</button>
                </div>
                <span class="sub" style="margin: 0" x-show="!kardexContable">Se lee de arriba hacia abajo: cada fila suma o resta, y la última columna dice cuánto quedó.</span>
            </div>

            {{-- vista sencilla --}}
            <table class="lista kardex-simple" style="margin-top: 12px" x-show="!kardexContable">
                <tr><th>Fecha</th><th>Qué pasó</th><th class="num">Entró</th><th class="num">Salió</th><th class="num">Queda</th></tr>
                <template x-for="(m, k) in d.filas || []" :key="k"><tr :class="m.tipo === 'ANTERIOR' || m.tipo === 'INICIAL' ? 'tenue' : ''">
                    <td x-text="fechaCorta(m.fecha) + ' ' + hora(m.fecha)"></td>
                    <td><span :class="m.tipo === 'MERMA' ? 'mal' : ''" x-text="m.texto"></span><div class="quien" x-show="m.usuario" x-text="'Registró: ' + m.usuario"></div></td>
                    <td class="num ok"><strong x-text="m.entrada ? '+ ' + num(m.entrada.cantidad, 3) + ' ' + d.item.unidad : ''"></strong></td>
                    <td class="num sale"><strong x-text="m.salida ? '− ' + num(m.salida.cantidad, 3) + ' ' + d.item.unidad : ''"></strong></td>
                    <td class="num" :class="m.saldo.cantidad < 0 ? 'mal' : ''"><strong x-text="num(m.saldo.cantidad, 3) + ' ' + d.item.unidad"></strong></td></tr></template>
                <tr x-show="!(d.filas || []).length"><td colspan="5">No hay movimientos en estas fechas.</td></tr>
            </table>

            {{-- vista contable (valorizada por costo promedio ponderado) --}}
            <table class="lista kardex" style="margin-top: 12px" x-show="kardexContable">
                <tr><th rowspan="2">Fecha</th><th rowspan="2">Movimiento</th><th rowspan="2">Detalle</th>
                    <th colspan="3" class="grupo-col">Entrada</th><th colspan="3" class="grupo-col">Salida</th><th colspan="3" class="grupo-col">Saldo</th></tr>
                <tr><th class="num">Cant.</th><th class="num">C. unit.</th><th class="num">Total</th>
                    <th class="num">Cant.</th><th class="num">C. unit.</th><th class="num">Total</th>
                    <th class="num">Cant.</th><th class="num">C. unit.</th><th class="num">Total</th></tr>
                <template x-for="(m, k) in d.filas || []" :key="k"><tr :class="m.tipo === 'ANTERIOR' || m.tipo === 'INICIAL' ? 'tenue' : ''">
                    <td x-text="fechaCorta(m.fecha) + ' ' + hora(m.fecha)"></td>
                    <td><span class="chip" :class="['COMPRA', 'ACOPIO'].includes(m.tipo) || (m.tipo === 'PRODUCCION' && m.entrada) ? 'ok' : (m.tipo === 'AJUSTE' ? 'ojo' : (m.tipo === 'MERMA' ? 'mal' : ''))" x-text="TIPO_KARDEX[m.tipo]"></span></td>
                    <td x-text="m.detalle + (m.usuario ? ' · ' + m.usuario : '')"></td>
                    <td class="num ok" x-text="m.entrada ? num(m.entrada.cantidad, 3) : ''"></td>
                    <td class="num" x-text="m.entrada ? cu(m.entrada.costo) : ''"></td>
                    <td class="num" x-text="m.entrada ? soles(m.entrada.total) : ''"></td>
                    <td class="num" x-text="m.salida ? num(m.salida.cantidad, 3) : ''"></td>
                    <td class="num" x-text="m.salida ? cu(m.salida.costo) : ''"></td>
                    <td class="num" x-text="m.salida ? soles(m.salida.total) : ''"></td>
                    <td class="num"><strong x-text="num(m.saldo.cantidad, 3)"></strong></td>
                    <td class="num" x-text="cu(m.saldo.costo)"></td>
                    <td class="num" x-text="soles(m.saldo.total)"></td></tr></template>
                <tr x-show="!(d.filas || []).length"><td colspan="12">No hay movimientos en estas fechas.</td></tr>
            </table>
        </section>

        {{-- ---------------------------------------------------- PÉRDIDAS --}}
        <section x-show="seccion === 'mermas' && !cargando && !error">
            <div class="fila"><h1>Pérdidas (mermas)</h1><div class="espacio"></div>
                <button class="btn rojo" @click="abrirMerma('LECHE')">+ Leche perdida</button>
                <button class="btn rojo" @click="abrirMerma('PRODUCTO')">+ Queso o yogurt perdido</button></div>
            <p class="sub">La leche que se corta o se derrama, el queso que se pasa, el yogurt que se malogra. Se descuenta del stock y sale en el kardex.</p>
            <div class="tarjetas">
                <div class="tarjeta"><div class="eti">Leche sin procesar</div><div class="valor" x-text="num(d.leche?.sin_usar) + ' L'"></div>
                    <div class="apoyo" x-text="num(d.leche?.tanque) + ' L de los últimos 3 días (se puede producir)'"></div></div>
                <template x-for="p in d.productos || []" :key="p.id">
                    <div class="tarjeta"><div class="eti" x-text="p.nombre"></div><div class="valor" x-text="num(p.stock, 2) + ' ' + p.unidad"></div><div class="apoyo">en almacén</div></div>
                </template>
            </div>
            <h2>Últimas pérdidas</h2>
            <table class="lista"><tr><th>Fecha</th><th>Qué</th><th class="num">Cantidad</th><th>Motivo</th><th>Registró</th></tr>
                <template x-for="m in d.mermas || []" :key="m.id"><tr>
                    <td x-text="fechaCorta(m.fecha) + ' ' + hora(m.fecha)"></td>
                    <td x-text="m.que + (m.lote ? ' · lote ' + m.lote : '')"></td>
                    <td class="num mal"><strong x-text="'− ' + num(m.cantidad, 3) + ' ' + m.unidad_corta"></strong></td>
                    <td x-text="m.motivo_texto + (m.detalle ? ' · ' + m.detalle : '')"></td>
                    <td x-text="m.usuario || ''"></td></tr></template>
                <tr x-show="!(d.mermas || []).length"><td colspan="5">Todavía no se registró ninguna pérdida.</td></tr>
            </table>
        </section>

        {{-- ---------------------------------------------------- LOTES EN PRODUCCIÓN --}}
        <section x-show="seccion === 'lotes' && !cargando && !error">
            <div class="fila"><h1>Lotes en producción</h1><div class="espacio"></div>
                <button class="btn" @click="ir('formulas')">+ Hacer otro lote</button></div>
            <p class="sub">Un lote "en proceso" ya descontó la leche y los insumos. Cuando termines, escribe cuántos salieron y ciérralo: recién ahí entran al stock para vender y al kardex.</p>
            <template x-for="l in (d.lotes || []).filter((l) => l.estado === 'EN_PROCESO')" :key="l.id">
                <div class="tarjeta" style="margin-bottom: 12px; border-color: var(--verde-borde)">
                    <div class="fila">
                        <div style="flex: 1; min-width: 220px">
                            <strong style="font-size: 18px" x-text="l.codigo + ' · ' + l.producto"></strong>
                            <div class="sub" style="margin: 2px 0 0" x-text="'Iniciado el ' + fechaCorta(l.iniciado_en) + ' ' + hora(l.iniciado_en) + ' con ' + num(l.litros_leche, 0) + ' L de leche · vence el ' + fechaCorta(l.vence_el)"></div>
                            <div style="margin-top: 6px" x-text="'Deben salir entre ' + num(l.esperado_min, 0) + ' y ' + num(l.esperado_max, 0) + ' ' + l.unidad.split(' ')[0]"></div>
                            <div class="sub" style="margin: 4px 0 0" x-show="(l.insumos || []).length"
                                 x-text="'Se usó: ' + l.insumos.map((i) => i.nombre.toLowerCase() + ' ' + num(i.cantidad, 3) + ' ' + i.unidad).join(', ')"></div>
                        </div>
                        <div style="min-width: 260px">
                            <label style="margin-top: 0" x-text="'¿Cuántos ' + l.unidad.split(' ')[0] + ' salieron?'"></label>
                            <div class="fila">
                                <input type="number" min="0" step="1" style="width: 120px" x-model="cierre[l.id]" :placeholder="num(l.esperado_min, 0)">
                                <button class="btn" @click="cerrarLote(l)">Cerrar lote</button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
            <div class="aviso bien" x-show="!(d.lotes || []).some((l) => l.estado === 'EN_PROCESO')">No hay lotes en proceso. Para hacer uno, ve a Fórmulas y acepta la cantidad.</div>
            <h2>Últimos lotes terminados</h2>
            <table class="lista"><tr><th>Lote</th><th>Producto</th><th class="num">Leche</th><th class="num">Esperado</th><th class="num">Salió</th><th class="num">Desvío</th></tr>
                <template x-for="l in (d.lotes || []).filter((l) => l.estado !== 'EN_PROCESO')" :key="l.id"><tr>
                    <td x-text="l.codigo + ' · ' + fechaCorta(l.iniciado_en)"></td><td x-text="l.producto"></td>
                    <td class="num" x-text="num(l.litros_leche, 0) + ' L'"></td>
                    <td class="num" x-text="num(l.esperado_min, 0) + '–' + num(l.esperado_max, 0)"></td>
                    <td class="num"><strong x-text="num(l.obtenido, 0) + ' ' + l.unidad.split(' ')[0]"></strong></td>
                    <td class="num" :class="l.fuera_referencia == 1 ? 'mal' : 'ok'" x-text="(l.desvio_pct > 0 ? '+' : '') + num(l.desvio_pct, 1) + ' %'"></td></tr></template>
            </table>
        </section>

        {{-- ---------------------------------------------------- FÓRMULAS --}}
        <section x-show="seccion === 'formulas' && !cargando && !error">
            <h1>Fórmulas</h1>
            <p class="sub" x-text="'Cómo se hace el queso y el yogurt. Escribe cuánto quieres hacer y te dice qué necesitas. En el tanque hay ' + num(d.tanque) + ' L de leche aceptada.'"></p>
            {{-- aquí solo lo que impide producir; lo que está bajo el mínimo se ve en Almacén --}}
            <template x-for="a in (d.avisos || []).filter((a) => a.nivel === 'FALTA')" :key="a.nivel + a.insumo_id + a.producto">
                <div class="aviso" :class="a.nivel === 'FALTA' ? 'error' : ''">
                    <span x-text="a.texto"></span>
                    <span x-text="' Comprar al menos ' + num(a.comprar, 3) + ' ' + a.unidad + '.'"></span>
                </div>
            </template>
            <template x-for="p in d.productos || []" :key="p.id">
                <div class="tarjeta" style="margin-top: 16px">
                    <div class="fila"><h2 style="margin: 0; color: var(--texto); font-size: 18px; text-transform: none; letter-spacing: 0" x-text="p.nombre"></h2>
                        <div class="espacio"></div>
                        <span class="chip" :class="p.alcanza_litros !== null && p.alcanza_litros < Math.max(d.tanque, 100) ? 'mal' : 'ok'"
                              x-text="p.alcanza_litros === null ? 'Sin insumos en la fórmula' : 'Con el stock de hoy alcanza para ' + num(p.alcanza_litros, 0) + ' L'"></span>
                        <button class="btn chico" x-show="usuario?.rol === 'ADMIN'" @click="abrirFormula(p)">Editar fórmula</button></div>
                    <p class="sub" style="margin: 6px 0 12px"
                       x-text="'Rinde ' + num(p.rendimiento_min * 100) + (p.rendimiento_max !== p.rendimiento_min ? '–' + num(p.rendimiento_max * 100) : '') + ' ' + p.unidad + ' por cada 100 L · vida útil ' + p.vida_util_dias + ' días'"></p>
                    {{-- "quiero hacer 10 quesos": cuánta leche y cuánto de cada insumo, y qué falta --}}
                    <div class="fila quiero">
                        <strong>Quiero hacer</strong>
                        <input type="number" min="0" step="1" :value="cantidadQuiero(p)" @input="quiero[p.id] = $event.target.value" style="width: 110px">
                        <strong x-text="unidadProducto(p)"></strong>
                        <span class="sub" style="margin: 0" x-text="'→ necesitas ' + num(litrosPara(p), 0) + ' L de leche (en el tanque hay ' + num(d.tanque, 1) + ' L)'"></span>
                    </div>
                    <table class="lista"><tr><th>Insumo</th><th class="num">Por 100 L</th>
                        <th class="num" x-text="'Necesitas para ' + num(cantidadQuiero(p), 0) + ' ' + unidadProducto(p)"></th><th class="num">Hay en almacén</th><th></th></tr>
                        <template x-for="i in p.insumos" :key="i.id"><tr>
                            <td x-text="i.nombre"></td><td class="num" x-text="num(i.por_100l, 3) + ' ' + i.unidad"></td>
                            <td class="num" :class="necesita(i, p) > i.stock ? 'mal' : ''"><strong x-text="num(necesita(i, p), 3) + ' ' + i.unidad"></strong></td>
                            <td class="num" x-text="num(i.stock, 3) + ' ' + i.unidad"></td>
                            <td><span class="chip" :class="necesita(i, p) > i.stock ? 'mal' : 'ok'"
                                      x-text="necesita(i, p) > i.stock ? 'Falta ' + num(necesita(i, p) - i.stock, 3) + ' ' + i.unidad : 'Alcanza'"></span></td></tr></template>
                        <tr x-show="!p.insumos.length"><td colspan="5">Esta fórmula no tiene insumos.</td></tr>
                    </table>
                    {{-- ¿se puede? sí → botón Aceptar; no → dice qué falta y el botón no se puede usar --}}
                    <div class="decision" :class="sePuede(p).ok ? 'si' : 'no'">
                        <div class="texto">
                            <strong x-text="sePuede(p).ok ? '✓ Sí se puede hacer ' + num(cantidadQuiero(p), 0) + ' ' + unidadProducto(p) + '.' : '✗ No se puede.'"></strong>
                            <span x-text="' ' + (sePuede(p).ok ? 'Hay leche e insumos suficientes.' : sePuede(p).motivo)"></span>
                            <div class="sub" style="margin: 4px 0 0" x-show="sePuede(p).ok && usuario?.rol !== 'ADMIN'">El lote lo inicia el operario (en la app) o el administrador.</div>
                        </div>
                        <button class="btn" x-show="usuario?.rol === 'ADMIN'" :disabled="!sePuede(p).ok" @click="abrirProducir(p)"
                                x-text="'Aceptar: hacer ' + num(cantidadQuiero(p), 0) + ' ' + unidadProducto(p)"></button>
                    </div>
                    <h2>Cómo se hace</h2>
                    <ol class="pasos"><template x-for="paso in p.procedimiento"><li x-text="paso"></li></template></ol>
                    <p class="sub" x-show="!p.procedimiento.length">Sin pasos registrados.</p>
                </div>
            </template>
        </section>

        {{-- ---------------------------------------------------- ÓRDENES --}}
        <section x-show="seccion === 'ordenes' && !cargando && !error">
            <div class="fila"><h1>Órdenes y comprobantes</h1><div class="espacio"></div><button class="btn" @click="abrirOrden()">+ Nueva orden</button></div>
            <p class="sub">A quién se le compró cada insumo y con qué comprobante. Cuando llegue lo pedido, toca <strong>Llegó</strong> y registra la factura, boleta o recibo.</p>
            <table class="lista"><tr><th>Código</th><th>Vendedor</th><th>Insumos</th><th>Comprobante</th><th class="num">Monto</th><th>Estado</th><th></th></tr>
                <template x-for="o in d.ordenes || []" :key="o.id"><tr>
                    <td style="white-space: nowrap"><span x-text="o.codigo"></span><div class="sub" style="margin:0" x-text="fechaCorta(o.created_at)"></div></td>
                    <td><span x-text="o.proveedor"></span>
                        <div class="sub" style="margin:0" x-text="o.vendedor ? o.vendedor.tipo_documento + ' ' + o.vendedor.documento : 'sin DNI/RUC (orden antigua)'"></div></td>
                    <td x-text="resumenItems(o)"></td>
                    <td><span x-text="o.comprobante || '—'"></span>
                        <div class="sub" style="margin:0" x-show="o.comprobante_fecha" x-text="'del ' + fechaCorta(o.comprobante_fecha)"></div>
                        <div class="sub" style="margin:0" x-show="o.anulada_motivo" x-text="'Anulada: ' + o.anulada_motivo"></div></td>
                    <td class="num"><span x-text="soles(o.total ?? o.costo_estimado)"></span>
                        <div class="sub" style="margin:0" x-text="o.total === null ? 'estimado' : (o.igv !== null ? 'IGV ' + soles(o.igv) : 'pagado')"></div></td>
                    <td><span class="chip" :class="o.estado === 'RECIBIDA' ? 'ok' : (o.estado === 'EMITIDA' ? 'ojo' : 'mal')" x-text="o.estado === 'EMITIDA' ? 'por llegar' : o.estado.toLowerCase()"></span></td>
                    <td class="acciones">
                        <button class="btn chico" x-show="o.estado === 'EMITIDA'" @click="abrirRecibir(o)">Llegó</button>
                        <button class="btn chico rojo" x-show="o.estado === 'EMITIDA'" @click="abrirAnularOrden(o)">Anular</button>
                        <button class="btn chico gris" x-show="o.tiene_foto" @click="verFoto(o)">Ver foto</button>
                        <label class="btn chico gris" x-show="o.estado === 'RECIBIDA' && !o.tiene_foto" style="cursor:pointer">+ Foto
                            <input type="file" accept="image/*" style="display:none" @change="agregarFoto(o, $event)"></label>
                    </td></tr></template>
            </table>
        </section>

        <section x-show="seccion === 'vendedores' && !cargando && !error">
            <div class="fila"><h1>Vendedores de insumos</h1><div class="espacio"></div><button class="btn" @click="abrirVendedor()">+ Nuevo vendedor</button></div>
            <p class="sub">Personas (DNI) y empresas (RUC) que nos venden insumos. No son los proveedores de leche: esos están en el Padrón.</p>
            <table class="lista"><tr><th>Nombre o razón social</th><th>DNI / RUC</th><th>Teléfono</th><th class="num">Compras</th><th class="num">Pagado</th><th>Última</th><th></th></tr>
                <template x-for="v in d.vendedores || []" :key="v.id"><tr :class="v.activo ? '' : 'tenue'">
                    <td><span x-text="v.nombre"></span><div class="sub" style="margin:0" x-show="v.direccion" x-text="v.direccion"></div></td>
                    <td x-text="v.tipo_documento + ' ' + v.documento"></td><td x-text="v.telefono || '—'"></td>
                    <td class="num" x-text="v.compras"></td><td class="num" x-text="soles(v.pagado)"></td>
                    <td x-text="v.ultima ? fechaCorta(v.ultima) : '—'"></td>
                    <td class="acciones"><span class="chip mal" x-show="!v.activo">desactivado</span>
                        <button class="btn chico gris" @click="abrirVendedor(v)">Editar</button></td></tr></template>
            </table>
            <p class="sub" x-show="!(d.vendedores || []).length">Todavía no hay vendedores. Agrega el primero con <strong>+ Nuevo vendedor</strong>.</p>
        </section>

        {{-- ---------------------------------------------------- MOVIMIENTOS --}}
        <section x-show="seccion === 'movimientos' && !cargando && !error">
            <h1>Últimas ventas</h1>
            <p class="sub">Toca el número para ver o imprimir la nota de venta, o mándala por correo. (Las entradas y salidas de cada producto están en Kardex.)</p>
            <table class="lista"><tr><th>Fecha</th><th>Producto</th><th>Cliente y total</th><th class="num">Cantidad</th><th>Nota de venta</th></tr>
                <template x-for="(m, k) in (d.movimientos || []).filter((m) => m.venta_id)" :key="k"><tr>
                    <td x-text="fechaCorta(m.fecha) + ' ' + hora(m.fecha)"></td><td x-text="m.que"></td><td x-text="m.ref"></td>
                    <td class="num" :class="m.entrada ? 'ok' : ''" x-text="m.delta"></td>
                    <td><template x-if="m.venta_id"><span>
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
            <button class="btn" @click="guardarProveedor()" :disabled="!!f.enviando" x-text="f.enviando ? 'Guardando…' : 'Agregar proveedor'"></button></div>
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
            <button class="btn" @click="guardarTrabajador()" :disabled="!!f.enviando" x-text="f.enviando ? 'Guardando…' : 'Agregar trabajador'"></button></div>
    </div>

    {{-- detalle de adulteraciones de un proveedor --}}
    <div class="ventana" x-show="ventana === 'adulterado'">
        <h3 x-text="f.adulterado?.nombre"></h3>
        <p class="sub" x-text="'DNI ' + (f.adulterado?.dni || '') + ' · ' + (f.adulterado?.comunidad || '') + ' · ' + (f.adulterado?.vehiculo || '')"></p>
        <div class="aviso error" x-show="f.adulterado?.de_baja" x-text="'Dado de baja: ' + (f.adulterado?.motivo_baja || 'segunda adulteración')"></div>
        <table class="lista"><tr><th>Vez</th><th>Día</th><th class="num">Agua añadida</th><th>Medida</th></tr>
            <template x-for="v in f.adulterado?.veces || []"><tr>
                <td x-text="v.nivel + '.ª'"></td>
                <td x-text="fechaCorta(v.fecha) + (v.litros != null ? ' · ' + num(v.litros) + ' L' : '')"></td>
                <td class="num" x-text="v.agua_anadida != null ? num(v.agua_anadida) + ' %' : '—'"></td>
                <td><span class="chip" :class="v.medida === 'BAJA_DEFINITIVA' ? 'mal' : 'ojo'"
                          x-text="v.medida === 'BAJA_DEFINITIVA' ? 'Baja definitiva' : 'Precio reducido' + (v.precio_aplicado ? ' (' + soles(v.precio_aplicado) + '/L)' : '')"></span>
                    <div class="apoyo" style="color: var(--texto-3); font-size: 12px; margin-top: 3px" x-show="v.analista" x-text="'Analizó: ' + v.analista + (v.muestra ? ' · muestra de ' + v.muestra.toLowerCase() : '')"></div></td></tr></template>
        </table>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cerrar</button></div>
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
        <div class="dos"><button class="btn" :disabled="!!f.enviando" @click="confirmarPago('EFECTIVO')">Efectivo</button>
            <button class="btn" :disabled="!!f.enviando" @click="confirmarPago('YAPE')">Yape</button></div>
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cancelar</button></div>
    </div>

    {{-- anular una orden de compra que no llegó --}}
    <div class="ventana" x-show="ventana === 'anularOrden'">
        <h3 x-text="'Anular ' + (f.orden?.codigo || '')"></h3>
        <p class="sub" x-text="f.orden ? f.orden.proveedor + ' · ' + resumenItems(f.orden) : ''"></p>
        <p class="sub">La orden deja de estar "por llegar". El stock no cambia porque todavía no llegó nada.</p>
        <label>¿Por qué se anula?</label><input x-model="f.motivo" placeholder="Ej. se pidió al vendedor equivocado">
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cancelar</button>
            <button class="btn rojo" @click="anularOrden()" :disabled="!!f.enviando" x-text="f.enviando ? 'Guardando…' : 'Anular orden'"></button></div>
    </div>

    {{-- enviar nota por correo --}}
    <div class="ventana" x-show="ventana === 'correo'">
        <h3 x-text="'Enviar nota ' + (f.numero || '')"></h3>
        <label>Correo del cliente</label><input type="email" x-model="f.correo" placeholder="cliente@gmail.com">
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cancelar</button>
            <button class="btn" @click="enviarCorreo()" :disabled="!!f.enviando" x-text="f.enviando ? 'Enviando…' : 'Enviar'"></button></div>
    </div>

    {{-- registrar pérdida de leche / queso / yogurt --}}
    <div class="ventana" x-show="ventana === 'merma'">
        <h3>Registrar pérdida</h3>
        <div class="opciones" style="margin-top: 10px">
            <button :class="{ activo: f.tipo === 'LECHE' }" @click="f.tipo = 'LECHE'; f.motivo = null; f.lote_id = null">Leche</button>
            <template x-for="p in f.datos?.productos || []" :key="p.id">
                <button :class="{ activo: f.tipo === 'PRODUCTO' && f.producto_id === p.id }"
                        @click="f.tipo = 'PRODUCTO'; f.producto_id = p.id; f.motivo = null; f.lote_id = null" x-text="p.nombre"></button>
            </template>
        </div>
        <p class="sub" style="margin: 10px 0 0" x-show="f.datos"
           x-text="f.tipo === 'LECHE' ? 'Hay ' + num(f.datos?.leche.sin_usar) + ' L de leche sin procesar. Se descuenta de la más antigua.' : 'Hay ' + num(hayParaMerma().cant, 2) + ' ' + hayParaMerma().u + ' en almacén.'"></p>
        <label>¿Qué pasó?</label>
        <div class="opciones">
            <template x-for="(texto, clave) in f.datos?.motivos?.[f.tipo] || {}" :key="f.tipo + clave">
                <button :class="{ activo: f.motivo === clave }" @click="f.motivo = clave" x-text="texto"></button>
            </template>
        </div>
        <label x-text="'¿Cuánto se perdió? (' + hayParaMerma().u + ')'"></label>
        <input type="number" min="0" :step="f.tipo === 'LECHE' ? 0.5 : 1" x-model="f.cantidad">
        <div x-show="f.tipo === 'PRODUCTO' && (productoMerma()?.lotes || []).length">
            <label>¿De qué lote era? (opcional)</label>
            <select x-model.number="f.lote_id">
                <option :value="null">No sé / varios lotes</option>
                <template x-for="l in productoMerma()?.lotes || []" :key="l.id"><option :value="l.id" x-text="l.codigo + ' · vence ' + fechaCorta(l.vence_el)"></option></template>
            </select>
        </div>
        <label x-text="f.motivo === 'OTRO' ? 'Explica qué pasó' : 'Detalle (opcional)'"></label>
        <input x-model="f.detalle" maxlength="200" placeholder="Ej.: se cortó por la tarde, olía agrio">
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cancelar</button>
            <button class="btn rojo" @click="guardarMerma()" :disabled="!!f.enviando || !f.datos" x-text="f.enviando ? 'Guardando…' : 'Registrar pérdida'"></button></div>
    </div>

    {{-- cambio de comunidad registrado por el admin (el productor llamó o vino a Huata) --}}
    <div class="ventana" x-show="ventana === 'cambio'">
        <h3 x-text="f.editar ? 'Corregir traslado' : 'Cambio de comunidad'"></h3>
        <p class="sub" x-text="f.editar ? 'Si te equivocaste de lugar, de fecha o de cómo avisó, corrígelo aquí.' : 'El productor llamó o vino a Huata. El cambio queda hecho al guardar.'"></p>
        <div x-show="!f.proveedor">
            <label>Busca al productor</label>
            <input x-model="f.buscar" placeholder="Nombre o DNI">
            <table class="lista" style="margin-top: 8px" x-show="(f.buscar || '').trim().length >= 2">
                <template x-for="p in productoresCambio()" :key="p.id">
                    <tr class="clic" @click="f.proveedor = p"><td x-text="p.nombre"></td><td x-text="'DNI ' + p.dni"></td><td x-text="p.comunidad"></td></tr>
                </template>
                <tr x-show="!productoresCambio().length"><td colspan="3">No se encontró. Revisa el nombre o el DNI.</td></tr>
            </table>
        </div>
        <div x-show="f.proveedor">
            <div class="tarjeta" style="padding: 10px 14px">
                <strong x-text="f.proveedor?.nombre"></strong> <span class="sub" x-text="'· DNI ' + (f.proveedor?.dni || '')"></span>
                <div x-show="!f.editar" x-text="'Ahora está en ' + (f.proveedor?.comunidad || '') + ' (vehículo ' + (f.proveedor?.vehiculo || '—') + ')'"></div>
                <div x-show="f.editar" x-text="'Sale de ' + (f.traslado?.origen || '') + (f.traslado?.programado ? ' · se traslada el ' + fechaCorta(f.traslado?.desde) : ' · se trasladó el ' + fechaCorta(f.traslado?.desde))"></div>
                <a href="#" x-show="!f.fijo" @click.prevent="f.proveedor = null">Elegir otro</a>
            </div>
            <label x-text="f.editar ? '¿A qué comunidad va?' : '¿A qué comunidad se cambia?'"></label>
            <select x-model.number="f.comunidad_id">
                <option :value="null">Elige…</option>
                <template x-for="c in (f.datos?.comunidades || []).filter((c) => c.id !== (f.editar ? f.traslado?.origen_id : f.proveedor?.comunidad_id))" :key="c.id">
                    <option :value="c.id" :selected="c.id === f.comunidad_id" :disabled="!c.vehiculo" x-text="c.nombre + (c.vehiculo ? ' · lo recoge ' + c.vehiculo + (c.acopiador ? ' (' + c.acopiador + ')' : '') : ' · ningún vehículo pasa por ahí')"></option>
                </template>
            </select>
            <label>¿Cómo avisó?</label>
            <div class="opciones">
                <button :class="{ activo: f.canal === 'LLAMADA' }" @click="f.canal = 'LLAMADA'">📞 Llamó por teléfono</button>
                <button :class="{ activo: f.canal === 'OFICINA' }" @click="f.canal = 'OFICINA'">🏠 Vino a Huata</button>
                <button x-show="f.traslado?.canal === 'APP'" :class="{ activo: f.canal === 'APP' }" @click="f.canal = 'APP'">📱 Por la app</button>
            </div>
            <div x-show="!f.editar || f.traslado?.programado">
            <label>¿Desde cuándo?</label>
            <div class="opciones">
                <button :class="{ activo: f.cuando === 'hoy' }" @click="f.cuando = 'hoy'">Desde hoy</button>
                <button :class="{ activo: f.cuando === 'manana' }" @click="f.cuando = 'manana'">Desde mañana</button>
                <button :class="{ activo: f.cuando === 'fecha' }" @click="f.cuando = 'fecha'">Otra fecha</button>
            </div>
            <input type="date" x-show="f.cuando === 'fecha'" x-model="f.fecha" style="margin-top: 8px">
            </div>
            <p class="sub" style="margin-top: 10px" x-show="f.editar && !f.traslado?.programado">Ya se hizo, así que su fecha no se cambia. Si no iba, usa “Anular traslado”: vuelve a su comunidad anterior.</p>
            <label>Motivo (opcional)</label><input x-model="f.motivo" maxlength="300" placeholder="Ej.: se mudó, queda más cerca del camino">
        </div>
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie">
            <button class="btn rojo" x-show="f.editar && f.traslado" @click="anularTraslado()" :disabled="!!f.enviando">Anular traslado</button>
            <div class="espacio"></div>
            <button class="btn gris" @click="ventana = null">Cancelar</button>
            <button class="btn" x-show="f.proveedor" @click="guardarCambio()" :disabled="!!f.enviando"
                    x-text="f.enviando ? 'Guardando…' : (f.editar ? 'Guardar corrección' : 'Guardar cambio')"></button></div>
    </div>

    {{-- ficha del proveedor: todo en un lugar (leche, pagos, comunidad) y solo 3 acciones --}}
    <div class="ventana ancha" x-show="ventana === 'ficha'">
        <template x-if="!f.ficha"><p class="sub" x-text="f.error || 'Cargando…'"></p></template>
        <template x-if="f.ficha"><div>
            <div class="fila" style="align-items: flex-start">
                <div style="flex: 1">
                    <h3 x-text="f.ficha.proveedor.nombre"></h3>
                    <div class="sub" style="margin: 0" x-text="'DNI ' + f.ficha.proveedor.dni + ' · inscrito el ' + fechaCorta(f.ficha.proveedor.inscrito)
                        + (f.ficha.proveedor.vacas ? ' · ' + f.ficha.proveedor.vacas + ' vacas' : '') + (f.ficha.proveedor.usa_app ? ' · usa la app' : ' · no usa la app')"></div>
                </div>
                <span class="chip" :class="f.ficha.proveedor.estado === 'ACTIVO' ? 'ok' : 'mal'" x-text="f.ficha.proveedor.estado === 'ACTIVO' ? 'Activo' : 'De baja'"></span>
            </div>
            <div class="aviso error" x-show="f.ficha.proveedor.motivo_baja" x-text="'De baja: ' + f.ficha.proveedor.motivo_baja"></div>
            <p style="margin: 10px 0 0">📍 <strong x-text="f.ficha.proveedor.comunidad"></strong>
                <span x-text="' · lo recoge ' + (f.ficha.proveedor.vehiculo || '—') + (f.ficha.proveedor.acopiador ? ' (' + f.ficha.proveedor.acopiador + ')' : '')"></span>
                <span class="etiqueta programado" x-show="f.ficha.proveedor.programado"
                      x-text="'⏳ Pasa a ' + f.ficha.proveedor.programado?.comunidad + ' el ' + fechaCorta(f.ficha.proveedor.programado?.desde)"></span></p>

            <div class="tarjetas" style="margin-top: 14px">
                <div class="tarjeta destacada"><div class="eti">Leche por día</div>
                    <div class="valor" x-text="f.ficha.leche.por_dia ? num(f.ficha.leche.por_dia) + ' L' : '—'"></div>
                    <div class="apoyo" x-text="(f.ficha.leche.dias_30 || 0) + ' días entregó en 30' + (f.ficha.proveedor.promedio ? ' · dijo ' + num(f.ficha.proveedor.promedio) + ' L' : '')"></div></div>
                <div class="tarjeta"><div class="eti">Esta semana</div><div class="valor" x-text="num(f.ficha.leche.litros_7) + ' L'"></div>
                    <div class="apoyo" x-text="'30 días: ' + num(f.ficha.leche.litros_30, 0) + ' L'"></div></div>
                <div class="tarjeta"><div class="eti">Por pagarle</div>
                    <div class="valor" :class="f.ficha.pagos.por_pagar > 0 ? 'ojo' : 'ok'" x-text="soles(f.ficha.pagos.por_pagar)"></div>
                    <div class="apoyo" x-text="'semana en curso ' + soles(f.ficha.pagos.semana_en_curso.monto) + ' (se paga el ' + fechaCorta(f.ficha.pagos.semana_en_curso.se_paga) + ')'"></div></div>
            </div>

            {{-- entregas y pagos juntos, una fila por semana; al tocarla se ven sus días --}}
            <h2>Entregas y pagos por semana</h2>
            <table class="lista semanas">
                <thead><tr><th>Semana</th><th class="num">Días</th><th class="num">Litros</th><th class="num">Monto</th><th>¿Pagado?</th></tr></thead>
                {{-- cada semana es un tbody: su fila y la de sus días --}}
                <template x-for="s in f.ficha.semanas" :key="s.inicio">
                    <tbody>
                        <tr class="clic" @click="f.abierta = f.abierta === s.inicio ? null : s.inicio">
                            <td><span x-text="(f.abierta === s.inicio ? '▾ ' : '▸ ') + fechaCorta(s.inicio) + ' – ' + fechaCorta(s.fin)"></span></td>
                            <td class="num" x-text="s.dias"></td>
                            <td class="num"><span x-text="num(s.litros) + ' L'"></span>
                                <div class="sub" style="margin: 0; font-size: 12px" x-show="s.litros_aceptados < s.litros" x-text="num(s.litros_aceptados) + ' L aceptados'"></div></td>
                            <td class="num" x-text="soles(s.monto)"></td>
                            <td><span class="chip" :class="{ ok: s.estado === 'PAGADO', ojo: s.estado === 'PENDIENTE' }"
                                      x-text="s.estado === 'PAGADO' ? '✓ ' + fechaCorta(s.pagado_el) + (s.metodo ? ' · ' + (s.metodo === 'YAPE' ? 'Yape' : 'efectivo') : '')
                                            : (s.estado === 'EN_CURSO' ? 'en curso · se paga el ' + fechaCorta(s.fin) : 'Pendiente')"></span></td>
                        </tr>
                        <tr x-show="f.abierta === s.inicio" class="dias-semana"><td colspan="5">
                            <template x-for="e in s.detalle" :key="e.fecha">
                                <span class="dia" :class="{ mal: e.calidad === 'RECHAZADO' }"
                                      x-text="fechaCorta(e.fecha) + ': ' + (e.ausente ? 'no entregó' : num(e.litros) + ' L' + (e.calidad === 'RECHAZADO' ? ' (rechazada)' : (e.calidad === 'PENDIENTE' ? ' (por analizar)' : '')))"></span>
                            </template>
                        </td></tr>
                    </tbody>
                </template>
                <tbody x-show="!f.ficha.semanas.length"><tr><td colspan="5">Todavía no entregó leche.</td></tr></tbody>
            </table>
            <div class="fila" style="margin-top: 6px">
                <span class="sub" style="margin: 0" x-text="'En total se le pagó ' + soles(f.ficha.pagos.pagado_total) + '.'"></span>
                <div class="espacio"></div>
                <button class="btn chico gris" x-show="f.ficha.hay_mas" @click="verMasSemanas()">Ver semanas anteriores</button>
            </div>

            <h2>Comunidades</h2>
            <ol class="historial">
                <template x-for="(h, k) in f.ficha.historial" :key="k">
                    <li :class="{ actual: k === f.ficha.historial.filter((x) => !x.futuro).length - 1, futuro: h.futuro }">
                        <strong x-text="h.comunidad"></strong>
                        <span class="sub" style="margin: 0" x-text="(h.futuro ? ' · desde el ' : ' · ') + fechaCorta(h.desde) + ' · ' + h.como + (h.quien ? ' (registró ' + h.quien + ')' : '')"></span>
                        <div class="sub" style="margin: 0" x-show="h.motivo" x-text="'Motivo: ' + h.motivo"></div>
                    </li>
                </template>
            </ol>

            <div class="pie">
                <button class="btn rojo" x-show="f.ficha.proveedor.estado !== 'BAJA'" @click="abrirEliminarProveedor()">Eliminar</button>
                <div class="espacio"></div>
                <button class="btn gris" @click="ventana = null">Cerrar</button>
                <button class="btn gris" x-show="f.ficha.proveedor.estado !== 'BAJA' && !f.ficha.proveedor.programado" @click="abrirCambio(f.ficha.proveedor.id)">Trasladar</button>
                <button class="btn" x-show="f.ficha.proveedor.estado !== 'BAJA'" @click="abrirEditarProveedor()">Editar</button>
            </div>
        </div></template>
    </div>

    {{-- editar proveedor: corregir errores, también la comunidad --}}
    <div class="ventana" x-show="ventana === 'editarProveedor'">
        <h3>Editar proveedor</h3>
        <p class="sub">Para corregir errores. Si se mudó de verdad, usa “Trasladar” (así queda en su historial).</p>
        <label>Nombre y apellidos</label><input x-model="f.nombre">
        <label>DNI</label><input x-model="f.dni" maxlength="8" @input="f.dni = f.dni.replace(/\D/g, '')">
        <div class="dos">
            <div><label>Litros por día que dice dar (opcional)</label><input type="number" min="0" step="0.5" x-model="f.promedio"></div>
            <div><label>Vacas que ordeña (opcional)</label><input type="number" min="0" step="1" x-model="f.vacas"></div>
        </div>
        <p class="sub" style="margin-top: 10px" x-show="f.leche?.por_dia" x-text="'En los últimos 30 días entregó en promedio ' + num(f.leche?.por_dia) + ' L por día.'"></p>
        <label x-text="f.programado ? 'Comunidad a la que se traslada el ' + fechaCorta(f.programado.desde) : 'Comunidad'"></label>
        <select x-model.number="f.comunidad_id">
            <template x-for="c in f.comunidades || []" :key="c.id">
                <option :value="c.id" :selected="c.id === f.comunidad_id" :disabled="!c.vehiculo"
                        x-text="c.nombre + (c.vehiculo ? ' · lo recoge ' + c.vehiculo : ' · ningún vehículo pasa')"></option>
            </template>
        </select>
        <div class="aviso" x-show="f.comunidad_id && f.comunidad_id !== f.comunidad_original"
             x-text="f.programado
                ? 'Se corrige el destino del traslado programado. Sigue en su comunidad hasta el ' + fechaCorta(f.programado.desde) + '.'
                : 'Se corrige su comunidad: desde ahora lo recoge el vehículo de esa ruta. Sus entregas y pagos pasados no cambian.' + (f.ultimoTraslado ? ' Si el error fue en el traslado, ese mismo traslado se corrige (no se cuenta como uno nuevo).' : '')"></div>
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="volverAFicha()">Cancelar</button>
            <button class="btn" @click="guardarEditarProveedor()" :disabled="!!f.enviando" x-text="f.enviando ? 'Guardando…' : 'Guardar cambios'"></button></div>
    </div>

    {{-- eliminar proveedor: sin entregas se borra; con entregas se da de baja --}}
    <div class="ventana" x-show="ventana === 'eliminarProveedor'">
        <h3 x-text="(f.modo === 'baja' ? 'Dar de baja a ' : 'Eliminar a ') + (f.nombre || '')"></h3>
        <template x-if="f.modo === 'eliminar'">
            <p>Nunca entregó leche: se borra del padrón por completo. Úsalo si se registró por error.</p>
        </template>
        <template x-if="f.modo === 'baja'">
            <div>
                <div class="aviso" x-text="f.aviso || ('Ya entregó leche (' + (f.entregas || 0) + ' entregas): no se puede borrar sin perder sus entregas y pagos.')"></div>
                <p>Se <strong>da de baja</strong>: ya no aparece en las rutas ni en la app del acopiador, y no puede entrar a la app. Su historia y pagos se quedan guardados.</p>
                <label>¿Por qué se da de baja?</label>
                <input x-model="f.motivo" maxlength="300" placeholder="Ej.: vendió sus vacas, se fue a vivir a Juliaca">
            </div>
        </template>
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="volverAFicha()">Cancelar</button>
            <button class="btn rojo" @click="confirmarEliminarProveedor()" :disabled="!!f.enviando"
                    x-text="f.enviando ? 'Guardando…' : (f.modo === 'baja' ? 'Dar de baja' : 'Eliminar')"></button></div>
    </div>

    {{-- historial de comunidades de un proveedor --}}
    <div class="ventana" x-show="ventana === 'historial'">
        <h3 x-text="f.historial?.proveedor.nombre || f.nombre"></h3>
        <p class="sub" x-text="f.historial ? 'DNI ' + f.historial.proveedor.dni + ' · ahora en ' + f.historial.proveedor.comunidad : 'Cargando…'"></p>
        <ol class="historial">
            <template x-for="(h, k) in f.historial?.pasos || []" :key="k">
                <li :class="{ actual: k === f.historial.pasos.filter((x) => !x.futuro).length - 1, futuro: h.futuro }">
                    <strong x-text="h.comunidad"></strong>
                    <span class="sub" style="margin: 0" x-text="(h.futuro ? ' · desde el ' : ' · ') + fechaCorta(h.desde) + ' · ' + h.como + (h.quien ? ' (registró ' + h.quien + ')' : '')"></span>
                    <div class="sub" style="margin: 0" x-show="h.motivo" x-text="'Motivo: ' + h.motivo"></div>
                    <span class="chip ojo" x-show="h.futuro">programado</span>
                </li>
            </template>
        </ol>
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cerrar</button>
            <button class="btn" x-show="f.historial && !f.programado" @click="abrirCambio(f.id)">Cambiar comunidad</button></div>
    </div>

    {{-- confirmar: iniciar el lote con la fórmula --}}
    <div class="ventana" x-show="ventana === 'producir'">
        <h3 x-text="'Hacer ' + num(f.cantidad, 0) + ' ' + (f.producto ? unidadProducto(f.producto) : '') + ' de ' + (f.producto?.nombre || '')"></h3>
        <p class="sub">Al aceptar se inicia el lote y se descuenta del almacén:</p>
        <table class="lista">
            <tr><td>Leche del tanque</td><td class="num"><strong x-text="num(f.litros, 0) + ' L'"></strong></td></tr>
            <template x-for="i in f.producto?.insumos || []" :key="i.id">
                <tr><td x-text="i.nombre"></td><td class="num"><strong x-text="num(i.por_100l * f.litros / 100, 3) + ' ' + i.unidad"></strong></td></tr>
            </template>
        </table>
        <p class="sub" style="margin-top: 12px" x-show="f.producto"
           x-text="'Deben salir entre ' + num(f.litros * (f.producto?.rendimiento_min || 0), 0) + ' y ' + num(f.litros * (f.producto?.rendimiento_max || 0), 0) + ' ' + (f.producto ? unidadProducto(f.producto) : '') + '. Vence en ' + (f.producto?.vida_util_dias || '') + ' días.'"></p>
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cancelar</button>
            <button class="btn" @click="confirmarProducir()" :disabled="!!f.enviando" x-text="f.enviando ? 'Iniciando…' : 'Aceptar e iniciar lote'"></button></div>
    </div>

    {{-- ajuste de kardex --}}
    <div class="ventana" x-show="ventana === 'ajuste'">
        <h3 x-text="'Ajuste de ' + (d.item?.nombre || '')"></h3>
        <p class="sub" x-text="'Stock actual: ' + num(d.item?.stock, 3) + ' ' + (d.item?.unidad || '') + '. Para lo que no es compra ni producción.'"></p>
        <div class="opciones">
            <button :class="{ activo: f.tipo === 'SALIDA' }" @click="f.tipo = 'SALIDA'">Salida (merma, vencido, faltante)</button>
            <button :class="{ activo: f.tipo === 'ENTRADA' }" @click="f.tipo = 'ENTRADA'">Entrada (sobrante, devolución)</button></div>
        <label x-text="'Cantidad (' + (d.item?.unidad || '') + ')'"></label><input type="number" min="0" step="0.001" x-model="f.cantidad">
        <label>Motivo</label><input x-model="f.motivo" maxlength="120" placeholder="Ej.: bolsa de azúcar mojada">
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cancelar</button>
            <button class="btn" @click="guardarAjuste()" :disabled="!!f.enviando" x-text="f.enviando ? 'Guardando…' : 'Registrar ajuste'"></button></div>
    </div>

    {{-- editar fórmula (solo admin) --}}
    <div class="ventana" x-show="ventana === 'formula'">
        <h3 x-text="'Fórmula de ' + (f.producto?.nombre || '')"></h3>
        <p class="sub">Cantidad de cada insumo por cada 100 L de leche. Los lotes ya iniciados no cambian.</p>
        <template x-for="(x, k) in f.insumos || []" :key="k">
            <div class="fila" style="margin-bottom: 8px">
                <select x-model.number="x.insumo_id" style="flex: 2; min-width: 160px">
                    <option :value="null">Elige…</option>
                    <template x-for="i in d.insumos || []" :key="i.id"><option :value="i.id" :selected="i.id === x.insumo_id" x-text="i.nombre"></option></template>
                </select>
                <input type="number" min="0" step="0.001" x-model="x.por_100l" style="flex: 1; min-width: 90px">
                <span style="width: 34px" x-text="unidadInsumo(x.insumo_id)"></span>
                <button class="btn chico rojo" @click="f.insumos.splice(k, 1)">Quitar</button>
            </div>
        </template>
        <button class="btn chico gris" @click="f.insumos.push({ insumo_id: null, por_100l: '' })">+ Agregar insumo</button>
        <label>Pasos (uno por línea)</label>
        <textarea x-model="f.pasos" rows="8"></textarea>
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cancelar</button>
            <button class="btn" @click="guardarFormula()" :disabled="!!f.enviando" x-text="f.enviando ? 'Guardando…' : 'Guardar fórmula'"></button></div>
    </div>

    {{-- nueva orden de compra --}}
    <div class="ventana ancha" x-show="ventana === 'orden'">
        <h3>Nueva orden de compra</h3>
        <label>¿A quién se le compra?</label>
        <div class="fila" style="gap: 8px">
            <select x-model.number="f.vendedor_id" style="flex: 1">
                <option :value="null">Elige el vendedor…</option>
                <template x-for="v in d.vendedores || []" :key="v.id"><option :value="v.id" :selected="f.vendedor_id === v.id" x-text="v.nombre + ' · ' + v.tipo_documento + ' ' + v.documento"></option></template>
            </select>
            <button class="btn chico gris" @click="abrirVendedor(null, true)">+ Nuevo vendedor</button>
        </div>
        <label>Insumos</label>
        <template x-for="(it, k) in f.items" :key="k">
            <div class="fila" style="gap: 8px; margin-bottom: 6px">
                <select x-model.number="it.insumo_id" style="flex: 2">
                    <option :value="null">Elige el insumo…</option>
                    <template x-for="i in d.inv?.insumos || []" :key="i.id"><option :value="i.id" :selected="it.insumo_id === i.id" x-text="i.nombre + ' · hay ' + num(i.stock, 3) + ' ' + i.unidad"></option></template>
                </select>
                <input type="number" min="0" step="0.001" x-model="it.cantidad" placeholder="Cantidad" style="flex: 1">
                <span style="width: 40px" x-text="insumoDe(it.insumo_id)?.unidad || ''"></span>
                <button class="btn chico gris" x-show="f.items.length > 1" @click="f.items.splice(k, 1)">Quitar</button>
            </div>
        </template>
        <button class="btn chico gris" @click="f.items.push({ insumo_id: null, cantidad: '' })">+ Otro insumo</button>
        <p class="sub" x-text="'Costo estimado (según la última compra): ' + soles(costoEstimadoOrden())"></p>
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cancelar</button>
            <button class="btn" @click="emitirOrden()" :disabled="!!f.enviando" x-text="f.enviando ? 'Emitiendo…' : 'Emitir orden'"></button></div>
    </div>

    {{-- llegó la compra: comprobante, lo pagado y la foto --}}
    <div class="ventana ancha" x-show="ventana === 'recibir'">
        <h3 x-text="'Llegó ' + (f.orden?.codigo || '')"></h3>
        <p class="sub" x-text="f.orden ? f.orden.proveedor + ' · ' + resumenItems(f.orden) : ''"></p>
        <template x-if="f.orden && !f.orden.vendedor"><div>
            <label>Vendedor (esta orden antigua no lo tenía)</label>
            <select x-model.number="f.vendedor_id">
                <option :value="null">Sin DNI/RUC</option>
                <template x-for="v in d.vendedores || []" :key="v.id"><option :value="v.id" x-text="v.nombre + ' · ' + v.tipo_documento + ' ' + v.documento"></option></template>
            </select>
        </div></template>
        <label>¿Qué comprobante te dieron?</label>
        <div class="opciones">
            <template x-for="(texto, clave) in d.comprobantes || {}" :key="clave">
                <button :class="{ activo: f.tipo === clave }" @click="f.tipo = clave" x-text="texto"></button>
            </template>
        </div>
        <p class="sub" style="margin: 6px 0 0" x-show="f.tipo === 'FACTURA' && vendedorRecibir()?.tipo_documento !== 'RUC'">Ojo: solo hay factura si el vendedor tiene RUC.</p>
        <div class="dos" x-show="f.tipo && f.tipo !== 'RECIBO'">
            <div><label>Serie</label><input x-model="f.serie" maxlength="4" :placeholder="f.tipo === 'FACTURA' ? 'F001' : 'B001'" @input="f.serie = f.serie.toUpperCase()"></div>
            <div><label>Número</label><input x-model="f.numero" maxlength="8" placeholder="00001234" @input="f.numero = f.numero.replace(/\D/g, '')"></div>
        </div>
        <label>Fecha del comprobante</label><input type="date" x-model="f.fecha" :max="hoy()">
        <label>¿Cuánto se pagó por cada insumo? (con IGV)</label>
        <table class="lista"><template x-for="it in f.orden?.items || []" :key="it.id"><tr>
            <td x-text="num(it.cantidad, 3) + ' ' + it.unidad + ' ' + it.insumo"></td>
            <td class="num" style="width: 140px"><input type="number" min="0" step="0.01" x-model="f.pagos[it.id]"></td></tr></template>
            <tr x-show="f.tipo === 'FACTURA'"><td>Subtotal (sin IGV)</td><td class="num" x-text="soles(subtotalFactura())"></td></tr>
            <tr x-show="f.tipo === 'FACTURA'"><td>IGV 18 %</td><td class="num" x-text="soles(totalRecibir() - subtotalFactura())"></td></tr>
            <tr><td><strong>Total</strong></td><td class="num"><strong x-text="soles(totalRecibir())"></strong></td></tr>
        </table>
        <label>Foto del comprobante (opcional)</label>
        <input type="file" accept="image/*" @change="elegirFoto($event)">
        <img x-show="f.foto" :src="f.foto" alt="Foto del comprobante" style="max-height: 160px; margin-top: 8px; border-radius: 8px">
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cancelar</button>
            <button class="btn" @click="recibirOrden()" :disabled="!!f.enviando" x-text="f.enviando ? 'Guardando…' : 'Registrar y subir stock'"></button></div>
    </div>

    {{-- vendedor de insumos --}}
    <div class="ventana" x-show="ventana === 'vendedor'">
        <h3 x-text="f.id ? 'Editar vendedor' : 'Nuevo vendedor'"></h3>
        <div class="opciones" style="margin-top: 10px">
            <button :class="{ activo: f.tipo_documento === 'RUC' }" @click="f.tipo_documento = 'RUC'">Empresa (RUC)</button>
            <button :class="{ activo: f.tipo_documento === 'DNI' }" @click="f.tipo_documento = 'DNI'">Persona (DNI)</button>
        </div>
        <label x-text="f.tipo_documento === 'RUC' ? 'RUC (11 dígitos)' : 'DNI (8 dígitos)'"></label>
        <input x-model="f.documento" :maxlength="f.tipo_documento === 'RUC' ? 11 : 8" @input="f.documento = f.documento.replace(/\D/g, '')">
        <label x-text="f.tipo_documento === 'RUC' ? 'Razón social' : 'Nombre y apellidos'"></label><input x-model="f.nombre">
        <div class="dos">
            <div><label>Teléfono (opcional)</label><input x-model="f.telefono"></div>
            <div><label>Dirección (opcional)</label><input x-model="f.direccion"></div>
        </div>
        <label style="display:flex; gap:8px; align-items:center" x-show="f.id"><input type="checkbox" x-model="f.activo" style="width:auto"> Activo (si no, ya no sale para nuevas órdenes)</label>
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="cerrarVendedor()">Cancelar</button>
            <button class="btn" @click="guardarVendedor()" :disabled="!!f.enviando" x-text="f.enviando ? 'Guardando…' : 'Guardar'"></button></div>
    </div>
</div>

</body>
</html>
