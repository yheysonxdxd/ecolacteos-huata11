<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ecolácteos Huata · Oficina</title>
{{-- logo: la vaca frente al arco, con totora y el lago (el mismo de la app del celular) --}}
<link rel="icon" type="image/svg+xml" href="/oficina-web/img/logo-chico.svg">
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">
<link rel="apple-touch-icon" href="/oficina-web/img/logo-180.png">
<meta name="theme-color" content="#0f1a24">
{{-- tema de día o de noche: lo que se eligió en esta computadora, o si no, el del sistema. Antes de dibujar, para que no parpadee --}}
<script>
    (function () {
        var t = null;
        try { t = localStorage.getItem('tema'); } catch (e) {}
        if (t !== 'dia' && t !== 'noche') t = window.matchMedia && matchMedia('(prefers-color-scheme: light)').matches ? 'dia' : 'noche';
        document.documentElement.dataset.tema = t;
        if (t === 'dia') document.querySelector('meta[name=theme-color]').content = '#f3f0e8';
    })();
</script>
<link rel="stylesheet" href="/oficina-web/vendor/leaflet/leaflet.css">
<script src="/oficina-web/vendor/leaflet/leaflet.js"></script>
<link rel="stylesheet" href="/oficina-web/estilos.css?v={{ filemtime(public_path('oficina-web/estilos.css')) }}">
<link rel="stylesheet" href="/oficina-web/tema-dia.css">
<link rel="stylesheet" href="/oficina-web/tema-dia-base.css">
<script src="/oficina-web/app.js"></script>
<script defer src="/oficina-web/vendor/alpine.min.js"></script>
</head>
<body x-data="oficina()" x-init="iniciar()">

{{-- ============================================================ LOGIN --}}
<div class="login" x-show="!usuario" x-cloak>
    {{-- foto real: vaca Brown Swiss (la raza lechera más común en Puno) en el pasto; crédito abajo a la derecha --}}
    <div class="escena" aria-hidden="true"></div>
    <div class="brillos" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>

    <header class="login-top">
        <div class="marca-top"><img src="/oficina-web/img/logo.svg" alt="" width="40" height="40"><span>Ecolácteos <em>Huata</em></span></div>
        <div class="reloj" x-text="horaEntrada()"></div>
    </header>

    <div class="login-intro">
        <span class="saludo"><i aria-hidden="true" x-text="saludoEntrada().icono"></i><span x-text="saludoEntrada().texto + ' · ' + fechaEntrada()"></span></span>
        <h1 class="titulo-entrada">De la vaca<br>a la <em>mesa</em>,<br>todo en un lugar.</h1>
        <p>El acopio de leche, su calidad, la planta y las ventas de la Planta Ecolácteos de Huata.</p>
        {{-- el camino de la leche: una gota recorre las cuatro estaciones y cada una se prende al pasar --}}
        <ol class="camino" aria-label="El camino de la leche">
            <li style="--i:0"><span class="estacion">🥛</span><b>Acopio</b><small>en el campo</small></li>
            <li style="--i:1"><span class="estacion">🔬</span><b>Calidad</b><small>en el laboratorio</small></li>
            <li style="--i:2"><span class="estacion">🧀</span><b>Planta</b><small>quesos y yogures</small></li>
            <li style="--i:3"><span class="estacion">🛒</span><b>Ventas</b><small>y cobranzas</small></li>
            <span class="riel" aria-hidden="true"><span class="gota"></span></span>
        </ol>
    </div>

    <form x-ref="formEntrada" @submit.prevent="entrar()" :class="{ listo: login.listo }" novalidate>
        {{-- arriba de la tarjeta: a qué parte se entra, el saludo grande y qué hacer (el logo ya está en la barra de arriba) --}}
        <span class="marca-sub"><i aria-hidden="true"></i>Oficina · Administración y Compras</span>
        <h2 class="bienvenida">Bienvenido <span class="mano" aria-hidden="true">👋</span></h2>
        <p class="indica">Ingresa con tu DNI y tu contraseña.</p>

        <label class="campo" :class="{ lleno: login.dni }">
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><circle cx="9" cy="11" r="2.2"/><path d="M5.8 16c.6-1.6 1.8-2.4 3.2-2.4s2.6.8 3.2 2.4M14 10h4M14 13.5h3"/></svg>
            <input x-model="login.dni" inputmode="numeric" maxlength="8" autocomplete="username" autofocus placeholder=" "
                   @input="login.dni = login.dni.replace(/\D/g, ''); login.error = null">
            <span class="etiqueta">DNI</span>
            <span class="digitos" aria-hidden="true"><template x-for="n in 8" :key="n"><i :class="{ si: login.dni.length >= n }"></i></template></span>
        </label>

        <label class="campo" :class="{ lleno: login.clave }">
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/><circle cx="12" cy="15.5" r="1.4"/></svg>
            <input :type="login.ver ? 'text' : 'password'" x-model="login.clave" autocomplete="current-password" placeholder=" "
                   @input="login.error = null" @keyup="login.mayus = $event.getModifierState?.('CapsLock')" @keydown="login.mayus = $event.getModifierState?.('CapsLock')">
            <span class="etiqueta">Contraseña</span>
            <button type="button" class="ojo" @click="login.ver = !login.ver" :aria-label="login.ver ? 'Ocultar contraseña' : 'Ver contraseña'" :title="login.ver ? 'Ocultar' : 'Ver'">
                <svg x-show="!login.ver" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg x-show="login.ver" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18M10.6 6.1A9.6 9.6 0 0 1 12 6c6 0 9.5 6 9.5 6a16 16 0 0 1-3 3.6M6.4 7.6A16 16 0 0 0 2.5 12S6 18.5 12 18.5a9 9 0 0 0 4.1-1M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
            </button>
        </label>
        <div class="mayus" x-show="login.mayus" x-transition>⇪ Las mayúsculas están activadas</div>

        <div class="aviso error" x-show="login.error" x-transition x-text="login.error" role="alert"></div>
        <button class="btn-entrar" :disabled="login.enviando || login.listo">
            <span x-show="!login.enviando && !login.listo">Ingresar <span class="flecha" aria-hidden="true">→</span></span>
            <span x-show="login.enviando" class="girando" aria-hidden="true"></span><span x-show="login.enviando">Ingresando…</span>
            <span x-show="login.listo">✓ ¡Listo!</span>
        </button>
        <p class="ayuda">¿Olvidaste tu contraseña? Pídela a la administración de la planta.</p>
    </form>

    <footer class="login-pie">
        <div class="leyenda">
            <strong>Brown Swiss en el pasto, la raza lechera de Puno</strong>
            <a href="https://commons.wikimedia.org/wiki/File:Braunvieh_am_Simplonpass,_Wallis_(Schweiz).jpg" target="_blank" rel="noopener">Foto: Olga Ernst · CC BY-SA 4.0</a>
        </div>
    </footer>
</div>

{{-- ============================================================ APP --}}
<div class="app" x-show="usuario" x-cloak>
    <nav class="menu">
        <div class="marca"><img class="sello" src="/oficina-web/img/logo.svg" alt="" width="38" height="38"><span>Ecolácteos <em>Huata</em></span></div>
        <div class="rol"><span class="avatar" aria-hidden="true" x-text="(usuario?.nombre || '?').trim().charAt(0).toUpperCase()"></span>
            <span><strong x-text="usuario?.nombre || ''"></strong><small x-text="ROLES[usuario?.rol] || ''"></small></span></div>
        <template x-for="(m, i) in menu()" :key="m.id">
            <div>
                <div class="grupo" x-show="i === 0 || menu()[i - 1].grupo !== m.grupo" x-text="m.grupo"></div>
                <button :class="{ activo: m.pestanas.some((p) => p.id === seccion) }" @click="ir(m.pestanas[0].id)">
                    <span class="ico" aria-hidden="true" x-html="iconoMenu(m.id)"></span><span x-text="m.nombre"></span></button>
            </div>
        </template>
        <button class="tema" @click="cambiarTema($event)" :aria-label="tema === 'dia' ? 'Pasar al tema de noche' : 'Pasar al tema de día'">
            <span class="ico" aria-hidden="true" x-html="iconoMenu(tema === 'dia' ? 'luna' : 'sol')"></span><span x-text="tema === 'dia' ? 'Tema de noche' : 'Tema de día'"></span></button>
        <button class="salir" @click="salir()"><span class="ico" aria-hidden="true" x-html="iconoMenu('salir')"></span><span>Cerrar sesión</span></button>
    </nav>

    <main>
        {{-- pestañas de la opción del menú (ej. Calidad → Por vehículo | Adulteraciones) --}}
        <div class="pestanas no-imprimir" x-show="(opcionActual()?.pestanas.length || 0) > 1">
            <template x-for="p in opcionActual()?.pestanas || []" :key="p.id">
                <button :class="{ activo: seccion === p.id }" @click="ir(p.id)" x-text="p.nombre"></button>
            </template>
        </div>
        {{-- notificación: tarjeta flotante; la de éxito se va sola a los 7 s, la de error se queda hasta cerrarla --}}
        <div class="noti" :class="aviso?.error ? 'error' : ''" x-show="aviso" x-cloak role="status" aria-live="polite"
             x-init="$watch('aviso', (a) => { const n = (window.__noti = (window.__noti || 0) + 1);
                if (a && !a.error && !a.nota) setTimeout(() => { if (window.__noti === n) aviso = null }, 7000) })">
            <div class="noti-icono" x-text="aviso?.error ? '!' : (aviso?.icono || '✓')"></div>
            <div class="noti-cuerpo">
                <strong x-show="aviso?.titulo" x-text="aviso?.titulo"></strong>
                <span x-text="(aviso?.texto || '').replace(/^✓\s*/, '')"></span>
                <span class="noti-detalle" x-show="aviso?.detalle" x-text="aviso?.detalle"></span>
                <template x-if="aviso?.nota">
                    <span class="noti-detalle"><a href="#" @click.prevent="verNota(aviso.nota.id)">Ver / imprimir nota en PDF</a>
                        · <a href="#" @click.prevent="abrirCorreo(aviso.nota.id, aviso.nota.numero)">Enviar por correo</a></span>
                </template>
            </div>
            <button class="noti-cerrar" @click="aviso = null" aria-label="Cerrar aviso">✕</button>
        </div>
        <div class="aviso error" x-show="error" x-text="'No se pudo leer la base de datos: ' + error"></div>
        {{-- aviso en todas las pantallas: lotes vencidos o que vencen esta semana (en Almacén está la lista completa) --}}
        <div class="aviso-vence" x-show="porVencer.length && !avisoVenceCerrado && seccion !== 'inventario'" x-transition.opacity>
            <span class="av-ico" aria-hidden="true">⏳</span>
            <div class="av-texto">
                <strong x-text="[porVencer.filter((x) => x.vencido).length ? porVencer.filter((x) => x.vencido).length + (porVencer.filter((x) => x.vencido).length === 1 ? ' lote vencido' : ' lotes vencidos') : '',
                                 porVencer.filter((x) => !x.vencido).length ? porVencer.filter((x) => !x.vencido).length + ' por vencer esta semana' : ''].filter(Boolean).join(' · ')"></strong>
                <small x-text="porVencer.slice(0, 3).map((x) => x.producto + ' ' + x.codigo + ' (' + textoVence(x) + ')').join(' · ') + (porVencer.length > 3 ? ' · y ' + (porVencer.length - 3) + ' más' : '')"></small>
            </div>
            <button class="btn chico" @click="filtroLotes = ''; ir('lotes-almacen')">Ver lotes</button>
            <button class="cerrar-panel" @click="avisoVenceCerrado = true" aria-label="Cerrar aviso">✕</button>
        </div>
        {{-- mientras llegan los datos: la silueta de una pantalla (título, cifras y filas) que brilla --}}
        <div class="esqueleto" x-show="cargando" role="status" aria-label="Cargando">
            <div class="esq esq-titulo"></div>
            <div class="esq esq-sub"></div>
            <div class="esq-cifras"><div class="esq"></div><div class="esq"></div><div class="esq"></div></div>
            <div class="esq esq-fila"></div><div class="esq esq-fila"></div><div class="esq esq-fila"></div><div class="esq esq-fila"></div>
        </div>

        {{-- ---------------------------------------------------- TOTALES --}}
        <template x-if="dDe === 'totales'">
        <section x-show="seccion === 'totales' && !cargando && !error">
            {{-- encabezado: título, fechas y periodo a la derecha --}}
            <div class="cabecera">
                <div><h1>Totales de acopio</h1>
                    <p class="sub" style="margin: 0" x-text="(d.desde === d.hasta ? fechaCorta(d.desde) : fechaCorta(d.desde) + ' – ' + fechaCorta(d.hasta)) + ' · ' + num(d.entregas, 0) + ' entregas'"></p></div>
                <div class="espacio"></div>
                <div class="pestanas" style="margin: 0">
                    <template x-for="p in [['dia','Día'],['semana','Semana'],['mes','Mes'],['calendario','Calendario']]">
                        <button :class="{ activo: periodo === p[0] }" @click="cambiar('periodo', p[0])" x-text="p[1]"></button>
                    </template>
                </div>
            </div>

            {{-- calendario del mes: litros de cada día; al tocar un día, todo lo de abajo es de ese día --}}
            <template x-if="periodo === 'calendario' && d.cal">
                <div class="tarjeta calendario-acopio">
                    <div class="fila cal-cabeza">
                        <button class="btn chico gris" @click="mesAcopio(d.cal.anterior)" title="Mes anterior" aria-label="Mes anterior">◀</button>
                        <strong x-text="nombreMes(d.cal.mes)"></strong>
                        <button class="btn chico gris" @click="mesAcopio(d.cal.siguiente)" :disabled="!d.cal.siguiente" title="Mes siguiente" aria-label="Mes siguiente">▶</button>
                        <div class="espacio"></div>
                        <span class="apoyo" x-text="num(d.cal.litros, 0) + ' L en el mes · ' + d.cal.dias.length + ' días con acopio'"></span>
                    </div>
                    <div class="cal-semana" aria-hidden="true"><span>lu</span><span>ma</span><span>mi</span><span>ju</span><span>vi</span><span>sá</span><span>do</span></div>
                    <div class="cal-dias">
                        <template x-for="c in celdasCalendario(d.cal)" :key="c.clave">
                            <button class="cal-dia" :disabled="!c.fecha || c.futuro" @click="cambiar('acopioFecha', c.fecha)"
                                    :class="{ vacio: !c.fecha, sin: c.fecha && !c.litros, elegido: c.fecha && c.fecha === acopioFecha, hoy: c.fecha === d.cal.hoy }"
                                    :style="c.litros ? '--nivel:' + c.nivel : ''"
                                    :title="c.fecha ? fechaCorta(c.fecha) + (c.litros ? ': ' + num(c.litros, 0) + ' L, ' + c.entregas + ' entregas' + (c.rechazadas ? ', ' + c.rechazadas + ' rechazadas' : '') : ': sin acopio') : ''">
                                <span class="n" x-text="c.dia || ''"></span>
                                <b x-text="c.litros ? num(c.litros, 0) : ''"></b>
                                <i class="rech" x-show="c.rechazadas" x-text="c.rechazadas + ' ✕'"></i>
                            </button>
                        </template>
                    </div>
                    <p class="apoyo cal-pie">Toca un día para ver sus totales abajo. El color más fuerte es el día con más leche.</p>
                </div>
            </template>

            {{-- arriba: los litros con su gráfico por día y la calidad en un anillo --}}
            <div class="resumen-acopio">
                <div class="tarjeta destacada grande-litros">
                    <div class="litros-cabeza">
                        <span class="gota" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3c3.5 4.6 6 8 6 11a6 6 0 0 1-12 0c0-3 2.5-6.4 6-11z"/><path d="M9.2 14.5a2.9 2.9 0 0 0 2.6 2.7"/></svg></span>
                        <div class="eti">Litros acopiados</div>
                        <div class="espacio"></div>
                        <span class="periodo-chip" x-text="d.desde === d.hasta ? fechaCorta(d.desde) : (periodo === 'mes' ? 'Últimos 30 días' : 'Últimos 7 días')"></span>
                    </div>
                    {{-- la cifra (cuenta hasta el total al cargar) y cuánto subió o bajó contra el periodo de antes --}}
                    <div class="litros-cifra">
                        <div class="valor enorme"><span x-effect="contarHasta($el, d.litros)"></span><small>L</small></div>
                        <template x-if="d.anterior">
                            <div class="comparar" :class="d.litros >= d.anterior.litros ? 'sube' : 'baja'">
                                <b x-text="d.anterior.litros ? (d.litros >= d.anterior.litros ? '▲ ' : '▼ ') + num(Math.abs(d.litros - d.anterior.litros) / d.anterior.litros * 100, 0) + ' %' : '▲ nuevo'"></b>
                                <span x-text="(d.desde === d.hasta ? 'vs. el ' + fechaCorta(d.anterior.desde) : 'vs. los ' + (periodo === 'mes' ? 30 : 7) + ' días anteriores') + ' · ' + num(d.anterior.litros, 0) + ' L'"></span>
                            </div>
                        </template>
                    </div>
                    <div class="litros-chips">
                        <span><b x-text="soles(d.a_pagar)"></b> a pagar a proveedores</span>
                        <span><b x-text="num(d.entregas, 0)"></b> entregas</span>
                        <span class="ojo" x-show="d.pendientes"><b x-text="d.pendientes"></b> sin analizar</span>
                    </div>
                    {{-- cuánto trajo cada carro: una barra partida con el color de su ruta (el mismo del mapa) --}}
                    <div class="reparto" x-show="d.litros > 0">
                        <div class="reparto-barra">
                            <template x-for="v in (d.vehiculos || []).filter((x) => x.litros > 0)" :key="v.codigo">
                                <span :style="'flex:' + v.litros + ';background:' + colorRuta(v.codigo)" :title="v.codigo + ': ' + num(v.litros, 0) + ' L'"></span>
                            </template>
                        </div>
                        <div class="reparto-leyenda">
                            <template x-for="v in (d.vehiculos || []).filter((x) => x.litros > 0)" :key="v.codigo">
                                <span><i :style="'background:' + colorRuta(v.codigo)"></i><b x-text="v.codigo"></b>
                                    <span x-text="num(v.litros, 0) + ' L · ' + num(v.litros / d.litros * 100, 0) + ' %'"></span></span>
                            </template>
                        </div>
                    </div>
                    <div class="grafico-litros" x-show="(d.por_dia || []).length > 1">
                        <div class="grafico-litros-cabeza">
                            <span>Litros por día</span>
                            <span class="prom"><i></i><span x-text="'promedio ' + num(d.litros / ((d.por_dia || []).length || 1), 0) + ' L'"></span></span>
                            <span class="mejor-ley">★ mejor día</span>
                        </div>
                        <div x-html="svgLitrosDia(diasDelPeriodo(d.desde, d.hasta, d.por_dia))"></div>
                    </div>
                </div>
                <div class="tarjeta calidad-acopio">
                    <div class="eti">Calidad de las entregas</div>
                    <div class="dona-fila">
                        <div class="dona" x-html="svgDona([
                            { valor: d.aceptados_pct || 0, color: 'var(--exito)', nombre: 'Aceptadas' },
                            { valor: d.rechazados_pct || 0, color: 'var(--alerta)', nombre: 'Rechazadas' },
                            { valor: d.adulterada_pct || 0, color: 'rgba(239,107,107,.5)', nombre: 'Adulteradas' },
                            { valor: d.entregas ? d.pendientes / d.entregas * 100 : 0, color: 'var(--aviso)', nombre: 'Sin analizar' }],
                            num(d.aceptados_pct, 1) + '%', 'aceptadas')"></div>
                        <ul class="leyenda-dona">
                            <li><i style="background: var(--exito)"></i>Aceptadas<b x-text="num(d.aceptados_pct, 1) + ' %'"></b></li>
                            <li><i style="background: var(--alerta)"></i>Rechazadas<b x-text="num(d.rechazados_pct, 1) + ' %'"></b></li>
                            <li><i style="background: rgba(239,107,107,.5)"></i>Adulteradas<b x-text="num(d.adulterada_pct, 1) + ' %'"></b></li>
                            <li x-show="d.pendientes"><i style="background: var(--aviso)"></i>Sin analizar<b x-text="d.pendientes"></b></li>
                        </ul>
                    </div>
                    {{-- el promedio y el mejor día del periodo --}}
                    <div class="mini-datos" x-show="(d.por_dia || []).length > 1">
                        <div><span>Promedio por día</span><b x-text="num(d.litros / d.por_dia.length, 0) + ' L'"></b></div>
                        <div><span>Mejor día</span><b x-text="(() => { const m = [...(d.por_dia || [])].sort((a, b) => b.litros - a.litros)[0]; return m ? num(m.litros, 0) + ' L · ' + fechaCorta(m.fecha) : ''; })()"></b></div>
                    </div>
                </div>
            </div>

            {{-- un carro por tarjeta: litros, su parte del total, ruta y calidad --}}
            <h2>Por vehículo · litros y calidad</h2>
            <div class="vehiculos">
                <template x-for="v in dDe === 'totales' ? (d.vehiculos || []) : []" :key="v.codigo">
                    <article class="vehiculo" :class="{ alerta: v.adulteradas || v.rechazo_pct > 8, 'sin-salida': !v.litros }" :style="'--ruta: ' + colorRuta(v.codigo)">
                        <header>
                            <span class="placa" x-text="v.codigo"></span>
                            <span class="tipo-vehiculo" x-html="iconoMenu('acopio')" :title="v.tipo || 'Vehículo'"></span>
                            <div class="quien"><strong x-text="v.acopiador || 'Sin acopiador'"></strong>
                                <small x-text="(v.ruta || '').split(', ').filter(Boolean).length + ' comunidades'"></small></div>
                        </header>
                        {{-- un carro que no trajo leche: un aviso amable en vez de ceros sueltos --}}
                        <div class="vehiculo-vacio" x-show="!v.litros">
                            <span aria-hidden="true">🌙</span>
                            <div><strong x-text="d.desde === d.hasta ? 'No salió este día' : 'No salió en estas fechas'"></strong>
                                <small x-text="v.acopiador ? 'No hay entregas anotadas en su ruta.' : 'Falta asignarle un acopiador en Configuración → Vehículos.'"></small></div>
                        </div>
                        <div class="litros-vehiculo" x-show="v.litros"><b x-text="num(v.litros, 0)"></b> L
                            <span x-text="d.litros ? num(v.litros / d.litros * 100, 0) + ' % del total' : ''"></span></div>
                        <div class="barra" x-show="v.litros"><span :style="'width:' + (v.litros / maximo(d.vehiculos, 'litros') * 100) + '%'"></span></div>
                        <div class="ruta-chips"><template x-for="c in (v.ruta || '').split(', ').filter(Boolean)"><span x-text="c"></span></template></div>
                        <dl class="medidas-vehiculo" x-show="v.analisis">
                            <div><dt>Grasa</dt><dd x-text="num(v.grasa, 2) + ' %'"></dd></div>
                            <div><dt>Densidad</dt><dd x-text="Number(v.densidad).toFixed(4)"></dd></div>
                            <div><dt>Temp.</dt><dd x-text="num(v.temperatura) + ' °C'"></dd></div>
                            <div><dt>Rechazo</dt><dd :class="{ mal: v.rechazo_pct > 8 }" x-text="num(v.rechazo_pct) + ' %'"></dd></div>
                        </dl>
                        <p class="apoyo-fila" x-show="!v.analisis && v.litros">Sin análisis de Calidad en estas fechas.</p>
                        <span class="chip mal" x-show="v.adulteradas" x-text="v.adulteradas + (v.adulteradas === 1 ? ' entrega adulterada' : ' entregas adulteradas')"></span>
                    </article>
                </template>
            </div>
            <p class="sub" style="margin-top: 12px">Grasa, densidad y temperatura son promedios de los análisis de Calidad en estas fechas.</p>
        </section>
        </template>

        {{-- ---------------------------------------------------- MAPA DE HUATA --}}
        {{-- x-show sin "cargando": el mapa no se borra ni se vuelve a medir al recargar --}}
        <section x-show="seccion === 'mapa' && !error">
            <div class="cabecera">
                <div><h1>Mapa de Huata</h1>
                    <p class="sub" style="margin: 0" x-text="mapaEditar ? 'Arrastra cada comunidad a su lugar real. Se guarda al soltarla.' : 'Toca una comunidad para ver quiénes están ahí. Cada color es un carro.'"></p></div>
                <div class="espacio"></div>
                <input type="date" x-model="mapaFecha" @change="cargar()" style="width:auto" title="Día">
                <button class="btn chico" :class="mapaEditar ? '' : 'gris'" @click="alternarEdicionMapa()"
                        x-text="mapaEditar ? '✓ Listo' : '📍 Corregir ubicaciones'"></button>
            </div>

            <div class="mapa-zona">
                <div class="mapa-caja">
                    <div id="mapa-huata" :class="{ moviendo: !!mapaMover }"></div>
                    {{-- encima del mapa: el carro que se está mirando, volver a ver todo y cómo leer los pines --}}
                    <div class="mapa-flota arriba" x-show="dDe === 'mapa'">
                        <span class="mf-carro" x-show="mapaVehiculo" :style="'--c:' + colorRuta(mapaVehiculo)"><i></i><span x-text="'Ruta de ' + mapaVehiculo"></span></span>
                        <button class="mf-btn" @click="mapaVehiculo ? filtrarMapa(mapaVehiculo) : encuadrarMapa()" x-text="mapaVehiculo ? '✕ Ver todos los carros' : '⤢ Ver toda Huata'"></button>
                    </div>
                    <div class="mapa-flota abajo" x-show="dDe === 'mapa'">
                        <span><i class="mf-anillo" aria-hidden="true"></i>El anillo se llena con los que ya entregaron</span>
                        <span class="ojo" x-show="resumenMapa().aproximadas"><i class="mf-punteado" aria-hidden="true"></i><span x-text="'Ubicación aproximada (' + resumenMapa().aproximadas + ')'"></span></span>
                    </div>
                </div>

                {{-- panel: la gente de la comunidad tocada --}}
                <aside class="panel-mapa">
                    <template x-if="mapaMover">
                        <div class="mover-aviso">
                            <div class="mover-ico">🔄</div>
                            <strong x-text="'¿A dónde se cambia ' + mapaMover.nombre + '?'"></strong>
                            <p>Toca en el mapa la comunidad nueva (las que laten). Luego confirmas cómo avisó y desde cuándo.</p>
                            <button class="btn gris" @click="cancelarMover()">Cancelar</button>
                        </div>
                    </template>
                    <template x-if="!mapaMover && !comunidadMapa() && dDe === 'mapa'">
                        <div class="resumen-mapa">
                            {{-- el día: cuánta leche y cuántos entregaron, con un anillo de avance --}}
                            <div class="rm-cabeza">
                                <div class="rm-anillo" :style="'--p:' + (resumenMapa().proveedores ? Math.round(resumenMapa().registrados / resumenMapa().proveedores * 100) : 0) + '%'">
                                    <b x-text="(resumenMapa().proveedores ? Math.round(resumenMapa().registrados / resumenMapa().proveedores * 100) : 0) + '%'"></b></div>
                                <div class="rm-dia">
                                    <span x-text="mapaFecha ? 'El ' + fechaCorta(mapaFecha) + ' en Huata' : 'Hoy en Huata'"></span>
                                    <strong x-text="num(resumenMapa().litros, 0) + ' L'"></strong>
                                    <small x-text="resumenMapa().registrados + ' de ' + resumenMapa().proveedores + ' proveedores entregaron'"></small>
                                </div>
                            </div>

                            <div class="rm-titulo"><span>Por carro</span><small>tócalo para ver su ruta</small></div>
                            <div class="rm-carros">
                                <template x-for="v in carrosMapa()" :key="v.codigo">
                                    <button class="rm-carro" :class="{ activo: mapaVehiculo === v.codigo, tenue: mapaVehiculo && mapaVehiculo !== v.codigo }"
                                            :style="'--c:' + v.color" @click="filtrarMapa(v.codigo)" :aria-pressed="mapaVehiculo === v.codigo">
                                        <i class="rm-punto" aria-hidden="true"></i>
                                        <span class="rm-que" :title="(v.acopiador || 'sin acopiador') + ' · ' + v.comunidades + (v.comunidades === 1 ? ' comunidad' : ' comunidades')"><b x-text="v.codigo"></b><small x-text="v.acopiador || 'sin acopiador'"></small></span>
                                        <span class="rm-cifra"><b x-text="num(v.litros, 0) + ' L'"></b><small x-text="v.registrados + ' de ' + v.proveedores"></small></span>
                                        <span class="rm-barra" aria-hidden="true"><em :style="'width: ' + v.pct + '%'"></em></span>
                                    </button>
                                </template>
                            </div>

                            <div class="rm-titulo"><span>Comunidades</span><small>toca una para ver su gente</small></div>
                            <div class="rm-lista">
                                <template x-for="c in comunidadesMapa()" :key="c.id">
                                    <button class="rm-comunidad" :class="{ tenue: c.tenue, completa: c.proveedores && c.pct === 100 }" :style="'--c:' + c.color + '; --p:' + c.pct + '%'" @click="verComunidadMapa(c)">
                                        <i class="rm-mini" aria-hidden="true"></i>
                                        <span class="rm-nombre"><b x-text="c.nombre"></b><small x-text="(c.carro || 'sin carro') + (c.aproximada ? ' · ubicación aproximada' : '')"></small></span>
                                        <span class="rm-cuenta" x-text="c.proveedores ? c.registrados + '/' + c.proveedores : '—'"></span>
                                        <span class="rm-litros" x-text="c.litros ? num(c.litros, 0) + ' L' : ''"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>
                    <template x-if="!mapaMover && comunidadMapa()">
                        <div>
                            <div class="panel-cabeza" :style="'--c:' + colorRuta(vehiculoDeComunidad(comunidadMapa().id)?.codigo)">
                                <div class="fila"><h2 x-text="comunidadMapa().nombre"></h2><div class="espacio"></div>
                                    <button class="cerrar-x" @click="mapaComunidad = null; dibujarMapa()" aria-label="Cerrar">✕</button></div>
                                <p x-text="vehiculoDeComunidad(comunidadMapa().id) ? '🚚 Lo recoge ' + vehiculoDeComunidad(comunidadMapa().id).codigo + ' · ' + (vehiculoDeComunidad(comunidadMapa().id).acopiador || 'sin acopiador') : 'Ningún carro pasa por aquí'"></p>
                                <div class="panel-cifras">
                                    <div><b x-text="(comunidadMapa().gente || []).length"></b><span>personas</span></div>
                                    <div><b x-text="entregaronMapa(comunidadMapa())"></b><span>entregaron</span></div>
                                    <div><b x-text="num(comunidadMapa().litros, 0) + ' L'"></b><span x-text="mapaFecha ? 'ese día' : 'hoy'"></span></div>
                                </div>
                            </div>
                            <div class="gente-mapa">
                                <template x-for="p in comunidadMapa().gente || []" :key="p.id">
                                    <div class="persona-mapa" :class="{ falta: !p.entrega }">
                                        <span class="estado-persona" x-text="!p.entrega ? '○' : (p.entrega.ausente ? '–' : '✓')"></span>
                                        <div class="que"><strong x-text="p.nombre"></strong>
                                            <small x-text="'DNI ' + p.dni + ' · ' + (!p.entrega ? 'todavía no entrega' : (p.entrega.ausente ? 'no entregó' : num(p.entrega.litros) + ' L' + (p.entrega.calidad === 'RECHAZADO' ? ' · rechazada' : '')))"></small>
                                            {{-- su carro no es el que pasa por su comunidad: conviene revisarlo --}}
                                            <small class="ojo" x-show="p.vehiculo && p.vehiculo !== vehiculoDeComunidad(comunidadMapa().id)?.codigo" x-text="'⚠ va en ' + p.vehiculo + ', no en el carro de esta comunidad'"></small></div>
                                        <div class="acciones-persona">
                                            <button class="btn chico gris" @click="abrirFicha(p.id)" title="Su leche, sus pagos y su historial">Ficha</button>
                                            <button class="btn chico" @click="empezarMover(p)" title="Cambiarlo a otra comunidad">🔄 Mover</button>
                                        </div>
                                    </div>
                                </template>
                                <div class="vacio-lista" x-show="!(comunidadMapa().gente || []).length"><span aria-hidden="true">·</span><p>Nadie vive aquí todavía.</p></div>
                            </div>
                        </div>
                    </template>
                </aside>
            </div>
        </section>

        {{-- ---------------------------------------------------- DINERO --}}
        <template x-if="dDe === 'dinero'">
        <section x-show="seccion === 'dinero' && !cargando && !error">
            <h1>Dinero</h1>
            <p class="sub">Lo que entra por ventas y lo que sale en pagos a proveedores.</p>
            <div class="pestanas">
                <button :class="{ activo: dinero === 'ventas' }" @click="cambiar('dinero', 'ventas')">Ventas, producción e inventario</button>
                <button :class="{ activo: dinero === 'pagos' }" @click="cambiar('dinero', 'pagos')">Pagos a proveedores</button>
                <button :class="{ activo: dinero === 'deudas' }" @click="cambiar('dinero', 'deudas')">Lo que se debe</button>
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
                        <div class="resumen-acopio">
                            <div class="tarjeta destacada">
                                <div class="eti">Ventas</div>
                                <div class="valor enorme" x-text="soles(d.ventas.total)"></div>
                                <div class="apoyo" x-text="d.ventas.cantidad + (d.ventas.cantidad === 1 ? ' venta' : ' ventas')"></div>
                                <div class="grafico-dias" x-show="d.ventas.barras.length > 1"
                                     x-html="svgBarrasDia(d.ventas.barras.map((b) => ({ etiqueta: b.etiqueta, valor: b.total })), 'soles')"></div>
                            </div>
                            <div class="tarjeta">
                                <div class="eti">¿Quién compra?</div>
                                <div class="dona-fila">
                                    <div class="dona" x-html="svgDona([
                                        { valor: d.ventas.por_tipo?.MAYORISTA || 0, color: 'var(--exito)', nombre: 'Mayoristas' },
                                        { valor: d.ventas.por_tipo?.DIRECTO || 0, color: '#8fd3ff', nombre: 'Clientes directos' },
                                        { valor: d.ventas.por_tipo?.PLANTA || 0, color: 'var(--aviso)', nombre: 'Proveedores' }],
                                        d.ventas.cantidad, d.ventas.cantidad === 1 ? 'venta' : 'ventas')"></div>
                                    <ul class="leyenda-dona">
                                        <li><i style="background: var(--exito)"></i>Mayoristas<b x-text="soles(d.ventas.por_tipo?.MAYORISTA)"></b></li>
                                        <li><i style="background: #8fd3ff"></i>Clientes directos<b x-text="soles(d.ventas.por_tipo?.DIRECTO)"></b></li>
                                        <li><i style="background: var(--aviso)"></i>Proveedores<b x-text="soles(d.ventas.por_tipo?.PLANTA)"></b></li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="dos-columnas">
                            <div>
                                <h2>Por producto</h2>
                                <div class="ranking">
                                    <template x-for="p in d.ventas.por_producto"><div class="puesto" :style="'--tono: ' + colorReceta({ nombre: p.nombre, tipo: /queso/i.test(p.nombre) ? 'QUESO' : /yogurt/i.test(p.nombre) ? 'YOGURT' : '' })">
                                        <i class="punto"></i>
                                        <div class="nombre-puesto"><strong x-text="p.nombre"></strong><small x-text="num(p.cantidad, 0) + ' ' + p.unidad"></small></div>
                                        <b x-text="soles(p.total)"></b>
                                        <div class="barra"><span :style="'width:' + (p.total / maximo(d.ventas.por_producto, 'total') * 100) + '%'"></span></div>
                                    </div></template>
                                    <div class="vacio-lista" x-show="!d.ventas.por_producto.length"><span aria-hidden="true">·</span><p>No hubo ventas en este periodo.</p></div>
                                </div>
                            </div>
                            <div>
                                <h2>Últimas ventas</h2>
                                <div class="movimientos">
                                    <template x-for="u in d.ventas.ultimas" :key="u.id"><div class="movimiento">
                                        <div class="cuando"><b x-text="fechaCorta(u.vendida_en)"></b><span x-text="hora(u.vendida_en)"></span></div>
                                        <div class="que"><strong x-text="u.cliente"></strong><small x-text="num(u.cantidad, 0) + ' · ' + u.producto + (u.vendedor ? ' · ' + u.vendedor : '')"></small></div>
                                        <b class="monto" x-text="soles(u.total)"></b>
                                        <button class="btn chico gris" @click="verNota(u.id)" :aria-label="'Nota de venta de ' + u.cliente">Nota</button>
                                    </div></template>
                                    <div class="vacio-lista" x-show="!d.ventas.ultimas.length"><span aria-hidden="true">·</span><p>Todavía no hay ventas.</p></div>
                                </div>
                            </div>
                        </div>

                        <h2>Producción</h2>
                        <div class="kpis-fila">
                            <div class="kpi ok"><span class="kpi-ico" aria-hidden="true">🧀</span><div><b x-text="d.produccion.lotes"></b><span>Lotes</span>
                                <small x-text="d.produccion.en_curso ? d.produccion.en_curso + ' en proceso' : 'todos cerrados'"></small></div></div>
                            <div class="kpi"><span class="kpi-ico" aria-hidden="true">🥛</span><div><b x-text="num(d.produccion.litros, 0) + ' L'"></b><span>Leche usada</span><small>en lotes de este periodo</small></div></div>
                            <div class="kpi" :class="d.produccion.fuera_referencia ? 'ojo' : 'ok'"><span class="kpi-ico" aria-hidden="true" x-text="d.produccion.fuera_referencia ? '⚠' : '✓'"></span>
                                <div><b x-text="d.produccion.fuera_referencia"></b><span>Fuera de rango</span><small>lotes que rindieron distinto a lo esperado</small></div></div>
                        </div>
                        <h2>Inventario de hoy</h2>
                        <div class="stock-grilla">
                            <template x-for="p in d.inventario.productos"><div class="stock-item" :class="{ cero: !(p.stock > 0) }"
                                 :style="'--tono: ' + colorReceta({ nombre: p.nombre, tipo: /queso/i.test(p.nombre) ? 'QUESO' : /yogurt/i.test(p.nombre) ? 'YOGURT' : '' })">
                                <i class="punto"></i>
                                <div><strong x-text="p.nombre"></strong><b x-text="num(p.stock, 0) + ' ' + p.unidad"></b>
                                    <span class="chip ojo" x-show="p.lotes_por_vencer" x-text="'⏳ ' + p.lotes_por_vencer + ' lote(s) vencen esta semana'"></span></div>
                            </div></template>
                        </div>
                        <template x-for="i in d.inventario.insumos_bajo_minimo"><div class="aviso error" x-text="i.nombre + ' bajo el mínimo: quedan ' + num(i.stock, 2) + ' ' + i.unidad + ' (mínimo ' + num(i.stock_minimo, 2) + ')'"></div></template>
                        <div class="aviso" x-show="d.inventario.ordenes_por_recibir" x-text="d.inventario.ordenes_por_recibir + ' órdenes de compra por recibir · ' + soles(d.inventario.ordenes_monto)"></div>
                    </div>
                </template>
            </div>

            {{-- pagos --}}
            {{-- todas las deudas, sin ir semana por semana: cada proveedor con sus semanas sin pagar --}}
            <div x-show="dinero === 'deudas'">
                <template x-if="dinero === 'deudas' && d.proveedores">
                    <div>
                        <div class="tarjeta destacada avance-pagos">
                            <div class="fila" style="align-items: flex-end">
                                <div><div class="eti">Se debe a proveedores</div>
                                    <div class="valor enorme" :class="d.total ? '' : 'ok'" x-text="d.total ? soles(d.total) : '✓ No se debe nada'"></div>
                                    <div class="apoyo" x-text="d.proveedores.length + (d.proveedores.length === 1 ? ' proveedor' : ' proveedores') + ' · ' + d.cuantas + ' boletas sin pagar · semanas cerradas hasta el ' + fechaCorta(d.hasta)"></div></div>
                                <div class="espacio"></div>
                                <button class="btn grande" x-show="d.cuantas > 1"
                                        @click="pagar(d.proveedores.flatMap(p => p.semanas.map(s => s.id)), 'Pagar todo lo que se debe (' + soles(d.total) + ')')">Pagar todo</button>
                            </div>
                        </div>
                        <input type="search" x-model="buscar" placeholder="Buscar por nombre, DNI, comunidad o vehículo" class="buscador" aria-label="Buscar proveedor" x-show="d.proveedores.length > 5">
                        <div class="pagos">
                            <template x-for="p in deudasFiltradas()" :key="p.proveedor_id">
                                <div class="deuda" :class="{ abierta: deudaAbierta[p.proveedor_id] }">
                                    <div class="pago" role="button" tabindex="0" :aria-expanded="!!deudaAbierta[p.proveedor_id]"
                                         @click="deudaAbierta[p.proveedor_id] = !deudaAbierta[p.proveedor_id]" @keydown.enter="deudaAbierta[p.proveedor_id] = !deudaAbierta[p.proveedor_id]">
                                        <span class="inicial" x-text="p.nombre.trim().charAt(0)"></span>
                                        <div class="que"><strong x-text="p.nombre"></strong>
                                            <small x-text="p.comunidad + ' · ' + p.vehiculo + ' · se le debe desde el ' + fechaCorta(p.desde)"></small></div>
                                        <b class="monto" x-text="soles(p.total)"></b>
                                        <span class="chip ojo" x-text="(p.semanas.length === 1 ? '1 semana' : p.semanas.length + ' semanas') + ' ' + (deudaAbierta[p.proveedor_id] ? '▴' : '▾')"></span>
                                        <button class="btn chico" @click.stop="pagar(p.semanas.map(s => s.id), 'Pagar a ' + p.nombre + ' todo lo que se le debe (' + soles(p.total) + ')')">Pagar</button>
                                    </div>
                                    <div class="deuda-semanas" x-show="deudaAbierta[p.proveedor_id]">
                                        <template x-for="s in p.semanas" :key="s.id">
                                            <div class="deuda-semana">
                                                <div><strong x-text="'Semana ' + fechaCorta(s.inicio) + ' – ' + fechaCorta(s.fin)"></strong><small x-text="num(s.litros) + ' L aceptados'"></small></div>
                                                <b x-text="soles(s.monto)"></b>
                                                <button class="btn chico gris" @click="abrirBoleta({ id: s.id, proveedor: p.nombre, dni: p.dni, comunidad: p.comunidad, litros: s.litros, monto: s.monto, estado: 'PENDIENTE' })">Ver días</button>
                                                <button class="btn chico" x-show="p.semanas.length > 1" @click="pagar([s.id], 'Pagar a ' + p.nombre + ' la semana del ' + fechaCorta(s.inicio) + ' (' + soles(s.monto) + ')')">Pagar esta</button>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                            <div class="vacio-lista" x-show="!d.proveedores.length"><span aria-hidden="true">✓</span><p>No se le debe a nadie: todas las semanas cerradas están pagadas.</p></div>
                            <div class="vacio-lista" x-show="d.proveedores.length && !deudasFiltradas().length"><span aria-hidden="true">·</span><p>Nadie coincide con la búsqueda.</p></div>
                        </div>
                    </div>
                </template>
            </div>

            <div x-show="dinero === 'pagos'">
                <template x-if="d.boletas">
                    <div>
                        {{-- una sola semana a la vista: flechitas, calendario y aviso de las que quedaron sin pagar --}}
                        <div class="fila" style="margin-bottom: 14px; gap: 8px; flex-wrap: wrap">
                            <button class="btn chico gris" @click="cambiar('semana', d.anterior)" title="Semana anterior">◀</button>
                            <label class="btn chico gris" style="position: relative; cursor: pointer" title="Elegir otra semana">
                                <span x-text="'Semana ' + fechaCorta(d.inicio) + ' – ' + fechaCorta(d.fin)"></span>
                                <input type="date" :max="d.hasta" :value="d.inicio"
                                       @change="$event.target.value && cambiar('semana', $event.target.value)"
                                       @click="$event.target.showPicker?.()"
                                       style="position: absolute; inset: 0; opacity: 0; cursor: pointer">
                            </label>
                            <button class="btn chico gris" @click="cambiar('semana', d.siguiente)" :disabled="!d.siguiente" title="Semana siguiente">▶</button>
                            <button class="btn chico gris" @click="excel('/admin/excel/pagos?semana=' + d.inicio)" title="Descargar en Excel">⬇ Excel</button>
                            <div class="espacio"></div>
                            <span class="chip ojo" x-show="(d.otras_con_pendientes || []).length"
                                  x-text="'⚠ ' + (d.otras_con_pendientes || []).length + ' semana(s) más con pagos pendientes'"></span>
                            <button class="btn chico" x-show="(d.otras_con_pendientes || []).length"
                                    @click="cambiar('semana', d.otras_con_pendientes[0].inicio)"
                                    x-text="'Ir a la más antigua (' + fechaCorta((d.otras_con_pendientes || [])[0]?.inicio) + ')'"></button>
                        </div>
                        {{-- cuánto de la semana ya se pagó --}}
                        <div class="tarjeta destacada avance-pagos">
                            <div class="fila" style="align-items: flex-end">
                                <div><div class="eti">Por pagar esta semana</div>
                                    <div class="valor enorme" :class="d.pendiente ? '' : 'ok'" x-text="d.pendiente ? soles(d.pendiente) : '✓ Todo pagado'"></div>
                                    <div class="apoyo" x-text="'Semana ' + fechaCorta(d.inicio) + ' – ' + fechaCorta(d.fin) + ' · ' + d.boletas.length + ' proveedores'"></div></div>
                                <div class="espacio"></div>
                                <button class="btn grande" x-show="d.pendientes" @click="pagar(d.boletas.filter(b => b.estado === 'PENDIENTE').map(b => b.id), 'Pagar a los ' + d.pendientes + ' pendientes (' + soles(d.pendiente) + ')')"
                                        x-text="'Pagar a los ' + d.pendientes + ' pendientes'"></button>
                            </div>
                            <div class="progreso" :aria-label="'Pagado ' + num(d.total ? d.pagado / d.total * 100 : 0, 0) + ' %'">
                                <span :style="'width:' + (d.total ? d.pagado / d.total * 100 : 0) + '%'"></span></div>
                            <div class="fila pie-progreso">
                                <span><i class="punto-leyenda" style="background: var(--exito)"></i>Ya pagado <b x-text="soles(d.pagado)"></b></span>
                                <span><i class="punto-leyenda" style="background: var(--borde)"></i>Total de la semana <b x-text="soles(d.total)"></b></span>
                            </div>
                        </div>
                        <h2>Cada proveedor</h2>
                        <div class="pagos">
                            <template x-for="b in d.boletas" :key="b.id">
                                <div class="pago" :class="b.estado === 'PAGADO' ? 'pagado' : ''" role="button" tabindex="0" @click="abrirBoleta(b)" @keydown.enter="abrirBoleta(b)">
                                    <span class="inicial" x-text="b.proveedor.trim().charAt(0)"></span>
                                    <div class="que"><strong x-text="b.proveedor"></strong><small x-text="b.comunidad + ' · ' + b.vehiculo + ' · ' + num(b.litros) + ' L'"></small></div>
                                    <b class="monto" x-text="soles(b.monto)"></b>
                                    <span class="chip" :class="b.estado === 'PAGADO' ? 'ok' : 'ojo'"
                                          x-text="b.estado === 'PAGADO' ? '✓ ' + fechaCorta(b.pagado_el) + ' · ' + (b.metodo === 'YAPE' ? 'Yape' : 'Efectivo') : 'Pendiente'"></span>
                                    <button class="btn chico" x-show="b.estado === 'PENDIENTE'" @click.stop="pagar([b.id], 'Pagar a ' + b.proveedor + ' (' + soles(b.monto) + ')')">Pagar</button>
                                </div>
                            </template>
                            <div class="vacio-lista" x-show="!d.boletas.length"><span aria-hidden="true">✓</span><p>No hay nada que pagar en esta semana.</p></div>
                        </div>
                    </div>
                </template>
            </div>
        </section>
        </template>

        {{-- ---------------------------------------------------- PADRÓN --}}
        <template x-if="dDe === 'padron'">
        <section x-show="seccion === 'padron' && !cargando && !error">
            <div class="cabecera"><div><h1>Padrón</h1>
                <p class="sub" style="margin: 0">Los productores que entregan leche y la gente que trabaja en la planta.</p></div></div>
            <div class="kpis-fila">
                <div class="kpi ok"><span class="kpi-ico" aria-hidden="true">👩‍🌾</span><div>
                    <b x-text="(d.proveedores || []).filter((p) => p.estado === 'ACTIVO').length"></b><span>Proveedores activos</span>
                    <small x-text="new Set((d.proveedores || []).map((p) => p.comunidad)).size + ' comunidades · ' + new Set((d.proveedores || []).map((p) => p.vehiculo)).size + ' vehículos'"></small></div></div>
                <div class="kpi"><span class="kpi-ico" aria-hidden="true">🥛</span><div>
                    <b x-text="num((d.proveedores || []).reduce((t, p) => t + (p.leche?.litros_7 || 0), 0), 0) + ' L'"></b><span>Leche de los últimos 7 días</span>
                    <small>de todos los proveedores</small></div></div>
                <div class="kpi" :class="(d.proveedores || []).some((p) => p.programado) ? 'ojo' : 'ok'"><span class="kpi-ico" aria-hidden="true">🔄</span><div>
                    <b x-text="(d.proveedores || []).filter((p) => p.programado || p.cambio_reciente).length"></b><span>Cambios de comunidad</span>
                    <small>recientes o programados</small></div></div>
            </div>
            <div class="barra-herramientas">
                <div class="pestanas" style="margin: 0">
                    <button :class="{ activo: padron === 'proveedores' }" @click="padron = 'proveedores'" x-text="'Proveedores · ' + (d.proveedores || []).length"></button>
                    <button :class="{ activo: padron === 'trabajadores' }" @click="padron = 'trabajadores'" x-text="'Trabajadores · ' + (d.trabajadores || []).length"></button>
                </div>
                <input type="search" class="buscador" x-show="padron === 'proveedores'" x-model="buscar" placeholder="Buscar por nombre, DNI, comunidad o vehículo" aria-label="Buscar proveedor">
                <div class="espacio" x-show="padron !== 'proveedores'"></div>
                <button class="btn gris" x-show="padron === 'proveedores'" @click="excel('/admin/excel/padron')" title="Descargar en Excel">⬇ Excel</button>
                <button class="btn" x-show="padron === 'proveedores'" @click="abrirNuevoProveedor()">+ Nuevo proveedor</button>
                <button class="btn" x-show="padron === 'trabajadores'" @click="abrirNuevoTrabajador()">+ Nuevo trabajador</button>
            </div>
            <div x-show="padron === 'proveedores'" class="proveedores-lista">
                <template x-for="p in proveedoresFiltrados()" :key="p.id">
                    <div class="proveedor-fila" :class="{ baja: p.estado !== 'ACTIVO' }" role="button" tabindex="0" title="Toca para ver todo"
                         @click="abrirFicha(p.id)" @keydown.enter="abrirFicha(p.id)">
                        <span class="inicial de-ruta" :style="'--ruta: ' + colorRuta(p.vehiculo)" x-text="p.nombre.trim().charAt(0).toUpperCase()"></span>
                        <div class="que"><strong x-text="p.nombre"></strong><small x-text="'DNI ' + p.dni + (p.vacas ? ' · ' + p.vacas + ' vacas en ordeño' : '')"></small></div>
                        <div class="donde">
                            <span class="placa-ruta" :style="'--ruta: ' + colorRuta(p.vehiculo)" x-text="p.vehiculo"></span>
                            <span x-text="p.comunidad"></span>
                            {{-- cambio reciente (15 días) y traslado programado; al tocar: ficha con su historial --}}
                            <span class="etiqueta reciente" x-show="p.cambio_reciente" x-text="'🔄 desde el ' + fechaCorta(p.cambio_reciente?.desde) + ' · antes ' + p.cambio_reciente?.antes"></span>
                            <span class="etiqueta programado" x-show="p.programado" x-text="'⏳ pasa a ' + p.programado?.comunidad + ' el ' + fechaCorta(p.programado?.desde)"></span>
                        </div>
                        <div class="leche-dia" :title="p.leche ? num(p.leche.litros_30, 0) + ' L en los últimos 30 días' : 'Sin entregas recientes'">
                            <b x-text="p.leche?.por_dia ? num(p.leche.por_dia, 1) + ' L/día' : '—'"></b>
                            <div class="barra"><span :style="'width:' + Math.min(100, (p.leche?.por_dia || 0) / Math.max(1, ...(d.proveedores || []).map((x) => x.leche?.por_dia || 0)) * 100) + '%'"></span></div>
                        </div>
                        <span class="chip" :class="p.estado === 'ACTIVO' ? 'ok' : 'mal'" x-text="p.estado === 'ACTIVO' ? 'Activo' : 'De baja'" :title="p.motivo_baja || ''"></span>
                        {{-- los botones aparecen al pasar el mouse por la fila (en celular siempre); .stop: no abren la ficha --}}
                        <div class="acciones-fila al-pasar">
                            <button class="btn chico gris" x-show="p.estado !== 'BAJA' && (p.programado || p.cambio_reciente)" @click.stop="abrirCambio(p.id, true)" title="Editar traslado">✎ Traslado</button>
                            <button class="btn chico gris" x-show="p.estado !== 'BAJA' && !p.programado" @click.stop="abrirCambio(p.id)" title="Cambiar de comunidad">⇄ Mover</button>
                            <span class="chevron" aria-hidden="true">›</span>
                        </div>
                    </div>
                </template>
                <div class="vacio-lista" x-show="!proveedoresFiltrados().length"><span aria-hidden="true">·</span><p>Nadie coincide con la búsqueda.</p></div>
            </div>
            <div x-show="padron === 'trabajadores'" class="trabajadores">
                <template x-for="t in d.trabajadores || []" :key="t.id">
                    <div class="trabajador" :class="{ apagado: !t.activo }">
                        <span class="inicial grande" x-text="t.nombre.trim().charAt(0)"></span>
                        <div class="que"><strong x-text="t.nombre"></strong><small x-text="'DNI ' + t.dni"></small>
                            <span class="rol-chip" x-text="ROLES[t.rol] || t.rol"></span></div>
                        <div class="pie-trabajador">
                            <span class="chip" :class="t.activo ? 'ok' : 'mal'" x-text="t.activo ? 'Activo' : 'Desactivado'"></span>
                            <button class="btn chico" :class="t.activo ? 'rojo' : ''" @click="cambiarActivo(t)" x-text="t.activo ? 'Desactivar' : 'Activar'"></button>
                        </div>
                    </div>
                </template>
            </div>
        </section>
        </template>

        {{-- ---------------------------------------------------- CALIDAD --}}
        {{-- ---------------------------------------------------- ADULTERACIONES --}}
        <template x-if="dDe === 'adulteraciones'">
        <section x-show="seccion === 'adulteraciones' && !cargando && !error">
            <div class="cabecera"><div><h1>Adulteraciones</h1>
                <p class="sub" style="margin: 0">Proveedores a quienes Calidad les detectó agua añadida. La 1.ª vez se paga esa entrega a precio reducido; la 2.ª vez se les da de baja.</p></div></div>
            <div class="kpis-fila">
                <div class="kpi mal"><span class="kpi-ico" aria-hidden="true">⛔</span><div><b x-text="d.de_baja ?? 0"></b><span>Dados de baja</span><small>2.ª adulteración</small></div></div>
                <div class="kpi ojo"><span class="kpi-ico" aria-hidden="true">⚠</span><div><b x-text="d.advertidos ?? 0"></b><span>Con advertencia</span><small>1.ª vez, siguen entregando</small></div></div>
            </div>
            <div class="barra-herramientas">
                <div class="pestanas" style="margin: 0">
                    <button :class="{ activo: adulteracion === 'todos' }" @click="adulteracion = 'todos'">Todos</button>
                    <button :class="{ activo: adulteracion === 'baja' }" @click="adulteracion = 'baja'">Dados de baja</button>
                    <button :class="{ activo: adulteracion === 'advertidos' }" @click="adulteracion = 'advertidos'">Con advertencia</button>
                </div>
                <input type="search" x-model="buscar" placeholder="Buscar por nombre, DNI, comunidad o vehículo" class="buscador" aria-label="Buscar">
            </div>
            <div class="personas">
                <template x-for="p in adulteradosFiltrados()" :key="p.id">
                    <button class="persona" :class="p.de_baja ? 'mal' : 'ojo'" @click="verAdulterado(p)">
                        <span class="inicial" x-text="p.nombre.trim().charAt(0)"></span>
                        <span class="datos"><strong x-text="p.nombre"></strong>
                            <small x-text="'DNI ' + p.dni + ' · ' + p.comunidad + ' · ' + p.vehiculo"></small></span>
                        <span class="veces" :title="p.veces.length + (p.veces.length === 1 ? ' vez' : ' veces')">
                            <template x-for="k in p.veces.length"><i></i></template>
                            <em x-text="'última ' + fechaCorta(p.ultima)"></em></span>
                        <span class="chip" :class="p.de_baja ? 'mal' : 'ojo'" x-text="p.de_baja ? 'Dado de baja' : 'Advertencia'"></span>
                    </button>
                </template>
                <div class="vacio-lista" x-show="!adulteradosFiltrados().length">
                    <span aria-hidden="true">✓</span>
                    <p x-text="(d.proveedores || []).length ? 'Nadie coincide con la búsqueda.' : 'No hay proveedores sancionados por adulteración.'"></p></div>
            </div>
        </section>
        </template>

        {{-- ---------------------------------------------------- RECHAZOS Y CAPACITACIÓN --}}
        <template x-if="dDe === 'rechazos'">
        <section x-show="seccion === 'rechazos' && !cargando && !error">
            <div class="cabecera">
                <div><h1>Rechazos y capacitación</h1>
                    <p class="sub" style="margin: 0">Entregas que Calidad rechazó por grasa, proteína, densidad, temperatura, pH o leche significada (la adulteración va aparte).
                        Se pagan S/ 0 y la medida es <strong>capacitar al productor</strong>: cuando ya se habló con él, anótalo con “Ya se le capacitó”.</p></div>
            </div>
            <div class="barra-herramientas">
                <div class="semana-nav">
                    <button class="btn chico gris" @click="cambiar('semanaRech', d.anterior)" title="Semana anterior" aria-label="Semana anterior">◀</button>
                    <label class="btn chico gris" style="position: relative; cursor: pointer; margin: 0" title="Elegir otra semana">
                        <span x-text="'Semana ' + fechaCorta(d.inicio) + ' – ' + fechaCorta(d.fin)"></span>
                        <input type="date" :max="d.hasta" :value="d.inicio"
                               @change="$event.target.value && cambiar('semanaRech', $event.target.value)"
                               @click="$event.target.showPicker?.()"
                               style="position: absolute; inset: 0; opacity: 0; cursor: pointer">
                    </label>
                    <button class="btn chico gris" @click="cambiar('semanaRech', d.siguiente)" :disabled="!d.siguiente" title="Semana siguiente" aria-label="Semana siguiente">▶</button>
                </div>
                <div class="espacio"></div>
                <button class="btn chico gris" @click="excel('/calidad/excel/analisis?mes=' + d.inicio.slice(0, 7))" title="Descargar en Excel">⬇ Análisis del mes en Excel</button>
            </div>
            <div class="aviso" x-show="d.por_capacitar" x-text="d.por_capacitar + (d.por_capacitar === 1 ? ' productor espera' : ' productores esperan') + ' su capacitación. Cuando ya se habló con ellos, toca «Ya se le capacitó».'"></div>
            <div class="resumen-rechazos">
                <div class="tarjeta destacada">
                    <div class="eti">Rechazos de la semana</div>
                    <div class="valor enorme" x-text="d.resumen?.rechazos ?? 0"></div>
                    <div class="apoyo" x-text="(d.resumen?.proveedores ?? 0) + ' productores · ' + num(d.resumen?.litros_rechazados ?? 0) + ' L sin pagar'"></div>
                </div>
                <div class="tarjeta">
                    <div class="eti">Por causa</div>
                    <div class="causas">
                        <template x-for="c in d.resumen?.por_causa || []" :key="c.causa"><div class="causa">
                            <span x-text="c.causa"></span>
                            <div class="barra"><span :style="'width:' + (c.veces / maximo(d.resumen.por_causa, 'veces') * 100) + '%'"></span></div>
                            <b x-text="c.veces"></b></div></template>
                    </div>
                    <div class="apoyo" x-show="!(d.resumen?.por_causa || []).length">Sin rechazos esta semana.</div>
                </div>
            </div>
            <h2>Cada rechazo</h2>
            <div class="rechazos">
                <template x-for="r in d.filas || []" :key="r.id">
                    <article class="rechazo" :class="{ hecho: r.capacitado_en }">
                        <div class="fecha-bloque"><b x-text="Number(String(r.fecha).slice(8, 10))"></b>
                            <span x-text="fechaCorta(r.fecha).split(' ')[1]"></span></div>
                        <div class="cuerpo-rechazo">
                            <strong x-text="r.proveedor"></strong>
                            <small x-text="r.comunidad + ' · ' + r.vehiculo + (r.analista ? ' · analizó ' + r.analista : '')"></small>
                            <div class="etiquetas-rechazo">
                                <template x-for="c in r.causas"><span class="chip mal" x-text="c"></span></template>
                                <span class="chip ojo" x-show="!r.rechazada">se recibió igual</span>
                                <span class="chip mal fuerte" x-show="r.repite" x-text="r.veces_30_dias + ' rechazos en 30 días'"></span>
                            </div>
                        </div>
                        <div class="litros-rechazo" :class="r.rechazada ? 'mal' : ''"><b x-text="num(r.litros)"></b> L</div>
                        <div class="capacitacion">
                            <template x-if="r.capacitado_en"><div><span class="chip ok" x-text="'✓ Capacitado el ' + fechaCorta(r.capacitado_en)"></span>
                                <small x-text="(r.capacitado_por || '') + (r.nota ? ' · “' + r.nota + '”' : '')"></small></div></template>
                            <button class="btn chico" x-show="!r.capacitado_en" @click="abrirCapacitar(r)">Ya se le capacitó</button>
                        </div>
                    </article>
                </template>
                <div class="vacio-lista" x-show="!(d.filas || []).length"><span aria-hidden="true">✓</span><p>Calidad no rechazó ninguna entrega esta semana.</p></div>
            </div>
        </section>
        </template>

        {{-- ---------------------------------------------------- CAMBIOS DE ZONA --}}
        <template x-if="dDe === 'solicitudes'">
        <section x-show="seccion === 'solicitudes' && !cargando && !error">
            <div class="cabecera">
                <div><h1>Cambios de comunidad</h1>
                    <p class="sub" style="margin: 0">Los productores casi no tienen internet: cuando llamen o vengan a Huata, registra aquí su cambio y queda hecho al momento.
                        El vehículo se asigna solo según la ruta que pasa por la comunidad. Si alguno lo pidió por la app, apruébalo o recházalo abajo.</p></div>
                <div class="espacio"></div>
                <button class="btn" @click="abrirCambio()">+ Registrar cambio</button>
            </div>
            <div class="rechazos">
                <template x-for="s in d.solicitudes || []" :key="s.id">
                    <article class="rechazo cambio-fila" :class="{ hecho: s.estado !== 'PENDIENTE' }">
                        <span class="inicial" x-text="s.proveedor.trim().charAt(0)"></span>
                        <div class="cuerpo-rechazo">
                            <strong x-text="s.proveedor"></strong>
                            <small x-text="'DNI ' + s.dni + ' · ' + (CANAL[s.canal] || s.canal) + (s.registrada_por ? ' · registró ' + s.registrada_por : '')"></small>
                            <div class="ruta-cambio"><span x-text="s.origen"></span><i aria-hidden="true">→</i><b x-text="s.destino"></b></div>
                            <small x-show="s.motivo" x-text="'“' + s.motivo + '”'"></small>
                        </div>
                        <div class="litros-rechazo"><small style="display: block; font-size: 11px">desde</small><b style="font-size: 16px" x-text="fechaCorta(s.solicitada_para)"></b></div>
                        <div class="capacitacion">
                            <span class="chip" :class="s.estado === 'APROBADA' ? 'ok' : (s.estado === 'PENDIENTE' ? 'ojo' : 'mal')"
                                  x-text="s.estado === 'APROBADA' ? 'Aprobada · desde ' + fechaCorta(s.vigente_desde) : (s.estado === 'PENDIENTE' ? 'Pendiente' : 'Rechazada')"></span>
                            <div class="fila" style="justify-content: flex-end; margin-top: 6px" x-show="s.estado === 'PENDIENTE'">
                                <button class="btn chico" @click="resolverSolicitud(s, true)">Aprobar</button>
                                <button class="btn chico rojo" @click="resolverSolicitud(s, false)">Rechazar</button></div>
                        </div>
                    </article>
                </template>
                <div class="vacio-lista" x-show="!(d.solicitudes || []).length"><span aria-hidden="true">·</span><p>Todavía no hay cambios de comunidad.</p></div>
            </div>
        </section>
        </template>

        {{-- ---------------------------------------------------- CONCILIACIÓN --}}
        <template x-if="dDe === 'conciliacion'">
        <section x-show="seccion === 'conciliacion' && !cargando && !error">
            <div class="cabecera">
                <div><h1>Conciliación campo vs. planta</h1>
                    <p class="sub" style="margin: 0">Compara lo que el acopiador anotó en las casas (campo) con lo que se midió al descargar en la tina (planta).
                        Escribe los litros que llegaron de cada vehículo y la diferencia se calcula sola · tolerancia ±<span x-text="num(d.tolerancia)"></span> %.</p></div>
                <div class="espacio"></div>
                <input type="date" x-model="concFecha" @change="cargar()" :max="hoy()" style="width:auto" title="Día" aria-label="Día">
                <button class="btn chico gris" @click="excel('/admin/excel/entregas?mes=' + (concFecha || hoy()).slice(0, 7))" title="Descargar en Excel">⬇ Entregas del mes</button>
            </div>
            <div class="conciliaciones">
                <template x-for="f in dDe === 'conciliacion' ? (d.filas || []) : []" :key="f.vehiculo_id">
                    <article class="conciliacion" :class="f.litros_planta === null ? 'falta' : (f.fuera_tolerancia ? 'fuera' : 'bien')">
                        <header><span class="placa-ruta" :style="'--ruta: ' + colorRuta(f.codigo)" x-text="f.codigo"></span>
                            <span class="chip" x-show="f.litros_planta !== null" :class="f.fuera_tolerancia ? 'mal' : 'ok'" x-text="f.fuera_tolerancia ? 'Fuera de tolerancia' : 'Dentro'"></span>
                            <span class="chip ojo" x-show="f.litros_planta === null">Falta anotar</span></header>
                        <div class="par-barras">
                            <span>Campo</span><div class="barra"><span class="esperado" :style="'width:' + (f.litros_campo / Math.max(f.litros_campo, f.litros_planta || 0, 0.001) * 100) + '%'"></span></div><b x-text="num(f.litros_campo) + ' L'"></b>
                            <span>Planta</span><div class="barra"><span :class="f.fuera_tolerancia ? 'bajo' : ''" :style="'width:' + ((f.litros_planta || 0) / Math.max(f.litros_campo, f.litros_planta || 0, 0.001) * 100) + '%'"></span></div>
                            <b x-text="f.litros_planta === null ? '—' : num(f.litros_planta) + ' L'"></b>
                        </div>
                        <div class="diferencia" x-show="f.litros_planta !== null" :class="f.fuera_tolerancia ? 'mal' : 'ok'" x-text="'Diferencia ' + (f.diferencia_pct > 0 ? '+' : '') + num(f.diferencia_pct, 2) + ' %'"></div>
                        <div class="fila" style="flex-wrap: nowrap">
                            <input type="number" min="0" step="0.1" x-model="concLitros[f.vehiculo_id]" @keydown.enter="guardarPlanta(f)"
                                   placeholder="Litros que llegaron a la planta" :aria-label="'Litros en planta de ' + f.codigo">
                            <button class="btn" @click="guardarPlanta(f)" x-text="f.litros_planta === null ? 'Guardar' : 'Corregir'"></button>
                        </div>
                    </article>
                </template>
                <div class="vacio-lista" x-show="!(d.filas || []).length"><span aria-hidden="true">·</span><p>Ese día no hubo acopio en campo.</p></div>
            </div>
        </section>
        </template>

        {{-- ---------------------------------------------------- CONFIGURACIÓN --}}
        <template x-if="dDe?.startsWith('config-')">
        <section x-show="seccion?.startsWith('config-') && !cargando && !error">
            <h1 x-text="{ 'config-precios': 'Precios', 'config-calidad': 'Límites de calidad', 'config-acopio': 'Acopio y planta',
                'config-productos': 'Productos', 'config-almacen': 'Almacén · insumos', 'config-vehiculos': 'Vehículos y rutas',
                'config-empresa': 'Datos de la empresa', 'config-historial': 'Historial de cambios' }[seccion]"></h1>
            <p class="sub" x-show="seccion !== 'config-historial'">Lo que cambies aquí rige para lo que se registre desde ahora; lo ya registrado no cambia.
                Cada cambio queda en el <strong>Historial de cambios</strong> con quién lo hizo.</p>

            {{-- precios: leche (con fecha) y venta --}}
            <template x-if="seccion === 'config-precios' && d.precio_leche"><div>
                <h2>Precio de la leche</h2>
                <div class="tarjetas" style="margin-bottom: 12px">
                    <div class="tarjeta destacada"><div class="eti">Hoy se paga</div><div class="valor" x-text="soles(d.precio_leche.hoy) + ' por litro'"></div>
                        <div class="apoyo">Cada entrega se paga con el precio que regía el día que se entregó.</div></div>
                </div>
                <template x-for="p in d.precio_leche.programados" :key="p.id">
                    <div class="aviso" style="margin-bottom: 10px"><span x-text="'Desde el ' + fechaCorta(p.desde) + ' se pagará ' + soles(p.precio) + ' por litro.'"></span>
                        <button class="btn chico gris" style="margin-left: 8px" @click="quitarPrecioLeche(p)">Quitar</button></div>
                </template>
                <div class="tarjeta" style="max-width: 560px">
                    <div class="dos">
                        <div><label for="cfg-leche-precio">Precio nuevo por litro (S/)</label>
                            <input id="cfg-leche-precio" type="number" min="0.5" max="10" step="0.01" x-model="cfgLeche.precio" :placeholder="num(d.precio_leche.hoy, 2)"></div>
                        <div><label for="cfg-leche-desde">Desde el día</label>
                            <input id="cfg-leche-desde" type="date" x-model="cfgLeche.desde" :min="hoy()"></div>
                    </div>
                    <p class="sub" style="margin: 8px 0 0">Conviene que empiece un lunes, para que toda la semana de pago tenga el mismo precio.</p>
                    <div style="margin-top: 10px"><button class="btn" @click="guardarPrecioLeche()">Guardar precio de la leche</button></div>
                </div>
                <p class="sub" style="margin-top: 8px" x-show="d.precio_leche.anteriores.length"
                   x-text="'Precios anteriores: ' + d.precio_leche.anteriores.map((p) => soles(p.precio) + ' desde el ' + fechaCorta(p.desde)).join(' · ')"></p>

                <template x-for="c in camposDe('precios')" :key="c.clave"><div class="tarjeta" style="max-width: 560px; margin-top: 12px">
                    <label :for="'cfg-' + c.clave" x-text="c.etiqueta + (c.unidad ? ' (' + c.unidad + ')' : '')"></label>
                    <input :id="'cfg-' + c.clave" type="number" :min="c.min" :max="c.max" step="0.1" x-model="cfg[c.clave]">
                    <p class="sub" style="margin: 6px 0 0" x-text="c.ayuda + ' Con el precio de hoy: ' + soles(d.precio_leche.hoy * (Number(cfg[c.clave]) || 0) / 100) + ' por litro.'"></p>
                    <div style="margin-top: 10px"><button class="btn" @click="guardarConfig('precios')" :disabled="!cambiadosDe('precios').length">Guardar</button></div>
                </div></template>

                <h2>Precios de venta</h2>
                <p class="sub" style="margin-bottom: 8px">Desde hoy, para las ventas nuevas. Las notas de venta ya hechas conservan su precio.
                    Cambia el número y toca <strong>Guardar</strong> (o Enter).</p>
                {{-- Quesos y Yogures como botones grandes: al tocar uno se despliegan sus tipos, cada uno con sus tres precios --}}
                <template x-for="fam in familiasPrecioVenta()" :key="fam.nombre">
                    <div class="familia-precios" :class="{ abierta: precioAbierto[fam.tipo] }">
                        <button class="familia-boton" @click="precioAbierto[fam.tipo] = !precioAbierto[fam.tipo]" :aria-expanded="!!precioAbierto[fam.tipo]">
                            <span class="fam-ico" aria-hidden="true" x-text="fam.icono"></span>
                            <span class="fam-texto"><strong x-text="fam.nombre"></strong>
                                <small x-text="fam.productos.map((p) => p.producto.replace(/^(queso|yogurt)\s+(de\s+)?/i, '')).join(' · ')"></small></span>
                            <span class="fam-rango" x-text="rangoPrecios(fam)"></span>
                            <span class="fam-cuantos" x-text="fam.productos.length + (fam.productos.length === 1 ? ' tipo' : ' tipos')"></span>
                            <span class="fam-flecha" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg></span>
                        </button>
                        <div class="desplegable" :class="{ abierto: precioAbierto[fam.tipo] }"><div class="dentro">
                        <div class="precios-venta">
                            <template x-for="p in fam.productos" :key="p.producto_id">
                                <article class="precio-producto" :style="'--tono: ' + colorReceta({ nombre: p.producto, tipo: fam.tipo })">
                                    <header><span class="punto"></span><div><strong x-text="p.producto"></strong><small x-text="'precio por ' + p.unidad.replace(/s de /, ' de ').replace(/litros/, 'litro')"></small></div></header>
                                    <template x-for="(nombre, tipo) in d.tipos_cliente" :key="tipo">
                                        <label class="precio-fila" :class="{ cambiado: String(cfgVenta[p.producto_id + '.' + tipo] ?? '') !== String(p.precios[tipo] ?? '') }">
                                            <span class="quien" :title="nombre"><i aria-hidden="true" x-text="{ MAYORISTA: '🏪', DIRECTO: '🏠', PLANTA: '🥛' }[tipo] || '👤'"></i><span x-text="{ MAYORISTA: 'Mayorista', DIRECTO: 'Directo', PLANTA: 'Proveedor' }[tipo] || nombre"></span></span>
                                            <span class="soles-caja"><em>S/</em>
                                                <input type="number" min="0.1" step="0.01" x-model="cfgVenta[p.producto_id + '.' + tipo]" placeholder="—"
                                                       @keydown.enter="guardarPrecioVenta(p, tipo)" :aria-label="p.producto + ' · ' + nombre"></span>
                                            <button class="btn chico" x-show="String(cfgVenta[p.producto_id + '.' + tipo] ?? '') !== String(p.precios[tipo] ?? '')"
                                                    @click.prevent="guardarPrecioVenta(p, tipo)">Guardar</button>
                                        </label>
                                    </template>
                                </article>
                            </template>
                        </div>
                        </div></div>
                    </div>
                </template>
            </div></template>

            {{-- calidad, acopio y empresa: una tarjeta por medida; mínimo y máximo de lo mismo van juntos con su barrita del rango --}}
            <template x-for="g in ['calidad', 'acopio', 'empresa']" :key="g">
                <div x-show="seccion === 'config-' + g">
                    <div class="campos-config">
                        <template x-for="f in filasConfig(g)" :key="f.clave">
                            <div class="campo-config" :class="{ cambiado: f.campos.some((c) => String(cfg[c.clave] ?? '').trim() !== String(c.valor ?? '')), ancho: f.campos[0].tipo !== 'numero' }">
                                <div class="campo-cabeza">
                                    <span class="campo-ico" aria-hidden="true" x-text="f.icono"></span>
                                    <strong x-text="f.nombre"></strong>
                                </div>
                                <div class="campo-entradas">
                                    <template x-for="c in f.campos" :key="c.clave">
                                        <label class="entrada-unidad">
                                            <span class="quien" x-show="f.campos.length > 1" x-text="/m[aá]x/i.test(c.etiqueta) ? 'Máximo' : 'Mínimo'"></span>
                                            <span class="caja">
                                                <input :id="'cfg-' + c.clave" :type="c.tipo === 'numero' ? 'number' : 'text'" :min="c.min" :max="c.max" step="any"
                                                       :maxlength="c.tipo === 'ruc' ? 11 : (c.tipo === 'texto' ? c.max : null)" x-model="cfg[c.clave]"
                                                       :aria-label="c.etiqueta" :class="{ cambiado: String(cfg[c.clave] ?? '').trim() !== String(c.valor ?? '') }">
                                                <i x-show="c.unidad" x-text="c.unidad"></i>
                                            </span>
                                        </label>
                                    </template>
                                </div>
                                {{-- dónde cae lo aceptado dentro de lo que se puede poner (min..max del campo) --}}
                                <div class="rango-config" x-show="f.campos[0].tipo === 'numero' && f.campos[0].min != null">
                                    <span class="lleno" :style="barraRangoConfig(f)"></span>
                                </div>
                                <div class="rango-pie" x-show="f.campos[0].tipo === 'numero' && f.campos[0].min != null">
                                    <span x-text="f.campos[0].min"></span><span x-text="textoRangoConfig(f)"></span><span x-text="f.campos[0].max"></span>
                                </div>
                                <p class="campo-ayuda" x-show="f.ayuda" x-text="f.ayuda"></p>
                            </div>
                        </template>
                    </div>
                    <div class="fila" style="margin-top: 14px; gap: 8px">
                        <button class="btn" @click="guardarConfig(g)" :disabled="!cambiadosDe(g).length"
                                x-text="cambiadosDe(g).length ? 'Guardar ' + cambiadosDe(g).length + (cambiadosDe(g).length === 1 ? ' cambio' : ' cambios') : 'Sin cambios'"></button>
                        <button class="btn gris" x-show="cambiadosDe(g).length" @click="cargar()">Deshacer</button>
                    </div>
                </div>
            </template>

            {{-- productos: otro queso, yogurt de sabores; el que ya no se hace se desactiva (no se borra su historia) --}}
            <template x-if="seccion === 'config-productos' && d.productos"><div>
                <div class="fila" style="margin: -8px 0 12px">
                    <p class="sub" style="margin: 0">Lo que la planta produce y vende. Para un yogurt de otro sabor, crea uno nuevo y <strong>copia la fórmula</strong> de uno parecido;
                        luego ajusta su fórmula en <strong>Fórmulas y producción</strong>. Los precios se cambian en <strong>Precios</strong>.
                    Un <strong>sabor</strong> (yogurt de durazno) o una <strong>variedad</strong> (queso con orégano) se agrega en Fórmulas con <strong>+ Agregar sabor</strong>.</p>
                    <div class="espacio"></div><button class="btn" @click="abrirProducto()">+ Nuevo producto</button></div>
                <table class="lista"><tr><th>Producto</th><th>Tipo</th><th>Se cuenta en</th><th class="num">Sale de 100 L</th><th class="num">Dura</th><th>Fórmula</th><th>Estado</th><th></th></tr>
                    <template x-for="p in d.productos" :key="p.id"><tr :class="p.activo ? '' : 'tenue'">
                        <td><span class="punto-tabla" :style="'background: ' + colorReceta(p) + '; display: inline-block; margin-right: 8px'"></span><span x-text="(p.base_id ? '↳ ' : '') + p.nombre"></span>
                            <div class="sub" style="margin: 0" x-show="p.base_id" x-text="(p.tipo === 'YOGURT' ? 'sabor' : 'variedad') + ' · su fórmula sale de la base'"></div></td>
                        <td x-text="TIPOS_PRODUCTO[p.tipo]"></td><td x-text="p.unidad"></td>
                        <td class="num" x-text="(p.rinde_min === p.rinde_max ? num(p.rinde_min, 2) : num(p.rinde_min, 2) + '–' + num(p.rinde_max, 2))"></td>
                        <td class="num" x-text="p.vida_util_dias + ' días'"></td>
                        <td><span class="chip" :class="p.insumos_en_formula ? 'ok' : 'ojo'" x-text="p.insumos_en_formula ? p.insumos_en_formula + ' insumo(s)' : 'Sin fórmula'"></span></td>
                        <td><span class="chip" :class="p.activo ? 'ok' : ''" x-text="p.activo ? 'Se produce' : 'Ya no se produce'"></span></td>
                        <td class="num"><button class="btn chico gris" @click="abrirProducto(p)">Editar</button></td></tr></template>
                </table>
            </div></template>

            {{-- almacén: insumos, unidad y stock mínimo --}}
            <template x-if="seccion === 'config-almacen' && d.insumos"><div>
                <p class="sub" style="margin-top: -8px">El stock no se cambia aquí: sube con las compras y baja con la producción o con <strong>Corregir</strong> en Almacén.
                    El mínimo es desde cuánto se avisa que hay que comprar.</p>
                <table class="lista"><tr><th>Insumo</th><th style="width: 110px">Unidad</th><th class="num">Hay</th><th class="num" style="width: 130px">Stock mínimo</th><th></th></tr>
                    <template x-for="i in d.insumos" :key="i.id"><tr>
                        <td><input x-model="cfgInsumos[i.id].nombre" :aria-label="'Nombre de ' + i.nombre"></td>
                        <td><input x-model="cfgInsumos[i.id].unidad" maxlength="12" :aria-label="'Unidad de ' + i.nombre"></td>
                        <td class="num" :class="i.stock < i.minimo ? 'mal' : ''" x-text="num(i.stock, 3) + ' ' + i.unidad"></td>
                        <td class="num"><input type="number" min="0" step="any" x-model="cfgInsumos[i.id].minimo" style="text-align: right" :aria-label="'Mínimo de ' + i.nombre"></td>
                        <td class="num"><button class="btn chico" x-show="insumoCambiado(i)" @click="guardarInsumo(i)">Guardar</button></td></tr></template>
                    <tr><td><input x-model="nuevoInsumo.nombre" placeholder="Insumo nuevo (ej.: Bolsa para queso)" aria-label="Nombre del insumo nuevo"></td>
                        <td><input x-model="nuevoInsumo.unidad" maxlength="12" placeholder="kg, L, un" aria-label="Unidad del insumo nuevo"></td><td></td>
                        <td class="num"><input type="number" min="0" step="any" x-model="nuevoInsumo.minimo" placeholder="0" style="text-align: right" aria-label="Mínimo del insumo nuevo"></td>
                        <td class="num"><button class="btn chico" @click="guardarInsumo(null)">+ Agregar</button></td></tr>
                </table>
            </div></template>

            {{-- vehículos: código, tipo, acopiador y su ruta (comunidades en orden) --}}
            <template x-if="seccion === 'config-vehiculos' && d.vehiculos"><div style="display: grid; gap: 14px">
                <p class="sub" style="margin: -8px 0 0">Cada vehículo recoge las comunidades de su ruta en este orden. Si pasas una comunidad a otro vehículo, sus proveedores pasan con ella desde hoy.</p>
                <template x-for="v in d.vehiculos" :key="v.id"><div class="tarjeta vehiculo-config" :style="(v.activo ? '' : 'opacity: .6; ') + '--ruta: ' + colorRuta(v.codigo)">
                    <div class="fila" style="gap: 8px; align-items: flex-end">
                        <div style="width: 110px"><label :for="'veh-cod-' + v.id">Código</label><input :id="'veh-cod-' + v.id" x-model="cfgVeh[v.id].codigo" maxlength="12"></div>
                        <div style="width: 130px"><label :for="'veh-tipo-' + v.id">Tipo</label>
                            <select :id="'veh-tipo-' + v.id" x-model="cfgVeh[v.id].tipo"><option value="CARRO">Carro</option><option value="MOTOCAR">Motocar (circuito)</option></select></div>
                        <div style="flex: 1; min-width: 180px"><label :for="'veh-acop-' + v.id">Acopiador</label>
                            <select :id="'veh-acop-' + v.id" x-model.number="cfgVeh[v.id].acopiador_id">
                                <option :value="null">Sin acopiador fijo</option>
                                <template x-for="a in d.acopiadores" :key="a.id"><option :value="a.id" :selected="cfgVeh[v.id].acopiador_id === a.id" x-text="a.nombre"></option></template>
                            </select></div>
                        <button class="btn" x-show="vehiculoCambiado(v)" @click="guardarVehiculo(v)">Guardar</button>
                        <button class="btn gris" @click="guardarVehiculo(v, !v.activo)" x-text="v.activo ? 'Dejar de usar' : 'Volver a usar'"></button>
                    </div>
                    <label style="margin-top: 14px" x-text="'Ruta · ' + v.ruta.length + ' comunidades · ' + v.ruta.reduce((t, c) => t + c.proveedores, 0) + ' proveedores'"></label>
                    <table class="lista">
                        <template x-for="(c, k) in v.ruta" :key="c.id"><tr>
                            <td style="width: 34px; color: var(--texto-3)" x-text="k + 1"></td>
                            <td><input x-model="cfgCom[c.id].nombre" :aria-label="'Nombre de ' + c.nombre"></td>
                            <td style="white-space: nowrap"><span x-text="c.proveedores + ' prov.'"></span>
                                <span class="chip ojo" x-show="!c.con_gps" title="Ubícala en Acopio → Mapa de Huata">sin ubicar</span></td>
                            <td style="width: 150px"><select x-model.number="cfgCom[c.id].vehiculo_id" :aria-label="'Vehículo de ' + c.nombre">
                                <template x-for="o in d.vehiculos.filter((o) => o.activo || o.id === v.id)" :key="o.id">
                                    <option :value="o.id" :selected="o.id === cfgCom[c.id].vehiculo_id" x-text="o.id === v.id ? o.codigo + ' (este)' : 'Pasar al ' + o.codigo"></option></template>
                            </select></td>
                            <td class="acciones" style="white-space: nowrap">
                                <button class="btn chico gris" :disabled="k === 0" @click="moverComunidad(v, k, -1)" title="Antes en la ruta">↑</button>
                                <button class="btn chico gris" :disabled="k === v.ruta.length - 1" @click="moverComunidad(v, k, 1)" title="Después en la ruta">↓</button>
                                <button class="btn chico" x-show="comunidadCambiada(c, v.id)" @click="guardarComunidad(c, v.id)">Guardar</button></td></tr></template>
                        <tr x-show="!v.ruta.length"><td colspan="5">Todavía no recoge ninguna comunidad.</td></tr>
                    </table>
                    <div class="fila" style="gap: 8px; margin-top: 8px" x-show="v.activo">
                        <input x-model="nuevaCom[v.id]" placeholder="Comunidad nueva en esta ruta" style="max-width: 320px" :aria-label="'Comunidad nueva para el ' + v.codigo">
                        <button class="btn chico gris" @click="agregarComunidad(v)">+ Agregar comunidad</button></div>
                </div></template>
                <div class="aviso" x-show="d.sueltas.length" x-text="'Comunidades que no recoge ningún vehículo: ' + d.sueltas.map((c) => c.nombre).join(', ')"></div>
                <div class="tarjeta">
                    <h2 style="margin-top: 0">Vehículo nuevo</h2>
                    <div class="fila" style="gap: 8px; align-items: flex-end">
                        <div style="width: 110px"><label for="veh-nuevo-cod">Código</label><input id="veh-nuevo-cod" x-model="nuevoVeh.codigo" maxlength="12" placeholder="C-04"></div>
                        <div style="width: 160px"><label for="veh-nuevo-tipo">Tipo</label>
                            <select id="veh-nuevo-tipo" x-model="nuevoVeh.tipo"><option value="CARRO">Carro</option><option value="MOTOCAR">Motocar (circuito)</option></select></div>
                        <div style="flex: 1; min-width: 180px"><label for="veh-nuevo-acop">Acopiador</label>
                            <select id="veh-nuevo-acop" x-model.number="nuevoVeh.acopiador_id"><option :value="null">Sin acopiador fijo</option>
                                <template x-for="a in d.acopiadores" :key="a.id"><option :value="a.id" x-text="a.nombre"></option></template></select></div>
                        <button class="btn" @click="guardarVehiculo(null)">+ Agregar vehículo</button>
                    </div>
                </div>
            </div></template>

            {{-- historial --}}
            <template x-if="seccion === 'config-historial'"><div>
                <p class="sub">Los últimos 40 cambios de configuración y de precios, lo más reciente arriba.</p>
                <table class="lista"><tr><th>Cuándo</th><th>Qué</th><th>Antes</th><th>Ahora</th><th>Quién</th></tr>
                    <template x-for="(h, k) in d.historial || []" :key="k"><tr>
                        <td style="white-space: nowrap" x-text="fechaCorta(h.fecha) + ' ' + hora(h.fecha)"></td>
                        <td><span x-text="h.que"></span><div class="sub" style="margin:0" x-show="h.desde" x-text="'rige desde el ' + fechaCorta(h.desde)"></div></td>
                        <td x-text="h.antes"></td><td><strong x-text="h.despues"></strong></td><td x-text="h.usuario || '—'"></td></tr></template>
                    <tr x-show="!(d.historial || []).length"><td colspan="5">Todavía no se cambió nada.</td></tr>
                </table>
            </div></template>
        </section>
        </template>

        {{-- ---------------------------------------------------- COSTOS --}}
        <template x-if="dDe === 'costos'">
        <section x-show="seccion === 'costos' && !cargando && !error">
            <div class="cabecera"><div><h1>Costos y rendimiento</h1>
                <p class="sub" style="margin: 0" x-text="'Desde el ' + fechaCorta(d.desde) + ' (últimos 30 días).'"></p></div></div>
            <div class="resumen-acopio">
                <div class="tarjeta destacada">
                    <div class="eti">Costo por litro procesado</div>
                    <div class="valor enorme" x-text="'S/ ' + costoLitroTotal().toFixed(3)"></div>
                    {{-- de qué está hecho ese costo: leche, insumos, gastos de planta y lo perdido --}}
                    <div class="barra-partes" :title="partesCosto().map((p) => p.nombre + ' S/ ' + p.valor.toFixed(3)).join(' · ')">
                        <template x-for="p in partesCosto()" :key="p.nombre"><span :style="'flex:' + p.valor + ';background:' + p.color"></span></template></div>
                    <div class="fila pie-progreso" style="flex-wrap: wrap">
                        <template x-for="p in partesCosto()" :key="p.nombre">
                            <span><i class="punto-leyenda" :style="'background:' + p.color"></i><span x-text="p.nombre"></span> <b x-text="'S/ ' + p.valor.toFixed(3)"></b></span></template>
                    </div>
                    <p class="apoyo" style="margin-top: 14px" x-show="Number(d.costo_leche_litro) > 0"
                       x-text="'La leche es el ' + num(Number(d.costo_leche_litro) / (costoLitroTotal() || 1) * 100, 0) + ' % de lo que cuesta producir.'
                           + (Number(d.gastos_planta) > 0 ? ' Gastos de planta en 30 días: ' + soles(d.gastos_planta) + '.' : '')
                           + (Number(d.perdidas?.total) > 0 ? ' Pérdidas: ' + soles(d.perdidas.total) + '.' : '')"></p>
                </div>
                <div class="kpis-fila" style="grid-template-columns: 1fr; margin: 0">
                    <div class="kpi"><span class="kpi-ico" aria-hidden="true">🥛</span><div><b x-text="num(d.litros_procesados, 0) + ' L'"></b><span>Leche procesada</span><small>en lotes de estos 30 días</small></div></div>
                    <div class="kpi ok"><span class="kpi-ico" aria-hidden="true">🏷</span><div><b x-text="soles(d.ventas_total)"></b><span>Ventas</span><small x-text="(d.ventas_cantidad || 0) + ' ventas'"></small></div></div>
                </div>
            </div>
            <h2>Rendimiento por cada 100 L de leche</h2>
            <div class="rendimientos">
                <template x-for="r in d.rendimiento || []" :key="r.producto">
                    <div class="rendimiento" :style="'--tono: ' + colorReceta(productoVisual(r.producto))">
                        <header><i class="punto"></i><strong x-text="r.producto"></strong><small x-text="r.lotes + (r.lotes === 1 ? ' lote' : ' lotes')"></small></header>
                        <div class="par-barras">
                            <span>Esperado</span><div class="barra"><span class="esperado" :style="'width:' + (r.esperado_100l / Math.max(r.esperado_100l, r.real_100l, 0.001) * 100) + '%'"></span></div><b x-text="num(r.esperado_100l)"></b>
                            <span>Real</span><div class="barra"><span :class="r.real_100l < r.esperado_100l ? 'bajo' : ''" :style="'width:' + (r.real_100l / Math.max(r.esperado_100l, r.real_100l, 0.001) * 100) + '%'"></span></div>
                            <b :class="r.real_100l < r.esperado_100l ? 'ojo' : 'ok'" x-text="num(r.real_100l)"></b>
                        </div>
                    </div>
                </template>
                <div class="vacio-lista" x-show="!(d.rendimiento || []).length"><span aria-hidden="true">·</span><p>No hay lotes terminados en este periodo.</p></div>
            </div>
            <h2>Precios de venta vigentes</h2>
            {{-- un producto por fila y un tipo de cliente por columna --}}
            <table class="lista precios-tabla"><tr><th>Producto</th><th class="num">Mayorista</th><th class="num">Cliente directo</th><th class="num">Proveedor de leche</th></tr>
                <template x-for="fila in preciosPorProducto(d.precios || [])" :key="fila.producto"><tr>
                    <td><span class="punto-tabla" :style="'background: ' + colorReceta(productoVisual(fila.producto))"></span><span x-text="fila.producto"></span></td>
                    <td class="num" x-text="fila.MAYORISTA != null ? soles(fila.MAYORISTA) : '—'"></td>
                    <td class="num" x-text="fila.DIRECTO != null ? soles(fila.DIRECTO) : '—'"></td>
                    <td class="num" x-text="fila.PLANTA != null ? soles(fila.PLANTA) : '—'"></td></tr></template>
            </table>
        </section>
        </template>

        {{-- ---------------------------------------------------- SESIONES --}}
        <template x-if="dDe === 'sesiones'">
        <section x-show="seccion === 'sesiones' && !cargando && !error">
            <div class="cabecera"><div><h1>Sesiones abiertas</h1>
                <p class="sub" style="margin: 0">Quién tiene la app o esta web abierta. Cierra las sesiones de quien perdió el celular o dejó de trabajar.</p></div></div>
            <div class="trabajadores">
                <template x-for="u in d.usuarios || []" :key="u.id">
                    <div class="trabajador">
                        <span class="inicial grande" x-text="u.nombre.trim().charAt(0)"></span>
                        <div class="que"><strong x-text="u.nombre"></strong><small x-text="'Último uso ' + fechaCorta(u.ultimo_uso) + ' ' + hora(u.ultimo_uso)"></small>
                            <span class="rol-chip" x-text="ROLES[u.rol] || u.rol"></span></div>
                        <div class="pie-trabajador">
                            <span><b class="cuenta-sesiones" x-text="u.sesiones"></b> <span class="apoyo-fila" style="display: inline" x-text="u.sesiones === 1 ? 'sesión abierta' : 'sesiones abiertas'"></span></span>
                            <button class="btn chico rojo" @click="cerrarSesiones(u)">Cerrar sesiones</button>
                        </div>
                    </div>
                </template>
                <div class="vacio-lista" x-show="!(d.usuarios || []).length"><span aria-hidden="true">✓</span><p>Nadie tiene sesiones abiertas.</p></div>
            </div>
        </section>
        </template>

        {{-- ---------------------------------------------------- VENDER --}}
        <template x-if="dDe === 'vender'">
        <section x-show="seccion === 'vender' && !cargando && !error">
            <h1>Nueva venta</h1>
            {{-- paso a paso: 1 cliente → 2 productos → 3 confirmar --}}
            <div class="pasos-venta">
                <template x-for="(t, k) in ['Cliente', 'Productos', 'Confirmar']" :key="k">
                    <button :class="{ activo: f.paso === k + 1, hecho: f.paso > k + 1 }" @click="pasoVenta(k + 1)">
                        <span class="paso" x-text="f.paso > k + 1 ? '✓' : k + 1"></span> <span x-text="t"></span></button>
                </template>
            </div>
            <div class="aviso error" x-show="f.error" x-text="f.error"></div>

            {{-- 1 · cliente --}}
            <div class="tarjeta paso-venta" x-show="f.paso === 1">
                <h2>¿A quién le vendes?</h2>
                <input type="search" x-model="buscarCliente" @input.debounce.350ms="alBuscarCliente()" placeholder="Buscar por nombre, DNI/RUC o teléfono" class="grande" inputmode="search">
                <template x-if="!buscarCliente.trim()"><div>
                    <div class="eti" style="margin-top: 14px" x-show="clientesFrecuentes().length">Últimos clientes</div>
                    <div class="chips-cliente">
                        <template x-for="c in clientesFrecuentes()" :key="c.id">
                            <button class="btn gris" :class="{ elegido: f.cliente === c.id }" @click="elegirCliente(c, true)" x-text="c.nombre"></button></template>
                    </div>
                </div></template>
                <template x-if="buscarCliente.trim()"><div class="lista-clientes">
                    <template x-for="c in clientesVisibles().filter((c) => Number(c.activo)).slice(0, 8)" :key="c.id">
                        <button class="cliente-op" @click="elegirCliente(c, true)">
                            <strong x-text="c.nombre"></strong>
                            <small x-text="[TIPO_CLIENTE[c.tipo] || c.tipo, c.ruc ? (c.ruc.length === 8 ? 'DNI ' : 'RUC ') + c.ruc : '', c.telefono ? 'Tel. ' + c.telefono : ''].filter(Boolean).join(' · ')"></small></button></template>
                    {{-- un DNI/RUC que no es de ningún cliente: su nombre (RENIEC/SUNAT o de la planta) y agregarlo al toque --}}
                    <template x-if="docVenta && docVenta.numero === buscarCliente.trim() && !clientesVisibles().some((c) => c.ruc === docVenta.numero)">
                        <div class="doc-encontrado" :class="{ nada: !docVenta.cargando && !docVenta.nombre }">
                            <div class="que">
                                <small x-text="(docVenta.numero.length === 8 ? 'DNI ' : 'RUC ') + docVenta.numero"></small>
                                <strong x-text="docVenta.cargando ? 'Buscando el nombre…' : (docVenta.nombre || (docVenta.sin_token ? 'No es de ningún cliente' : 'No se encontró'))"></strong>
                                <small x-show="docVenta.nombre" x-text="FUENTE_DOC[docVenta.fuente] || ''"></small>
                                <small x-show="!docVenta.cargando && !docVenta.nombre" x-text="docVenta.error || (docVenta.sin_token ? 'Para traer el nombre de RENIEC falta poner el token (DECOLECTA_TOKEN). Puedes escribirlo a mano.' : '')"></small>
                            </div>
                            {{-- dos caminos: guardarlo como cliente o venderle nada más (la nota sale a su nombre) --}}
                            <div class="doc-acciones" x-show="!docVenta.cargando">
                                <button class="btn gris" x-show="docVenta.nombre" @click="soloVender(docVenta)" title="No se guarda como cliente: la nota sale a su nombre">Solo vender</button>
                                <button class="btn" @click="abrirCliente(null, { nombre: docVenta.nombre || '', documento: docVenta.numero, fuente: docVenta.fuente })"
                                        x-text="docVenta.nombre ? '+ Agregar como cliente' : '+ Agregar a mano'"></button>
                            </div>
                        </div>
                    </template>
                    <div class="apoyo" x-show="!clientesVisibles().filter((c) => Number(c.activo)).length && !docVenta">Nadie se llama así. Agrégalo con <strong>+ Nuevo cliente</strong>.</div>
                </div></template>
                {{-- alguien de paso que no se registra: solo su nombre en la nota (y su DNI si lo da) --}}
                <div class="sin-registro" x-show="f.sinRegistro">
                    <div class="eti">Vender a alguien sin registrar · la nota sale solo a su nombre</div>
                    <div class="fila" style="gap: 8px; margin-top: 6px">
                        <input :value="f.sinRegistro?.nombre" @input="f.sinRegistro.nombre = $event.target.value" placeholder="Nombre de quien compra" maxlength="150" style="flex: 2" aria-label="Nombre de quien compra"
                               @keydown.enter="venderSinRegistro()">
                        <input :value="f.sinRegistro?.dni" @input="f.sinRegistro.dni = $event.target.value.replace(/\D/g, '')" placeholder="DNI (opcional)" maxlength="8" inputmode="numeric" style="flex: 1" aria-label="DNI (opcional)"
                               @keydown.enter="venderSinRegistro()">
                        <button class="btn" @click="venderSinRegistro()">Seguir →</button>
                        <button class="btn gris" @click="f.sinRegistro = null">Cancelar</button>
                    </div>
                </div>
                <div class="fila" style="margin-top: 18px">
                    <button class="btn gris" @click="abrirCliente()">+ Nuevo cliente</button>
                    <button class="btn gris" x-show="!f.sinRegistro" @click="f.sinRegistro = { nombre: '', dni: '' }; $nextTick(() => document.querySelector('.sin-registro input')?.focus())">👤 Vender a alguien sin registrar</button>
                    <button class="btn chico gris" x-show="clienteVenta() && clienteVenta().tipo !== 'PLANTA'" @click="abrirCliente(clienteVenta())">Editar cliente</button>
                    <div class="espacio"></div>
                    <button class="btn" x-show="clienteVenta()" @click="pasoVenta(2)" x-text="'Seguir con ' + nombreComprador() + ' →'"></button>
                </div>
            </div>

            {{-- 2 · productos (solo los que hay) --}}
            <div class="tarjeta paso-venta" x-show="f.paso === 2">
                <h2>¿Qué lleva <span x-text="nombreComprador()"></span>?</h2>
                <p class="sub" style="margin-top: 0" x-show="f.comprador">Sin registrarlo como cliente: la nota sale a su nombre<span x-text="f.comprador?.documento ? ' (' + (f.comprador.documento.length === 8 ? 'DNI ' : 'RUC ') + f.comprador.documento + ')' : ''"></span>.</p>
                <p class="sub" style="margin-top: 0">Elige el producto; si tiene tipos o sabores, elige cuál. Pon cuántos y dale a <strong>Agregar</strong>. El precio es el de <span x-text="(TIPO_CLIENTE[clienteVenta()?.tipo] || '').toLowerCase()"></span>.</p>
                {{-- a) el producto (bases), separado en Quesos / Yogurt --}}
                <template x-for="g in gruposVenta()" :key="g.tipo"><div class="grupo-venta">
                    <div class="eti" x-text="g.nombre"></div>
                    <div class="chips-cliente">
                        <template x-for="fa in g.familias" :key="fa.base.id">
                            <button class="producto-venta" :class="{ elegido: f.familia === fa.base.id }" :style="'--tono: ' + colorReceta(fa.base)" @click="elegirFamilia(fa)">
                                <span class="mini" aria-hidden="true" x-html="dibujoReceta(fa.base)"></span>
                                <span class="txt"><strong x-text="fa.sabores.length > 1 ? fa.base.nombre.replace(/\s+natural$/i, '') : fa.base.nombre"></strong>
                                    <small x-text="fa.juntos ? fa.sabores.length + ' tipos' + (fa.sabores.some((p) => p.stock > 0) ? ' · hay ' + num(fa.sabores.reduce((t, p) => t + Math.max(0, p.stock), 0), 0) : ' · sin stock')
                                    : fa.sabores.length > 1 ? fa.sabores.length + ' sabores' : (fa.base.stock > 0 ? 'hay ' + num(fa.base.stock, 0) + ' ' + unidadProducto(fa.base) : 'sin stock')"></small></span>
                                <small class="chip ojo" x-show="fa.sabores.some((p) => vencePronto(p))" title="Tiene un lote que vence esta semana: véndelo primero">⏳ vence pronto</small></button></template>
                    </div>
                </div></template>
                <div class="apoyo" x-show="!gruposVenta().length">No hay productos. Agrégalos en Configuración → Productos.</div>

                {{-- b) el sabor (solo si tiene) --}}
                <template x-if="familiaVenta() && familiaVenta().sabores.length > 1"><div class="grupo-venta sabores-venta" x-revelar>
                    <div class="eti" x-text="(familiaVenta().juntos ? '¿Qué queso? · ' : '¿De qué sabor? · ') + familiaVenta().base.nombre"></div>
                    <div class="chips-cliente">
                        <template x-for="p in familiaVenta().sabores" :key="p.id">
                            <button class="producto-venta sabor" :class="{ elegido: f.producto === p.id }" :style="'--tono: ' + colorReceta(p)" @click="elegirProducto(p)">
                                <i class="punto" aria-hidden="true"></i>
                                <span class="txt"><strong x-text="nombreSabor(p, familiaVenta().juntos)"></strong><small x-text="p.stock > 0 ? 'hay ' + num(p.stock, 0) : 'sin stock'"></small></span>
                                <small class="chip ojo" x-show="loteQueVence(p)" :title="'Lote ' + loteQueVence(p)?.codigo + ': véndelo primero'"
                                       x-text="'⏳ ' + (loteQueVence(p) ? textoVence(loteQueVence(p)) : '')"></small></button></template>
                    </div>
                </div></template>

                {{-- c) cuántos --}}
                <template x-if="f.producto"><div class="agregar-producto" x-revelar>
                    <div style="flex: 1; min-width: 200px"><div class="eti">Elegiste</div>
                        <strong style="font-size: 17px" x-text="(d.productos || []).find((x) => x.id === f.producto)?.nombre"></strong>
                        <small class="apoyo" style="display: block" x-text="(() => { const p = (d.productos || []).find((x) => x.id === f.producto); return p ? soles(p.precios?.[clienteVenta()?.tipo]) + ' c/u · hay ' + num(p.stock, 0) + ' ' + p.unidad : ''; })()"></small>
                        {{-- sale primero lo que vence primero --}}
                        <small class="apoyo" style="display: block" :class="{ ojo: vencePronto((d.productos || []).find((x) => x.id === f.producto)) }"
                               x-text="(() => { const p = (d.productos || []).find((x) => x.id === f.producto); return p ? textoLotes(p, (Number(f.cantidades[p.id]) || 0) + (Number(f.cuantos) || 0)) : ''; })()"></small>
                        <small class="apoyo ojo" style="display: block" x-show="(d.productos || []).find((x) => x.id === f.producto)?.vencido > 0"
                               x-text="(() => { const p = (d.productos || []).find((x) => x.id === f.producto); return p ? num(p.vencido, 2) + ' ' + p.unidad + ' ya vencieron: no se venden, regístralos en Almacén → Pérdidas' : ''; })()"></small></div>
                    <div style="width: 110px"><label for="venta-cuantos">Cuántos</label>
                        <input id="venta-cuantos" type="number" min="1" step="1" class="grande" x-model="f.cuantos" @keydown.enter.prevent="agregarProducto()"></div>
                    <button class="btn" @click="agregarProducto()">Agregar</button>
                </div></template>

                <div class="eti" style="margin-top: 18px">Lleva</div>
                <div class="lleva-lista">
                    <template x-for="l in lineasVenta()" :key="l.p.id"><div class="renglon">
                        <span><strong x-text="num(l.cantidad, 2)"></strong> × <span x-text="l.p.nombre"></span> <small x-text="'(' + soles(l.precio) + ')'"></small>
                            <small style="display: block" x-text="textoLotes(l.p, l.cantidad)"></small></span>
                        <span class="espacio"></span>
                        <strong x-text="soles(l.importe)"></strong>
                        <button class="btn chico gris" @click="quitarProducto(l.p)" :aria-label="'Quitar ' + l.p.nombre" title="Quitar">✕</button></div></template>
                    <div class="apoyo" x-show="!lineasVenta().length">Todavía no lleva nada.</div>
                </div>
                <div class="fila" style="margin-top: 18px">
                    <button class="btn gris" @click="pasoVenta(1)">← Cliente</button>
                    <div class="espacio"></div>
                    <strong style="font-size: 18px" x-show="lineasVenta().length" x-text="'Total ' + soles(totalVenta())"></strong>
                    <button class="btn" @click="pasoVenta(3)" :disabled="!lineasVenta().length">Revisar →</button>
                </div>
            </div>

            {{-- 3 · confirmar --}}
            <div class="tarjeta paso-venta resumen-venta" x-show="f.paso === 3">
                <div class="eti">Nota de venta</div>
                <div class="cliente" x-text="nombreComprador()"></div>
                <small class="apoyo" x-show="f.comprador" x-text="(f.comprador?.documento ? (f.comprador.documento.length === 8 ? 'DNI ' : 'RUC ') + f.comprador.documento + ' · ' : '') + 'sin registrar como cliente'"></small>
                <template x-for="l in lineasVenta()" :key="l.p.id"><div class="renglon">
                    <span><span x-text="num(l.cantidad, 2) + ' × ' + l.p.nombre + ' (' + soles(l.precio) + ')'"></span>
                        <small class="apoyo" style="display: block" x-text="textoLotes(l.p, l.cantidad)"></small></span>
                    <span x-text="soles(l.importe)"></span></div></template>
                <div class="total"><span>Total</span><strong x-text="soles(totalVenta())"></strong></div>
                {{-- cómo paga: efectivo, Yape o tarjeta (con Yape o tarjeta se puede anotar el N.° de operación) --}}
                <label>¿Cómo paga?</label>
                <div class="metodos-pago" role="radiogroup" aria-label="Cómo paga">
                    <template x-for="[clave, m] in Object.entries(METODO_PAGO)" :key="clave">
                        <button type="button" role="radio" :aria-checked="f.metodo === clave" :class="{ activo: f.metodo === clave }" @click="f.metodo = clave">
                            <span aria-hidden="true" x-text="m.icono"></span><span x-text="m.nombre"></span></button>
                    </template>
                </div>
                <div x-show="f.metodo !== 'EFECTIVO'">
                    <label x-text="'N.° de operación ' + (f.metodo === 'YAPE' ? 'del Yape' : 'del voucher') + ' (opcional)'"></label>
                    <input x-model="f.operacion" maxlength="30" inputmode="numeric" placeholder="Ej. 123456">
                </div>
                <label>Enviar la nota al correo (opcional)</label>
                <input type="email" x-model="f.correo" placeholder="cliente@gmail.com">
                <div class="fila" style="margin-top: 18px">
                    <button class="btn gris" @click="pasoVenta(2)">← Productos</button>
                    <div class="espacio"></div>
                    <button class="btn" @click="registrarVenta()" :disabled="!!f.enviando"
                            x-text="f.enviando ? 'Registrando…' : '✓ Registrar venta'"></button>
                </div>
            </div>
        </section>
        </template>

        {{-- ---------------------------------------------------- INVENTARIO --}}
        {{-- ---------------------------------------------------- LOTES EN ALMACÉN --}}
        {{-- todos los lotes con stock, por producto, en el orden en que salen al vender (el que vence primero arriba) --}}
        <template x-if="dDe === 'lotes-almacen'">
        <section x-show="seccion === 'lotes-almacen' && !cargando && !error">
            <div class="cabecera">
                <div><h1>Lotes en almacén</h1>
                    <p class="sub" style="margin: 0">Todo lo que hay hecho, lote por lote, en el orden en que sale al vender: <strong>primero el que vence antes</strong>.</p></div>
                <div class="espacio"></div>
                <button class="btn rojo" @click="abrirMerma('PRODUCTO')">+ Registrar pérdida</button>
            </div>

            {{-- cuatro estados: tocar uno muestra solo esos lotes --}}
            <div class="lotes-estados">
                <template x-for="e in ['NUEVO', 'BIEN', 'POR_VENCER', 'VENCIDO']" :key="e">
                    <button class="le-tile" :class="'e-' + e.toLowerCase() + (filtroLotes === e ? ' activo' : '') + (filtroLotes && filtroLotes !== e ? ' apagado' : '') + (d.cuenta?.[e] ? '' : ' cero')"
                            @click="filtroLotes = filtroLotes === e ? '' : e" :aria-pressed="filtroLotes === e">
                        <span class="le-ico" aria-hidden="true" x-text="ESTADO_LOTE[e].icono"></span>
                        <b x-text="d.cuenta?.[e] || 0"></b>
                        <span class="le-txt"><strong x-text="ESTADO_LOTE[e].texto"></strong><small x-text="ESTADO_LOTE[e].ayuda"></small></span>
                    </button>
                </template>
            </div>
            <div class="lotes-filtro" x-show="filtroLotes" x-transition>
                <span x-text="'Mostrando solo: ' + (ESTADO_LOTE[filtroLotes]?.texto || '').toLowerCase()"></span>
                <button class="btn chico gris" @click="filtroLotes = ''">Ver todos</button>
            </div>

            <div class="lotes-grilla">
                <template x-for="p in productosLotes()" :key="p.producto_id">
                    <article class="lote-producto" :style="'--tono: ' + colorReceta({ nombre: p.nombre, tipo: p.tipo, variante: p.variante })">
                        <header>
                            <div class="mini-dibujo" x-html="dibujoReceta({ nombre: p.nombre, tipo: p.tipo, variante: p.variante, base_id: p.base_id })"></div>
                            <div class="lp-que"><strong x-text="p.nombre"></strong>
                                <small x-text="p.lotes.length + (p.lotes.length === 1 ? ' lote' : ' lotes') + ' · quedan ' + num(p.queda, 2) + ' ' + p.unidad"></small></div>
                        </header>
                        {{-- de qué lotes está hecho el stock: una barra partida, del color de su estado --}}
                        <div class="lp-barra" aria-hidden="true">
                            <template x-for="l in p.lotes" :key="l.lote_id">
                                <i :class="'e-' + l.estado.toLowerCase()" :style="'flex-grow: ' + l.queda" :title="l.codigo + ': ' + num(l.queda, 2)"></i>
                            </template>
                        </div>

                        <ol class="lp-lotes">
                            <template x-for="l in p.visibles" :key="l.lote_id">
                                <li class="lote-fila" :class="'e-' + l.estado.toLowerCase() + (saleAhora(p, l) ? ' ahora' : '')">
                                    <span class="lf-orden" :title="'Sale en el lugar ' + l.orden" x-text="l.orden + 'º'"></span>
                                    <div class="lf-cuerpo">
                                        <div class="lf-arriba">
                                            <strong class="lf-codigo" x-text="'Lote ' + l.codigo"></strong>
                                            <span class="lf-estado" x-text="ESTADO_LOTE[l.estado].icono + ' ' + estadoLote(l)"></span>
                                            <span class="lf-ahora" x-show="saleAhora(p, l)">➜ sale en la próxima venta</span>
                                        </div>
                                        {{-- la vida del lote: del día que se hizo al día que vence; el punto es hoy --}}
                                        <div class="lf-vida" :title="'Lleva ' + l.hecho_hace + ' de ' + l.vida_dias + ' días'">
                                            <div class="lf-riel"><i class="lf-falta" :style="'width: calc(' + (100 - vidaLote(l)) + '% + 1px)'"></i><i class="lf-hoy" :style="'left: ' + vidaLote(l) + '%'"></i></div>
                                            <div class="lf-fechas">
                                                <span x-text="'Hecho el ' + fechaCorta(l.elaborado) + ' · ' + textoHecho(l)"></span>
                                                <span class="lf-vence" x-text="textoVence(l) + ' (' + fechaCorta(l.vence_el) + ')'"></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="lf-queda">
                                        <b x-text="num(l.queda, 2)"></b><small x-text="'de ' + num(l.obtenido, 2)"></small>
                                        <span class="lf-mini" aria-hidden="true"><i :style="'width: ' + Math.min(100, l.queda / (l.obtenido || 1) * 100) + '%'"></i></span>
                                    </div>
                                    <div class="lf-accion">
                                        <button class="btn chico rojo" x-show="l.vencido" @click="registrarVencido({ ...l, producto_id: p.producto_id })">Registrar pérdida</button>
                                        <button class="btn chico" x-show="saleAhora(p, l)" @click="ir('vender')">Vender</button>
                                    </div>
                                </li>
                            </template>
                        </ol>
                    </article>
                </template>
            </div>

            <div class="vacio-lista" x-show="!productosLotes().length"><span aria-hidden="true">🧀</span>
                <p x-text="filtroLotes ? 'Ahora no hay lotes en «' + ESTADO_LOTE[filtroLotes].texto + '».' : 'No hay lotes en el almacén. Cuando se termine un lote en planta, aparece aquí.'"></p></div>
        </section>
        </template>

        <template x-if="dDe === 'inventario'">
        <section x-show="seccion === 'inventario' && !cargando && !error">
            <div class="cabecera">
                <div><h1>Almacén</h1>
                    <p class="sub" style="margin: 0">Lo que hay hoy y lo que se pierde. Si un insumo se malogró, se venció o al contar no cuadra, toca <strong>Corregir</strong>.
                        Lo que falta se compra en la pestaña <strong>🛒 Compras</strong>.</p></div>
                <div class="espacio"></div>
                <button class="btn" x-show="(d.por_comprar || []).length" @click="irAComprar('todo')"
                        x-text="'🛒 Comprar lo que falta (' + (d.por_comprar || []).length + ')'"></button>
                <button class="btn rojo" @click="abrirMerma('PRODUCTO')">+ Registrar pérdida</button>
            </div>
            <div class="kpis-fila">
                <div class="kpi ok"><span class="kpi-ico" aria-hidden="true">🧀</span><div>
                    <b x-text="(d.productos || []).filter((p) => p.stock > 0).length + ' de ' + (d.productos || []).length"></b><span>Productos con stock</span><small>listos para vender</small></div></div>
                <div class="kpi ok"><span class="kpi-ico" aria-hidden="true">✓</span><div>
                    <b x-text="(d.insumos || []).filter((i) => i.stock >= i.minimo).length"></b><span>Insumos bien</span><small>sobre su mínimo</small></div></div>
                <div class="kpi" :class="(d.insumos || []).some((i) => i.stock < i.minimo) ? 'mal' : 'ok'"><span class="kpi-ico" aria-hidden="true">⚠</span><div>
                    <b x-text="(d.insumos || []).filter((i) => i.stock < i.minimo).length"></b><span>Bajo el mínimo</span><small>hay que comprar pronto</small></div></div>
            </div>
            {{-- lotes vencidos o por vencer: lo vencido se registra como pérdida; lo que está por vencer se vende primero --}}
            <div class="por-vencer" x-show="(d.por_vencer || []).length">
                <div class="pv-cabeza"><span aria-hidden="true">⏳</span>
                    <div><strong>Lotes vencidos o por vencer</strong>
                        <small>Lo vencido ya no se vende: regístralo como pérdida. Lo que vence esta semana, véndelo primero (al vender sale solo primero).</small></div></div>
                <template x-for="x in d.por_vencer || []" :key="x.lote_id">
                    <div class="pv-fila" :class="x.vencido ? 'vencido' : (x.dias <= 2 ? 'urgente' : '')" :style="'--tono: ' + colorReceta({ nombre: x.producto, tipo: x.tipo })">
                        <i class="punto" aria-hidden="true"></i>
                        <div class="pv-que"><strong x-text="x.producto"></strong><small x-text="'Lote ' + x.codigo + ' · quedan ' + num(x.queda, 2) + ' ' + x.unidad"></small></div>
                        <span class="pv-dias" x-text="(x.vencido ? '⛔ ' : '⏳ ') + textoVence(x)"></span>
                        <button class="btn chico rojo" x-show="x.vencido" @click="registrarVencido(x)">Registrar pérdida</button>
                        <button class="btn chico" x-show="!x.vencido" @click="ir('vender')">Vender primero</button>
                    </div>
                </template>
            </div>
            <div class="aviso bien" x-show="!(d.avisos || []).length">No falta ningún insumo.</div>
            <template x-for="a in d.avisos || []" :key="a.nivel + a.insumo_id + a.producto">
                <div class="aviso aviso-comprar" :class="a.nivel === 'FALTA' ? 'error' : ''"><span x-text="a.texto"></span>
                    <button class="btn chico" x-show="a.comprar > 0" @click="irAComprar(a.insumo_id, a.comprar)">🛒 Comprar</button></div>
            </template>

            <h2>Productos terminados</h2>
            {{-- productos agrupados: quesos y yogurt (con sus sabores); cada grupo se abre como un combo --}}
            <div class="grupos-insumos">
                <template x-for="g in gruposProductosAlmacen()" :key="g.id">
                    <section class="grupo-insumos" :class="{ abierto: almacenAbierto[g.id] }">
                        <button class="cabeza-grupo" @click="almacenAbierto[g.id] = !almacenAbierto[g.id]" :aria-expanded="!!almacenAbierto[g.id]">
                            <span class="ico-grupo" aria-hidden="true" x-text="g.icono"></span>
                            <span class="texto"><strong x-text="g.nombre"></strong>
                                <small x-text="g.items.length + (g.items.length === 1 ? ' producto' : ' productos') + ' · ' + g.conStock + ' con stock'"></small></span>
                            <span class="puntos-productos" aria-hidden="true"><template x-for="x in g.items" :key="x.id"><i :style="'background: ' + colorReceta(x)" :class="{ vacio: !(x.stock > 0) }"></i></template></span>
                            <span class="total-grupo" x-show="g.total !== null"><b x-text="num(g.total, 0)"></b> <span x-text="g.unidad + (g.vencido > 0 ? ' para vender' : '')"></span>
                                <em class="vencido-grupo" x-show="g.vencido > 0" x-text="'⛔ ' + num(g.vencido, 0) + ' vencidos'"></em></span>
                            <span class="flecha" aria-hidden="true">▾</span>
                        </button>
                        <div class="desplegable" :class="{ abierto: almacenAbierto[g.id] }"><div class="dentro">
            <div class="productos-almacen">
                <template x-for="p in g.items" :key="p.id">
                    <article class="producto-almacen" :class="{ cero: !(p.stock > 0) }" :style="'--tono: ' + colorReceta(p)">
                        <div class="mini-dibujo" x-html="dibujoReceta(p)"></div>
                        <div class="info">
                            <strong x-text="p.nombre"></strong>
                            <div class="stock-grande"><b x-text="num(p.stock, 0)"></b> <span x-text="p.unidad + (p.vencido > 0 ? ' para vender' : '')"></span></div>
                            <button class="vencido-producto" x-show="p.vencido > 0" @click="registrarVencido((d.por_vencer || []).find((x) => x.producto_id === p.id && x.vencido))"
                                    x-text="'⛔ ' + num(p.vencido, 2) + ' ' + p.unidad + ' vencidos · registrar pérdida'"></button>
                            <div class="precios">
                                <span title="Precio mayorista">May. <b x-text="soles(p.precios?.MAYORISTA)"></b></span>
                                <span title="Precio cliente directo">Dir. <b x-text="soles(p.precios?.DIRECTO)"></b></span>
                                <span title="Precio proveedor de leche">Prov. <b x-text="soles(p.precios?.PLANTA)"></b></span>
                            </div>
                        </div>
                        <button class="btn chico gris" @click="verKardex(p.id, 'producto')">Kardex</button>
                    </article>
                </template>
            </div>
                        </div></div>
                    </section>
                </template>
            </div>

            <h2>Insumos</h2>
            {{-- insumos agrupados por para qué sirven (sale de las fórmulas); cada grupo se abre como un combo
                 y los que tienen algo por comprar empiezan abiertos --}}
            <div class="grupos-insumos">
                <template x-for="g in gruposInsumos()" :key="g.id">
                    <section class="grupo-insumos" :class="{ abierto: grupoInsumoAbierto(g), falta: g.faltan }">
                        <button class="cabeza-grupo" @click="almacenAbierto[g.id] = !grupoInsumoAbierto(g)" :aria-expanded="grupoInsumoAbierto(g)">
                            <span class="ico-grupo" aria-hidden="true" x-text="g.icono"></span>
                            <span class="texto"><strong x-text="g.nombre"></strong>
                                <small x-text="g.items.length + (g.items.length === 1 ? ' insumo' : ' insumos')"></small></span>
                            <span class="iconitos" aria-hidden="true" x-text="g.items.slice(0, 6).map((i) => iconoInsumo(i)).join(' ')"></span>
                            <span class="chip" :class="g.faltan ? 'mal' : 'ok'" x-text="g.faltan ? '⚠ ' + g.faltan + ' por comprar' : '✓ todo bien'"></span>
                            <span class="flecha" aria-hidden="true">▾</span>
                        </button>
                        <div class="desplegable" :class="{ abierto: grupoInsumoAbierto(g) }"><div class="dentro">
            {{-- una tarjeta por insumo: su ícono, cuánto queda y un anillo que se llena (lleno = el doble del mínimo) --}}
            <div class="insumos-tarjetas">
                <template x-for="i in g.items" :key="i.id">
                    <article class="insumo-tarjeta" :class="{ bajo: i.stock < i.minimo }" :style="'--tono: ' + colorInsumo(i)">
                        {{-- el anillo lleno es el doble del mínimo; la rayita de abajo marca el mínimo --}}
                        <div class="anillo" :title="'Mínimo ' + num(i.minimo, 3) + ' ' + i.unidad">
                            <div x-html="svgNivel(i.minimo > 0 ? i.stock / (i.minimo * 2) : 1, i.stock < i.minimo)"></div>
                            <span class="icono-insumo" aria-hidden="true" x-text="iconoInsumo(i)"></span>
                        </div>
                        <strong x-text="i.nombre"></strong>
                        <div class="queda-insumo"><b x-text="num(i.stock, 3)"></b> <span x-text="i.unidad"></span></div>
                        <small x-text="'mínimo ' + num(i.minimo, 3) + ' ' + i.unidad + ' · ' + soles(i.costo) + ' por ' + i.unidad"></small>
                        <span class="chip mal" x-show="i.stock < i.minimo">⚠ Hay que comprar</span>
                        <div class="botones">
                            <button class="btn chico" @click="abrirAjuste(i)">Corregir</button>
                            <button class="btn chico gris" @click="verKardex(i.id)">Kardex</button>
                        </div>
                    </article>
                </template>
            </div>
                        </div></div>
                    </section>
                </template>
            </div>
        </section>
        </template>

        {{-- ---------------------------------------------------- KARDEX --}}
        <template x-if="dDe === 'kardex'">
        <section x-show="seccion === 'kardex' && !cargando && !error">
            <div class="cabecera no-imprimir"><div><h1>Kardex · entradas y salidas</h1></div><div class="espacio"></div>
                <button class="btn gris" @click="window.print()">🖨 Imprimir</button></div>
            <h1 class="solo-imprimir" x-text="'Kardex · ' + (d.item?.nombre || '')"></h1>
            <div class="pestanas no-imprimir" style="margin-top: 10px">
                <template x-for="t in pestanasKardex()" :key="t.tipo + t.id">
                    <button :class="{ activo: pestanaKardexActiva(t) }" @click="tipoKardex(t.tipo, t.id)" x-text="t.nombre"></button>
                </template>
            </div>
            <p class="sub" x-show="kardex.tipo === 'insumo'">Entra cuando llega una compra. Sale cuando se usa para hacer un lote. Si algo se malogró, se venció o al contar no cuadra, se corrige en <strong>Almacén</strong>.</p>
            <p class="sub" x-show="kardex.tipo === 'producto'">Entra cuando se termina un lote. Sale cuando se vende o se pierde (se venció, se malogró).</p>
            <p class="sub" x-show="kardex.tipo === 'leche'">Entra la leche que Calidad acepta cada día. Sale la que se usa para hacer cada lote o se pierde (se cortó, se derramó).</p>
            <div class="fila no-imprimir" style="align-items: flex-end">
                <div style="flex: 2; min-width: 200px" x-show="kardex.tipo === 'insumo'"><label>Insumo</label>
                    <select x-model.number="kardex.id" @change="cargar()">
                        <template x-for="o in opcionesKardex()" :key="kardex.tipo + o.id"><option :value="o.id" :selected="o.id === kardex.id" x-text="o.nombre"></option></template>
                    </select></div>
                {{-- queso o yogurt: todos juntos o uno solo (solo Queso Paria, solo Yogurt de fresa) --}}
                <div style="flex: 2; min-width: 200px" x-show="kardex.tipo === 'producto' && productosDelKardex().length > 1"><label for="kardex-producto">Producto</label>
                    <select id="kardex-producto" @change="elegirProductoKardex($event.target.value)">
                        <option value="todos" :selected="!kardex.solo" x-text="'Todos · ' + (gruposKardex().find((g) => g.ids.includes(kardex.id))?.nombre || '').toLowerCase()"></option>
                        <template x-for="p in productosDelKardex()" :key="p.id"><option :value="p.id" :selected="kardex.solo && p.id === kardex.id" x-text="p.nombre"></option></template>
                    </select></div>
                <div style="flex: 1; min-width: 140px"><label for="kardex-desde">Desde</label>
                    <input id="kardex-desde" type="date" min="2020-01-01" :max="hoy()" :value="kardex.desde" @change="fechaKardex('desde', $event.target.value)"></div>
                <div style="flex: 1; min-width: 140px"><label for="kardex-hasta">Hasta</label>
                    <input id="kardex-hasta" type="date" min="2020-01-01" :max="hoy()" :value="kardex.hasta" @change="fechaKardex('hasta', $event.target.value)"></div>
                {{-- atajos de fechas: sin fechas es todo el historial (desde lo que ya había al empezar) --}}
                <div class="pestanas rango-rapido" style="margin: 0">
                    <template x-for="r in [['todo', 'Todo'], ['mes', 'Este mes'], ['pasado', 'Mes pasado']]">
                        <button :class="{ activo: rangoKardexActivo() === r[0] }" @click="rangoKardex(r[0])" x-text="r[1]"></button>
                    </template>
                </div>
            </div>
            {{-- tres cifras quietas: lo que entró, lo que salió y lo que queda --}}
            <div class="cifras-kardex" x-show="d.item">
                <div><span>Entró</span><b class="ok" x-text="'+ ' + num(d.totales?.entradas.cantidad, 3) + ' ' + (d.item?.unidad || '')"></b>
                    <small x-show="kardexContable" x-text="soles(d.totales?.entradas.total)"></small></div>
                <div><span>Salió</span><b class="sale" x-text="'− ' + num(d.totales?.salidas.cantidad, 3) + ' ' + (d.item?.unidad || '')"></b>
                    <small x-show="kardexContable" x-text="soles(d.totales?.salidas.total)"></small></div>
                <div class="queda"><span>Queda</span><b :class="d.item?.stock < d.item?.minimo ? 'mal' : ''" x-text="num(d.totales?.saldo.cantidad, 3) + ' ' + (d.item?.unidad || '')"></b>
                    <small x-text="(kardexContable ? 'vale ' + soles(d.totales?.saldo.total) : '') + (d.item?.minimo ? (kardexContable ? ' · ' : '') + 'mínimo ' + num(d.item.minimo, 3) : '')"></small></div>
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
            {{-- vista sencilla: una tabla limpia, se lee de arriba hacia abajo --}}
            <table class="lista kardex-claro" style="margin-top: 12px" x-show="!kardexContable">
                <tr><th>Fecha</th><th>Qué pasó</th><th class="num">Entró</th><th class="num">Salió</th><th class="num">Queda</th></tr>
                <template x-for="(m, k) in dDe === 'kardex' ? (d.filas || []) : []" :key="k">
                    <tr :class="m.tipo === 'ANTERIOR' || m.tipo === 'INICIAL' ? 'tenue' : ''">
                        <td class="fecha-k"><span x-text="fechaCorta(m.fecha)"></span><small x-text="hora(m.fecha)"></small></td>
                        <td><strong class="que-kardex" x-show="m.que" x-text="m.que"></strong>
                            <span :class="m.tipo === 'MERMA' ? 'mal' : ''" x-text="m.texto"></span>
                            <small class="quien" x-show="m.usuario" x-text="'Registró: ' + m.usuario"></small></td>
                        <td class="num ok" x-text="m.entrada ? '+ ' + num(m.entrada.cantidad, 3) : ''"></td>
                        <td class="num sale" x-text="m.salida ? '− ' + num(m.salida.cantidad, 3) : ''"></td>
                        <td class="num queda" :class="m.saldo.cantidad < 0 ? 'mal' : ''"><b x-text="num(m.saldo.cantidad, 3)"></b> <small x-text="d.item.unidad"></small></td>
                    </tr>
                </template>
                <tr x-show="!(d.filas || []).length"><td colspan="5">No hay movimientos en estas fechas.</td></tr>
            </table>

            {{-- vista contable (valorizada por costo promedio ponderado) --}}
            <table class="lista kardex" style="margin-top: 12px" x-show="kardexContable">
                <tr><th rowspan="2">Fecha</th><th rowspan="2">Movimiento</th><th rowspan="2">Detalle</th>
                    <th colspan="3" class="grupo-col">Entrada</th><th colspan="3" class="grupo-col">Salida</th><th colspan="3" class="grupo-col">Saldo</th></tr>
                <tr><th class="num">Cant.</th><th class="num">C. unit.</th><th class="num">Total</th>
                    <th class="num">Cant.</th><th class="num">C. unit.</th><th class="num">Total</th>
                    <th class="num">Cant.</th><th class="num">C. unit.</th><th class="num">Total</th></tr>
                <template x-for="(m, k) in dDe === 'kardex' ? (d.filas || []) : []" :key="k"><tr :class="m.tipo === 'ANTERIOR' || m.tipo === 'INICIAL' ? 'tenue' : ''">
                    <td x-text="fechaCorta(m.fecha) + ' ' + hora(m.fecha)"></td>
                    <td><span class="chip" :class="['COMPRA', 'ACOPIO'].includes(m.tipo) || (m.tipo === 'PRODUCCION' && m.entrada) ? 'ok' : (m.tipo === 'AJUSTE' ? 'ojo' : (m.tipo === 'MERMA' ? 'mal' : ''))" x-text="TIPO_KARDEX[m.tipo]"></span></td>
                    <td x-text="(m.que ? m.que + ' · ' : '') + m.detalle + (m.usuario ? ' · ' + m.usuario : '')"></td>
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
        </template>

        {{-- ---------------------------------------------------- PÉRDIDAS --}}
        <template x-if="dDe === 'mermas'">
        <section x-show="seccion === 'mermas' && !cargando && !error">
            <div class="cabecera">
                <div><h1>Pérdidas</h1>
                    <p class="sub" style="margin: 0">Lo que se pierde (leche, queso, yogurt o insumos) sale del stock, va al kardex y cuenta en el costo.</p></div>
                <div class="espacio"></div>
                <button class="btn rojo" @click="abrirMerma('PRODUCTO')">🗑️ Registrar pérdida</button>
            </div>
            {{-- lo perdido este mes en soles, por tipo --}}
            <div class="perdidas-mes">
                <div class="pm-total">
                    <span class="eti">Perdido este mes</span>
                    <b x-text="soles(d.mes?.valor || 0)"></b>
                    <small x-text="(d.mes?.veces || 0) + ((d.mes?.veces || 0) === 1 ? ' pérdida' : ' pérdidas')"></small>
                    <em class="cambio-mes" x-show="d.mes?.anterior" :class="(d.mes?.valor || 0) > (d.mes?.anterior || 0) ? 'sube' : 'baja'"
                        x-text="((d.mes?.valor || 0) > (d.mes?.anterior || 0) ? '▲ ' : '▼ ') + 'el mes pasado ' + soles(d.mes?.anterior || 0)"></em>
                </div>
                <template x-for="t in [['LECHE', '🥛', 'Leche'], ['PRODUCTO', '🧀', 'Quesos y yogures'], ['INSUMO', '🧂', 'Insumos']]" :key="t[0]">
                    <div class="pm-tipo" :class="{ cero: !d.mes?.por_tipo?.[t[0]]?.veces }">
                        <span aria-hidden="true" x-text="t[1]"></span>
                        <div><small x-text="t[2]"></small><b x-text="soles(d.mes?.por_tipo?.[t[0]]?.valor || 0)"></b>
                            <small x-text="(d.mes?.por_tipo?.[t[0]]?.veces || 0) + ' veces'"></small></div>
                    </div>
                </template>
            </div>
            {{-- se repite: algo pasa con cómo se guarda --}}
            <template x-for="r in d.repetidas || []" :key="r.tipo + r.que">
                <div class="aviso" style="margin-bottom: 10px" x-text="'⚠ ' + r.que + ' se perdió ' + r.veces + ' veces en 30 días (' + soles(r.valor) + '). Revisa cómo se guarda o se maneja.'"></div>
            </template>
            <div class="pm-hay">
                <span>🥛 <b x-text="num(d.leche?.sin_usar, 0) + ' L'"></b> de leche sin procesar</span>
                <template x-for="p in (d.productos || []).filter((p) => p.stock > 0)" :key="p.id">
                    <span :style="'--tono: ' + colorReceta(productoVisual(p.nombre))"><i class="punto"></i><b x-text="num(p.stock, 2) + ' ' + p.unidad"></b> <span x-text="p.nombre"></span></span>
                </template>
            </div>
            {{-- leche de días anteriores que nunca se procesó: ya no está en el tanque, pero tampoco se registró como pérdida --}}
            <p class="pm-nota" x-show="d.leche?.vieja > 0.5" style="margin: -4px 0 10px"
               x-text="'ℹ Además hay ' + num(d.leche?.vieja, 0) + ' L de leche de días anteriores que nunca entró a un lote ni se registró como pérdida (no cuenta en el tanque).'"></p>
            <template x-for="p in (d.productos || []).filter((p) => p.por_entregar > 0)" :key="'pe' + p.id">
                <p class="pm-nota" style="margin: -4px 0 10px"
                   x-text="'ℹ ' + p.nombre + ': se vendieron ' + num(p.por_entregar, 2) + ' ' + p.unidad + ' sin stock; salen del próximo lote.'"></p>
            </template>
            <h2>Últimas pérdidas</h2>
            <div class="movimientos">
                <template x-for="m in d.mermas || []" :key="m.id"><div class="movimiento perdida">
                    <span class="estado-orden" aria-hidden="true" x-text="iconoPerdida(m)"></span>
                    <div class="que"><strong x-text="m.que + (m.lote ? ' · lote ' + m.lote : '')"></strong>
                        <small x-text="m.motivo_texto + (m.detalle ? ' · ' + m.detalle : '') + ' · ' + fechaCorta(m.fecha) + ' ' + hora(m.fecha) + (m.usuario ? ' · ' + m.usuario : '')"></small></div>
                    <button class="btn chico gris" x-show="m.tiene_foto" @click="verFotoDe('perdida', m.id)">🖼 Foto</button>
                    <div class="monto-perdida"><b class="mal" x-text="'− ' + num(m.cantidad, 3) + ' ' + m.unidad_corta"></b>
                        <small x-text="m.valor != null ? soles(m.valor) : ''"></small></div>
                </div></template>
                <div class="vacio-lista" x-show="!(d.mermas || []).length"><span aria-hidden="true">✓</span><p>Todavía no se registró ninguna pérdida.</p></div>
            </div>
        </section>
        </template>

        {{-- ---------------------------------------------------- GASTOS DE PLANTA --}}
        <template x-if="dDe === 'gastos'">
        <section x-show="seccion === 'gastos' && !cargando && !error">
            <div class="cabecera compras-cabecera">
                <div><h1>Gastos de planta</h1>
                    <p class="sub" style="margin: 0">Lo que no es insumo pero cuesta: reparaciones, mantenimiento, combustible, luz, agua, gas.</p></div>
                <div class="espacio"></div>
                <button class="btn gris" @click="panelEquipos = true" x-text="'⚙️ Equipos · ' + (d.equipos || []).filter((e) => e.activo).length"></button>
                <button class="btn" @click="abrirGasto()">+ Nuevo gasto</button>
            </div>
            {{-- mantenimientos que tocan o se pasaron --}}
            <template x-for="e in equiposConAviso()" :key="e.id">
                <div class="aviso-vence" style="margin-bottom: 10px">
                    <span class="av-ico" aria-hidden="true">🧽</span>
                    <div class="av-texto"><strong x-text="e.nombre + ': ' + textoMantenimiento(e.mantenimiento)"></strong>
                        <small x-text="'Cada ' + e.mantenimiento_dias + ' días' + (e.mantenimiento.desde_registro ? ' · todavía no se le anotó ninguno' : ' · el último: ' + fechaCorta(e.ultimo?.fecha))"></small></div>
                    <button class="btn chico" @click="abrirGasto(e.id, 'MANTENIMIENTO')">Anotar mantenimiento</button>
                </div>
            </template>
            <div class="compras-resumen gastos-resumen">
                <div class="cr-cifras">
                    <div class="cr-cifra">
                        <span class="cr-ico" aria-hidden="true">🧾</span>
                        <div><b x-text="soles(d.periodo?.gastado)"></b>
                            <span x-text="(d.periodo?.mes === 'todo' ? 'gastado en total' : 'gastado en ' + nombreMes(d.periodo?.mes).toLowerCase().split(' ')[0]) + ' · ' + (d.gastos || []).length + ' gastos'"></span>
                            <em class="cambio-mes" x-show="d.periodo?.anterior_gastado" :class="d.periodo?.gastado > d.periodo?.anterior_gastado ? 'sube' : 'baja'"
                                x-text="(d.periodo?.gastado > d.periodo?.anterior_gastado ? '▲ ' : '▼ ') + 'el mes pasado ' + soles(d.periodo?.anterior_gastado)"></em></div>
                    </div>
                    <div class="cr-cifra"><span class="cr-ico" aria-hidden="true">🔧</span>
                        <div><b x-text="(d.gastos || []).filter((g) => g.categoria === 'REPARACION').length"></b><span>reparaciones este mes</span></div></div>
                </div>
                <div class="cr-gasto" x-show="(d.periodo?.por_categoria || []).length">
                    <div class="eti">¿En qué se gastó?</div>
                    <div class="gasto-fila">
                        <div class="dona" x-html="svgDona(partesGastoPlanta().map((p) => ({ valor: p.monto, color: p.color, nombre: p.texto })), num(d.periodo?.gastado, 0), 'soles')"></div>
                        <ul class="leyenda-gasto">
                            <template x-for="p in partesGastoPlanta()" :key="p.categoria">
                                <li :title="p.texto + ': ' + soles(p.monto)"><i :style="'background:' + p.color"></i><span class="nom" x-text="p.icono + ' ' + p.texto"></span>
                                    <b x-text="soles(p.monto)"></b></li>
                            </template>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="filtros-compras">
                <div class="espacio"></div>
                <template x-if="d.periodo?.mes === 'todo'"><div class="semana-nav">
                    <span class="chip ok">Viendo todo</span>
                    <button class="btn chico gris" @click="cambiar('mesGastos', 'actual')">Ver por mes</button></div></template>
                <template x-if="d.periodo && d.periodo.mes !== 'todo'"><div class="semana-nav">
                    <button class="btn chico gris" @click="cambiar('mesGastos', d.periodo.anterior)" title="Mes anterior" aria-label="Mes anterior">◀</button>
                    <span class="btn chico gris" style="min-width: 118px; text-align: center; cursor: default" x-text="nombreMes(d.periodo?.mes)"></span>
                    <button class="btn chico gris" @click="cambiar('mesGastos', d.periodo.siguiente)" :disabled="!d.periodo?.siguiente" title="Mes siguiente" aria-label="Mes siguiente">▶</button>
                    <button class="btn chico gris" @click="cambiar('mesGastos', 'todo')">Todo</button></div></template>
            </div>
            <div class="ordenes">
                <template x-for="g in d.gastos || []" :key="g.id">
                    <article class="orden gasto" :class="'cat-' + g.categoria.toLowerCase()">
                        <span class="iconos-orden" aria-hidden="true"><i x-text="CATEGORIA_GASTO[g.categoria]"></i></span>
                        <div class="que">
                            <div class="que-linea"><strong x-text="g.categoria_texto + (g.equipo ? ' · ' + g.equipo : '')"></strong>
                                <small x-text="fechaCorta(g.fecha) + (g.usuario ? ' · ' + g.usuario : '')"></small></div>
                            <small style="display: block; font-size: 13.5px; color: var(--texto-2); margin-top: 2px" x-show="g.detalle" x-text="g.detalle"></small>
                        </div>
                        <div class="monto-orden"><b x-text="soles(g.monto)"></b></div>
                        <div class="acciones-fila">
                            <button class="btn chico gris" x-show="g.tiene_foto" @click="verFotoDe('gasto', g.id)">🖼 Foto</button>
                            <button class="btn chico gris" @click="borrarGasto(g)" title="Borrar (si se anotó mal)">🗑️</button>
                        </div>
                    </article>
                </template>
                <div class="vacio-lista" x-show="!(d.gastos || []).length"><span aria-hidden="true">🔧</span>
                    <p x-text="d.periodo?.mes === 'todo' ? 'Todavía no hay gastos anotados.' : 'No hay gastos en ' + nombreMes(d.periodo?.mes).toLowerCase() + '. Anota el primero con + Nuevo gasto.'"></p></div>
            </div>

            {{-- equipos: panel al costado, con lo gastado en el año y su mantenimiento --}}
            <div class="panel-lado-velo" x-show="panelEquipos" x-transition.opacity @click="panelEquipos = false"></div>
            <aside class="panel-lado" :class="{ abierto: panelEquipos }" @keydown.escape.window="!ventana && !$event._ventana && (panelEquipos = false)" aria-label="Equipos">
                <header class="panel-lado-cabeza">
                    <span class="ico-plegable" aria-hidden="true">⚙️</span>
                    <div><strong>Equipos de la planta</strong>
                        <small>A cada uno se le anotan sus reparaciones. Si le pones cada cuántos días le toca mantenimiento, te avisa.</small></div>
                    <button class="cerrar-panel" @click="panelEquipos = false" aria-label="Cerrar">✕</button>
                </header>
                <div class="panel-lado-herramientas"><div class="espacio" style="flex: 1"></div><button class="btn chico" @click="abrirEquipo()">+ Agregar equipo</button></div>
                <div class="panel-lado-lista">
                    <template x-for="e in d.equipos || []" :key="e.id">
                        <article class="vendedor-tarjeta" :class="{ apagado: !e.activo }">
                            <div class="vt-cabeza">
                                <span class="inicial" x-text="e.tipo === 'VEHICULO' ? '🚚' : '⚙️'"></span>
                                <div class="vt-que"><strong x-text="e.nombre"></strong>
                                    <small x-text="e.mantenimiento ? textoMantenimiento(e.mantenimiento) : 'sin aviso de mantenimiento'" :class="{ mal: e.avisar }"></small>
                                    <small x-show="e.ultimo" x-text="'Lo último: ' + (e.ultimo?.detalle || '') + ' · ' + fechaCorta(e.ultimo?.fecha)"></small></div>
                                <span class="chip mal" x-show="!e.activo">ya no se usa</span>
                            </div>
                            <div class="vt-cifras">
                                <div><b x-text="soles(e.gastado_anio)"></b><span>en el año</span></div>
                                <div><b x-text="e.reparaciones_anio"></b><span x-text="e.reparaciones_anio === 1 ? 'reparación' : 'reparaciones'"></span></div>
                                <div><b x-text="e.mantenimiento_dias ? 'c/ ' + e.mantenimiento_dias + ' d' : '—'"></b><span>mantenimiento</span></div>
                            </div>
                            <div class="vt-acciones">
                                <button class="btn chico gris" @click="abrirGasto(e.id)">🔧 Anotar reparación</button>
                                <button class="btn chico gris" @click="abrirEquipo(e)">✎ Editar</button>
                            </div>
                        </article>
                    </template>
                </div>
            </aside>
        </section>
        </template>

        {{-- ---------------------------------------------------- LOTES EN PRODUCCIÓN --}}
        <template x-if="dDe === 'lotes'">
        <section x-show="seccion === 'lotes' && !cargando && !error">
            <div class="cabecera">
                <div><h1>Lotes en producción</h1>
                    <p class="sub" style="margin: 0">Cuando termines un lote, escribe cuántos salieron y ciérralo: recién ahí entra al stock.</p></div>
                <div class="espacio"></div>
                <button class="btn" @click="ir('formulas')">+ Hacer otro lote</button>
            </div>

            {{-- una fila por lote abierto: qué es, cuánto debería salir y cuánto salió; lo demás en ⓘ. Los olvidados van primero --}}
            <div class="lotes-abiertos">
                <template x-for="l in lotesAbiertos()" :key="l.id">
                    <article class="lote-prod" :class="{ olvidado: loteOlvidado(l), abierto: loteInfo[l.id] }" :style="'--tono: ' + colorReceta(productoVisual(l.producto))">
                        <div class="lf-principal">
                            <div class="mini-dibujo" x-html="dibujoReceta(productoVisual(l.producto))"></div>
                            <button class="lf-que" @click="loteInfo[l.id] = !loteInfo[l.id]" :aria-expanded="!!loteInfo[l.id]" title="Ver detalle del lote">
                                <strong x-text="l.producto"></strong>
                                <small x-show="!loteOlvidado(l)" x-text="l.codigo + ' · ' + num(l.litros_leche, 0) + ' L de leche · ' + haceCuanto(l.iniciado_en)"></small>
                                <small class="olvido" x-show="loteOlvidado(l)" x-text="'⚠ abierto ' + haceCuanto(l.iniciado_en) + ' · ¿ya salió? ciérralo'"></small>
                                <span class="lf-info" aria-hidden="true">ⓘ</span>
                            </button>
                            <div class="lf-espera" :title="'Se acepta de ' + aceptadoLote(l)"><span>debe salir</span><b x-text="'~' + esperadoCorto(l)"></b></div>
                            <div class="lf-cierre">
                                <span class="caja-unidad"><input type="number" min="0" step="1" x-model="cierre[l.id]" placeholder="salieron"
                                        :aria-label="'¿Cuántos ' + l.unidad.split(' ')[0] + ' salieron del lote ' + l.codigo + '?'" @keydown.enter="cerrarLote(l)">
                                    <i x-text="l.unidad.split(' ')[0]"></i></span>
                                <button class="btn" @click="cerrarLote(l)">Cerrar</button>
                            </div>
                        </div>
                        {{-- lo que dice el número escrito: dentro de lo esperado o no --}}
                        <div class="lf-veredicto" x-show="estadoCierre(l)" :class="estadoCierre(l)?.clase" x-text="estadoCierre(l)?.texto"></div>
                        {{-- ⓘ: el detalle para consultar --}}
                        <div class="lf-detalle" x-show="loteInfo[l.id]" x-transition.opacity>
                            <span><b>Lote</b> <span x-text="l.codigo"></span></span>
                            <span><b>Leche</b> <span x-text="num(l.litros_leche, 0) + ' L'"></span></span>
                            <span><b>Empezó</b> <span x-text="fechaCorta(l.iniciado_en) + ' ' + hora(l.iniciado_en)"></span></span>
                            <span><b>Vence</b> <span x-text="fechaCorta(l.vence_el)"></span></span>
                            <span><b>Se acepta</b> <span x-text="aceptadoLote(l)"></span></span>
                            <span class="ancho" x-show="(l.insumos || []).length"><b>Se usó</b> <span x-text="l.insumos.map((i) => i.nombre.toLowerCase() + ' ' + num(i.cantidad, 3) + ' ' + i.unidad).join(' · ')"></span></span>
                        </div>
                    </article>
                </template>
                <div class="aviso bien" x-show="!lotesAbiertos().length">No hay lotes en proceso. Para hacer uno, toca <strong>+ Hacer otro lote</strong>.</div>
            </div>

            {{-- los terminados, cerrados: una línea de resumen que se abre al tocarla --}}
            <div class="terminados" :class="{ abierto: terminadosAbiertos }" x-show="lotesTerminados().length">
                <button class="terminados-cabeza" @click="terminadosAbiertos = !terminadosAbiertos" :aria-expanded="terminadosAbiertos">
                    <span class="gc-flecha" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg></span>
                    <strong x-text="'✓ ' + lotesTerminados().length + ' lotes terminados hace poco'"></strong>
                    <span class="t-bien" x-text="lotesTerminados().filter((l) => l.fuera_referencia != 1).length + ' salieron bien'"></span>
                    <span class="t-mal" x-show="lotesTerminados().some((l) => l.fuera_referencia == 1)"
                          x-text="lotesTerminados().filter((l) => l.fuera_referencia == 1).length + ' fuera de lo esperado'"></span>
                </button>
                <div class="terminados-lista" x-show="terminadosAbiertos">
                    <template x-for="l in lotesTerminados()" :key="l.id">
                        <div class="terminado" :class="{ fuera: l.fuera_referencia == 1 }" :style="'--tono: ' + colorReceta(productoVisual(l.producto))">
                            <i class="punto"></i>
                            <strong x-text="l.producto"></strong>
                            <small x-text="l.codigo + ' · ' + fechaCorta(l.iniciado_en)"></small>
                            <span class="t-salio" x-text="num(l.obtenido, 2) + ' ' + l.unidad.split(' ')[0]"></span>
                            <b :class="l.fuera_referencia == 1 ? 'mal' : 'ok'" :title="'Se esperaba ' + esperadoLote(l)"
                               x-text="(l.fuera_referencia == 1 ? '⚠ ' : '✓ ') + (l.desvio_pct > 0 ? '+' : '') + num(l.desvio_pct, 1) + ' %'"></b>
                        </div>
                    </template>
                </div>
            </div>
        </section>
        </template>

        {{-- ---------------------------------------------------- FÓRMULAS --}}
        {{-- arriba: una sola forma de producir (uno o varios); abajo: las recetas, compactas, se abren al tocar --}}
        <template x-if="dDe === 'formulas'">
        <section x-show="seccion === 'formulas' && !cargando && !error">
            <h1>Producir</h1>
            <p class="sub">Planifica lo de hoy con la leche del tanque: el sistema revisa solo que alcancen la leche y los insumos.</p>
            {{-- aquí solo lo que impide producir; lo que está bajo el mínimo se ve en Almacén --}}
            <template x-for="a in (d.avisos || []).filter((a) => a.nivel === 'FALTA')" :key="a.nivel + a.insumo_id + a.producto">
                <div class="aviso error">
                    <span x-text="a.texto"></span>
                    <span x-text="' Comprar al menos ' + num(a.comprar, 3) + ' ' + a.unidad + '.'"></span>
                </div>
            </template>
            {{-- planificador: a la izquierda los productos (− / +), a la derecha el tanque y lo que se usa --}}
            <div class="producir">
                <div class="producir-productos">
                    <h2 class="titulo-producir">¿Qué vas a producir hoy?</h2>
                    <p class="sub" style="margin: 0 0 14px">Pon cuánto quieres de cada uno con <strong>−</strong> y <strong>+</strong>. En el resumen ves al toque si alcanzan la leche y los insumos.</p>
                    <template x-for="g in gruposProducir()" :key="g.tipo"><div class="grupo-producir">
                        <div class="eti" x-text="g.nombre"></div>
                        <template x-for="fa in g.familias" :key="fa.base.id">
                            <div class="familia-producir" :class="{ activa: fa.items.some((x) => Number(varios[x.id]) > 0) }" :style="'--tono: ' + colorReceta(fa.base)">
                                <div class="mini-dibujo" x-html="dibujoReceta(fa.base)"></div>
                                <div class="items">
                                    {{-- con sabores: cerrado con su flechita; al tocarlo se despliega la lista de sabores --}}
                                    <button class="cabeza-familia" x-show="fa.items.length > 1" @click="familiaAbierta[fa.base.id] = !familiaAbierta[fa.base.id]"
                                            :aria-expanded="!!familiaAbierta[fa.base.id]" :class="{ abierta: familiaAbierta[fa.base.id] }">
                                        <div class="texto">
                                            <strong x-text="fa.base.nombre.replace(/\s+natural$/i, '')"></strong>
                                            <small x-text="resumenFamilia(fa)"></small>
                                        </div>
                                        <span class="puntos"><template x-for="x in fa.items" :key="x.id"><i :style="'background: ' + colorReceta(x)" :class="{ lleno: Number(varios[x.id]) > 0 }"></i></template></span>
                                        <span class="flecha" aria-hidden="true">▾</span>
                                    </button>
                                    <div class="desplegable" :class="{ abierto: fa.items.length === 1 || familiaAbierta[fa.base.id] }"><div class="dentro">
                                    <template x-for="x in fa.items" :key="x.id">
                                        <div class="item-producir" :class="{ elegido: Number(varios[x.id]) > 0, sabor: !!x.base_id }" :style="'--tono: ' + colorReceta(x)">
                                            <div class="nombre">
                                                <i x-show="fa.items.length > 1"></i>
                                                <div>
                                                    <strong x-text="nombreEnFamilia(fa, x)"></strong>
                                                    <template x-for="k in [litrosDe(x)]" :key="k"><small class="brinca" x-text="Number(varios[x.id]) > 0 ? 'usa ' + num(k, 0) + ' L de leche' : 'rinde ' + num(x.rendimiento_min * 100, 1) + ' ' + unidadProducto(x) + ' por 100 L'"></small></template>
                                                </div>
                                            </div>
                                            <div class="control">
                                                <button class="menos" @click="sumarVarios(x, -1)" :disabled="!(Number(varios[x.id]) > 0)" :aria-label="'Menos ' + x.nombre">−</button>
                                                <input type="number" min="0" step="1" x-model="varios[x.id]" placeholder="0" :aria-label="'Cuánto ' + x.nombre">
                                                <button class="mas" @click="sumarVarios(x, 1)" :aria-label="'Más ' + x.nombre">+</button>
                                                <span class="unidad" x-text="unidadProducto(x)"></span>
                                            </div>
                                            <button class="max" x-show="maximoDe(x) > 0 && Number(varios[x.id]) !== maximoDe(x)" @click="varios[x.id] = String(maximoDe(x))"
                                                    :title="'Con la leche que queda alcanza para ' + maximoDe(x) + ' ' + unidadProducto(x)" x-text="'máx. ' + num(maximoDe(x), 0)"></button>
                                        </div>
                                    </template>
                                    </div></div>
                                </div>
                            </div>
                        </template>
                    </div></template>
                </div>

                <aside class="producir-resumen">
                    <div class="tanque">
                        <div class="cabeza"><span>🥛 Tanque de leche</span><strong x-text="num(d.tanque, 0) + ' L'"></strong></div>
                        <div class="barra-tanque" :class="{ pasa: tanqueProducir().pasa }" x-effect="animarUso(planVarios().litros)">
                            <template x-for="t in tanqueProducir().tramos" :key="t.id">
                                <i :style="'width: ' + t.ancho + '%; background-color: ' + t.color" :title="t.nombre + ': ' + num(t.litros, 0) + ' L'"></i></template>
                            {{-- la superficie de la leche: una ola que se mueve donde termina lo que se usa --}}
                            <b class="ola" x-show="tanqueProducir().tramos.length" :style="'left: ' + tanqueProducir().usado + '%'"></b>
                            <b class="exceso" x-show="tanqueProducir().pasa" :style="'left: ' + tanqueProducir().marca + '%'"></b>
                            <b class="marca" x-show="tanqueProducir().pasa" :style="'left: ' + tanqueProducir().marca + '%'"></b>
                        </div>
                        <div class="pie-tanque">
                            <span x-text="'Usas ' + num(usoAnimado, 0) + ' L'"></span>
                            <span :class="usoAnimado > d.tanque ? 'mal' : ''" x-text="usoAnimado > d.tanque ? 'Faltan ' + num(usoAnimado - d.tanque, 0) + ' L' : 'Quedan ' + num(d.tanque - usoAnimado, 0) + ' L'"></span>
                        </div>
                        <div class="leyenda-tanque" x-show="tanqueProducir().tramos.length">
                            <template x-for="t in tanqueProducir().tramos" :key="t.id"><span><i :style="'background: ' + t.color"></i><span x-text="t.nombre + ' · ' + num(t.litros, 0) + ' L'"></span></span></template>
                        </div>
                    </div>

                    <div class="usa" x-show="planVarios().elegidos.length">
                        <div class="eti">Lo que se usa</div>
                        <ul class="ingredientes">
                            {{-- la clave cambia cuando pasa a faltar: el renglón entra de nuevo y se sacude --}}
                            <template x-for="i in planVarios().insumos" :key="i.nombre + (i.necesita > i.stock + 1e-9 ? '-falta' : '')"><li :class="{ falta: i.necesita > i.stock + 1e-9 }">
                                <div class="fila-ing"><span class="nombre" x-text="i.nombre"></span><strong x-text="cantidadBonita(i.necesita, i.unidad)"></strong></div>
                                <div class="barra"><i :style="'width: ' + Math.min(100, i.necesita > 0 ? i.stock / i.necesita * 100 : 100) + '%'"></i></div>
                                <small x-text="i.necesita > i.stock + 1e-9 ? 'Falta ' + cantidadBonita(i.necesita - i.stock, i.unidad) + ' (hay ' + cantidadBonita(i.stock, i.unidad) + ')' : 'Hay ' + cantidadBonita(i.stock, i.unidad) + (i.productos.length > 1 ? ' · lo usan ' + i.productos.length + ' productos' : '')"></small>
                            </li></template>
                        </ul>
                    </div>
                    <div class="vacio-producir" x-show="!planVarios().elegidos.length">
                        <span>👈</span> Elige qué hacer y aquí ves si alcanza.
                    </div>

                    {{-- clave sí/no: al cambiar, el veredicto vuelve a entrar con rebote --}}
                    <template x-for="v in planVarios().elegidos.length ? [planVarios().ok ? 'si' : 'no'] : []" :key="v">
                        <div class="veredicto" :class="v">
                            <strong x-text="v === 'si' ? '✓ Sí se puede' : '✗ No alcanza'"></strong>
                            <span x-text="v === 'si' ? 'Vas a hacer ' + textoVarios(planVarios()) + '.' : planVarios().motivo"></span>
                        </div></template>
                    <button class="btn grande-producir" x-show="usuario?.rol === 'ADMIN'" :disabled="!planVarios().ok" @click="abrirProducirVarios()"
                            x-text="!planVarios().elegidos.length ? 'Iniciar lotes' : 'Iniciar ' + planVarios().elegidos.length + (planVarios().elegidos.length === 1 ? ' lote' : ' lotes')"></button>
                    <button class="btn gris chico" style="width: 100%; margin-top: 8px" x-show="planVarios().elegidos.length" @click="varios = {}">Limpiar</button>
                    <p class="sub" style="margin: 8px 0 0" x-show="planVarios().ok && usuario?.rol !== 'ADMIN'">Los lotes los inicia el operario (en la app) o el administrador.</p>
                </aside>

                {{-- en el celular el resumen queda abajo: esta barra fija dice si alcanza sin tener que bajar --}}
                <div class="barra-movil" x-show="planVarios().elegidos.length" :class="planVarios().ok ? 'si' : 'no'">
                    <div><strong x-text="planVarios().ok ? '✓ Sí alcanza' : '✗ No alcanza'"></strong>
                        <small x-text="'Usas ' + num(usoAnimado, 0) + ' de ' + num(d.tanque, 0) + ' L de leche'"></small></div>
                    <button class="btn chico" x-show="usuario?.rol === 'ADMIN'" :disabled="!planVarios().ok" @click="abrirProducirVarios()"
                            x-text="'Iniciar ' + planVarios().elegidos.length + (planVarios().elegidos.length === 1 ? ' lote' : ' lotes')"></button>
                </div>
            </div>

            {{-- recetario: una tarjeta por receta (con su dibujo) y la ficha completa al tocarla --}}
            <div class="fila recetario-cabeza no-imprimir">
                <div><h2 style="margin: 0">Recetario</h2>
                    <p class="sub" style="margin: 2px 0 0">Toca una receta para ver cuánto lleva, la curva de temperatura y cada paso.</p></div>
                <div class="espacio"></div>
                <div class="filtro-recetas" role="tablist">
                    <template x-for="t in [['TODOS', 'Todas'], ['QUESO', '🧀 Quesos'], ['YOGURT', '🥛 Yogurt']]" :key="t[0]">
                        <button :class="{ activo: recetaFiltro === t[0] }" @click="recetaFiltro = t[0]" x-text="t[1]"></button></template>
                </div>
                <button class="btn chico gris" x-show="usuario?.rol === 'ADMIN'" @click="ir('config-productos')">+ Producto nuevo</button>
            </div>

            <div class="recetario no-imprimir">
                <template x-for="g in recetasBase()" :key="g.p.id">
                    <button class="receta-card" :class="{ abierta: recetaActual() && familiaReceta(recetaActual())[0].id === g.p.id }"
                            :style="'--tono: ' + colorReceta(g.p)" @click="verReceta(g.p)">
                        <div class="dibujo" x-html="dibujoReceta(g.p)"></div>
                        <div class="cuerpo">
                            <span class="tipo" x-text="{ QUESO: 'Queso', YOGURT: 'Yogurt' }[g.p.tipo] || 'Producto'"></span>
                            <strong x-text="g.p.nombre"></strong>
                            <div class="datos">
                                <span><b x-text="salenReceta(g.p, 100)"></b>de 100 L</span>
                                <span><b x-text="g.p.vida_util_dias + ' días'"></b>dura</span>
                                <span><b x-text="g.p.procedimiento.length"></b>pasos</span>
                            </div>
                            <div class="sabores-mini" x-show="g.sabores.length">
                                <template x-for="x in g.sabores" :key="x.id"><i :style="'background: ' + colorReceta(x)" :title="x.variante"></i></template>
                                <span x-text="g.sabores.length + (g.p.tipo === 'YOGURT' ? ' sabores' : ' variedades')"></span>
                            </div>
                            <span class="estado" :class="!g.p.insumos.length ? 'ojo' : (g.p.alcanza_litros === null || g.p.alcanza_litros >= 100 ? 'ok' : 'mal')"
                                  x-text="!g.p.insumos.length ? 'Sin fórmula todavía' : (g.p.alcanza_litros === null || g.p.alcanza_litros >= 100 ? '✓ Hay insumos para ' + (g.p.alcanza_litros === null ? 'producir' : num(g.p.alcanza_litros, 0) + ' L') : 'Falta insumo: alcanza para ' + num(g.p.alcanza_litros, 0) + ' L')"></span>
                        </div>
                    </button>
                </template>
                <div class="apoyo" x-show="!recetasBase().length">No hay recetas de este tipo.</div>
            </div>

            {{-- ficha de la receta --}}
            <template x-if="recetaActual()"><article class="ficha-receta" id="ficha-receta" :style="'--tono: ' + colorReceta(recetaActual())"
                     x-data="{ get p() { return recetaActual(); } }">
                <header class="ficha-hero">
                    <div class="dibujo" x-html="dibujoReceta(p)"></div>
                    <div class="titulo">
                        <span class="tipo" x-text="({ QUESO: 'Queso', YOGURT: 'Yogurt' }[p.tipo] || 'Producto') + (p.base_id ? ' · sabor de ' + p.base : '')"></span>
                        <h3 x-text="p.nombre"></h3>
                        <p x-show="p.fuente" class="fuente">Fuente:
                            <template x-if="p.fuente?.url"><a :href="p.fuente.url" target="_blank" rel="noopener" x-text="p.fuente.texto"></a></template>
                            <template x-if="!p.fuente?.url"><span x-text="p.fuente?.texto"></span></template></p>
                        <div class="familia" x-show="familiaReceta(p).length > 1">
                            <template x-for="x in familiaReceta(p)" :key="x.id">
                                <button :class="{ activo: x.id === p.id }" @click="recetaAbierta = x.id">
                                    <i :style="'background: ' + colorReceta(x)"></i><span x-text="x.base_id ? x.variante : 'Natural'"></span></button></template>
                        </div>
                    </div>
                    <div class="acciones no-imprimir">
                        <button class="btn chico gris" @click="imprimirReceta()">🖨 Imprimir</button>
                        <button class="btn chico gris" x-show="usuario?.rol === 'ADMIN' && !p.base_id" @click="abrirSabor(p)" x-text="p.tipo === 'YOGURT' ? '+ Sabor' : '+ Variedad'"></button>
                        <button class="btn chico" x-show="usuario?.rol === 'ADMIN' && !p.base_id" @click="abrirFormula(p)">Editar fórmula</button>
                        <button class="btn chico" x-show="usuario?.rol === 'ADMIN' && p.base_id" @click="abrirSabor(null, p)" x-text="p.tipo === 'YOGURT' ? 'Editar sabor' : 'Editar variedad'"></button>
                        <button class="btn chico gris cerrar" @click="recetaAbierta = null" aria-label="Cerrar receta">✕</button>
                    </div>
                </header>

                <div class="ficha-kpis">
                    <div><span>Rinde (100 L)</span><strong x-text="salenReceta(p, 100)"></strong></div>
                    <div><span>Dura</span><strong x-text="p.vida_util_dias + ' días'"></strong></div>
                    <div><span>Trabajo en la tina</span><strong x-text="tiempoReceta(p).texto"></strong>
                        <small x-show="tiempoReceta(p).largo.length" x-text="'+ esperar ' + tiempoReceta(p).largo.join(' · ')"></small></div>
                    <div><span>Pasos</span><strong x-text="p.procedimiento.length"></strong></div>
                </div>

                <div class="ficha-cuerpo">
                    <section class="ficha-insumos">
                        <h4>Para cuántos litros</h4>
                        <div class="litros">
                            <button class="btn gris chico" @click="recetaLitros = Math.max(10, (Number(recetaLitros) || 0) - 10)" aria-label="10 litros menos">−</button>
                            <input type="number" min="1" step="10" x-model.number="recetaLitros" aria-label="Litros de leche">
                            <span>L de leche</span>
                            <button class="btn gris chico" @click="recetaLitros = (Number(recetaLitros) || 0) + 10" aria-label="10 litros más">+</button>
                        </div>
                        <div class="chips-litros">
                            <template x-for="l in [50, 100, 200, 500]" :key="l"><button :class="{ activo: recetaLitros == l }" @click="recetaLitros = l" x-text="l + ' L'"></button></template>
                            <button x-show="d.tanque > 0" :class="{ activo: recetaLitros == Math.floor(d.tanque) }" @click="recetaLitros = Math.floor(d.tanque)" x-text="'Tanque (' + num(d.tanque, 0) + ' L)'"></button>
                        </div>
                        <div class="salen">Salen <strong x-text="salenReceta(p, Number(recetaLitros) || 0)"></strong></div>

                        <h4>Lleva</h4>
                        <ul class="ingredientes">
                            <template x-for="i in insumosReceta(p, Number(recetaLitros) || 0)" :key="i.id"><li :class="{ falta: !i.alcanza }">
                                <div class="fila-ing">
                                    <span class="nombre"><span x-text="i.nombre"></span>
                                        <em x-show="i.propio" x-text="p.tipo === 'YOGURT' ? 'del sabor' : 'de la variedad'"></em></span>
                                    <strong x-text="cantidadBonita(i.necesita, i.unidad)"></strong>
                                </div>
                                <div class="barra"><i :style="'width: ' + (i.cubre * 100) + '%'"></i></div>
                                <small x-text="i.alcanza ? 'Hay ' + cantidadBonita(i.stock, i.unidad) + ' en almacén' : 'Falta ' + cantidadBonita(i.necesita - i.stock, i.unidad) + ' (hay ' + cantidadBonita(i.stock, i.unidad) + ')'"></small>
                            </li></template>
                        </ul>
                        <p class="apoyo" x-show="!p.insumos.length">Esta receta todavía no tiene insumos.
                            <a href="#" x-show="usuario?.rol === 'ADMIN'" @click.prevent="abrirFormula(p)">Ponerle su fórmula</a></p>
                    </section>

                    <section class="ficha-proceso">
                        <template x-if="curvaReceta(p)"><div class="curva">
                            <h4>Curva de temperatura</h4>
                            <div x-html="svgCurva(p)"></div>
                        </div></template>

                        <h4>Cómo se hace</h4>
                        <ol class="linea-tiempo">
                            <template x-for="paso in pasosReceta(p)" :key="paso.n"><li>
                                <span class="punto" x-text="paso.icono"></span>
                                <div>
                                    <span class="num-paso" x-text="'Paso ' + paso.n"></span>
                                    <p x-text="paso.texto"></p>
                                    <div class="etiquetas">
                                        <template x-for="t in paso.temps" :key="t.txt"><span class="temp" :class="t.valor >= 60 ? 'caliente' : t.valor >= 30 ? 'tibio' : 'frio'" x-text="'🌡 ' + t.txt"></span></template>
                                        <template x-for="t in paso.tiempos" :key="t.txt"><span class="tiempo" x-text="'⏱ ' + t.txt"></span></template>
                                    </div>
                                </div>
                            </li></template>
                        </ol>
                        <p class="apoyo" x-show="!p.procedimiento.length">Sin pasos registrados.</p>
                    </section>
                </div>
            </article></template>
        </section>
        </template>

        {{-- ---------------------------------------------------- ÓRDENES --}}
        <template x-if="dDe === 'ordenes'">
        <section x-show="seccion === 'ordenes' && !cargando && !error">
            {{-- arriba en una línea: título, mes y botones --}}
            <div class="cabecera compras-cabecera">
                <div><h1>Compras de insumos</h1>
                    <p class="sub" style="margin: 0">¿Ya lo compraste? Anota lo pagado y la foto. ¿Lo pediste? Toca <strong>Llegó</strong> cuando llegue.</p></div>
                <div class="espacio"></div>
                <button class="btn gris" @click="panelVendedores = true" :title="'Vendedores: a quién le compramos'"
                        x-text="'🏪 Vendedores · ' + (d.todosVendedores || []).filter(v => v.activo).length"></button>
                <button class="btn" @click="abrirCompra()">+ Nueva compra</button>
            </div>

            {{-- el resumen en una sola fila: cifras del mes · ¿qué comprar? · en qué se gastó --}}
            <div class="compras-resumen">
                <div class="cr-cifras" x-show="d.periodo">
                    <div class="cr-cifra">
                        <span class="cr-ico" aria-hidden="true">🧾</span>
                        <div><b x-text="soles(d.periodo?.gastado)"></b>
                            <span x-text="(d.periodo?.mes === 'todo' ? 'gastado en total' : 'gastado en ' + nombreMes(d.periodo?.mes).toLowerCase().split(' ')[0]) + ' · ' + (d.periodo?.compras || 0) + ' compras'"></span>
                            <em class="cambio-mes" x-show="d.periodo?.anterior_gastado" :class="d.periodo?.gastado > d.periodo?.anterior_gastado ? 'sube' : 'baja'"
                                x-text="(d.periodo?.gastado > d.periodo?.anterior_gastado ? '▲ ' : '▼ ') + num(Math.abs(d.periodo?.gastado - d.periodo?.anterior_gastado) / (d.periodo?.anterior_gastado || 1) * 100, 0) + ' % vs. ' + nombreMes(d.periodo?.anterior).toLowerCase().split(' ')[0]"
                                :title="'El mes anterior: ' + soles(d.periodo?.anterior_gastado)"></em></div>
                    </div>
                    <button class="cr-cifra" :class="{ ojo: (d.ordenes || []).some(o => o.estado === 'EMITIDA'), activo: compraFiltro.estado === 'llegar' }"
                            @click="compraFiltro.estado = compraFiltro.estado === 'llegar' ? 'todas' : 'llegar'">
                        <span class="cr-ico" aria-hidden="true">🕒</span>
                        <div><b x-text="(d.ordenes || []).filter(o => o.estado === 'EMITIDA').length"></b><span>por llegar</span></div>
                    </button>
                    <button class="cr-cifra" :class="{ ojo: sinFoto().length, activo: compraFiltro.estado === 'foto' }"
                            @click="compraFiltro.estado = compraFiltro.estado === 'foto' ? 'todas' : 'foto'">
                        <span class="cr-ico" aria-hidden="true">📷</span>
                        <div><b x-text="sinFoto().length"></b><span x-text="sinFoto().length ? 'sin foto del comprobante' : 'todas con su foto'"></span></div>
                    </button>
                </div>

                {{-- ¿qué hay que comprar?: bajo su mínimo o se acaba en menos de 2 semanas (según lo que se gasta por día) --}}
                <div class="por-comprar" :class="{ 'todo-bien': !insumosPorComprar().some((x) => x.urgente) }">
                    <div class="por-comprar-cabeza">
                        <span class="pc-ico" aria-hidden="true" x-text="insumosPorComprar().some((x) => x.urgente) ? '🛒' : '✅'"></span>
                        <div><strong x-text="insumosPorComprar().some((x) => x.urgente) ? 'Hay que comprar' : 'No falta nada'"></strong>
                            <small x-text="insumosPorComprar().some((x) => x.urgente) ? 'bajo el mínimo o se acaba en menos de 2 semanas' : 'lo primero que se va a acabar:'"></small></div>
                        <button class="btn chico" x-show="(d.inv?.por_comprar || []).length > 1" @click="abrirCompra(true)"
                                x-text="'Todo (' + (d.inv?.por_comprar || []).length + ')'"></button>
                    </div>
                    <div class="pc-lista">
                        <template x-for="x in (insumosPorComprar().some((x) => x.urgente) ? insumosPorComprar().filter((x) => x.urgente) : insumosPorComprar()).slice(0, 3)" :key="x.i.id">
                            <div class="pc-item" :class="{ urgente: x.urgente }">
                                <span class="pc-insumo" aria-hidden="true" x-text="iconoInsumo(x.i)"></span>
                                <div class="pc-que"><strong x-text="x.i.nombre"></strong>
                                    <small x-text="textoAlcance(x)"></small>
                                    <div class="pc-nivel"><span :style="'width:' + Math.round(x.nivel * 100) + '%'"></span></div></div>
                                <button class="btn chico" :class="{ gris: !x.urgente }" @click="abrirCompraDe(x.i.id, x.cantidad)"
                                        :title="'Comprar ' + num(x.cantidad, 3) + ' ' + x.i.unidad" x-text="'+ ' + num(x.cantidad, 3) + ' ' + x.i.unidad"></button>
                            </div>
                        </template>
                    </div>
                    <small class="pc-mas" x-show="insumosPorComprar().filter((x) => x.urgente).length > 3"
                           x-text="'y ' + (insumosPorComprar().filter((x) => x.urgente).length - 3) + ' más: toca Todo'"></small>
                </div>

                {{-- en qué se fue la plata del mes --}}
                <div class="cr-gasto" x-show="(d.periodo?.por_insumo || []).length">
                    <div class="eti">¿En qué se gastó?</div>
                    <div class="gasto-fila">
                        <div class="dona" x-html="svgDona(partesGasto().map((p) => ({ valor: p.total, color: p.color, nombre: p.nombre })), num(d.periodo?.gastado / 1000, 1) + ' mil', 'soles')"></div>
                        <ul class="leyenda-gasto">
                            <template x-for="p in partesGasto().slice(0, 4)" :key="p.nombre">
                                <li :title="p.nombre + ': ' + soles(p.total)"><i :style="'background:' + p.color"></i><span class="nom" x-text="p.icono + ' ' + p.nombre"></span>
                                    <b x-text="num(p.total / (d.periodo?.gastado || 1) * 100, 0) + ' %'"></b></li>
                            </template>
                        </ul>
                    </div>
                </div>
            </div>

            {{-- filtros en una línea --}}
            <div class="filtros-compras">
                <div class="pestanas" style="margin: 0">
                    <template x-for="e in [['todas', 'Todas'], ['llegar', '🕒 Por llegar'], ['foto', '📷 Sin foto']]">
                        <button :class="{ activo: compraFiltro.estado === e[0] }" @click="compraFiltro.estado = e[0]" x-text="e[1]"></button>
                    </template>
                </div>
                <select x-model.number="compraFiltro.vendedor" aria-label="Filtrar por vendedor">
                    <option value="">Todos los vendedores</option>
                    <template x-for="v in vendedoresDeCompras()" :key="v.id"><option :value="v.id" x-text="v.nombre"></option></template>
                </select>
                <select x-model.number="compraFiltro.insumo" aria-label="Filtrar por insumo">
                    <option value="">Todos los insumos</option>
                    <template x-for="i in d.inv?.insumos || []" :key="i.id"><option :value="i.id" x-text="iconoInsumo(i) + ' ' + i.nombre"></option></template>
                </select>
                <div class="espacio"></div>
                <template x-if="d.periodo?.mes === 'todo'"><div class="semana-nav">
                    <span class="chip ok">Viendo todo</span>
                    <button class="btn chico gris" @click="cambiar('mesCompras', 'actual')">Ver por mes</button></div></template>
                <template x-if="d.periodo && d.periodo.mes !== 'todo'"><div class="semana-nav">
                    <button class="btn chico gris" @click="cambiar('mesCompras', d.periodo.anterior)" title="Mes anterior" aria-label="Mes anterior">◀</button>
                    <label class="btn chico gris" style="position: relative; cursor: pointer; min-width: 130px; text-align: center; margin: 0" title="Elegir otro mes">
                        <span x-text="nombreMes(d.periodo?.mes)"></span>
                        <input type="month" :max="hoy().slice(0, 7)" :value="d.periodo?.mes"
                               @change="$event.target.value && cambiar('mesCompras', $event.target.value)" @click="$event.target.showPicker?.()"
                               style="position: absolute; inset: 0; opacity: 0; cursor: pointer"></label>
                    <button class="btn chico gris" @click="cambiar('mesCompras', d.periodo.siguiente)" :disabled="!d.periodo?.siguiente" title="Mes siguiente" aria-label="Mes siguiente">▶</button>
                    <button class="btn chico gris" @click="cambiar('mesCompras', 'todo')">Todo</button></div></template>
                <button class="btn chico gris" @click="excel('/compras/excel/compras?mes=' + (d.periodo?.mes || 'actual'))" title="Descargar en Excel">⬇ Excel</button>
            </div>

            {{-- las compras por semana: las dos primeras abiertas, las demás se abren al tocarlas --}}
            <template x-for="(g, k) in ordenesAgrupadas()" :key="g.titulo">
                <div class="grupo-compras" :class="{ abierto: semanaAbierta(g, k) }">
                    <button class="grupo-compras-cabeza" @click="semanasAbiertas[g.titulo] = !semanaAbierta(g, k)" :aria-expanded="semanaAbierta(g, k)">
                        <span class="gc-flecha" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg></span>
                        <strong x-text="g.titulo"></strong>
                        <small x-text="g.ordenes.length + (g.ordenes.length === 1 ? ' compra' : ' compras')"></small>
                        <span class="gc-iconos" aria-hidden="true" x-show="!semanaAbierta(g, k)"
                              x-text="[...new Set(g.ordenes.flatMap((o) => o.items.map((it) => iconoInsumo({ nombre: it.insumo }))))].slice(0, 6).join(' ')"></span>
                        <span class="espacio"></span><b x-show="g.total" x-text="soles(g.total)"></b>
                    </button>
                    <div class="ordenes" x-show="semanaAbierta(g, k)">
                        <template x-for="o in g.ordenes" :key="o.id">
                            <article class="orden" :class="[o.estado === 'RECIBIDA' ? 'recibida' : (o.estado === 'EMITIDA' ? 'por-llegar' : 'anulada'), o.estado === 'RECIBIDA' && !o.tiene_foto ? 'sin-foto' : '']">
                                <span class="iconos-orden" aria-hidden="true">
                                    <i x-text="iconoInsumo({ nombre: o.items[0]?.insumo })"></i>
                                    <em x-show="o.items.length > 1" x-text="'+' + (o.items.length - 1)"></em>
                                </span>
                                <div class="que">
                                    <div class="que-linea"><strong x-text="o.proveedor"></strong><span class="codigo-orden" x-text="o.codigo"></span>
                                        <small x-text="fechaCorta(o.comprobante_fecha || o.created_at) + (o.comprobante ? ' · ' + o.comprobante : '')"
                                               :title="o.vendedor ? o.vendedor.tipo_documento + ' ' + o.vendedor.documento : 'sin DNI/RUC (orden antigua)'"></small></div>
                                    <div class="items-chips">
                                        <template x-for="it in o.items" :key="it.id">
                                            <span class="item-chip">
                                                <b x-text="num(it.cantidad, 3) + ' ' + it.unidad"></b> <span x-text="it.insumo"></span>
                                                <em x-show="precioDeItem(it)" x-text="cu(precioDeItem(it)) + '/' + it.unidad"></em>
                                                <i class="cambio-precio" x-show="cambioPrecio(it) !== null && Math.abs(cambioPrecio(it)) >= 1" :class="cambioPrecio(it) > 0 ? 'sube' : 'baja'"
                                                   :title="'La compra anterior: ' + cu(it.precio_anterior) + ' por ' + it.unidad"
                                                   x-text="(cambioPrecio(it) > 0 ? '▲ ' : '▼ ') + num(Math.abs(cambioPrecio(it)), 0) + ' %'"></i>
                                            </span>
                                        </template>
                                    </div>
                                    <small class="mal" x-show="o.anulada_motivo" x-text="'Anulada: ' + o.anulada_motivo"></small>
                                </div>
                                <div class="monto-orden"><b x-text="soles(o.total ?? o.costo_estimado)"></b>
                                    <small x-text="o.estado === 'EMITIDA' ? 'estimado · por llegar' : o.estado === 'ANULADA' ? 'anulada' : (o.igv ? 'con IGV ' + soles(o.igv) : 'sin IGV')"></small></div>
                                <div class="acciones-fila">
                                    <button class="btn chico" x-show="o.estado === 'EMITIDA'" @click="abrirRecibir(o)">Llegó</button>
                                    <button class="btn chico rojo" x-show="o.estado === 'EMITIDA'" @click="abrirAnularOrden(o)">Anular</button>
                                    <button class="btn chico gris" x-show="o.tiene_foto" @click="verFoto(o)" title="Ver la foto del comprobante">🖼 Foto</button>
                                    <label class="btn chico falta-foto" x-show="o.estado === 'RECIBIDA' && !o.tiene_foto" title="Falta la foto del comprobante: toca para agregarla">📷 + Foto
                                        <input type="file" accept="image/*" style="display:none" @change="agregarFoto(o, $event)"></label>
                                    <button class="btn chico gris" x-show="o.estado !== 'EMITIDA'" @click="volverAComprar(o)" title="Volver a comprar: abre una compra nueva con lo mismo">↻ Repetir</button>
                                </div>
                            </article>
                        </template>
                    </div>
                </div>
            </template>
            <div class="vacio-lista" x-show="!ordenesAgrupadas().length"><span aria-hidden="true">·</span>
                <p x-text="(d.ordenes || []).length ? 'Ninguna compra coincide con los filtros.' : (d.periodo?.mes === 'todo' ? 'Todavía no hay compras.' : 'No hubo compras en ' + nombreMes(d.periodo?.mes).toLowerCase() + '.')"></p></div>
            {{-- vendedores: panel al costado que se abre con el botón de arriba (no alarga la página) --}}
            <div class="panel-lado-velo" x-show="panelVendedores" x-transition.opacity @click="panelVendedores = false"></div>
            <aside class="panel-lado" :class="{ abierto: panelVendedores }" @keydown.escape.window="!ventana && !$event._ventana && (panelVendedores = false)"
                   aria-label="Vendedores" :aria-hidden="!panelVendedores">
                <header class="panel-lado-cabeza">
                    <span class="ico-plegable" aria-hidden="true">🏪</span>
                    <div><strong x-text="'Vendedores · ' + (d.todosVendedores || []).filter(v => v.activo).length"></strong>
                        <small>A quién le compramos los insumos (DNI o RUC). Los proveedores de leche están en el Padrón.</small></div>
                    <button class="cerrar-panel" @click="panelVendedores = false" aria-label="Cerrar">✕</button>
                </header>
                <div class="panel-lado-herramientas">
                    <input type="search" class="buscador" x-model="buscarVendedor" placeholder="Buscar por nombre, DNI o RUC" aria-label="Buscar vendedor">
                    <button class="btn chico" @click="abrirVendedor()">+ Nuevo</button>
                </div>
                <label class="ver-desactivados" x-show="(d.todosVendedores || []).some(v => !v.activo)">
                    <input type="checkbox" x-model="verDesactivados"> Ver desactivados</label>
                <div class="panel-lado-lista">
                    <template x-for="v in vendedoresVisibles()" :key="v.id">
                        <article class="vendedor-tarjeta" :class="{ apagado: !v.activo }">
                            <div class="vt-cabeza">
                                <span class="inicial" x-text="v.nombre.trim().charAt(0).toUpperCase()"></span>
                                <div class="vt-que"><strong x-text="v.nombre"></strong>
                                    <small x-text="v.tipo_documento + ' ' + v.documento + (v.telefono ? ' · 📞 ' + v.telefono : '')"></small>
                                    <small x-show="v.direccion" x-text="'📍 ' + v.direccion"></small></div>
                                <span class="chip mal" x-show="!v.activo">desactivado</span>
                            </div>
                            <div class="vt-cifras">
                                <div><b x-text="v.compras"></b><span x-text="v.compras === 1 ? 'compra' : 'compras'"></span></div>
                                <div><b x-text="soles(v.pagado)"></b><span>pagado</span></div>
                                <div><b x-text="v.ultima ? fechaCorta(v.ultima) : '—'"></b><span>la última</span></div>
                            </div>
                            <div class="vt-acciones">
                                <button class="btn chico gris" x-show="v.compras" @click="verComprasDe(v)">🧾 Ver sus compras</button>
                                <button class="btn chico gris" @click="abrirVendedor(v)">✎ Editar</button>
                            </div>
                        </article>
                    </template>
                    <p class="sub" x-show="(d.todosVendedores || []).length && !vendedoresVisibles().length">Ningún vendedor coincide con lo que buscas.</p>
                    <p class="sub" x-show="!(d.todosVendedores || []).length">Todavía no hay vendedores. Agrega el primero con <strong>+ Nuevo</strong>.</p>
                </div>
            </aside>
        </section>
        </template>

        {{-- ---------------------------------------------------- MOVIMIENTOS --}}
        <template x-if="dDe === 'movimientos'">
        <section x-show="seccion === 'movimientos' && !cargando && !error">
            <div class="cabecera"><div><h1>Ventas</h1>
                <p class="sub" style="margin: 0">Toca <strong>Ver nota</strong> para verla o imprimirla, o mándala por correo. (Las entradas y salidas de cada producto están en Kardex.)</p></div></div>
            <div class="barra-herramientas">
                <template x-if="d.periodo?.mes === 'todo'"><div class="semana-nav">
                    <span class="chip ok">Viendo todo</span>
                    <button class="btn chico gris" @click="cambiar('mesVentas', 'actual')">Ver por mes</button></div></template>
                <template x-if="d.periodo && d.periodo.mes !== 'todo'"><div class="semana-nav">
                    <button class="btn chico gris" @click="cambiar('mesVentas', d.periodo.anterior)" title="Mes anterior" aria-label="Mes anterior">◀</button>
                    <label class="btn chico gris" style="position: relative; cursor: pointer; min-width: 140px; text-align: center; margin: 0" title="Elegir otro mes">
                        <span x-text="nombreMes(d.periodo?.mes)"></span>
                        <input type="month" :max="hoy().slice(0, 7)" :value="d.periodo?.mes"
                               @change="$event.target.value && cambiar('mesVentas', $event.target.value)" @click="$event.target.showPicker?.()"
                               style="position: absolute; inset: 0; opacity: 0; cursor: pointer"></label>
                    <button class="btn chico gris" @click="cambiar('mesVentas', d.periodo.siguiente)" :disabled="!d.periodo?.siguiente" title="Mes siguiente" aria-label="Mes siguiente">▶</button>
                    <button class="btn chico gris" @click="cambiar('mesVentas', 'todo')">Ver todo</button></div></template>
                <div class="espacio"></div>
                <button class="btn chico gris" @click="excel('/compras/excel/ventas?mes=' + (d.periodo?.mes || 'actual'))" title="Descargar en Excel">⬇ Excel</button>
            </div>
            <div class="kpis-fila" x-show="d.periodo">
                <div class="kpi ok"><span class="kpi-ico" aria-hidden="true">🏷</span><div><b x-text="soles(d.periodo?.vendido)"></b>
                    <span x-text="d.periodo?.mes === 'todo' ? 'Vendido en total' : 'Vendido en ' + nombreMes(d.periodo?.mes).toLowerCase()"></span>
                    <small x-text="(d.periodo?.ventas || 0) + ' venta(s) · ' + notasVenta().length + ' notas'"></small></div></div>
                <div class="kpi"><span class="kpi-ico" aria-hidden="true">🧾</span><div>
                    <b x-text="notasVenta().length ? soles((d.periodo?.vendido || 0) / notasVenta().length) : '—'"></b><span>Promedio por nota</span><small>lo que lleva un cliente cada vez</small></div></div>
            </div>
            {{-- para cuadrar la caja: cuánto entró por cada forma de pago --}}
            <div class="cobrado-por" x-show="d.periodo?.por_metodo">
                <template x-for="[clave, m] in Object.entries(METODO_PAGO)" :key="clave">
                    <div class="pago-chip grande" :class="clave.toLowerCase()"><span x-text="m.icono + ' ' + m.nombre"></span><b x-text="soles(d.periodo?.por_metodo?.[clave] || 0)"></b></div>
                </template>
            </div>
            <div class="notas">
                <template x-for="n in notasVenta()" :key="n.nota">
                    <article class="nota">
                        <div class="numero-nota"><span>Nota</span><b x-text="n.numero.replace(/^NV-0*/, '')"></b></div>
                        <div class="que"><strong x-text="n.cliente"></strong><small x-text="fechaCorta(n.fecha) + ' · ' + hora(n.fecha) + ' · ' + n.numero"></small>
                            <div class="etiquetas-rechazo">
                                <template x-for="(p, k) in n.productos" :key="k"><span class="chip" :style="'--tono: ' + colorReceta(productoVisual(p.que))">
                                    <i class="punto-chip"></i><span x-text="num(p.cantidad) + ' ' + p.unidad + ' · ' + p.que"></span></span></template>
                            </div></div>
                        <div class="monto-nota"><b class="monto" x-text="soles(n.total)"></b>
                            <span class="pago-chip" :class="n.metodo.toLowerCase()" :title="n.operacion ? 'N.° de operación ' + n.operacion : ''"
                                  x-text="METODO_PAGO[n.metodo]?.icono + ' ' + (METODO_PAGO[n.metodo]?.nombre || n.metodo)"></span></div>
                        <div class="acciones-fila">
                            <button class="btn chico gris" @click="verNota(n.venta_id)" title="Ver o imprimir la nota">📄 Ver nota</button>
                            <button class="btn chico gris" @click="abrirCorreo(n.venta_id, n.numero)" title="Mandar la nota por correo">✉ Correo</button></div>
                    </article>
                </template>
                <div class="vacio-lista" x-show="!(d.movimientos || []).length"><span aria-hidden="true">·</span>
                    <p x-text="d.periodo?.mes === 'todo' ? 'Todavía no hay ventas.' : 'No hubo ventas en ' + nombreMes(d.periodo?.mes).toLowerCase() + '.'"></p></div>
            </div>
        </section>
        </template>

        {{-- ---------------------------------------------------- CALIDAD: MUESTREO (rol Calidad) --}}
        <template x-if="dDe === 'muestreo'">
        <section x-show="seccion === 'muestreo' && !cargando && !error">
            <div class="fila"><h1>Muestreo</h1><div class="espacio"></div>
                <button class="btn gris" @click="cargar()">Actualizar</button></div>
            <p class="sub">No hace falta analizar a todos: elige a cualquier proveedor y escribe lo que marca el lactoscan.
                Las entregas ya cuentan como aceptadas; si la muestra sale mal, el diagnóstico la corrige.</p>
            <div class="tarjetas">
                <div class="tarjeta"><div class="eti">Analizadas hoy</div><div class="valor ok" x-text="d.analizadas_hoy || 0"></div></div>
                <div class="tarjeta"><div class="eti">Sin analizar (hoy y ayer)</div><div class="valor" x-text="(d.entregas || []).length"></div></div>
            </div>
            <div class="muestreo">
                <div>
                    <input type="search" x-model="buscarMuestra" placeholder="Buscar proveedor, comunidad o vehículo" style="margin-top: 14px">
                    <template x-for="[titulo, lista] in muestrasVisibles()" :key="titulo">
                        <div><h2 x-text="titulo + ' · ' + lista.length"></h2>
                        <table class="lista">
                            <template x-for="e in lista" :key="e.id">
                                <tr class="clic" :class="{ elegida: muestra?.entrega.id === e.id }" @click="elegirMuestra(e)">
                                    <td><strong x-text="e.proveedor"></strong>
                                        <small class="apoyo-fila" x-text="e.comunidad + ' · ' + e.vehiculo + ' · ' + e.hora"></small></td>
                                    <td class="num"><span x-text="num(e.litros) + ' L'"></span>
                                        <small class="apoyo-fila ojo" x-show="Math.abs(desvioMuestra(e)) >= 20"
                                               x-text="(desvioMuestra(e) > 0 ? '+' : '') + num(desvioMuestra(e), 0) + ' % vs. su promedio'"></small></td></tr>
                            </template>
                        </table></div>
                    </template>
                    <div class="aviso bien" style="margin-top: 14px" x-show="!(d.entregas || []).length">No hay entregas de hoy ni de ayer sin analizar.</div>
                    <div class="aviso" style="margin-top: 14px" x-show="(d.entregas || []).length && !muestrasVisibles().length" x-text="'Nadie coincide con “' + buscarMuestra + '”.'"></div>
                </div>

                <div class="tarjeta analisis">
                    {{-- sin nadie elegido: los pasos y los límites que rigen hoy (los mismos de Configuración → Calidad) --}}
                    <template x-if="!muestra"><div class="muestreo-guia">
                        <div class="guia-ico" aria-hidden="true">🧪</div>
                        <strong>Elige un proveedor de la lista</strong>
                        <ol class="pasos-guia">
                            <li><b>1</b>Toca a alguien de la izquierda</li>
                            <li><b>2</b>Escribe lo que marca el lactoscan</li>
                            <li><b>3</b>Mira el veredicto al momento y guarda</li>
                        </ol>
                        <div class="eti" style="margin-top: 18px">Límites de hoy</div>
                        <div class="limites-hoy">
                            <span>🧈 Grasa <b x-text="'≥ ' + d.rangos?.grasa_min + ' %'"></b></span>
                            <span>🧀 Proteína <b x-text="'≥ ' + d.rangos?.proteina_min + ' %'"></b></span>
                            <span>⚖️ Densidad <b x-text="d.rangos?.densidad_min + ' – ' + d.rangos?.densidad_max"></b></span>
                            <span>💧 Agua <b x-text="'< ' + d.rangos?.agua_adulter + ' %'"></b></span>
                            <span>🌡️ Temperatura <b x-text="'≤ ' + d.rangos?.temperatura_max + ' °C'"></b></span>
                            <span>🧪 pH <b x-text="d.rangos?.ph_min + ' – ' + d.rangos?.ph_max"></b></span>
                        </div></div></template>
                    <template x-if="muestra"><div>
                        <div class="fila"><div><div class="eti">Análisis de</div><strong style="font-size: 18px" x-text="muestra.entrega.proveedor"></strong>
                            <small class="apoyo-fila" x-text="muestra.entrega.comunidad + ' · ' + muestra.entrega.vehiculo + ' · ' + fechaCorta(muestra.entrega.fecha) + ' · ' + num(muestra.entrega.litros) + ' L'"></small></div>
                            <div class="espacio"></div><button class="btn chico gris" @click="muestra = null" :disabled="muestra.enviando">Cancelar</button></div>
                        <div class="pestanas" style="margin: 12px 0 4px">
                            <button :class="{ activo: muestra.tipo === 'CAMPO' }" @click="muestra.tipo = 'CAMPO'">Muestra de ruta</button>
                            <button :class="{ activo: muestra.tipo === 'PLANTA' }" @click="muestra.tipo = 'PLANTA'">Muestra de planta</button>
                        </div>
                        <div class="medidas">
                            <template x-for="x in medidasMuestra()" :key="x.k"><div>
                                <label :for="'m-' + x.k" x-text="x.nombre"></label>
                                <input :id="'m-' + x.k" type="number" min="0" :step="x.paso" x-model="muestra[x.k]" :class="{ cambiado: x.fuera }">
                                <small class="apoyo-fila" :class="{ mal: x.fuera }" x-text="x.ayuda"></small></div></template>
                        </div>
                        <label style="display: flex; gap: 8px; align-items: center; cursor: pointer">
                            <input type="checkbox" x-model="muestra.significada" style="width: auto"> Leche significada (expuesta al sol antes del recojo)</label>
                        <div class="veredicto" :class="veredictoMuestra().resultado === 'ACEPTADO' ? 'bien' : 'mal'">
                            <div class="eti">Veredicto en vivo</div>
                            <strong x-text="veredictoMuestra().resultado"></strong>
                            <div x-text="veredictoMuestra().causas.length ? veredictoMuestra().causas.join(' · ') : 'Todos los parámetros dentro de rango.'"></div>
                        </div>
                        <div class="aviso error" x-show="muestra.error" x-text="muestra.error"></div>
                        <div class="fila" style="gap: 10px; margin-top: 12px">
                            <button class="btn rojo" style="flex: 1" @click="guardarAnalisis(false)" :disabled="muestra.enviando">Rechazar</button>
                            <button class="btn" style="flex: 1" @click="guardarAnalisis(true)" :disabled="muestra.enviando" x-text="muestra.enviando ? 'Guardando…' : 'Aceptar'"></button>
                        </div>
                        <p class="sub" style="margin: 8px 0 0">El servidor recalcula el veredicto y aplica la sanción que corresponda. Queda registrado con tu nombre.</p>
                    </div></template>
                </div>
            </div>
        </section>
        </template>

        {{-- ---------------------------------------------------- CALIDAD: INFRACTORES --}}
        <template x-if="dDe === 'infractores'">
        <section x-show="seccion === 'infractores' && !cargando && !error">
            <div class="cabecera"><div><h1>Infractores</h1>
                <p class="sub" style="margin: 0">Sanciones registradas: adulteración 1.ª vez = precio reducido, 2.ª vez = baja definitiva; acidez o leche significada = capacitación.</p></div></div>
            <div class="rechazos">
                <template x-for="s in d.data || []" :key="s.id">
                    <article class="rechazo">
                        <div class="fecha-bloque"><b x-text="Number(String(s.created_at).slice(8, 10))"></b><span x-text="fechaCorta(s.created_at).split(' ')[1]"></span></div>
                        <div class="cuerpo-rechazo"><strong x-text="s.proveedor?.nombre || '—'"></strong><small x-text="s.proveedor?.comunidad?.nombre || ''"></small>
                            <div class="etiquetas-rechazo"><span class="chip" :class="s.tipo === 'ADULTERACION' ? 'mal' : 'ojo'"
                                x-text="{ ADULTERACION: 'Adulteración', SIGNIFICADA: 'Leche significada' }[s.tipo] || 'Acidez'"></span></div></div>
                        <div class="vez" :class="s.nivel > 1 ? 'mal' : ''"><b x-text="s.nivel + '.ª'"></b><small>vez</small></div>
                        <div class="capacitacion"><strong :class="s.medida === 'BAJA_DEFINITIVA' ? 'mal' : ''"
                            x-text="s.medida === 'PRECIO_REDUCIDO' ? 'Precio reducido' + (s.precio_aplicado ? ' (' + soles(s.precio_aplicado) + '/L)' : '') : (s.medida === 'BAJA_DEFINITIVA' ? 'Baja definitiva' : 'Capacitación')"></strong></div>
                    </article>
                </template>
                <div class="vacio-lista" x-show="!(d.data || []).length"><span aria-hidden="true">✓</span><p>No hay infractores registrados.</p></div>
            </div>
        </section>
        </template>

        {{-- ---------------------------------------------------- CALIDAD: HISTORIAL --}}
        <template x-if="dDe === 'historial-calidad'">
        <section x-show="seccion === 'historial-calidad' && !cargando && !error">
            <div class="cabecera"><div><h1>Historial de análisis</h1>
                <p class="sub" style="margin: 0" x-text="'Últimos ' + (d.analisis || []).length + ' análisis, con quién los hizo.'"></p></div></div>
            <div class="rechazos">
                <template x-for="a in d.analisis || []" :key="a.id">
                    <article class="rechazo analisis-fila" :class="a.veredicto === 'ACEPTADO' ? '' : 'malo'">
                        <div class="fecha-bloque"><b x-text="Number(String(a.fecha).slice(8, 10))"></b><span x-text="fechaCorta(a.fecha).split(' ')[1]"></span></div>
                        <div class="cuerpo-rechazo"><strong x-text="a.proveedor"></strong>
                            <small x-text="a.hora + ' · ' + a.vehiculo + ' · muestra de ' + (a.muestra === 'PLANTA' ? 'planta' : 'ruta') + (a.analista ? ' · ' + a.analista : '')"></small>
                            <div class="etiquetas-rechazo" x-show="a.causas.length"><template x-for="c in a.causas"><span class="chip mal" x-text="c"></span></template></div></div>
                        <dl class="medidas-vehiculo medidas-analisis">
                            <div><dt>Litros</dt><dd x-text="num(a.litros)"></dd></div>
                            <div><dt>Grasa</dt><dd x-text="num(a.grasa, 2) + ' %'"></dd></div>
                            <div><dt>Agua</dt><dd :class="a.veredicto !== 'ACEPTADO' && a.agua > 0 ? 'mal' : ''" x-text="num(a.agua, 2) + ' %'"></dd></div>
                        </dl>
                        <div class="capacitacion"><span class="chip" :class="a.veredicto === 'ACEPTADO' ? 'ok' : 'mal'" x-text="a.veredicto.toLowerCase()"></span></div>
                    </article>
                </template>
                <div class="vacio-lista" x-show="!(d.analisis || []).length"><span aria-hidden="true">·</span><p>Todavía no hay análisis.</p></div>
            </div>
        </section>
        </template>
    </main>
</div>

{{-- ============================================================ VENTANAS --}}
{{-- confirmación propia: sale encima de todo, incluso de otra ventana abierta --}}
<div class="velo encima" x-show="pregunta" x-cloak @keydown.escape.window="pregunta && responder(false)" @click.self="responder(false)">
    <div class="ventana confirmar" :class="pregunta?.tono" role="alertdialog" aria-modal="true" :aria-label="pregunta?.titulo">
        <div class="icono" x-text="pregunta?.icono || '❓'"></div>
        <h3 x-text="pregunta?.titulo"></h3>
        <p x-text="pregunta?.texto"></p>
        <template x-if="(pregunta?.filas || []).length"><table class="lista">
            <template x-for="r in pregunta.filas" :key="r.k"><tr><td x-text="r.k"></td><td class="num" :class="r.clase || ''"><strong x-text="r.v"></strong></td></tr></template>
        </table></template>
        <div class="pie"><button class="btn gris" @click="responder(false)" x-text="pregunta?.no"></button>
            <button class="btn" :class="pregunta?.tono === 'peligro' ? 'rojo' : ''" @click="responder(true)" x-text="pregunta?.si" x-init="$watch('pregunta', (p) => p && $nextTick(() => $el.focus()))"></button></div>
    </div>
</div>

<div class="velo" x-show="ventana" x-cloak @keydown.escape.window="if (!pregunta && ventana) { $event._ventana = true; cerrarVentana() }" @click.self="ventana = null">

    {{-- nuevo proveedor --}}
    <div class="ventana" x-show="ventana === 'proveedor'">
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
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
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
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
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
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

    {{-- ya se capacitó al productor --}}
    <div class="ventana" x-show="ventana === 'capacitar'">
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
        <h3 x-text="'Ya se capacitó a ' + (f.rechazo?.proveedor || '')"></h3>
        <p class="sub" x-text="(f.rechazo?.comunidad || '') + ' · rechazada el ' + fechaCorta(f.rechazo?.fecha) + ' por ' + (f.rechazo?.causas || []).join(', ')"></p>
        <p class="sub" x-show="f.pendientes > 1" x-text="'Vale para sus ' + f.pendientes + ' rechazos pendientes hasta ese día.'"></p>
        <label for="cap-nota">Qué se le dijo (opcional)</label>
        <input id="cap-nota" x-model="f.nota" maxlength="200" placeholder="Ej.: enfriar el porongo en agua apenas ordeña">
        <div class="aviso error" x-show="f.error" x-text="f.error" style="margin-top: 10px"></div>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cancelar</button>
            <button class="btn" :disabled="f.enviando" @click="guardarCapacitado()" x-text="f.enviando ? 'Guardando…' : 'Guardar'"></button></div>
    </div>

    {{-- detalle de boleta --}}
    <div class="ventana" x-show="ventana === 'boleta'">
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
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
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
        <h3 x-text="f.titulo"></h3>
        <p class="sub">¿Cómo se pagó?</p>
        <div class="dos"><button class="btn" :disabled="!!f.enviando" @click="confirmarPago('EFECTIVO')">Efectivo</button>
            <button class="btn" :disabled="!!f.enviando" @click="confirmarPago('YAPE')">Yape</button></div>
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="f.fichaId ? abrirFicha(f.fichaId) : (ventana = null)">Cancelar</button></div>
    </div>

    {{-- anular una orden de compra que no llegó --}}
    <div class="ventana" x-show="ventana === 'anularOrden'">
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
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
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
        <h3 x-text="'Enviar nota ' + (f.numero || '')"></h3>
        <label>Correo del cliente</label><input type="email" x-model="f.correo" placeholder="cliente@gmail.com">
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cancelar</button>
            <button class="btn" @click="enviarCorreo()" :disabled="!!f.enviando" x-text="f.enviando ? 'Enviando…' : 'Enviar'"></button></div>
    </div>

    {{-- registrar pérdida de leche / queso / yogurt --}}
    <div class="ventana ventana-merma" x-show="ventana === 'merma'">
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
        <div class="ventana-cabeza">
            <span class="ventana-ico rojo" aria-hidden="true">🗑️</span>
            <div><h3>Registrar pérdida</h3>
                <p>Lo que se pierde sale del stock y queda anotado en el kardex con su motivo.</p></div>
        </div>

        {{-- 1. qué se perdió: leche o un producto (con lo que hay de cada uno) --}}
        <div class="paso-compra" style="margin-top: 0; padding-top: 0; border-top: 0">
            <div class="paso-titulo"><b>1</b><span>¿Qué se perdió?</span></div>
            <div class="que-merma">
                <button class="merma-op leche" :class="{ activo: f.tipo === 'LECHE' }" @click="f.tipo = 'LECHE'; f.motivo = null; f.lote_id = null">
                    <span class="mo-ico" aria-hidden="true">🥛</span>
                    <span><b>Leche</b><small x-text="f.datos ? num(f.datos.leche.sin_usar, 0) + ' L sin procesar' : '…'"></small></span>
                </button>
                <template x-for="p in productosMerma()" :key="p.id">
                    <button class="merma-op" :class="{ activo: f.tipo === 'PRODUCTO' && f.producto_id === p.id, vacio: !(p.stock > 0) }" :style="'--tono: ' + colorReceta(p)"
                            @click="elegirProductoMerma(p)">
                        <span class="mo-dibujo" aria-hidden="true" x-html="dibujoReceta(p)"></span>
                        <span><b x-text="p.nombre"></b><small x-text="p.stock > 0 ? 'hay ' + num(p.stock, 2) + ' ' + p.unidad : 'sin stock'"></small></span>
                        <i class="mo-vencido" x-show="(p.lotes || []).some((l) => l.vencido)" title="Tiene lotes vencidos">⛔</i>
                    </button>
                </template>
            </div>
            {{-- insumos: el saco que se rompe, la bolsa que se moja --}}
            <div class="eti" style="margin: 12px 0 6px">🧂 Insumos</div>
            <div class="que-merma insumos">
                <template x-for="i in f.datos?.insumos || []" :key="i.id">
                    <button class="merma-op" :class="{ activo: f.tipo === 'INSUMO' && f.insumo_id === i.id, vacio: !(i.stock > 0) }" @click="elegirInsumoMerma(i)">
                        <span class="mo-ico" aria-hidden="true" x-text="iconoInsumo(i)"></span>
                        <span><b x-text="i.nombre"></b><small x-text="i.stock > 0 ? 'hay ' + num(i.stock, 3) + ' ' + i.unidad : 'sin stock'"></small></span>
                    </button>
                </template>
            </div>
        </div>

        {{-- 2. qué pasó --}}
        <div class="paso-compra">
            <div class="paso-titulo"><b>2</b><span>¿Qué pasó?</span></div>
            <div class="motivos-merma">
                <template x-for="(texto, clave) in f.datos?.motivos?.[f.tipo] || {}" :key="f.tipo + clave">
                    <button :class="{ activo: f.motivo === clave }" @click="f.motivo = clave">
                        <span aria-hidden="true" x-text="iconoMotivo(clave)"></span><b x-text="texto"></b></button>
                </template>
            </div>
        </div>

        {{-- 3. de qué lote y cuánto --}}
        <div class="paso-compra">
            <div class="paso-titulo"><b>3</b><span x-text="f.tipo === 'PRODUCTO' && (productoMerma()?.lotes || []).length ? '¿De qué lote y cuánto?' : '¿Cuánto se perdió?'"></span></div>
            <div class="lotes-merma" x-show="f.tipo === 'PRODUCTO' && (productoMerma()?.lotes || []).length">
                <template x-for="l in productoMerma()?.lotes || []" :key="l.id">
                    <button :class="{ activo: f.lote_id === l.id, vencido: l.vencido }" @click="elegirLoteMerma(l)">
                        <b x-text="l.codigo"></b>
                        <small x-text="(l.vencido ? '⛔ venció el ' : 'vence el ') + fechaCorta(l.vence_el)"></small>
                        <small x-text="'quedan ' + num(l.queda, 2)"></small>
                    </button>
                </template>
                <button :class="{ activo: !f.lote_id }" @click="f.lote_id = null"><b>No sé</b><small>varios lotes</small></button>
            </div>
            <div class="cuanto-merma">
                <span class="caja-unidad"><input type="number" min="0" :step="f.tipo === 'LECHE' ? 0.5 : (f.tipo === 'INSUMO' ? 0.001 : 1)" x-model="f.cantidad" placeholder="0" aria-label="Cuánto se perdió">
                    <i x-text="hayParaMerma().u"></i></span>
                <button class="btn chico gris" x-show="topeMerma() > 0" @click="f.cantidad = String(topeMerma())"
                        x-text="'Todo (' + num(topeMerma(), 2) + ' ' + hayParaMerma().u + ')'"></button>
                <span class="de-cuanto" x-text="'de ' + num(topeMerma(), 2) + ' ' + hayParaMerma().u + (f.lote_id ? ' del lote' : (f.tipo === 'LECHE' ? ' sin procesar' : ' en almacén'))"></span>
            </div>
            <div class="barra-merma" x-show="topeMerma() > 0"><span :style="'width:' + Math.min(100, (Number(f.cantidad) || 0) / topeMerma() * 100) + '%'"></span></div>
            <div class="detalle-foto">
                <div style="flex: 1; min-width: 220px">
                    <label x-text="f.motivo === 'OTRO' ? 'Explica qué pasó' : 'Detalle (opcional)'"></label>
                    <input x-model="f.detalle" maxlength="200" :placeholder="f.tipo === 'INSUMO' ? 'Ej.: se cayó del estante y se abrió' : 'Ej.: se cortó por la tarde, olía agrio'">
                </div>
                {{-- foto opcional: el saco roto, el queso con hongos --}}
                <label class="foto-chica" :class="{ con: f.foto }" title="Foto (opcional)">
                    <input type="file" accept="image/*" @change="elegirFotoMerma($event)">
                    <template x-if="f.foto"><img :src="f.foto" alt="Foto de la pérdida"></template>
                    <span x-show="!f.foto">📷<small>Foto (opcional)</small></span>
                </label>
            </div>
        </div>

        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        {{-- abajo: lo que se pierde y lo que queda, siempre a la vista --}}
        <div class="pie pie-compra">
            <div class="total-compra perdida">
                <span>Se pierde</span>
                <b x-text="(Number(f.cantidad) > 0 ? num(f.cantidad, 2) : '0') + ' ' + hayParaMerma().u"></b>
                <small x-text="Number(f.cantidad) > 0 ? (valorMerma() ? '≈ ' + soles(valorMerma()) + ' · ' : '') + 'quedan ' + num(Math.max(0, hayParaMerma().cant - Number(f.cantidad)), 3) + ' ' + hayParaMerma().u
                    + (f.tipo === 'LECHE' ? ' de leche' : ' de ' + ((f.tipo === 'INSUMO' ? insumoMerma()?.nombre : productoMerma()?.nombre) || '').toLowerCase()) : 'elige qué y cuánto'"></small>
            </div>
            <div class="espacio"></div>
            <button class="btn gris" @click="ventana = null">Cancelar</button>
            <button class="btn rojo" @click="guardarMerma()" :disabled="!!f.enviando || !f.datos" x-text="f.enviando ? 'Guardando…' : '🗑️ Registrar pérdida'"></button>
        </div>
    </div>

    {{-- gasto de planta: reparación, mantenimiento, combustible… --}}
    <div class="ventana ventana-gasto" x-show="ventana === 'gasto'">
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
        <div class="ventana-cabeza">
            <span class="ventana-ico" aria-hidden="true" x-text="CATEGORIA_GASTO[f.categoria] || '🧾'"></span>
            <div><h3>Nuevo gasto de planta</h3><p>Lo que no es insumo pero cuesta. Entra en el costo por litro.</p></div>
        </div>
        <div class="paso-compra" style="margin-top: 0; padding-top: 0; border-top: 0">
            <div class="paso-titulo"><b>1</b><span>¿Qué fue?</span></div>
            <div class="motivos-merma">
                <template x-for="(texto, clave) in d.categorias || {}" :key="clave">
                    <button class="verde" :class="{ activo: f.categoria === clave }" @click="f.categoria = clave"><span aria-hidden="true" x-text="CATEGORIA_GASTO[clave]"></span><b x-text="texto"></b></button>
                </template>
            </div>
        </div>
        <div class="paso-compra" x-show="pideEquipo() || f.categoria === 'COMBUSTIBLE'">
            <div class="paso-titulo"><b>2</b><span x-text="f.categoria === 'COMBUSTIBLE' ? '¿De qué carro? (opcional)' : '¿A qué equipo?'"></span></div>
            <div class="fila" style="gap: 8px; flex-wrap: nowrap">
                <select x-model.number="f.equipo_id" style="flex: 1" aria-label="Equipo">
                    <option :value="null">Elige el equipo…</option>
                    <template x-for="e in (d.equipos || []).filter((e) => e.activo)" :key="e.id">
                        <option :value="e.id" :selected="f.equipo_id === e.id" x-text="(e.tipo === 'VEHICULO' ? '🚚 ' : '⚙️ ') + e.nombre"></option></template>
                </select>
                <button class="btn chico gris" @click="abrirEquipo()">+ Agregar equipo</button>
            </div>
        </div>
        <div class="paso-compra">
            <div class="paso-titulo"><b x-text="pideEquipo() || f.categoria === 'COMBUSTIBLE' ? 3 : 2"></b><span>¿Cuánto y cuándo?</span></div>
            <div class="cuanto-merma">
                <span class="soles-caja" style="width: 170px"><em>S/</em><input type="number" min="0" step="0.1" x-model="f.monto" placeholder="0.00" aria-label="Monto"></span>
                <input type="date" x-model="f.fecha" :max="hoy()" style="width: 180px" aria-label="Fecha">
            </div>
            <div class="detalle-foto">
                <div style="flex: 1; min-width: 220px">
                    <label x-text="f.categoria === 'OTRO' ? '¿En qué se gastó?' : 'Detalle (opcional)'"></label>
                    <input x-model="f.detalle" maxlength="200" placeholder="Ej.: se cambió la resistencia, técnico de Juliaca">
                </div>
                <label class="foto-chica" :class="{ con: f.foto }" title="Foto del comprobante (opcional)">
                    <input type="file" accept="image/*" @change="elegirFotoGasto($event)">
                    <template x-if="f.foto"><img :src="f.foto" alt="Foto del comprobante"></template>
                    <span x-show="!f.foto">📷<small>Boleta (opcional)</small></span>
                </label>
            </div>
        </div>
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie pie-compra">
            <div class="total-compra"><span>Gasto</span><b x-text="soles(Number(f.monto) || 0)"></b>
                <small x-text="(d.categorias?.[f.categoria] || 'elige qué fue') + (f.equipo_id ? ' · ' + (equipoDe(f.equipo_id)?.nombre || '') : '')"></small></div>
            <div class="espacio"></div>
            <button class="btn gris" @click="ventana = null">Cancelar</button>
            <button class="btn" @click="guardarGasto()" :disabled="!!f.enviando" x-text="f.enviando ? 'Guardando…' : '✓ Guardar gasto'"></button>
        </div>
    </div>

    {{-- equipo: nombre y cada cuántos días le toca mantenimiento --}}
    <div class="ventana" x-show="ventana === 'equipo'">
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
        <h3 x-text="f.id ? 'Editar equipo' : 'Agregar equipo'"></h3>
        <label>Nombre</label>
        <input x-model="f.nombre" maxlength="80" placeholder="Ej.: Pasteurizadora, Tina quesera, Prensa, Cámara de frío">
        <label>¿Qué es?</label>
        <div class="opciones">
            <button :class="{ activo: f.tipo === 'PLANTA' }" @click="f.tipo = 'PLANTA'">⚙️ Equipo de planta</button>
            <button :class="{ activo: f.tipo === 'VEHICULO' }" @click="f.tipo = 'VEHICULO'">🚚 Vehículo</button>
        </div>
        <label>¿Cada cuántos días le toca mantenimiento? (opcional)</label>
        <div class="fila" style="gap: 8px; align-items: center">
            <input type="number" min="1" max="730" step="1" x-model="f.mantenimiento_dias" placeholder="Ej.: 90" style="width: 140px">
            <span class="sub" style="margin: 0">días · vacío = no avisar</span>
        </div>
        <label x-show="f.id" style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin-top: 14px">
            <input type="checkbox" x-model="f.activo" style="width: auto"> Se sigue usando</label>
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="ventana = null; panelEquipos = true">Cancelar</button>
            <button class="btn" @click="guardarEquipo()" :disabled="!!f.enviando" x-text="f.enviando ? 'Guardando…' : 'Guardar'"></button></div>
    </div>

    {{-- cambio de comunidad registrado por el admin (el productor llamó o vino a Huata) --}}
    <div class="ventana" x-show="ventana === 'cambio'">
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
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
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
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

            {{-- lo que se le debe: pagarlo aquí mismo, todo junto o semana por semana --}}
            <template x-if="(f.ficha.pagos.pendientes || []).length">
                <div class="deuda-ficha">
                    <div class="fila">
                        <div><strong x-text="'Se le debe ' + soles(f.ficha.pagos.pendientes.reduce((t, b) => t + b.monto, 0))"></strong>
                            <small x-text="f.ficha.pagos.pendientes.length === 1 ? '1 semana sin pagar' : f.ficha.pagos.pendientes.length + ' semanas sin pagar · desde el ' + fechaCorta(f.ficha.pagos.pendientes[0].inicio)"></small></div>
                        <div class="espacio"></div>
                        <button class="btn" @click="pagar(f.ficha.pagos.pendientes.map((b) => b.id),
                                'Pagar a ' + f.ficha.proveedor.nombre + ' todo lo que se le debe (' + soles(f.ficha.pagos.pendientes.reduce((t, b) => t + b.monto, 0)) + ')', f.ficha.proveedor.id)"
                                x-text="f.ficha.pagos.pendientes.length > 1 ? 'Pagar todo' : 'Pagar'"></button>
                    </div>
                    <div class="deuda-ficha-semanas" x-show="f.ficha.pagos.pendientes.length > 1">
                        <template x-for="b in f.ficha.pagos.pendientes" :key="b.id">
                            <div class="fila"><span x-text="'Semana ' + fechaCorta(b.inicio) + ' – ' + fechaCorta(b.fin)"></span>
                                <div class="espacio"></div><b x-text="soles(b.monto)"></b>
                                <button class="btn chico gris" @click="pagar([b.id], 'Pagar a ' + f.ficha.proveedor.nombre + ' la semana del ' + fechaCorta(b.inicio) + ' (' + soles(b.monto) + ')', f.ficha.proveedor.id)">Pagar esta</button></div>
                        </template>
                    </div>
                </div>
            </template>

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
                                            : (s.estado === 'EN_CURSO' ? 'en curso · se paga el ' + fechaCorta(s.fin) : 'Pendiente')"></span>
                                <button class="btn chico" x-show="s.estado === 'PENDIENTE' && s.boleta_id && s.monto > 0"
                                        @click.stop="pagar([s.boleta_id], 'Pagar a ' + f.ficha.proveedor.nombre + ' la semana del ' + fechaCorta(s.inicio) + ' (' + soles(s.monto) + ')', f.ficha.proveedor.id)">Pagar</button></td>
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
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
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
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
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
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
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

    {{-- producir varios a la vez --}}
    <div class="ventana" x-show="ventana === 'producir-varios'">
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
        <h3 x-text="'Hacer ' + (f.plan ? textoVarios(f.plan) : '')"></h3>
        <p class="sub">Al aceptar se inician los lotes a la vez y se descuenta del almacén:</p>
        <table class="lista">
            <template x-for="e in f.plan?.elegidos || []" :key="e.p.id">
                <tr><td x-text="'Leche para ' + e.p.nombre"></td><td class="num"><strong x-text="num(e.litros, 0) + ' L'"></strong></td></tr>
            </template>
            <template x-for="i in f.plan?.insumos || []" :key="i.nombre">
                <tr><td x-text="i.nombre"></td><td class="num"><strong x-text="num(i.necesita, 3) + ' ' + i.unidad"></strong></td></tr>
            </template>
        </table>
        <template x-for="e in f.plan?.elegidos || []" :key="'r' + e.p.id">
            <p class="sub" style="margin: 8px 0 0" x-text="e.p.nombre + ': se espera que salgan ' + (e.p.rendimiento_min === e.p.rendimiento_max ? num(e.litros * e.p.rendimiento_min, 2) : 'entre ' + num(e.litros * e.p.rendimiento_min, 2) + ' y ' + num(e.litros * e.p.rendimiento_max, 2)) + ' ' + unidadProducto(e.p) + '. Vence en ' + e.p.vida_util_dias + ' días.'"></p>
        </template>
        <div class="aviso error" x-show="f.error" x-text="f.error" style="margin-top: 10px"></div>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cancelar</button>
            <button class="btn" @click="confirmarProducirVarios()" :disabled="!!f.enviando"
                    x-text="f.enviando ? 'Iniciando…' : 'Aceptar e iniciar ' + (f.plan?.elegidos.length || 0) + ((f.plan?.elegidos.length || 0) === 1 ? ' lote' : ' lotes')"></button></div>
    </div>

    {{-- corregir stock (ajuste de kardex) --}}
    <div class="ventana" x-show="ventana === 'ajuste'">
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
        <h3 x-text="'Corregir el stock de ' + (f.item?.nombre || '')"></h3>
        <p class="sub" x-text="'El sistema dice que hay ' + num(f.item?.stock, 3) + ' ' + (f.item?.unidad || '') + '. Las compras y lo que se usa en los lotes se anotan solos; esto es para todo lo demás.'"></p>
        {{-- lo que se rompe, se moja o se vence es una pérdida (sale en Pérdidas con su valor); aquí solo conteo, devolución u otra cosa --}}
        <button class="ir-perdida" @click="abrirMerma('INSUMO', f.item?.id)">
            <span aria-hidden="true">🧺</span><span><b>¿Se rompió el saco, se mojó o se venció?</b><small>Regístralo como pérdida: queda con su valor en soles y su foto.</small></span><i aria-hidden="true">→</i></button>
        <label>¿Qué pasó?</label>
        <div class="opciones" style="flex-wrap: wrap">
            <template x-for="r in RAZONES_AJUSTE" :key="r.id">
                <button :class="{ activo: f.razon === r.id }" @click="f.razon = r.id; f.error = null" x-text="r.texto"></button></template></div>
        <template x-if="f.razon === 'contamos'"><div>
            <label x-text="'¿Cuánto hay de verdad? (' + (f.item?.unidad || '') + ')'"></label>
            <input type="number" min="0" step="0.001" x-model="f.hay">
            <p class="sub" x-show="diferenciaConteo() !== null"
               x-text="Math.abs(diferenciaConteo()) < 0.0005 ? 'Cuadra: no hay nada que corregir.' : (diferenciaConteo() > 0 ? 'Sobran ' : 'Faltan ') + num(Math.abs(diferenciaConteo()), 3) + ' ' + (f.item?.unidad || '') + '. Se corrige al guardar.'"></p>
        </div></template>
        <template x-if="f.razon && f.razon !== 'contamos'"><div>
            <div class="opciones" x-show="f.razon === 'otro'" style="margin-top: 10px">
                <button :class="{ activo: f.tipo === 'SALIDA' }" @click="f.tipo = 'SALIDA'">Hay menos</button>
                <button :class="{ activo: f.tipo === 'ENTRADA' }" @click="f.tipo = 'ENTRADA'">Hay más</button></div>
            <label x-text="(razonAjuste()?.tipo ?? f.tipo) === 'ENTRADA' ? '¿Cuánto entró? (' + (f.item?.unidad || '') + ')' : '¿Cuánto se perdió? (' + (f.item?.unidad || '') + ')'"></label>
            <input type="number" min="0" step="0.001" x-model="f.cantidad">
            <label x-text="f.razon === 'otro' ? '¿Qué pasó?' : 'Detalle (opcional)'"></label>
            <input x-model="f.detalle" maxlength="100" placeholder="Ej.: se mojó una bolsa en el almacén">
        </div></template>
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cancelar</button>
            <button class="btn" @click="guardarAjuste()" :disabled="!!f.enviando" x-text="f.enviando ? 'Guardando…' : 'Guardar'"></button></div>
    </div>

    {{-- sabor o variedad: lo que le agrega a su base --}}
    <div class="ventana" x-show="ventana === 'sabor'">
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
        <h3 x-text="(f.id ? 'Editar ' : 'Nuevo ') + (f.tipo === 'YOGURT' ? 'sabor' : 'variedad') + ' de ' + (f.base || '')"></h3>
        <p class="sub" x-text="'Lleva todo lo del ' + (f.base || '') + ' (insumos, pasos, rendimiento y días que dura). Aquí solo escribes lo que le agrega.'"></p>
        <label x-text="f.tipo === 'YOGURT' ? 'Elige el sabor' : 'Elige la variedad'" x-show="!f.id"></label>
        <div class="opciones" x-show="!f.id"><template x-for="o in opcionesSabor()" :key="o">
            <button :class="{ activo: f.sabor === o }" :disabled="saborYaExiste(o)" :title="saborYaExiste(o) ? 'Ya existe' : ''"
                    @click="elegirSabor(o)" x-text="o"></button></template></div>
        <label for="sab-sabor" x-text="f.id ? (f.tipo === 'YOGURT' ? 'Sabor' : 'Variedad') : '…o escribe otro'"></label>
        <input id="sab-sabor" x-model="f.sabor" @input="nombreSabor()" maxlength="40" :placeholder="f.tipo === 'YOGURT' ? 'Ej.: Camu camu' : 'Ej.: Con ajo'">
        <label for="sab-nombre">Nombre como se vende</label>
        <input id="sab-nombre" x-model="f.nombre" @input="f.nombreTocado = true" maxlength="60">
        <label for="sab-insumo" x-text="f.tipo === 'YOGURT' ? '¿Qué le da el sabor?' : '¿Qué lleva de más?'"></label>
        <select id="sab-insumo" x-model="f.insumo_id">
            <option value="">— Elige un insumo —</option>
            <template x-for="i in d.insumos || []" :key="i.id"><option :value="i.id" x-text="i.nombre + ' (' + i.unidad + ')'"></option></template>
            <option value="nuevo">+ Es un insumo nuevo</option></select>
        <template x-if="f.insumo_id === 'nuevo'"><div class="fila" style="gap: 8px; flex-wrap: nowrap; margin-top: 8px">
            <input x-model="f.insumo_nombre" maxlength="80" placeholder="Nombre (ej.: Saborizante durazno)" aria-label="Nombre del insumo nuevo">
            <input x-model="f.insumo_unidad" maxlength="12" placeholder="L, kg" style="width: 90px" aria-label="Unidad del insumo nuevo"></div></template>
        <label for="sab-cant" x-text="'¿Cuánto por cada 100 L de leche?' + (unidadSabor() ? ' (' + unidadSabor() + ')' : '')"></label>
        <div class="fila" style="gap: 8px; flex-wrap: nowrap">
            <input id="sab-cant" type="number" min="0" step="any" x-model="f.por_100l" placeholder="Ej.: 0.3">
            <template x-if="dosisHermano()"><button class="btn chico gris" style="white-space: nowrap" @click="f.por_100l = dosisHermano().por_100l"
                x-text="'Igual que ' + dosisHermano().variante.toLowerCase() + ' (' + num(dosisHermano().por_100l, 3) + ')'"></button></template></div>
        <p class="sub" style="margin: 4px 0 0">Usa la dosis de la etiqueta del saborizante o de la guía técnica.</p>
        <label for="sab-paso">Paso que se agrega (opcional)</label>
        <input id="sab-paso" x-model="f.paso" maxlength="300" :placeholder="f.tipo === 'YOGURT' ? 'Si lo dejas vacío: al batir, agregar el saborizante…' : 'Si lo dejas vacío: al salar la cuajada, mezclar…'">
        <template x-if="!f.id && f.precios"><div>
            <label x-text="'Precios (vacío = los mismos del ' + (f.base || '') + ')'"></label>
            <div class="fila" style="gap: 8px; flex-wrap: nowrap">
                <input type="number" min="0" step="0.1" x-model="f.precios.MAYORISTA" placeholder="Mayorista S/" aria-label="Precio mayorista">
                <input type="number" min="0" step="0.1" x-model="f.precios.DIRECTO" placeholder="Cliente S/" aria-label="Precio cliente directo">
                <input type="number" min="0" step="0.1" x-model="f.precios.PLANTA" placeholder="Proveedor S/" aria-label="Precio para proveedor de leche"></div>
        </div></template>
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cancelar</button>
            <button class="btn" @click="guardarSabor()" :disabled="!!f.enviando" x-text="f.enviando ? 'Guardando…' : (f.id ? 'Guardar' : 'Agregar')"></button></div>
    </div>

    {{-- producto nuevo o editar --}}
    <div class="ventana" x-show="ventana === 'producto'">
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
        <h3 x-text="f.id ? 'Editar ' + (f.nombre || 'producto') : 'Producto nuevo'"></h3>
        <template x-if="!f.id"><div>
            <label for="prod-copiar">Copiar la fórmula de (opcional)</label>
            <select id="prod-copiar" x-model="f.copiar_de" @change="copiarDatosDe(f.copiar_de)">
                <option value="">— Empezar sin fórmula —</option>
                <template x-for="p in d.productos || []" :key="p.id"><option :value="p.id" x-text="p.nombre"></option></template></select>
            <p class="sub" style="margin: 4px 0 0">Ej.: para un yogurt de durazno, copia el de fresa y luego cambia el saborizante.</p>
        </div></template>
        <template x-if="!f.id"><div>
            <label>Elige qué es (o escribe el nombre abajo)</label>
            <div class="opciones"><template x-for="o in PRODUCTOS_COMUNES" :key="o.nombre">
                <button :class="{ activo: f.nombre === o.nombre }" :disabled="(d.productos || []).some((x) => x.nombre.toLowerCase() === o.nombre.toLowerCase())"
                        @click="f.nombre = o.nombre; f.tipo = o.tipo; f.unidad = o.unidad" x-text="o.nombre"></button></template></div>
            <p class="sub" style="margin: 4px 0 0">Un sabor de yogurt o un queso con orégano no va aquí: se agrega en Producir > Recetas con <strong>+ Sabor</strong> o <strong>+ Variedad</strong>.</p>
        </div></template>
        <label for="prod-nombre">Nombre</label>
        <input id="prod-nombre" x-model="f.nombre" maxlength="60" placeholder="Ej.: Queso fresco">
        <label>Tipo</label>
        <div class="opciones"><template x-for="(t, k) in TIPOS_PRODUCTO" :key="k">
            <button :class="{ activo: f.tipo === k }" @click="f.tipo = k" x-text="t"></button></template></div>
        <p class="sub" x-show="f.base_id" style="margin: 8px 0 0">Es un sabor: tipo, unidad, rendimiento y días que dura salen de su base.</p>
        <div x-show="!f.base_id">
        <label for="prod-unidad">Se cuenta en</label>
        <input id="prod-unidad" x-model="f.unidad" maxlength="20" placeholder="Ej.: quesos de 1 kg, botellas de 1 L">
        <label>¿Cuánto sale de 100 L de leche? (mínimo y máximo)</label>
        <div class="fila" style="gap: 8px; flex-wrap: nowrap">
            <input type="number" min="0" step="any" x-model="f.rinde_min" placeholder="mín." aria-label="Mínimo que sale de 100 L">
            <span>a</span>
            <input type="number" min="0" step="any" x-model="f.rinde_max" placeholder="máx." aria-label="Máximo que sale de 100 L">
            <span x-text="(f.unidad || '').split(' ')[0]"></span></div>
        <p class="sub" style="margin: 4px 0 0">Con el mínimo se calcula cuánta leche hace falta, así la cantidad pedida sale segura.</p>
        <label for="prod-dura">¿Cuántos días dura?</label>
        <input id="prod-dura" type="number" min="1" step="1" x-model="f.vida_util_dias" placeholder="Ej.: 21">
        </div>
        <template x-if="!f.id && f.precios"><div>
            <label>Precios de venta (opcional, se pueden poner después en Precios)</label>
            <div class="fila" style="gap: 8px; flex-wrap: nowrap">
                <input type="number" min="0" step="0.1" x-model="f.precios.MAYORISTA" placeholder="Mayorista S/" aria-label="Precio mayorista">
                <input type="number" min="0" step="0.1" x-model="f.precios.DIRECTO" placeholder="Cliente S/" aria-label="Precio cliente directo">
                <input type="number" min="0" step="0.1" x-model="f.precios.PLANTA" placeholder="Proveedor S/" aria-label="Precio para proveedor de leche"></div>
        </div></template>
        <template x-if="f.id"><div>
            <label>¿Se sigue produciendo?</label>
            <div class="opciones">
                <button :class="{ activo: f.activo }" @click="f.activo = true">Sí, se produce</button>
                <button :class="{ activo: !f.activo }" @click="f.activo = false">Ya no se produce</button></div>
            <p class="sub" style="margin: 4px 0 0" x-show="!f.activo">Deja de salir para producir; lo que quede en stock se puede seguir vendiendo. Sus ventas y lotes no se borran.</p>
        </div></template>
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cancelar</button>
            <button class="btn" @click="guardarProducto()" :disabled="!!f.enviando" x-text="f.enviando ? 'Guardando…' : (f.id ? 'Guardar' : 'Crear producto')"></button></div>
    </div>

    {{-- editar fórmula (solo admin) --}}
    <div class="ventana" x-show="ventana === 'formula'">
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
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

    {{-- nueva compra: "ya lo compré" (en un paso) o "lo pido" (llega después) --}}
    <div class="ventana ancha ventana-compra" x-show="ventana === 'compra'">
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
        <div class="ventana-cabeza">
            <span class="ventana-ico" aria-hidden="true">🛒</span>
            <div><h3>Nueva compra de insumos</h3>
                <p x-text="f.modo === 'orden' ? 'Anota el pedido; cuando llegue, toca Llegó y el stock sube.' : 'Anota lo que pagaste y la foto de la boleta: el stock sube al guardar.'"></p></div>
        </div>

        {{-- 1. cómo fue: ya se compró o se pide --}}
        <div class="modo-compra">
            <button :class="{ activo: f.modo === 'directa' }" @click="f.modo = 'directa'">
                <span aria-hidden="true">🧾</span><b>Ya lo compré</b><small>tengo la boleta · el stock sube ahora</small></button>
            <button :class="{ activo: f.modo === 'orden' }" @click="f.modo = 'orden'">
                <span aria-hidden="true">📦</span><b>Lo pido</b><small>llega después · se registra con «Llegó»</small></button>
        </div>

        {{-- 2. a quién --}}
        <div class="paso-compra">
            <div class="paso-titulo"><b>1</b><span x-text="f.modo === 'orden' ? '¿A quién se le pide?' : '¿A quién se le compró?'"></span></div>
            <div class="fila" style="gap: 8px; flex-wrap: nowrap">
                <select x-model.number="f.vendedor_id" style="flex: 1" aria-label="Vendedor">
                    <option :value="null">Elige el vendedor…</option>
                    <template x-for="v in d.vendedores || []" :key="v.id"><option :value="v.id" :selected="f.vendedor_id === v.id" x-text="v.nombre + ' · ' + v.tipo_documento + ' ' + v.documento"></option></template>
                </select>
                <button class="btn chico gris" @click="abrirVendedor(null, true)">+ Nuevo</button>
            </div>
        </div>

        {{-- 3. qué: una fila por insumo con su ícono, cantidad con su unidad y lo pagado con S/ --}}
        <div class="paso-compra">
            <div class="paso-titulo"><b>2</b><span x-text="f.modo === 'orden' ? '¿Qué insumos y cuánto?' : '¿Qué compraste, cuánto y cuánto pagaste? (con IGV)'"></span></div>
            <div class="lineas-compra">
                <template x-for="(it, k) in f.items || []" :key="k">
                    <div class="linea-compra">
                        <span class="lc-ico" aria-hidden="true" x-text="it.insumo_id ? iconoInsumo(insumoDe(it.insumo_id)) : '＋'"></span>
                        <select x-model.number="it.insumo_id" @change="elegirInsumoCompra(it)" aria-label="Insumo">
                            <option :value="null">Elige el insumo…</option>
                            <template x-for="i in d.inv?.insumos || []" :key="i.id"><option :value="i.id" :selected="it.insumo_id === i.id" x-text="i.nombre + ' · hay ' + num(i.stock, 3) + ' ' + i.unidad"></option></template>
                        </select>
                        <span class="caja-unidad"><input type="number" min="0" step="0.001" x-model="it.cantidad" @input="sugerirPrecio(it)" placeholder="Cantidad" aria-label="Cantidad">
                            <i x-text="insumoDe(it.insumo_id)?.unidad || ''"></i></span>
                        <span class="soles-caja" x-show="f.modo === 'directa'"><em>S/</em>
                            <input type="number" min="0" step="0.01" x-model="it.pagado" @input="it.pagoTocado = true" placeholder="pagado" aria-label="Cuánto se pagó"></span>
                        <button class="quitar-linea" x-show="f.items.length > 1" @click="f.items.splice(k, 1)" title="Quitar este insumo" aria-label="Quitar">✕</button>
                        <small class="lc-pista" x-show="it.insumo_id" x-text="precioUnidad(it)"></small>
                    </div>
                </template>
            </div>
            <button class="btn chico gris" @click="f.items.push(lineaCompra(null, ''))">+ Otro insumo</button>
        </div>

        {{-- 4. el comprobante (solo si ya se compró) --}}
        <template x-if="f.modo === 'directa'"><div class="paso-compra">
            <div class="paso-titulo"><b>3</b><span>¿Qué te dieron?</span></div>
            <div class="comprobantes">
                <template x-for="(texto, clave) in d.comprobantes || {}" :key="clave">
                    <button :class="{ activo: f.tipo === clave }" @click="f.tipo = clave">
                        <span aria-hidden="true" x-text="{ FACTURA: '🏢', BOLETA: '🧾', RECIBO: '📝' }[clave] || '🧾'"></span><b x-text="texto"></b></button>
                </template>
            </div>
            <p class="aviso" style="margin: 8px 0 0" x-show="f.tipo === 'FACTURA' && vendedorRecibir()?.tipo_documento !== 'RUC'">Ojo: solo hay factura si el vendedor tiene RUC.</p>
            <div class="comprobante-datos">
                {{-- la foto: un recuadro grande para tocar; con la foto no hace falta la serie ni el número --}}
                <label class="foto-caja" :class="{ con: f.foto }">
                    <input type="file" accept="image/*" @change="elegirFoto($event)">
                    <template x-if="f.foto"><img :src="f.foto" alt="Foto del comprobante"></template>
                    <template x-if="!f.foto"><span><b>📷</b>Sacar o elegir la foto<small>con la foto no hace falta la serie ni el número</small></span></template>
                    <em x-show="f.foto">Cambiar foto</em>
                </label>
                <div class="comprobante-campos">
                    <div class="dos" x-show="f.tipo && f.tipo !== 'RECIBO'">
                        <div><label x-text="'Serie' + (f.foto ? ' (opcional)' : '')"></label><input x-model="f.serie" maxlength="4" :placeholder="f.tipo === 'FACTURA' ? 'F001' : 'B001'" @input="f.serie = f.serie.toUpperCase()"></div>
                        <div><label x-text="'Número' + (f.foto ? ' (opcional)' : '')"></label><input x-model="f.numero" maxlength="8" placeholder="00001234" @input="f.numero = f.numero.replace(/\D/g, '')"></div>
                    </div>
                    <label>Fecha del comprobante</label><input type="date" x-model="f.fecha" :max="hoy()">
                </div>
            </div>
        </div></template>

        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        {{-- abajo, siempre a la vista: el total y guardar --}}
        <div class="pie pie-compra">
            <div class="total-compra">
                <span x-text="f.modo === 'orden' ? 'Costo estimado' : 'Total pagado'"></span>
                <b x-text="soles(f.modo === 'orden' ? costoEstimadoOrden() : totalCompra())"></b>
                <small x-show="f.modo === 'directa' && f.tipo === 'FACTURA'" x-text="'incluye IGV ' + soles(totalCompra() - Math.round(totalCompra() / 1.18 * 100) / 100)"></small>
                <small x-show="f.modo === 'orden'">según la última compra</small>
            </div>
            <div class="espacio"></div>
            <button class="btn gris" @click="ventana = null">Cancelar</button>
            <button class="btn" @click="guardarCompra()" :disabled="!!f.enviando"
                    x-text="f.enviando ? 'Guardando…' : (f.modo === 'orden' ? 'Anotar pedido' : '✓ Guardar y subir stock')"></button>
        </div>
    </div>

    {{-- llegó la compra: comprobante, lo pagado y la foto --}}
    <div class="ventana ancha" x-show="ventana === 'recibir'">
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
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
            <div><label x-text="'Serie' + (f.foto ? ' (opcional)' : '')"></label><input x-model="f.serie" maxlength="4" :placeholder="f.tipo === 'FACTURA' ? 'F001' : 'B001'" @input="f.serie = f.serie.toUpperCase()"></div>
            <div><label x-text="'Número' + (f.foto ? ' (opcional)' : '')"></label><input x-model="f.numero" maxlength="8" placeholder="00001234" @input="f.numero = f.numero.replace(/\D/g, '')"></div>
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
        <label>Foto del comprobante <span class="sub">(con la foto no hace falta escribir la serie ni el número)</span></label>
        <input type="file" accept="image/*" @change="elegirFoto($event)">
        <img x-show="f.foto" :src="f.foto" alt="Foto del comprobante" style="max-height: 160px; margin-top: 8px; border-radius: 8px">
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="ventana = null">Cancelar</button>
            <button class="btn" @click="recibirOrden()" :disabled="!!f.enviando" x-text="f.enviando ? 'Guardando…' : 'Registrar y subir stock'"></button></div>
    </div>

    {{-- cliente (a quién le vendemos) --}}
    <div class="ventana" x-show="ventana === 'cliente'">
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
        <h3 x-text="f.id ? 'Editar cliente' : 'Nuevo cliente'"></h3>
        <label>¿Qué tipo de cliente es?</label>
        <div class="opciones">
            <button :class="{ activo: f.tipo === 'MAYORISTA' }" @click="f.tipo = 'MAYORISTA'">Mayorista (compra para revender)</button>
            <button :class="{ activo: f.tipo === 'DIRECTO' }" @click="f.tipo = 'DIRECTO'">Cliente directo (para su casa)</button></div>
        <p class="sub" style="margin: 6px 0 0">El precio de cada venta sale solo según este tipo.</p>
        {{-- primero el DNI/RUC: al completarlo, el nombre se pone solo (RENIEC/SUNAT) --}}
        <label>DNI o RUC (opcional)</label>
        <input x-model="f.documento" maxlength="11" inputmode="numeric" @input="f.documento = f.documento.replace(/\D/g, ''); alEscribirDocumento()" placeholder="8 dígitos (DNI) u 11 (RUC): el nombre sale solo">
        <p class="doc-estado" x-show="f.doc" :class="{ ok: f.doc?.nombre, mal: f.doc && !f.doc.cargando && !f.doc.nombre }"
           x-text="!f.doc ? '' : f.doc.cargando ? 'Buscando el nombre…'
                 : f.doc.nombre ? '✓ ' + (FUENTE_DOC[f.doc.fuente] || 'Encontrado') + (f.doc.puesto === false ? ': ' + f.doc.nombre + ' (no se cambió el nombre que escribiste)' : '')
                 : (f.doc.error || (f.doc.sin_token ? 'Falta el token de RENIEC (DECOLECTA_TOKEN): escribe el nombre a mano.' : 'No se encontró: escribe el nombre a mano.'))"></p>
        <p class="doc-estado mal" x-show="f.doc?.fuente === 'cliente' && f.doc?.cliente_id !== f.id">⚠ Ya hay un cliente con ese documento.</p>
        <label>Nombre de la persona o del negocio</label><input x-model="f.nombre" placeholder="Ej.: Puesto Mercado Túpac Amaru">
        <div class="dos">
            <div><label>Teléfono (opcional)</label><input x-model="f.telefono"></div>
            <div><label>Correo (opcional)</label><input type="email" x-model="f.email" placeholder="Para mandarle la nota de venta"></div>
        </div>
        <label style="display:flex; gap:8px; align-items:center" x-show="f.id"><input type="checkbox" x-model="f.activo" style="width:auto"> Activo (si no, ya no sale para vender; sus ventas antiguas se quedan)</label>
        <div class="aviso error" x-show="f.error" x-text="f.error"></div>
        <div class="pie"><button class="btn gris" @click="cerrarCliente()">Cancelar</button>
            <button class="btn" @click="guardarCliente()" :disabled="!!f.enviando" x-text="f.enviando ? 'Guardando…' : 'Guardar'"></button></div>
    </div>

    {{-- vendedor de insumos --}}
    <div class="ventana" x-show="ventana === 'vendedor'">
        <button class="cerrar-ventana" @click="cerrarVentana($el)" aria-label="Cerrar" title="Cerrar (Esc)">✕</button>
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
