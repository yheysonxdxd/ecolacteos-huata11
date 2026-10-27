/*
 * Ecolácteos Huata · Oficina (web para Admin, Compras y el laboratorio de Calidad).
 *
 * Usa las MISMAS rutas /api/v1 que la app del celular: mismos permisos por
 * rol, mismas validaciones y mismos datos. La sesión (token) vive solo en
 * esta pestaña del navegador (sessionStorage): al cerrarla hay que volver a
 * entrar, porque la computadora de la oficina puede ser compartida.
 */

const API = '/api/v1';

// ------------------------------------------------------------------ formato
const soles = (v) => 'S/ ' + Number(v || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const num = (v, d = 1) => Number(v || 0).toLocaleString('es-PE', { maximumFractionDigits: d });
const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'set', 'oct', 'nov', 'dic'];
const fechaCorta = (iso) => {
  if (!iso) return '';
  const [a, m, d] = String(iso).slice(0, 10).split('-');
  return `${Number(d)} ${MESES[Number(m) - 1]}`;
};
const hora = (iso) => String(iso || '').slice(11, 16);
const TIPO_CLIENTE = { MAYORISTA: 'Mayorista', DIRECTO: 'Cliente directo', PLANTA: 'Proveedor de leche' };
const ROLES = { ACOPIADOR: 'Acopiador', CALIDAD: 'Calidad', OPERARIO: 'Operario', COMPRAS: 'Compras', ADMIN: 'Administrador', PRODUCTOR: 'Productor' };
/** Litros que va mostrando el tanque de Producir mientras cuenta (ver animarUso). */
const ANIM_USO = { valor: 0, cuadro: 0 };
const esCorreo = (s) => /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(String(s || '').trim());
// los compañeros entran por http://IP:8000, donde crypto.randomUUID no existe (solo en https o localhost)
const nuevoUuid = () => crypto.randomUUID?.() ?? '10000000-1000-4000-8000-100000000000'.replace(/[018]/g,
  (c) => (c ^ (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (c / 4)))).toString(16));
/** "2026-10-21 11:40:05" en hora de Perú, como manda la app. */
const ahoraLima = () => new Date().toLocaleString('sv-SE', { timeZone: 'America/Lima' });

// mapa de Huata (Leaflet): fuera del estado de Alpine para que no lo envuelva en proxies
const MAPA = { m: null, capa: null, encuadrado: false };
// en el satélite hacen falta colores bien distintos (en la app son tonos de verde)
const COLOR_RUTA = { 'C-01': '#4cc38a', 'C-02': '#5fb3ff', 'C-03': '#e8b44c', 'M-01': '#f07ad8' };
const colorRuta = (codigo) => COLOR_RUTA[codigo] || '#ffffff';

function oficina() {
  return {
    // --------------------------------------------------------------- sesión
    token: sessionStorage.getItem('token'),
    usuario: JSON.parse(sessionStorage.getItem('usuario') || 'null'),
    login: { dni: '', clave: '', ver: false, error: null, enviando: false },

    // ------------------------------------------------------------- navegación
    seccion: null,
    cargando: false,
    error: null,
    aviso: null,
    d: {},          // datos de la sección abierta
    dDe: null,      // de qué sección son esos datos (mientras carga otra, d sigue siendo de la anterior)
    ventana: null,  // formulario/diálogo abierto
    pregunta: null, // confirmación propia (en vez del confirm() gris del navegador), ver preguntar()
    f: {},          // campos del formulario abierto
    fOrden: null,   // orden a medio llenar mientras se agrega un vendedor

    // filtros de secciones
    periodo: 'semana',
    semana: null,
    dinero: 'ventas',
    semanaRech: null, // rechazos de calidad: semana a la vista (null = esta semana)
    padron: 'proveedores',
    adulteracion: 'todos',
    buscar: '',
    kardex: { tipo: 'insumo', id: null, desde: '', hasta: '', solo: false }, // tipo: insumo | producto | leche · solo: un producto y no toda su familia
    recetaAbierta: null, // receta abierta en el recetario (Producir > Recetario)
    recetaFiltro: 'TODOS', // TODOS · QUESO · YOGURT · OTRO
    recetaLitros: 100, // la ficha recalcula los insumos para estos litros
    varios: {},     // producir varios a la vez: producto => cuánto (vacío o 0 = no se hace)
    familiaAbierta: {}, // Producir: base => true si su lista de sabores está desplegada
    usoAnimado: 0,  // litros que muestra el tanque: van contando hasta lo que se usa de verdad
    mapaFecha: '',  // vacío = hoy
    mesCompras: '', // vacío = mes actual (2026-10)
    mesVentas: '',
    buscarVendedor: '',
    buscarCliente: '',
    buscarMuestra: '',
    muestra: null,  // análisis a medio escribir: { entrega, tipo: CAMPO|PLANTA, grasa, proteina, …, enviando, error }
    verClientesInactivos: false,
    fVenta: null,   // la venta a medias mientras se agrega o edita un cliente
    verDesactivados: false,
    vendedoresAbiertos: (() => { try { return localStorage.getItem('vendedoresAbiertos') === '1'; } catch { return false; } })(),
    concFecha: '',  // vacío = último día con acopio
    concLitros: {}, // vehiculo_id => litros que llegaron a planta (lo que se está escribiendo)
    mapaEditar: false, // arrastrar comunidades a su lugar real
    cfg: {},        // configuración: clave => valor que se está escribiendo
    cfgLeche: { precio: '', desde: '' },
    cfgVenta: {},   // 'producto_id.TIPO' => precio que se está escribiendo
    cfgInsumos: {}, nuevoInsumo: { nombre: '', unidad: 'kg', minimo: '' },
    cfgVeh: {}, cfgCom: {}, nuevaCom: {}, nuevoVeh: { codigo: '', tipo: 'CARRO', acopiador_id: null },

    soles, num, fechaCorta, hora, TIPO_CLIENTE, ROLES, esCorreo,

    iniciar() {
      // si la ventana del cliente se cierra con Esc o clic afuera, se vuelve a la venta a medias
      this.$watch('ventana', (v) => { if (!v && this.fVenta) { this.f = this.fVenta; this.fVenta = null; } });
      if (this.usuario) this.ir(this.menu()[0].pestanas[0].id);
    },

    /** Para dibujar un producto del que solo se tiene el nombre (lotes, pérdidas): adivina si es queso o yogurt. */
    productoVisual(nombre) {
      const n = String(nombre || '');
      return { nombre: n, tipo: /queso/i.test(n) ? 'QUESO' : /yogurt/i.test(n) ? 'YOGURT' : 'OTRO', base_id: /yogurt de /i.test(n) ? 1 : null };
    },
    /** "hace 3 h", "hace 2 días" desde una fecha y hora. */
    haceCuanto(iso) {
      const min = Math.max(0, (Date.now() - new Date(String(iso).replace(' ', 'T')).getTime()) / 60000);
      if (min < 60) return 'hace ' + Math.round(min) + ' min';
      if (min < 60 * 24) return 'hace ' + Math.round(min / 60) + ' h';
      return 'hace ' + Math.round(min / 1440) + (Math.round(min / 1440) === 1 ? ' día' : ' días');
    },
    /** Precios vigentes [{ producto, tipo_cliente, precio }] → una fila por producto: { producto, MAYORISTA, DIRECTO, PLANTA }. */
    preciosPorProducto(precios) {
      const filas = {};
      precios.forEach((p) => { (filas[p.producto] ??= { producto: p.producto })[p.tipo_cliente] = p.precio; });
      return Object.values(filas);
    },
    /** Color de la ruta de un vehículo (el mismo del mapa de Huata). */
    colorRuta(codigo) { return COLOR_RUTA[codigo] || '#8394a4'; },
    // ------------------------------------------------- gráficos chicos (SVG)
    /** Barras por día (viewBox 0 0 640 112, ancho y bajo): [{ fecha, valor, clase? }]; la más alta llega arriba y debajo va el día. */
    svgBarrasDia(serie, unidad = 'L') {
      if (!serie?.length) return '';
      const max = Math.max(...serie.map((x) => x.valor), 1);
      const ancho = 620 / serie.length, barra = Math.min(46, ancho * 0.58);
      const DIAS = ['do', 'lu', 'ma', 'mi', 'ju', 'vi', 'sá'];
      return `<svg viewBox="0 0 640 112" role="img" aria-label="Litros por día">${serie.map((x, k) => {
        const h = Math.max(2, (x.valor / max) * 70), cx = 10 + ancho * k + ancho / 2;
        // la etiqueta de abajo: la que traiga (ventas) o el día de la fecha
        const [a, m, d] = String(x.fecha || '').slice(0, 10).split('-').map(Number);
        const dia = x.etiqueta ?? (serie.length <= 8 ? DIAS[new Date(a, m - 1, d).getDay()] + ' ' + d : String(d));
        const cuanto = unidad === 'soles' ? soles(x.valor) : num(x.valor, 0) + ' ' + unidad;
        return `<g><title>${x.etiqueta ?? fechaCorta(x.fecha)}: ${cuanto}</title>
          <rect x="${cx - barra / 2}" y="${88 - h}" width="${barra}" height="${h}" rx="4" class="${x.clase || ''}"/>
          ${serie.length <= 8 ? `<text x="${cx}" y="${82 - h}" class="g-valor">${num(x.valor, 0)}</text>` : ''}
          <text x="${cx}" y="106" class="g-eje">${dia}</text></g>`;
      }).join('')}</svg>`;
    },
    /** Anillo con partes [{ valor, color, nombre }] y un número al centro (viewBox 0 0 120 120). */
    svgDona(partes, centro, debajo = '') {
      const total = partes.reduce((t, x) => t + x.valor, 0) || 1;
      const R = 46, C = 2 * Math.PI * R;
      let va = 0;
      return `<svg viewBox="0 0 120 120" role="img" aria-label="${partes.map((x) => x.nombre + ' ' + num(x.valor, 1)).join(', ')}">
        <circle cx="60" cy="60" r="${R}" fill="none" stroke="var(--superficie-2)" stroke-width="13"/>
        ${partes.filter((x) => x.valor > 0).map((x) => {
          const largo = (x.valor / total) * C;
          const s = `<circle cx="60" cy="60" r="${R}" fill="none" stroke="${x.color}" stroke-width="13"
            stroke-dasharray="${Math.max(0.1, largo - (partes.filter((y) => y.valor > 0).length > 1 ? 1.5 : 0))} ${C}"
            stroke-dashoffset="${-va}" transform="rotate(-90 60 60)"/>`;
          va += largo;
          return s;
        }).join('')}
        <text x="60" y="62" class="dona-centro">${centro}</text><text x="60" y="80" class="dona-debajo">${debajo}</text></svg>`;
    },
    /** Ícono de línea de cada opción del menú (SVG 24×24, toma el color del texto). */
    iconoMenu(id) {
      const T = {
        acopio: '<path d="M3 16V8h11v8"/><path d="M14 11h4l3 3v2h-7"/><circle cx="7" cy="17.5" r="2"/><circle cx="17" cy="17.5" r="2"/>',
        laboratorio: '<path d="M9 3h6"/><path d="M10 3v6l-5 9a2 2 0 0 0 1.8 3h10.4a2 2 0 0 0 1.8-3l-5-9V3"/><path d="M7.5 15h9"/>',
        'calidad-grupo': '<path d="M9 3h6"/><path d="M10 3v6l-5 9a2 2 0 0 0 1.8 3h10.4a2 2 0 0 0 1.8-3l-5-9V3"/><path d="M7.5 15h9"/>',
        'dinero-grupo': '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6.5 9.5h.01M17.5 14.5h.01"/>',
        'padron-grupo': '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 4.5a3 3 0 0 1 0 6M18 14a5 5 0 0 1 3 6"/>',
        'config-grupo': '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M2 12h3M19 12h3M4.9 19.1 7 17M17 7l2.1-2.1"/>',
        'ordenes-grupo': '<path d="M3 4h2l2.4 11h11l2-8H6.2"/><circle cx="9" cy="19.5" r="1.5"/><circle cx="17" cy="19.5" r="1.5"/>',
        almacen: '<path d="M3 9l9-5 9 5v11H3z"/><path d="M8 20v-7h8v7"/><path d="M8 16h8"/>',
        'formulas-grupo': '<path d="M5 11h14v3a7 7 0 0 1-14 0z"/><path d="M9 7c0-1.5 1-2 1-3.5M13 7c0-1.5 1-2 1-3.5"/><path d="M3 11h18"/>',
        vender: '<path d="M20 12.6 12.6 20a2 2 0 0 1-2.8 0L3 13.2V3h10.2L20 9.8a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        kardex: '<path d="M4 4h16v16H4z"/><path d="M4 9h16M4 14h16M10 9v11"/>',
        salir: '<path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4"/><path d="M10 16l-4-4 4-4M6 12h10"/>',
      };
      return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">${T[id] || '<circle cx="12" cy="12" r="4"/>'}</svg>`;
    },
    /**
     * Menú: cada opción agrupa una o más pantallas que se muestran como
     * pestañas arriba (ej. Calidad → Por vehículo | Adulteraciones).
     */
    menu() {
      // el analista registra los análisis con su propio usuario (el administrador solo los supervisa)
      if (this.usuario?.rol === 'CALIDAD') {
        return [{ id: 'laboratorio', nombre: 'Calidad', grupo: 'Laboratorio',
          pestanas: [{ id: 'muestreo', nombre: 'Muestreo' }, { id: 'infractores', nombre: 'Infractores' }, { id: 'historial-calidad', nombre: 'Historial' }] }];
      }
      const admin = this.usuario?.rol === 'ADMIN';
      // en el orden del día de la planta: comprar → guardar → producir → vender; el kardex (para revisar) al final
      const compras = [
        { id: 'ordenes-grupo', nombre: 'Compras de insumos', grupo: 'Compras y almacén',
          pestanas: [{ id: 'ordenes', nombre: 'Compras de insumos' }] }, // órdenes y vendedores en una sola pantalla
        { id: 'almacen', nombre: 'Almacén', grupo: 'Compras y almacén',
          pestanas: [{ id: 'inventario', nombre: 'Stock y avisos' }, { id: 'mermas', nombre: 'Pérdidas' }] },
        { id: 'formulas-grupo', nombre: 'Fórmulas y producción', grupo: 'Compras y almacén',
          // los lotes son de planta: los ve e inicia/cierra el administrador (Compras solo ve las fórmulas)
          pestanas: [{ id: 'formulas', nombre: 'Producir y recetas' }, ...(admin ? [{ id: 'lotes', nombre: 'Lotes en producción' }] : [])] },
        { id: 'vender', nombre: 'Vender', grupo: 'Compras y almacén',
          pestanas: [{ id: 'vender', nombre: 'Nueva venta' }, { id: 'movimientos', nombre: 'Ventas y notas' }] },
        { id: 'kardex', nombre: 'Kardex · entradas y salidas', grupo: 'Compras y almacén', pestanas: [{ id: 'kardex', nombre: 'Kardex · entradas y salidas' }] },
      ];
      if (!admin) return compras;
      return [
        { id: 'acopio', nombre: 'Acopio', grupo: 'Administración',
          pestanas: [{ id: 'totales', nombre: 'Totales' }, { id: 'mapa', nombre: 'Mapa de Huata' },
            { id: 'conciliacion', nombre: 'Conciliación campo vs. planta' }] },
        // llega la leche → se analiza → se paga; el padrón (se toca poco) al final
        // la calidad de cada vehículo se ve en Totales, junto a sus litros
        { id: 'calidad-grupo', nombre: 'Calidad', grupo: 'Administración',
          pestanas: [{ id: 'adulteraciones', nombre: 'Adulteraciones' }, { id: 'rechazos', nombre: 'Rechazos y capacitación' }] },
        { id: 'dinero-grupo', nombre: 'Dinero', grupo: 'Administración',
          pestanas: [{ id: 'dinero', nombre: 'Ventas y pagos' }, { id: 'costos', nombre: 'Costos y rendimiento' }] },
        { id: 'padron-grupo', nombre: 'Padrón', grupo: 'Administración',
          pestanas: [{ id: 'padron', nombre: 'Proveedores y trabajadores' }, { id: 'solicitudes', nombre: 'Cambios de comunidad' },
            { id: 'sesiones', nombre: 'Sesiones abiertas' }] },
        // lo que antes solo se cambiaba en la base: precios, límites de calidad, datos de la empresa
        { id: 'config-grupo', nombre: 'Configuración', grupo: 'Administración',
          pestanas: [{ id: 'config-precios', nombre: 'Precios' }, { id: 'config-productos', nombre: 'Productos' }, { id: 'config-calidad', nombre: 'Calidad' },
            { id: 'config-acopio', nombre: 'Acopio y planta' }, { id: 'config-almacen', nombre: 'Almacén' },
            { id: 'config-vehiculos', nombre: 'Vehículos y rutas' }, { id: 'config-empresa', nombre: 'Empresa' },
            { id: 'config-historial', nombre: 'Historial de cambios' }] },
        ...compras,
      ];
    },
    /** Opción del menú en la que está la pantalla abierta. */
    opcionActual() { return this.menu().find((m) => m.pestanas.some((p) => p.id === this.seccion)); },

    // ------------------------------------------------------------------- API
    async api(ruta, cuerpo = null) {
      const r = await fetch(API + ruta, {
        method: cuerpo ? 'POST' : 'GET',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: 'Bearer ' + this.token },
        body: cuerpo ? JSON.stringify(cuerpo) : null,
      });
      if (r.status === 401) { this.salir('Tu sesión venció o tu usuario fue desactivado. Vuelve a ingresar.'); throw new Error('sesión vencida'); }
      const j = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(j.message || 'El servidor respondió ' + r.status);
      return j;
    },

    async entrar() {
      const l = this.login;
      if (!/^\d{8}$/.test(l.dni)) { l.error = 'El DNI debe tener 8 dígitos'; return; }
      if (!l.clave) { l.error = 'Escribe tu contraseña'; return; }
      l.enviando = true; l.error = null;
      try {
        const r = await fetch(API + '/login', {
          method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
          body: JSON.stringify({ dni: l.dni, password: l.clave, dispositivo: 'web-oficina' }),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.message || 'No se pudo ingresar');
        if (!['ADMIN', 'COMPRAS', 'CALIDAD'].includes(j.rol)) {
          throw new Error('La web de oficina es para Administración, Compras y Calidad. Tu rol (' + (ROLES[j.rol] || j.rol) + ') usa la app del celular.');
        }
        this.token = j.token;
        this.usuario = { nombre: j.usuario?.nombre, rol: j.rol };
        sessionStorage.setItem('token', this.token);
        sessionStorage.setItem('usuario', JSON.stringify(this.usuario));
        l.clave = '';
        this.ir(this.menu()[0].pestanas[0].id);
      } catch (e) {
        l.error = e.message;
      } finally {
        l.enviando = false;
      }
    },

    async salir(mensaje = null) {
      if (this.token && !mensaje) fetch(API + '/logout', { method: 'POST', headers: { Authorization: 'Bearer ' + this.token } }).catch(() => {});
      sessionStorage.clear();
      this.token = null; this.usuario = null; this.seccion = null; this.d = {};
      this.login.error = mensaje;
    },

    // ------------------------------------------------------------- secciones
    async ir(id) {
      this.seccion = id; this.aviso = null; this.buscar = '';
      await this.cargar();
    },

    async cargar() {
      this.cargando = true; this.error = null;
      if (this.dDe !== this.seccion) this.dDe = null; // se quita la pantalla anterior antes de que lleguen los datos nuevos
      try {
        switch (this.seccion) {
          case 'totales': this.d = await this.api('/admin/totales?periodo=' + this.periodo); break;
          case 'dinero':
            this.d = this.dinero === 'ventas'
              ? await this.api('/admin/negocio?periodo=' + this.periodo)
              : await this.api('/admin/pagos' + (this.semana ? '?semana=' + this.semana : ''));
            break;
          case 'padron': this.d = await this.api('/admin/padron'); break;
          case 'mapa': this.d = await this.api('/admin/mapa' + (this.mapaFecha ? '?fecha=' + this.mapaFecha : '')); break;
          case 'conciliacion':
            this.d = await this.api('/admin/conciliacion' + (this.concFecha ? '?fecha=' + this.concFecha : ''));
            this.concFecha = this.d.fecha;
            this.concLitros = Object.fromEntries(this.d.filas.map((f) => [f.vehiculo_id, f.litros_planta ?? '']));
            break;
          case 'rechazos':
            this.d = await this.api('/admin/rechazos' + (this.semanaRech ? '?semana=' + this.semanaRech : ''));
            this.semanaRech = this.d.inicio;
            break;
          case 'adulteraciones': case 'solicitudes': case 'costos': case 'sesiones':
            this.d = await this.api('/admin/' + this.seccion); break;
          case 'muestreo': {
            const [m, rangos] = await Promise.all([this.api('/calidad/muestreo'), this.api('/calidad/parametros')]);
            this.d = { ...m, rangos };
            if (this.muestra && !m.entregas.some((e) => e.id === this.muestra.entrega.id)) this.muestra = null;
            break;
          }
          case 'infractores': this.d = await this.api('/calidad/infractores'); break;
          case 'historial-calidad': this.d = await this.api('/calidad/historial'); break;
          case 'vender': this.d = await this.api('/compras/ventas-datos'); this.nuevaVenta(); break;
          case 'inventario': this.d = await this.api('/compras/inventario'); break;
          case 'ordenes': {
            // d.vendedores: los activos, para elegir en la orden · todosVendedores: la lista de abajo, con sus compras
            const [ordenes, inv, vend] = await Promise.all([
              this.api('/compras/ordenes?mes=' + (this.mesCompras || 'actual')), this.api('/compras/inventario'), this.api('/compras/vendedores')]);
            this.d = { ...ordenes, inv, todosVendedores: vend.vendedores };
            this.mesCompras = ordenes.periodo.mes;
            break;
          }
          case 'movimientos':
            this.d = await this.api('/compras/movimientos?mes=' + (this.mesVentas || 'actual'));
            this.mesVentas = this.d.periodo.mes;
            break;
          case 'config-precios': case 'config-calidad': case 'config-acopio': case 'config-empresa': case 'config-historial':
            this.d = await this.api('/admin/configuracion');
            this.cfg = Object.fromEntries(this.d.campos.map((c) => [c.clave, c.valor]));
            this.cfgLeche = { precio: '', desde: this.hoy() };
            this.cfgVenta = Object.fromEntries(this.d.precios_venta.flatMap((p) =>
              Object.entries(p.precios).map(([tipo, v]) => [p.producto_id + '.' + tipo, v ?? ''])));
            break;
          case 'config-almacen': case 'config-vehiculos': case 'config-productos':
            this.d = await this.api('/admin/configuracion/maestros');
            this.cfgInsumos = Object.fromEntries(this.d.insumos.map((i) => [i.id, { nombre: i.nombre, unidad: i.unidad, minimo: i.minimo }]));
            this.nuevoInsumo = { nombre: '', unidad: 'kg', minimo: '' };
            this.cfgVeh = Object.fromEntries(this.d.vehiculos.map((v) => [v.id, { codigo: v.codigo, tipo: v.tipo, acopiador_id: v.acopiador_id }]));
            this.cfgCom = Object.fromEntries([...this.d.vehiculos.flatMap((v) => v.ruta.map((c) => [c.id, { nombre: c.nombre, vehiculo_id: v.id }])),
              ...this.d.sueltas.map((c) => [c.id, { nombre: c.nombre, vehiculo_id: null }])]);
            this.nuevaCom = {};
            this.nuevoVeh = { codigo: '', tipo: 'CARRO', acopiador_id: null };
            break;
          case 'formulas': this.d = await this.api('/compras/formulas'); break;
          case 'mermas': this.d = await this.api('/compras/mermas'); break;
          case 'lotes': this.d = await this.api('/planta/lotes'); this.cierre = {}; break;
          case 'kardex': {
            const inv = await this.api('/compras/inventario');
            const k = this.kardex;
            // insumos o productos: si lo elegido ya no está en la lista, el primero
            const lista = k.tipo === 'producto' ? inv.productos : inv.insumos;
            if (k.tipo !== 'leche' && !lista.some((i) => i.id === k.id)) k.id = lista[0]?.id ?? null;
            this.d = { inv, ...(await this.datosKardex()) };
            break;
          }
        }
        this.dDe = this.seccion;
      } catch (e) {
        this.error = e.message;
      } finally {
        this.cargando = false;
      }
      // Leaflet necesita que el contenedor ya se vea para medirlo
      if (this.seccion === 'mapa' && !this.error) this.$nextTick(() => this.dibujarMapa());
    },

    cambiar(campo, valor) { this[campo] = valor; this.cargar(); },
    maximo(lista, campo) { return Math.max(1, ...lista.map((x) => Number(x[campo]) || 0)); },

    // ---------------------------------------------------------- mapa de Huata
    /** ok: todos registrados · parcial: algunos · nada: ninguno · vacia: sin proveedores */
    estadoComunidad(c) {
      if (!c.proveedores) return 'vacia';
      if (c.registrados >= c.proveedores) return 'ok';
      return c.registrados > 0 ? 'parcial' : 'nada';
    },
    resumenMapa() {
      const cs = this.d.comunidades || [];
      return {
        registrados: cs.reduce((t, c) => t + c.registrados, 0), proveedores: cs.reduce((t, c) => t + c.proveedores, 0),
        litros: cs.reduce((t, c) => t + c.litros, 0), aproximadas: cs.filter((c) => c.aproximada).length,
      };
    },
    rutaTexto(v) {
      const porId = Object.fromEntries((this.d.comunidades || []).map((c) => [c.id, c.nombre]));
      return v.ruta.map((id) => porId[id] || '?').join(' → ') + (v.circular ? ' → (vuelve)' : '');
    },
    colorRuta,
    dibujarMapa() {
      const el = document.getElementById('mapa-huata');
      if (!el || !window.L || !this.d.comunidades) return;
      if (!MAPA.m) {
        MAPA.m = L.map(el);
        const satelite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
          { maxZoom: 18, attribution: 'Imágenes © Esri' });
        const calles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' });
        satelite.addTo(MAPA.m);
        L.control.layers({ 'Satélite': satelite, 'Calles': calles }, null, { position: 'topright' }).addTo(MAPA.m);
        L.control.scale({ imperial: false }).addTo(MAPA.m);
        MAPA.capa = L.layerGroup().addTo(MAPA.m);
      }
      MAPA.capa.clearLayers();
      const porId = Object.fromEntries(this.d.comunidades.map((c) => [c.id, c]));

      // rutas de los vehículos (con un borde oscuro para que se vean sobre el satélite)
      this.d.vehiculos.forEach((v) => {
        const pts = v.ruta.map((id) => porId[id]).filter(Boolean).map((c) => [c.latitud, c.longitud]);
        if (v.circular && pts.length > 2) pts.push(pts[0]);
        if (pts.length < 2) return;
        L.polyline(pts, { color: '#0b141c', weight: 8, opacity: 0.55, interactive: false }).addTo(MAPA.capa);
        L.polyline(pts, { color: colorRuta(v.codigo), weight: 4, opacity: 0.95, dashArray: v.circular ? '10 7' : null })
          .bindTooltip(v.codigo + (v.acopiador ? ' · ' + v.acopiador : ''), { sticky: true }).addTo(MAPA.capa);
      });

      // comunidades
      this.d.comunidades.forEach((c) => {
        const clases = ['pin', this.estadoComunidad(c), c.aproximada ? 'aprox' : '', this.mapaEditar ? 'editando' : ''].join(' ');
        const icon = L.divIcon({
          className: 'pin-huata', iconSize: [40, 40], iconAnchor: [20, 20],
          html: '<div class="' + clases + '">' + (c.proveedores ? c.registrados + '/' + c.proveedores : '') + '</div>',
        });
        const m = L.marker([c.latitud, c.longitud], { icon, draggable: this.mapaEditar, autoPan: true }).addTo(MAPA.capa);
        m.bindTooltip(c.nombre, { permanent: true, direction: 'bottom', offset: [0, 18], className: 'nombre-huata' });
        m.bindPopup('<b>' + c.nombre + '</b><br>' +
          (c.proveedores ? c.registrados + ' de ' + c.proveedores + ' proveedores registrados<br>' + num(c.litros) + ' L acopiados' : 'Sin proveedores') +
          (c.vias ? '<br><span class="gris">Vías: ' + c.vias + '</span>' : '') +
          (c.aproximada ? '<br><span class="ojo">Ubicación aproximada: corrígela con «Corregir ubicaciones».</span>' : ''));
        m.on('dragend', () => this.ubicarComunidad(c, m.getLatLng()));
      });

      if (!MAPA.encuadrado) {
        MAPA.m.fitBounds(this.d.comunidades.map((c) => [c.latitud, c.longitud]), { padding: [50, 50] });
        MAPA.encuadrado = true;
      }
      setTimeout(() => MAPA.m.invalidateSize(), 60);
    },
    alternarEdicionMapa() { this.mapaEditar = !this.mapaEditar; this.dibujarMapa(); },
    async ubicarComunidad(c, ll) {
      try {
        await this.api(`/admin/comunidades/${c.id}/ubicacion`, { latitud: ll.lat, longitud: ll.lng });
        c.latitud = ll.lat; c.longitud = ll.lng; c.aproximada = false;
        this.aviso = { texto: `✓ ${c.nombre} quedó en su nuevo lugar` };
      } catch (e) {
        this.aviso = { error: true, texto: 'No se pudo guardar la ubicación: ' + e.message };
      }
      this.dibujarMapa();
    },

    // --------------------------------------------------------------- padrón
    proveedoresFiltrados() {
      const b = this.buscar.trim().toLowerCase();
      const l = this.d.proveedores || [];
      return b ? l.filter((p) => [p.nombre, p.dni, p.comunidad, p.vehiculo].join(' ').toLowerCase().includes(b)) : l;
    },

    async abrirNuevoProveedor() {
      this.f = { nombre: '', dni: '', comunidad_id: null, vehiculo_id: null, promedio: '', vacas: '', con_acceso: false, clave: '', error: null, enviando: false };
      try {
        const b = await this.api('/sync/bootstrap');
        this.f.comunidades = b.comunidades || [];
        this.f.vehiculos = b.vehiculos || [];
        this.ventana = 'proveedor';
      } catch (e) { this.aviso = { error: true, texto: e.message }; }
    },
    elegirComunidad(c) {
      this.f.comunidad_id = c.id;
      const v = this.f.vehiculos.find((v) => (v.orden_ruta || []).map(Number).includes(Number(c.id)));
      if (v) this.f.vehiculo_id = v.id;
    },
    async guardarProveedor() {
      const f = this.f;
      if (f.nombre.trim().split(/\s+/).length < 2) return (f.error = 'Escribe nombre y apellidos');
      if (!/^\d{8}$/.test(f.dni)) return (f.error = 'El DNI debe tener 8 dígitos');
      if (!f.comunidad_id) return (f.error = 'Elige la comunidad');
      if (f.con_acceso && f.clave.length < 6) return (f.error = 'La clave debe tener al menos 6 caracteres');
      await this.enviar('/admin/proveedores', {
        nombre: f.nombre.trim(), dni: f.dni, comunidad_id: f.comunidad_id, vehiculo_id: f.vehiculo_id,
        promedio_litros: f.promedio ? Number(f.promedio) : null, vacas_ordeno: f.vacas ? Number(f.vacas) : null,
        con_acceso: f.con_acceso, clave: f.con_acceso ? f.clave : null,
      }, `✓ ${f.nombre.trim()} agregado al padrón` + (f.con_acceso ? ' · entra a la app con su DNI' : ''));
    },

    // -------------------------------------------------------- adulteraciones
    adulteradosFiltrados() {
      const b = this.buscar.trim().toLowerCase();
      return (this.d.proveedores || [])
        .filter((p) => this.adulteracion === 'todos' || (this.adulteracion === 'baja') === p.de_baja)
        .filter((p) => !b || [p.nombre, p.dni, p.comunidad, p.vehiculo].join(' ').toLowerCase().includes(b));
    },
    // -------------------------------------------------------- rechazos y capacitación
    abrirCapacitar(r) {
      const pendientes = (this.d.filas || []).filter((x) => x.proveedor_id === r.proveedor_id && !x.capacitado_en && x.fecha <= r.fecha).length;
      this.f = { rechazo: r, nota: '', pendientes, error: null, enviando: false };
      this.ventana = 'capacitar';
    },
    async guardarCapacitado() {
      const f = this.f;
      f.enviando = true; f.error = null;
      try {
        const r = await this.api(`/admin/rechazos/${f.rechazo.id}/capacitado`, { nota: f.nota.trim() || null });
        this.ventana = null;
        this.aviso = { texto: `✓ Anotado: ya se capacitó a ${r.proveedor}` + (r.marcados > 1 ? ` (vale para sus ${r.marcados} rechazos pendientes).` : '.') };
        await this.cargar();
      } catch (e) { f.error = e.message; } finally { f.enviando = false; }
    },
    verAdulterado(p) {
      this.f = { adulterado: p };
      this.ventana = 'adulterado';
    },

    abrirNuevoTrabajador() {
      this.f = { nombre: '', dni: '', rol: null, clave: '', telefono: '', ver: false, error: null, enviando: false };
      this.ventana = 'trabajador';
    },
    async guardarTrabajador() {
      const f = this.f;
      if (f.nombre.trim().split(/\s+/).length < 2) return (f.error = 'Escribe nombre y apellidos');
      if (!/^\d{8}$/.test(f.dni)) return (f.error = 'El DNI debe tener 8 dígitos');
      if (!f.rol) return (f.error = 'Elige el rol');
      if (f.clave.length < 6) return (f.error = 'La clave debe tener al menos 6 caracteres');
      await this.enviar('/admin/trabajadores', { nombre: f.nombre.trim(), dni: f.dni, rol: f.rol, clave: f.clave, telefono: f.telefono || null },
        `✓ ${f.nombre.trim()} ya puede entrar como ${ROLES[f.rol].toLowerCase()}`);
    },

    async cambiarActivo(t) {
      const accion = t.activo ? 'Desactivar' : 'Activar';
      if (!(await this.preguntar({
        icono: t.activo ? '🚫' : '✅', tono: t.activo ? 'peligro' : '', titulo: `¿${accion} a ${t.nombre}?`,
        texto: t.activo ? 'No podrá entrar a la app y se cerrará su sesión en todos sus celulares. Se puede volver a activar.' : 'Podrá volver a entrar con su DNI y contraseña.',
        si: t.activo ? 'Sí, desactivar' : 'Sí, activar',
      }))) return;
      try {
        await this.api(`/admin/trabajadores/${t.id}/activo`, { activo: !t.activo });
        this.aviso = { texto: t.activo ? `${t.nombre} quedó desactivado` : `✓ ${t.nombre} quedó activo` };
        await this.cargar();
      } catch (e) { this.aviso = { error: true, texto: e.message }; }
    },

    // ------------------------------------- cambio de comunidad por el admin
    // el productor llamó o vino a Huata (casi no tienen internet para pedirlo por la app)
    CANAL: { APP: 'Por la app', LLAMADA: '📞 Llamó', OFICINA: '🏠 Vino a Huata' },
    /** editar = true: corregir su traslado programado o reciente (lugar, fecha, cómo avisó, motivo). */
    async abrirCambio(proveedorId = null, editar = false) {
      this.f = { buscar: '', proveedor: null, fijo: !!proveedorId, editar, traslado: null, comunidad_id: null, canal: null,
        cuando: 'hoy', fecha: '', motivo: '', datos: null, error: null, enviando: false };
      this.ventana = 'cambio';
      try {
        this.f.datos = await this.api('/admin/solicitudes');
        if (proveedorId) this.f.proveedor = this.f.datos.proveedores.find((p) => p.id === proveedorId) || null;
        if (editar) {
          const t = (await this.api(`/admin/proveedores/${proveedorId}/traslado`)).traslado;
          Object.assign(this.f, { traslado: t, comunidad_id: t.destino_id, canal: t.canal, motivo: t.motivo || '',
            cuando: 'fecha', fecha: t.desde });
        }
      } catch (e) { this.f.error = e.message; }
    },
    async anularTraslado() {
      const f = this.f; const t = f.traslado;
      if (!(await this.preguntar({
        icono: '↩️', tono: 'peligro', titulo: `¿Anular el traslado de ${f.proveedor.nombre}?`,
        texto: t.programado ? `Ya no pasa a ${t.destino}: se queda en ${t.origen}.` : `Vuelve a ${t.origen} y lo recoge el vehículo de esa ruta.`,
        si: 'Sí, anular', no: 'No',
      }))) return;
      await this.enviar(`/admin/proveedores/${f.proveedor.id}/traslado/anular`, {}, null,
        (r) => `Traslado anulado: ${f.proveedor.nombre} se queda en ${r.se_queda_en}`);
    },
    // ---------------------------------------------------- ficha del proveedor
    // tocar al proveedor: todo en un lugar (leche, pagos, comunidades) y Editar · Trasladar · Eliminar
    async abrirFicha(id) {
      this.f = { id, ficha: null, abierta: null, error: null };
      this.ventana = 'ficha';
      try { this.f.ficha = await this.api(`/admin/proveedores/${id}/ficha`); } catch (e) { this.f.error = e.message; }
    },
    /** Entregas y pagos van de 8 en 8 semanas: así con meses de historia la lista no se hace larga. */
    async verMasSemanas() {
      const f = this.f;
      try { f.ficha = await this.api(`/admin/proveedores/${f.id}/ficha?semanas=${f.ficha.semanas_pedidas + 8}`); }
      catch (e) { this.aviso = { error: true, texto: e.message }; }
    },
    volverAFicha() { this.abrirFicha(this.f.id); },
    async abrirEditarProveedor() {
      const fi = this.f.ficha; const p = fi.proveedor;
      const ultimoTraslado = fi.historial.some((h) => h.como !== 'Inscripción' && !h.futuro);
      this.f = { id: p.id, nombre: p.nombre, dni: p.dni, promedio: p.promedio ?? '', vacas: p.vacas ?? '', leche: fi.leche,
        programado: p.programado, ultimoTraslado,
        // con traslado programado se corrige su destino; si no, la comunidad donde está
        comunidad_original: p.programado?.comunidad_id ?? p.comunidad_id, comunidad_id: p.programado?.comunidad_id ?? p.comunidad_id,
        comunidades: [], error: null, enviando: false };
      this.ventana = 'editarProveedor';
      try { this.f.comunidades = (await this.api('/admin/solicitudes')).comunidades; } catch (e) { this.f.error = e.message; }
    },
    async guardarEditarProveedor() {
      const f = this.f;
      if (f.nombre.trim().split(/\s+/).length < 2) return (f.error = 'Escribe nombre y apellidos');
      if (!/^\d{8}$/.test(f.dni)) return (f.error = 'El DNI debe tener 8 dígitos');
      f.enviando = true; f.error = null;
      try {
        const r = await this.api(`/admin/proveedores/${f.id}`, {
          nombre: f.nombre.trim(), dni: f.dni, comunidad_id: f.comunidad_id,
          promedio_litros: f.promedio !== '' ? Number(f.promedio) : null, vacas_ordeno: f.vacas !== '' ? Number(f.vacas) : null,
        });
        const QUE = { ALTA: ' · comunidad corregida', TRASLADO: ' · se corrigió el destino de su traslado',
          TRASLADO_ANULADO: ' · el traslado se anuló: vuelve a su comunidad', PROGRAMADO: ' · se corrigió el destino del traslado programado' };
        await this.cargar();
        await this.abrirFicha(f.id);
        this.aviso = { texto: `✓ Datos de ${f.nombre.trim()} corregidos` + (QUE[r.comunidad_corregida] || '') };
      } catch (e) { f.error = e.message; } finally { f.enviando = false; }
    },
    abrirEliminarProveedor() {
      // si ya entregó leche o tiene pagos no se borra: se da de baja (su historia y pagos se quedan)
      const fi = this.f.ficha; const h = fi.historia;
      this.f = { id: fi.proveedor.id, nombre: fi.proveedor.nombre, entregas: h.entregas,
        modo: h.entregas + h.pagos + h.sanciones > 0 ? 'baja' : 'eliminar', motivo: '', aviso: null, error: null, enviando: false };
      this.ventana = 'eliminarProveedor';
    },
    async confirmarEliminarProveedor() {
      const f = this.f;
      if (f.modo === 'baja') {
        if (f.motivo.trim().length < 3) return (f.error = 'Escribe por qué se da de baja');
        return this.enviar(`/admin/proveedores/${f.id}/baja`, { motivo: f.motivo.trim() }, `${f.nombre} quedó dado de baja (su historia se guarda)`);
      }
      f.enviando = true; f.error = null;
      try {
        await this.api(`/admin/proveedores/${f.id}/eliminar`, {});
        this.ventana = null;
        this.aviso = { texto: `${f.nombre} fue eliminado del padrón` };
        await this.cargar();
      } catch (e) {
        f.modo = 'baja'; f.aviso = e.message; // por si se registró algo mientras tanto
      } finally { f.enviando = false; }
    },

    async abrirHistorial(p) {
      this.f = { id: p.id, nombre: p.nombre, programado: p.programado, historial: null, error: null };
      this.ventana = 'historial';
      try { this.f.historial = await this.api(`/admin/proveedores/${p.id}/historial-comunidad`); } catch (e) { this.f.error = e.message; }
    },
    productoresCambio() {
      const b = (this.f.buscar || '').trim().toLowerCase();
      return (this.f.datos?.proveedores || []).filter((p) => (p.nombre + ' ' + p.dni).toLowerCase().includes(b)).slice(0, 8);
    },
    async guardarCambio() {
      const f = this.f;
      if (!f.comunidad_id) return (f.error = 'Elige la comunidad nueva');
      if (!f.canal) return (f.error = 'Indica si llamó o vino a Huata');
      const dia = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return d.toLocaleDateString('en-CA'); }; // AAAA-MM-DD
      const desde = f.cuando === 'hoy' ? dia(0) : (f.cuando === 'manana' ? dia(1) : f.fecha);
      if (!desde) return (f.error = 'Elige la fecha');
      if (f.editar) {
        return this.enviar(`/admin/proveedores/${f.proveedor.id}/traslado`, {
          comunidad_id: f.comunidad_id, canal: f.canal, motivo: f.motivo.trim() || null,
          desde: f.traslado.programado ? desde : null, // uno ya hecho no cambia de fecha
        }, null, (r) => `✓ Traslado corregido: ${f.proveedor.nombre} va a ${r.comunidad} desde el ${fechaCorta(r.desde)}`);
      }
      await this.enviar(`/admin/proveedores/${f.proveedor.id}/cambiar-comunidad`,
        { comunidad_id: f.comunidad_id, canal: f.canal, desde, motivo: f.motivo.trim() || null }, null,
        (r) => `✓ ${r.proveedor} pasa a ${r.comunidad} desde el ${fechaCorta(r.vigente_desde)}. Lo recoge el vehículo ${r.vehiculo}. ` +
          'Si te equivocaste, usa “Editar traslado” en su fila.');
    },

    // ---------------------------------------------------- cambios de zona
    async resolverSolicitud(s, aprobar) {
      if (!(await this.preguntar(aprobar
        ? { icono: '✅', titulo: `¿Aprobar el cambio de ${s.proveedor}?`, texto: `Pasa a ${s.destino} y lo recoge el vehículo que recorre esa comunidad.`, si: 'Sí, aprobar' }
        : { icono: '✖', tono: 'peligro', titulo: `¿Rechazar el cambio de ${s.proveedor}?`, texto: `Se queda donde está; no pasa a ${s.destino}.`, si: 'Sí, rechazar' }))) return;
      try {
        const r = await this.api(`/admin/solicitudes/${s.id}/resolver`, { aprobar });
        this.aviso = { texto: aprobar
          ? `✓ ${s.proveedor} pasa a ${s.destino} (vehículo ${r.vehiculo}) desde el ${fechaCorta(r.vigente_desde)}`
          : `Se rechazó el cambio de ${s.proveedor}` };
        await this.cargar();
      } catch (e) { this.aviso = { error: true, texto: e.message }; }
    },

    // ---------------------------------------------------------- configuración
    camposDe(grupo) { return (this.d.campos || []).filter((c) => c.grupo === grupo); },
    /** Los campos del grupo que se escribieron distinto a lo guardado. */
    cambiadosDe(grupo) {
      return this.camposDe(grupo).filter((c) => String(this.cfg[c.clave] ?? '').trim() !== String(c.valor ?? ''));
    },
    async guardarConfig(grupo) {
      const cambiados = this.cambiadosDe(grupo);
      if (!cambiados.length) { this.aviso = { texto: 'No cambiaste nada.' }; return; }
      try {
        const r = await this.api('/admin/configuracion', { valores: Object.fromEntries(cambiados.map((c) => [c.clave, this.cfg[c.clave]])) });
        this.aviso = { texto: `✓ Guardado (${r.cambios} ${r.cambios === 1 ? 'cambio' : 'cambios'}). Ya rige para lo que se registre desde ahora.` };
        await this.cargar();
      } catch (e) { this.aviso = { error: true, texto: e.message }; }
    },
    async guardarPrecioLeche() {
      const f = this.cfgLeche;
      if (!(Number(f.precio) > 0)) { this.aviso = { error: true, texto: 'Escribe el precio nuevo por litro' }; return; }
      try {
        const r = await this.api('/admin/configuracion/precio-leche', { precio: Number(f.precio), desde: f.desde });
        this.aviso = { texto: r.desde === this.hoy()
          ? `✓ Desde hoy se paga ${soles(r.precio)} por litro. Lo entregado antes conserva su precio.`
          : `✓ Anotado: desde el ${fechaCorta(r.desde)} se pagará ${soles(r.precio)} por litro. Hasta entonces sigue ${soles(r.hoy)}.` };
        await this.cargar();
      } catch (e) { this.aviso = { error: true, texto: e.message }; }
    },
    async quitarPrecioLeche(p) {
      try {
        await this.api(`/admin/configuracion/precio-leche/${p.id}/quitar`, {});
        this.aviso = { texto: `✓ Se quitó el precio de ${soles(p.precio)} que iba a regir desde el ${fechaCorta(p.desde)}.` };
        await this.cargar();
      } catch (e) { this.aviso = { error: true, texto: e.message }; }
    },
    async guardarPrecioVenta(p, tipo) {
      const v = this.cfgVenta[p.producto_id + '.' + tipo];
      if (!(Number(v) > 0)) { this.aviso = { error: true, texto: 'Escribe el precio' }; return; }
      try {
        const r = await this.api('/admin/configuracion/precio-venta', { producto_id: p.producto_id, tipo_cliente: tipo, precio: Number(v) });
        this.aviso = { texto: r.cambio
          ? `✓ ${p.producto} para ${this.d.tipos_cliente[tipo].toLowerCase()}: ${soles(r.precio)} desde hoy` + (r.antes ? ` (antes ${soles(r.antes)}).` : '.')
          : 'Ese ya era el precio.' };
        await this.cargar();
      } catch (e) { this.aviso = { error: true, texto: e.message }; }
    },

    // configuración · almacén, vehículos y rutas
    async guardarMaestro(ruta, cuerpo, texto) {
      try {
        const r = await this.api(ruta, cuerpo);
        this.aviso = { texto: typeof texto === 'function' ? texto(r) : texto };
        await this.cargar();
      } catch (e) { this.aviso = { error: true, texto: e.message }; }
    },
    insumoCambiado(i) {
      const f = this.cfgInsumos[i.id];
      return f && (f.nombre.trim() !== i.nombre || f.unidad.trim() !== i.unidad || Number(f.minimo) !== i.minimo);
    },
    // ------------------------------------------------- productos (otro queso, yogurt de sabores)
    TIPOS_PRODUCTO: { QUESO: 'Queso', YOGURT: 'Yogurt', OTRO: 'Otro' },
    /** Nuevo (p = null) o editar. Rinde = cuántos salen de 100 L de leche. */
    abrirProducto(p = null) {
      this.f = p
        ? { id: p.id, base_id: p.base_id, nombre: p.nombre, tipo: p.tipo, unidad: p.unidad, rinde_min: p.rinde_min, rinde_max: p.rinde_max,
            vida_util_dias: p.vida_util_dias, activo: p.activo, error: null, enviando: false }
        : { id: null, nombre: '', tipo: 'YOGURT', unidad: 'botellas de 1 L', rinde_min: '', rinde_max: '', vida_util_dias: '',
            copiar_de: '', precios: { MAYORISTA: '', DIRECTO: '', PLANTA: '' }, error: null, enviando: false };
      this.ventana = 'producto';
    },
    /** Al copiar la fórmula de otro producto, también sus datos (se cambia solo lo distinto). */
    copiarDatosDe(id) {
      const o = (this.d.productos || []).find((p) => p.id === Number(id));
      if (!o) return;
      Object.assign(this.f, { tipo: o.tipo, unidad: o.unidad, rinde_min: o.rinde_min, rinde_max: o.rinde_max, vida_util_dias: o.vida_util_dias });
    },
    async guardarProducto() {
      const f = this.f;
      if (f.nombre.trim().length < 3) return (f.error = 'Escribe el nombre del producto');
      if (!(Number(f.rinde_min) > 0)) return (f.error = '¿Cuánto sale de 100 L de leche? Escribe lo mínimo');
      if (f.rinde_max === '' || f.rinde_max === null) f.rinde_max = f.rinde_min;
      const cuerpo = { id: f.id, nombre: f.nombre.trim(), tipo: f.tipo, unidad: f.unidad.trim(), rinde_min: Number(f.rinde_min),
        rinde_max: Number(f.rinde_max), vida_util_dias: Number(f.vida_util_dias) };
      if (f.id) cuerpo.activo = !!f.activo;
      else {
        if (f.copiar_de) cuerpo.copiar_de = Number(f.copiar_de);
        cuerpo.precios = Object.fromEntries(Object.entries(f.precios).filter(([, v]) => v !== '' && v !== null).map(([k, v]) => [k, Number(v)]));
      }
      await this.enviar('/admin/configuracion/productos', cuerpo, null, (r) => f.id
        ? `✓ ${r.nombre} guardado`
        : `✓ ${r.nombre} creado. Revisa su fórmula en Fórmulas y producción` + (f.copiar_de ? ' (se copió la del producto que elegiste).' : '.'));
    },

    // lo más común en el Perú, para elegir con un toque (las dosis no se inventan: se escriben de la etiqueta o la guía)
    // los de Gloria (fresa, durazno, vainilla, lúcuma, guanábana, mora, manzana verde, saúco, frutos rojos,
    // maracuyá, fresa y plátano, piña, arándano) y otros frutos peruanos
    SABORES_YOGURT: ['Fresa', 'Durazno', 'Vainilla', 'Lúcuma', 'Guanábana', 'Piña', 'Maracuyá', 'Mora', 'Frutos rojos', 'Arándano',
      'Manzana verde', 'Saúco', 'Fresa y plátano', 'Mango', 'Aguaymanto', 'Coco', 'Plátano'],
    VARIEDADES_QUESO: [['Con orégano', 'Orégano seco'], ['Con ají', 'Ají molido'], ['Con hierbas', 'Hierbas finas secas'],
      ['Con aceitunas', 'Aceituna picada'], ['Con pimienta', 'Pimienta molida']],
    PRODUCTOS_COMUNES: [
      { nombre: 'Queso fresco', tipo: 'QUESO', unidad: 'quesos de 1 kg' }, { nombre: 'Queso andino', tipo: 'QUESO', unidad: 'quesos de 1 kg' },
      { nombre: 'Queso mantecoso', tipo: 'QUESO', unidad: 'quesos de 1 kg' }, { nombre: 'Queso maduro', tipo: 'QUESO', unidad: 'quesos de 1 kg' },
      { nombre: 'Quesillo', tipo: 'QUESO', unidad: 'kg' },
      // tipos de yogurt como los de Gloria: cada uno es su propia base y luego se le agregan sabores
      { nombre: 'Yogurt bebible', tipo: 'YOGURT', unidad: 'litros' }, { nombre: 'Yogurt batido', tipo: 'YOGURT', unidad: 'litros' },
      { nombre: 'Yogurt griego', tipo: 'YOGURT', unidad: 'litros' }, { nombre: 'Yogurt frutado', tipo: 'YOGURT', unidad: 'vasos' },
      { nombre: 'Yogurt descremado', tipo: 'YOGURT', unidad: 'litros' }, { nombre: 'Yogurt sin lactosa', tipo: 'YOGURT', unidad: 'litros' },
      { nombre: 'Manjar blanco', tipo: 'OTRO', unidad: 'kg' }, { nombre: 'Mantequilla', tipo: 'OTRO', unidad: 'kg' },
    ],
    opcionesSabor() { return this.f.tipo === 'YOGURT' ? this.SABORES_YOGURT : this.VARIEDADES_QUESO.map((v) => v[0]); },
    /** ¿Ya hay un sabor así en esta base? */
    saborYaExiste(o) {
      return (this.d.productos || []).some((x) => x.base_id === this.f.base_id && (x.variante || '').toLowerCase() === o.toLowerCase());
    },
    /** Al elegir de la lista: nombre y lo que lleva se llenan solos (el insumo si ya existe; si no, se propone crearlo). */
    elegirSabor(o) {
      const f = this.f;
      f.sabor = o; f.nombreTocado = false; this.nombreSabor();
      const insumo = f.tipo === 'YOGURT' ? `Saborizante ${o.toLowerCase()}` : (this.VARIEDADES_QUESO.find((v) => v[0] === o)?.[1] || o);
      const hay = (this.d.insumos || []).find((i) => i.nombre.toLowerCase() === insumo.toLowerCase());
      if (hay) f.insumo_id = String(hay.id);
      else { f.insumo_id = 'nuevo'; f.insumo_nombre = insumo; f.insumo_unidad = f.tipo === 'YOGURT' ? 'L' : 'kg'; }
    },
    /** Otro sabor de la misma base con un insumo de la misma unidad: para copiar su dosis si se quiere. */
    dosisHermano() {
      const f = this.f; const u = this.unidadSabor();
      if (!u) return null;
      for (const x of (this.d.productos || []).filter((x) => x.base_id === f.base_id && x.id !== f.id)) {
        const pr = (x.propios || []).find((y) => this.unidadInsumo(y.insumo_id) === u);
        if (pr) return { variante: x.variante || x.nombre, por_100l: pr.por_100l };
      }
      return null;
    },
    /** Sabor o variedad: [base] para uno nuevo, [p] para editar uno (su base sale de p.base_id). */
    abrirSabor(base, p = null) {
      const b = base || (this.d.productos || []).find((x) => x.id === p.base_id);
      const propio = p?.propios?.[0];
      this.f = {
        id: p?.id ?? null, base_id: b.id, base: b.nombre, tipo: b.tipo,
        sabor: p?.variante ?? '', nombre: p?.nombre ?? '', nombreTocado: !!p,
        insumo_id: propio ? String(propio.insumo_id) : '', insumo_nombre: '', insumo_unidad: 'L',
        por_100l: propio?.por_100l ?? '', paso: p?.paso_variante ?? '',
        precios: { MAYORISTA: '', DIRECTO: '', PLANTA: '' }, error: null, enviando: false,
      };
      this.ventana = 'sabor';
    },
    /** "Durazno" → "Yogurt de durazno"; para quesos: "Queso Paria con orégano". Se puede cambiar a mano. */
    nombreSabor() {
      const f = this.f; if (f.nombreTocado) return;
      const s = f.sabor.trim(); if (!s) { f.nombre = ''; return; }
      const base = f.base.replace(/\s+natural$/i, '');
      f.nombre = f.tipo === 'YOGURT' ? `${base} de ${s.toLowerCase()}` : `${f.base} ${/^con\s/i.test(s) ? s.toLowerCase() : 'con ' + s.toLowerCase()}`;
    },
    unidadSabor() { return this.f.insumo_id === 'nuevo' ? this.f.insumo_unidad : this.unidadInsumo(this.f.insumo_id); },
    async guardarSabor() {
      const f = this.f;
      if (f.sabor.trim().length < 2) return (f.error = f.tipo === 'YOGURT' ? 'Escribe el sabor' : 'Escribe la variedad');
      if (f.nombre.trim().length < 3) return (f.error = 'Escribe el nombre como se vende');
      if (!f.insumo_id) return (f.error = 'Elige qué lleva (o crea el insumo)');
      if (f.insumo_id === 'nuevo' && f.insumo_nombre.trim().length < 3) return (f.error = 'Escribe el nombre del insumo nuevo');
      if (!(Number(f.por_100l) > 0)) return (f.error = '¿Cuánto lleva por cada 100 L?');
      const cuerpo = { sabor: f.sabor.trim(), nombre: f.nombre.trim(), por_100l: Number(f.por_100l), paso: f.paso.trim() || null };
      if (f.insumo_id === 'nuevo') cuerpo.insumo_nuevo = { nombre: f.insumo_nombre.trim(), unidad: f.insumo_unidad.trim() || 'L' };
      else cuerpo.insumo_id = Number(f.insumo_id);
      if (!f.id) {
        cuerpo.base_id = f.base_id;
        cuerpo.precios = Object.fromEntries(Object.entries(f.precios).filter(([, v]) => v !== '' && v !== null).map(([k, v]) => [k, Number(v)]));
      }
      await this.enviar(f.id ? `/admin/sabores/${f.id}` : '/admin/sabores', cuerpo, null,
        (r) => f.id ? `✓ ${r.nombre} guardado` : `✓ ${r.nombre} agregado: ya se puede producir y vender`);
    },

    guardarInsumo(i) {
      const f = i ? this.cfgInsumos[i.id] : this.nuevoInsumo;
      return this.guardarMaestro('/admin/configuracion/insumos', { id: i?.id ?? null, nombre: f.nombre, unidad: f.unidad, minimo: f.minimo === '' ? null : Number(f.minimo) },
        (r) => i ? `✓ ${r.nombre} guardado` : `✓ ${r.nombre} agregado al almacén. Su stock sube cuando se registre una compra.`);
    },
    vehiculoCambiado(v) {
      const f = this.cfgVeh[v.id];
      return f && (f.codigo.trim().toUpperCase() !== v.codigo || f.tipo !== v.tipo || (f.acopiador_id ?? null) !== (v.acopiador_id ?? null));
    },
    guardarVehiculo(v, activo = undefined) {
      const f = v ? this.cfgVeh[v.id] : this.nuevoVeh;
      return this.guardarMaestro('/admin/configuracion/vehiculos', { id: v?.id ?? null, codigo: f.codigo, tipo: f.tipo, acopiador_id: f.acopiador_id || null,
        ...(activo === undefined ? {} : { activo }) },
        (r) => !v ? `✓ Vehículo ${r.codigo} agregado. Ahora agrégale comunidades a su ruta.`
          : activo === false ? `✓ El ${r.codigo} quedó sin usar` : activo ? `✓ El ${r.codigo} vuelve a estar en uso` : `✓ ${r.codigo} guardado`);
    },
    moverComunidad(v, k, paso) {
      const orden = v.ruta.map((c) => c.id);
      [orden[k], orden[k + paso]] = [orden[k + paso], orden[k]];
      return this.guardarMaestro(`/admin/configuracion/vehiculos/${v.id}/ruta`, { orden }, `✓ Orden de la ruta del ${v.codigo} guardado`);
    },
    comunidadCambiada(c, vehiculoId) {
      const f = this.cfgCom[c.id];
      return f && (f.nombre.trim() !== c.nombre || Number(f.vehiculo_id) !== vehiculoId);
    },
    guardarComunidad(c, vehiculoId) {
      const f = this.cfgCom[c.id];
      const pasa = Number(f.vehiculo_id) !== vehiculoId;
      return this.guardarMaestro('/admin/configuracion/comunidades', { id: c.id, nombre: f.nombre, vehiculo_id: Number(f.vehiculo_id) },
        (r) => pasa ? `✓ ${r.nombre} ahora la recoge el ${r.vehiculo}` + (c.proveedores ? `, con sus ${c.proveedores} proveedores` : '') : `✓ ${r.nombre} guardada`);
    },
    agregarComunidad(v) {
      return this.guardarMaestro('/admin/configuracion/comunidades', { nombre: this.nuevaCom[v.id] || '', vehiculo_id: v.id },
        (r) => `✓ ${r.nombre} agregada al final de la ruta del ${r.vehiculo}. Ubícala en Acopio → Mapa de Huata.`);
    },

    // ---------------------------------------------------------- conciliación
    async guardarPlanta(f) {
      const litros = Number(this.concLitros[f.vehiculo_id]);
      if (this.concLitros[f.vehiculo_id] === '' || !(litros >= 0)) { this.aviso = { error: true, texto: 'Escribe los litros que llegaron a planta de ' + f.codigo }; return; }
      try {
        const r = await this.api('/admin/conciliacion', { fecha: this.concFecha, vehiculo_id: f.vehiculo_id, litros_planta: litros });
        this.aviso = { texto: `✓ ${f.codigo}: ${num(r.litros_planta)} L en planta · diferencia ${num(r.diferencia_pct, 2)} %` + (r.fuera_tolerancia ? ' (fuera de tolerancia)' : '') };
        await this.cargar();
      } catch (e) { this.aviso = { error: true, texto: e.message }; }
    },

    // -------------------------------------------------------------- sesiones
    async cerrarSesiones(u) {
      if (!(await this.preguntar({
        icono: '🔒', tono: 'peligro', titulo: `¿Cerrar las ${u.sesiones} sesiones de ${u.nombre}?`,
        texto: 'Tendrá que volver a ingresar con su DNI y contraseña en todos sus celulares y computadoras.', si: 'Sí, cerrar sesiones',
      }))) return;
      try {
        const r = await this.api(`/admin/usuarios/${u.id}/cerrar-sesiones`, {});
        if (r.propia) return this.salir('Cerraste tus propias sesiones. Vuelve a ingresar.');
        this.aviso = { texto: `✓ Se cerraron ${r.cerradas} sesiones de ${u.nombre}` };
        await this.cargar();
      } catch (e) { this.aviso = { error: true, texto: e.message }; }
    },

    // ---------------------------------------------------------------- pagos
    async abrirBoleta(b) {
      this.f = { boleta: b, dias: null, error: null };
      this.ventana = 'boleta';
      try { this.f.dias = (await this.api('/admin/pagos/' + b.id)).dias; } catch (e) { this.f.error = e.message; }
    },
    pagar(ids, titulo) {
      this.f = { ids, titulo, error: null, enviando: false };
      this.ventana = 'pagar';
    },
    async confirmarPago(metodo) {
      this.f.enviando = true;
      try {
        const r = await this.api('/admin/pagos/pagar', { ids: this.f.ids, metodo });
        this.ventana = null;
        this.aviso = { texto: `✓ Se registraron ${r.pagadas} pagos por ${soles(r.total)} (${metodo === 'YAPE' ? 'Yape' : 'efectivo'})` };
        await this.cargar();
      } catch (e) { this.f.error = e.message; } finally { this.f.enviando = false; }
    },

    // ---------------------------------------------------------------- ventas
    // una venta puede llevar varios productos (queso y yogurt): cantidades = producto => cuánto (vacío = no lleva)
    nuevaVenta() {
      this.f = { cantidades: {}, cliente: null, correo: '', error: null, enviando: false, paso: 1,
        familia: null, producto: null, cuantos: '1' };
    },
    /** Paso a paso: 1 cliente, 2 productos, 3 confirmar. No deja avanzar sin lo necesario. */
    pasoVenta(n) {
      const f = this.f; f.error = null;
      if (n >= 2 && !this.clienteVenta()) { f.paso = 1; return (f.error = 'Elige a quién le vendes'); }
      if (n >= 3) {
        if (!this.lineasVenta().length) { f.paso = 2; return (f.error = 'Elige al menos un producto'); }
      }
      f.paso = n;
    },
    /** Los 6 clientes a los que se vendió hace menos (botones rápidos del paso 1). */
    clientesFrecuentes() {
      return (this.d.clientes || []).filter((c) => c.ultima_venta)
        .sort((a, b) => String(b.ultima_venta).localeCompare(String(a.ultima_venta))).slice(0, 6);
    },
    productosConStock() { return (this.d.productos || []).filter((p) => p.stock > 0); },
    /**
     * Paso 2: productos agrupados como en Fórmulas — cada base (Queso fresco, Yogurt natural…) con sus sabores debajo.
     * [{ tipo, nombre, familias: [{ base, sabores: [productos con stock, la base primero] }] }]
     */
    gruposVenta() {
      const todos = this.d.productos || [];
      const familias = todos.filter((p) => !p.base_id || !todos.some((b) => b.id === p.base_id)).map((base) => ({
        base, sabores: [base, ...todos.filter((p) => p.base_id === base.id)].filter((p) => p.stock > 0 || p.activo), // sin stock también: se puede vender si el cliente lo pide
      })).filter((fa) => fa.sabores.length);
      const NOMBRES = { QUESO: '🧀 Quesos', YOGURT: '🥛 Yogurt', OTRO: 'Otros' };
      return ['QUESO', 'YOGURT', 'OTRO'].map((tipo) => ({
        tipo, nombre: NOMBRES[tipo], familias: familias.filter((fa) => (fa.base.tipo || 'OTRO') === tipo),
      })).filter((g) => g.familias.length);
    },
    familiaVenta() { return this.gruposVenta().flatMap((g) => g.familias).find((fa) => fa.base.id === this.f.familia); },
    /** Sin sabores se elige directo; con sabores se muestran para escoger. */
    elegirFamilia(fa) {
      this.f.familia = fa.base.id; this.f.error = null;
      if (fa.sabores.length === 1) this.elegirProducto(fa.sabores[0]); else this.f.producto = null;
    },
    nombreSabor(p) { return p.base_id ? p.variante || p.nombre : 'Natural / sin sabor'; },
    elegirProducto(p) {
      this.f.producto = p.id; this.f.cuantos = '1'; this.f.error = null;
      this.$nextTick(() => document.getElementById('venta-cuantos')?.select());
    },
    /** Suma lo elegido a lo que ya lleva (si agrega fresa dos veces, se junta). */
    agregarProducto() {
      const f = this.f;
      const p = (this.d.productos || []).find((x) => x.id === f.producto);
      if (!p) return (f.error = f.familia ? 'Elige el sabor' : 'Elige un producto');
      const n = Number(f.cuantos);
      if (!(n > 0)) return (f.error = '¿Cuántos lleva?');
      f.cantidades[p.id] = String((Number(f.cantidades[p.id]) || 0) + n); // si no alcanza, se avisa y se pregunta al registrar
      f.producto = null; f.familia = null; f.cuantos = '1'; f.error = null;
    },
    quitarProducto(p) { this.f.cantidades[p.id] = ''; },
    /** De qué lotes sale [cantidad] de p: primero el que vence primero, igual que el servidor. [{ codigo, vence_el, dias, cantidad }] */
    lotesQueSalen(p, cantidad) {
      let falta = Number(cantidad) || 0;
      return (p?.lotes || []).flatMap((l) => {
        if (falta <= 0.005) return [];
        const usa = Math.min(falta, Number(l.queda)); falta -= usa;
        return [{ ...l, cantidad: usa }];
      });
    },
    cuandoVence(l) {
      return 'vence el ' + fechaCorta(l.vence_el) + (l.dias === 0 ? ' (hoy)' : l.dias === 1 ? ' (mañana)' : ` (en ${l.dias} días)`);
    },
    /** Tiene un lote que vence en 7 días o menos: hay que venderlo primero. */
    vencePronto(p) { return (p?.lotes || []).some((l) => l.dias <= 7); },
    textoLotes(p, cantidad) {
      const ls = this.lotesQueSalen(p, cantidad);
      const falta = this.faltaStock(p, cantidad);
      return [...ls.map((l) => (ls.length > 1 || falta ? num(l.cantidad, 2) + ' del ' : 'Sale del ') + 'lote ' + l.codigo + ', ' + this.cuandoVence(l)),
        ...(falta ? [`⚠ ${num(falta, 2)} sin stock (sale del próximo lote)`] : [])].join(' · ');
    },
    /** Cuánto de [cantidad] no hay en stock (0 si alcanza). */
    faltaStock(p, cantidad) { return Math.max(0, Math.round((Number(cantidad) - Math.max(0, Number(p?.stock) || 0)) * 100) / 100); },
    clienteVenta() { return (this.d.clientes || []).find((c) => c.id === this.f.cliente); },
    /** Lo que lleva el cliente: [{ p, cantidad, precio, importe }] (precio según su tipo, 0 si aún no se eligió). */
    lineasVenta() {
      const c = this.clienteVenta();
      return (this.d.productos || []).map((p) => {
        const cantidad = Number(this.f.cantidades?.[p.id]) || 0;
        const precio = c ? Number(p.precios?.[c.tipo] || 0) : 0;
        return { p, cantidad, precio, importe: Math.round(precio * cantidad * 100) / 100 };
      }).filter((l) => l.cantidad > 0);
    },
    totalVenta() { return this.lineasVenta().reduce((t, l) => t + l.importe, 0); },
    sumarCantidad(p, cuanto) {
      const n = Math.max(0, (Number(this.f.cantidades[p.id]) || 0) + cuanto);
      this.f.cantidades[p.id] = n ? String(n) : '';
    },
    elegirCliente(c, avanzar = false) {
      this.f.cliente = c.id; this.f.correo = c.email || '';
      if (avanzar) { this.buscarCliente = ''; this.pasoVenta(2); }
    },
    /** Clientes que coinciden con lo buscado (nombre, DNI/RUC o teléfono); los desactivados solo si se piden. */
    clientesVisibles() {
      const q = this.buscarCliente.trim().toLowerCase();
      const todos = [...(this.d.clientes || []), ...(this.verClientesInactivos ? this.d.inactivos || [] : [])];
      return todos.filter((c) => !q || c.nombre.toLowerCase().includes(q) || (c.ruc || '').includes(q) || (c.telefono || '').includes(q));
    },
    abrirCliente(c = null) {
      this.fVenta = this.f; // se guarda la venta a medias y se vuelve a ella al terminar
      this.f = c
        ? { id: c.id, nombre: c.nombre, tipo: c.tipo, documento: c.ruc || '', telefono: c.telefono || '', email: c.email || '',
            activo: !!Number(c.activo), error: null, enviando: false }
        : { id: null, nombre: '', tipo: 'MAYORISTA', documento: '', telefono: '', email: '', activo: true, error: null, enviando: false };
      this.ventana = 'cliente';
    },
    cerrarCliente() { this.f = this.fVenta; this.fVenta = null; this.ventana = null; },
    async guardarCliente() {
      const f = this.f;
      const doc = f.documento.replace(/\D/g, '');
      if (f.nombre.trim().length < 3) return (f.error = 'Escribe el nombre del cliente o de su negocio');
      if (doc && !/^\d{8}$/.test(doc) && !/^(10|15|16|17|20)\d{9}$/.test(doc)) return (f.error = 'El DNI lleva 8 dígitos y el RUC 11 (empieza en 10, 15, 16, 17 o 20)');
      if (f.email && !esCorreo(f.email)) return (f.error = 'Ese correo no es válido');
      f.enviando = true; f.error = null;
      try {
        const r = await this.api('/compras/clientes' + (f.id ? '/' + f.id : ''), {
          nombre: f.nombre.trim(), tipo: f.tipo, documento: doc || null, telefono: f.telefono.trim() || null,
          email: f.email.trim() || null, ...(f.id ? { activo: f.activo } : {}) });
        const datos = await this.api('/compras/ventas-datos');
        this.d = { ...this.d, clientes: datos.clientes, inactivos: datos.inactivos };
        this.f = this.fVenta; this.fVenta = null; this.ventana = null;
        const c = datos.clientes.find((x) => x.id === r.id);
        if (c) this.elegirCliente(c); else if (this.f.cliente === r.id) this.f.cliente = null; // desactivado: ya no se puede elegir
        this.aviso = { texto: `✓ ${r.nombre} ${f.id ? 'actualizado' : 'agregado'}` + (c && !f.id ? ' y elegido para esta venta' : '') };
      } catch (e) { f.error = e.message; } finally { f.enviando = false; }
    },
    async registrarVenta() {
      const f = this.f; const lineas = this.lineasVenta(); const c = this.clienteVenta();
      if (!lineas.length) return (f.error = 'Escribe cuánto lleva de cada producto');
      if (!c) return (f.error = 'Elige el cliente');
      const sinPrecio = lineas.find((l) => !l.precio);
      if (sinPrecio) return (f.error = `${sinPrecio.p.nombre} no tiene precio para ${TIPO_CLIENTE[c.tipo] || c.tipo}: ponlo en Configuración → Precios`);
      if (f.correo && !esCorreo(f.correo)) return (f.error = 'Ese correo no es válido (déjalo vacío si no quieres enviarlo)');
      // el cliente lo pide y no hay: se puede vender igual, pero se pregunta primero
      const faltan = lineas.filter((l) => this.faltaStock(l.p, l.cantidad) > 0);
      if (faltan.length && !(await this.preguntar({
        titulo: '¿Vender sin stock?', icono: '⚠', tono: 'aviso', si: 'Sí, vender igual', no: 'No, corregir',
        texto: 'No hay suficiente en stock. Lo que falta queda vendido sin lote y se descuenta del próximo lote que cierres (el stock queda en negativo).',
        filas: faltan.map((l) => ({ k: l.p.nombre, v: `hay ${num(Math.max(0, l.p.stock), 2)}, faltan ${num(this.faltaStock(l.p, l.cantidad), 2)}`, clase: 'ojo' })),
      }))) return;
      f.enviando = true; f.error = null;
      try {
        const r = await this.api('/compras/ventas', {
          cliente_id: c.id, items: lineas.map((l) => ({ producto_id: l.p.id, cantidad: l.cantidad })), correo: f.correo ? f.correo.trim() : null,
          sin_stock: faltan.length > 0,
        });
        this.aviso = {
          texto: `✓ Venta registrada: ${soles(r.total)} a ${c.nombre} · ${r.numero}` +
            (r.correo_enviado ? ` · nota enviada a ${r.correo}` : '') + (r.correo_error ? ` · ${r.correo_error}` : ''),
          nota: { id: r.id, numero: r.numero },
        };
        this.d = await this.api('/compras/ventas-datos');
        this.nuevaVenta();
      } catch (e) { f.error = e.message; } finally { f.enviando = false; }
    },

    // ---------------------------------------------------------- confirmar
    /**
     * Pregunta con una ventana del sistema y espera la respuesta (true = sí).
     * { titulo, texto, icono, tono: 'peligro'|'', si, no, filas: [{ k, v, clase }] }
     */
    preguntar(p) { return new Promise((resolver) => { this.pregunta = { no: 'Cancelar', si: 'Sí', tono: '', filas: [], ...p, resolver }; }); },
    responder(si) { const r = this.pregunta?.resolver; this.pregunta = null; r?.(si); },

    // ---------------------------------------------------------- Excel
    /** Baja el .xlsx con la sesión (por eso no basta un enlace) y lo guarda con el nombre que da el servidor. */
    async excel(ruta) {
      this.aviso = { texto: 'Preparando el Excel…' };
      try {
        const r = await fetch(API + ruta, { headers: { Authorization: 'Bearer ' + this.token, Accept: 'application/json' } });
        if (r.status === 401) { this.salir('Tu sesión venció o tu usuario fue desactivado. Vuelve a ingresar.'); return; }
        if (!r.ok) throw new Error((await r.json().catch(() => ({}))).message || 'El servidor respondió ' + r.status);
        const nombre = (r.headers.get('Content-Disposition') || '').match(/filename="?([^";]+)"?/)?.[1] || 'ecolacteos.xlsx';
        const a = document.createElement('a');
        a.href = URL.createObjectURL(await r.blob());
        a.download = nombre;
        a.click();
        setTimeout(() => URL.revokeObjectURL(a.href), 10000);
        this.aviso = { texto: `✓ ${nombre} quedó en tus Descargas` };
      } catch (e) {
        this.aviso = { error: true, texto: 'No se pudo descargar el Excel: ' + e.message };
      }
    },

    // ------------------------------------------------------ calidad: muestreo
    /** Entregas sin analizar que coinciden con lo buscado, separadas en hoy y ayer. */
    muestrasVisibles() {
      const q = this.buscarMuestra.trim().toLowerCase();
      const todas = (this.d.entregas || []).filter((e) => !q || [e.proveedor, e.comunidad, e.vehiculo].some((t) => (t || '').toLowerCase().includes(q)));
      return [['Hoy', todas.filter((e) => e.es_hoy)], ['Ayer', todas.filter((e) => !e.es_hoy)]].filter(([, l]) => l.length);
    },
    /** Litros fuera de lo normal (±20 % de su promedio): ayuda a decidir a quién muestrear. */
    desvioMuestra(e) { return e.promedio ? ((e.litros - e.promedio) / e.promedio) * 100 : 0; },
    elegirMuestra(e) {
      this.muestra = { entrega: e, tipo: 'CAMPO', grasa: '', proteina: '', densidad: '', temperatura: '', agua_anadida: '', ph: '',
        significada: false, enviando: false, error: null };
      this.$nextTick(() => document.getElementById('m-grasa')?.focus());
    },
    /** Cada medida con su límite, para pintar en rojo la que está fuera (mismos rangos del servidor). */
    medidasMuestra() {
      const r = this.d.rangos || {};
      const m = this.muestra || {};
      const v = (k) => (m[k] === '' || m[k] == null ? null : Number(m[k]));
      return [
        { k: 'grasa', nombre: 'Grasa', paso: '0.1', ayuda: `% · mín. ${r.grasa_min}`, fuera: v('grasa') !== null && v('grasa') < r.grasa_min },
        { k: 'proteina', nombre: 'Proteína', paso: '0.1', ayuda: `% · mín. ${r.proteina_min}`, fuera: v('proteina') !== null && v('proteina') < r.proteina_min },
        { k: 'densidad', nombre: 'Densidad', paso: '0.001', ayuda: `g/mL · ${r.densidad_min}–${r.densidad_max}`,
          fuera: v('densidad') !== null && (v('densidad') < r.densidad_min || v('densidad') > r.densidad_max) },
        { k: 'agua_anadida', nombre: 'Agua añadida', paso: '0.1', ayuda: `% · adulterada desde ${r.agua_adulter}`, fuera: v('agua_anadida') !== null && v('agua_anadida') >= r.agua_adulter },
        { k: 'temperatura', nombre: 'Temperatura (opcional)', paso: '0.5', ayuda: `°C · máx. ${r.temperatura_max}`, fuera: v('temperatura') !== null && v('temperatura') > r.temperatura_max },
        { k: 'ph', nombre: 'pH (opcional)', paso: '0.05', ayuda: `${r.ph_min}–${r.ph_max}`, fuera: v('ph') !== null && (v('ph') < r.ph_min || v('ph') > r.ph_max) },
      ];
    },
    /** Veredicto en vivo, igual que CalidadService::evaluar (el que vale es el del servidor). */
    veredictoMuestra() {
      const m = this.muestra; const r = this.d.rangos || {};
      if (!m) return null;
      const causas = this.medidasMuestra().filter((x) => x.fuera).map((x) => x.nombre.replace(' (opcional)', '').toLowerCase() + ' fuera de rango');
      if (m.significada) causas.push('leche significada (expuesta al sol)');
      const adulterada = m.agua_anadida !== '' && Number(m.agua_anadida) >= r.agua_adulter;
      return { resultado: adulterada ? 'ADULTERADA' : (causas.length ? 'RECHAZADO' : 'ACEPTADO'), causas };
    },
    async guardarAnalisis(aceptar) {
      const m = this.muestra;
      const faltan = ['grasa', 'proteina', 'densidad', 'agua_anadida'].filter((k) => m[k] === '' || m[k] == null);
      if (faltan.length) { m.error = 'Escribe lo que marca el lactoscan: grasa, proteína, densidad y agua añadida.'; return; }
      const v = this.veredictoMuestra();
      if (!(await this.preguntar({
        icono: aceptar ? '✅' : '⛔', tono: aceptar ? '' : 'peligro',
        titulo: (aceptar ? '¿Aceptar' : '¿Rechazar') + ' la leche de ' + m.entrega.proveedor + '?',
        texto: v.resultado === 'ACEPTADO' ? 'Todos los parámetros están dentro de rango.' : 'Veredicto en vivo: ' + v.resultado.toLowerCase() + ' (' + v.causas.join(', ') + '). ' +
          'El servidor lo vuelve a calcular y aplica la sanción que corresponda.',
        filas: [{ k: 'Entrega', v: num(m.entrega.litros) + ' L · ' + fechaCorta(m.entrega.fecha) }, { k: 'Muestra', v: m.tipo === 'PLANTA' ? 'de planta' : 'de ruta' }],
        si: aceptar ? 'Sí, aceptar' : 'Sí, rechazar', no: 'Revisar',
      }))) return;
      m.enviando = true; m.error = null;
      const n = (k) => (m[k] === '' || m[k] == null ? null : Number(m[k]));
      try {
        // mismo camino que la app del celular: /sync/push con una operación ANALISIS
        const r = await this.api('/sync/push', { operaciones: [{
          tipo: 'ANALISIS', uuid: nuevoUuid(), entrega_id: m.entrega.id, muestra: m.tipo,
          grasa: n('grasa'), proteina: n('proteina'), densidad: n('densidad'), temperatura: n('temperatura'),
          agua_anadida: n('agua_anadida'), ph: n('ph'), significada: m.significada,
          fuente: 'MANUAL', tomada_en: ahoraLima(), aceptar,
        }] });
        const res = r.resultados?.[0] || {};
        if (res.estado === 'ERROR') throw new Error(res.error);
        const s = res.sancion;
        const medida = { PRECIO_REDUCIDO: 'precio reducido', BAJA_DEFINITIVA: 'baja definitiva' }[s?.medida] || 'capacitación';
        this.aviso = {
          icono: res.veredicto === 'ACEPTADO' ? '✓' : '⚠', error: res.veredicto === 'ADULTERADA',
          titulo: `${m.entrega.proveedor}: ${(res.veredicto || '').toLowerCase()}`,
          texto: s ? `Sanción por ${s.tipo.toLowerCase()} (${s.nivel}.ª vez): ${medida}.` : 'Análisis guardado.',
        };
        this.muestra = null;
        await this.cargar();
      } catch (e) { m.error = e.message; } finally { m.enviando = false; }
    },

    // ---------------------------------------------------------- nota de venta
    // una fila por nota: una venta de varios productos viene en varias filas
    notasVenta() {
      const notas = [];
      for (const m of (this.d.movimientos || []).filter((m) => m.venta_id)) {
        let n = notas.find((x) => x.nota === m.nota);
        if (!n) notas.push(n = { nota: m.nota, venta_id: m.venta_id, numero: m.numero, fecha: m.fecha, cliente: m.cliente, total: 0, productos: [] });
        n.total += m.total;
        n.productos.push({ que: m.que, cantidad: m.cantidad, unidad: m.unidad });
      }
      return notas;
    },
    async verNota(id) {
      // se abre la pestaña al toque (si no, el navegador la bloquea) y luego se le pone el PDF
      const pestana = window.open('', '_blank');
      try {
        const r = await this.api(`/compras/ventas/${id}/nota`);
        if (pestana) pestana.location = r.url; else window.location = r.url;
      } catch (e) {
        pestana?.close();
        this.aviso = { error: true, texto: 'No se pudo abrir la nota: ' + e.message };
      }
    },
    abrirCorreo(id, numero, correo = '') {
      this.f = { id, numero, correo, error: null, enviando: false };
      this.ventana = 'correo';
    },
    async enviarCorreo() {
      if (!esCorreo(this.f.correo)) return (this.f.error = 'Ese correo no es válido');
      this.f.enviando = true;
      try {
        await this.api(`/compras/ventas/${this.f.id}/enviar-nota`, { correo: this.f.correo.trim() });
        this.ventana = null;
        this.aviso = { texto: `✓ Nota ${this.f.numero} enviada a ${this.f.correo.trim()}` };
      } catch (e) { this.f.error = e.message; } finally { this.f.enviando = false; }
    },

    // --------------------------------------------------------------- compras
    /**
     * Una sola ventana para comprar: "Ya lo compré" (lo común: se compra y se vuelve con la boleta)
     * o "Lo pido" (llega después y se registra con "Llegó"). [loQueFalta]: ya llena con lo que falta.
     * El precio sale de la última compra y el vendedor, de a quién se le compró la última vez.
     */
    abrirCompra(loQueFalta = false) {
      const items = loQueFalta
        ? (this.d.inv?.por_comprar || []).map((p) => this.lineaCompra(p.insumo_id, p.cantidad))
        : [this.lineaCompra(null, '')];
      this.f = { modo: 'directa', vendedor_id: null, items, tipo: null, serie: '', numero: '', fecha: this.hoy(),
        foto: null, error: null, enviando: false };
      items.forEach((l) => this.sugerirVendedor(l));
      this.ventana = 'compra';
    },
    lineaCompra(insumo_id, cantidad) {
      const l = { insumo_id, cantidad, pagado: '', pagoTocado: false };
      this.sugerirPrecio(l);
      return l;
    },
    insumoDe(id) { return (this.d.inv?.insumos || []).find((i) => i.id === id); },
    /** Lo que se pagó la última vez por unidad × la cantidad; si ya se escribió a mano, no se toca. */
    sugerirPrecio(l) {
      if (l.pagoTocado) return;
      const c = this.insumoDe(l.insumo_id)?.costo || 0;
      l.pagado = c && Number(l.cantidad) > 0 ? Math.round(c * Number(l.cantidad) * 100) / 100 : '';
    },
    /** Si todavía no se eligió a quién, el último que vendió ese insumo (si sigue activo). */
    sugerirVendedor(l) {
      const v = this.insumoDe(l.insumo_id)?.ultimo_vendedor_id;
      if (!this.f.vendedor_id && v && (this.d.vendedores || []).some((x) => x.id === v)) this.f.vendedor_id = v;
    },
    elegirInsumoCompra(l) { this.sugerirPrecio(l); this.sugerirVendedor(l); },
    precioUnidad(l) { const c = this.insumoDe(l.insumo_id)?.costo; return c ? `última vez ${this.cu(c)} por ${this.insumoDe(l.insumo_id).unidad}` : 'primera compra'; },
    costoEstimadoOrden() {
      return (this.f.items || []).reduce((t, it) => t + (Number(it.cantidad) || 0) * (this.insumoDe(it.insumo_id)?.costo || 0), 0);
    },
    totalCompra() { return (this.f.items || []).reduce((t, l) => t + (Number(l.pagado) || 0), 0); },
    async guardarCompra() {
      const f = this.f;
      if (!f.vendedor_id) return (f.error = 'Elige a quién se le compró');
      const items = f.items.filter((it) => it.insumo_id);
      if (!items.length) return (f.error = 'Elige al menos un insumo');
      if (items.some((it) => !(Number(it.cantidad) > 0))) return (f.error = 'Escribe la cantidad de cada insumo');
      if (new Set(items.map((it) => it.insumo_id)).size !== items.length) return (f.error = 'Hay un insumo repetido: junta las cantidades');
      if (f.modo === 'orden') {
        return this.enviar('/compras/ordenes', { vendedor_id: f.vendedor_id, items: items.map((it) => ({ insumo_id: it.insumo_id, cantidad: Number(it.cantidad) })) },
          null, (r) => `✓ Pedido ${r.codigo} anotado · costo estimado ${soles(r.costo_estimado)}. Cuando llegue, toca "Llegó" y registra el comprobante.`);
      }
      if (items.some((it) => it.pagado === '' || !(Number(it.pagado) >= 0))) return (f.error = 'Escribe cuánto se pagó por cada insumo');
      if (!f.tipo) return (f.error = '¿Qué te dieron? Elige factura, boleta o recibo');
      if (f.tipo === 'FACTURA' && this.vendedorRecibir()?.tipo_documento !== 'RUC') return (f.error = 'Solo hay factura si el vendedor tiene RUC. Si te dio boleta, elige boleta.');
      if (f.tipo !== 'RECIBO' && !f.foto && (!f.serie.trim() || !/^\d{1,8}$/.test(f.numero.trim())))
        return (f.error = 'Saca la foto de la boleta o factura, o escribe su serie y número');
      if (!f.fecha) return (f.error = 'Elige la fecha del comprobante');
      await this.enviar('/compras/ordenes/directa', {
        vendedor_id: f.vendedor_id, comprobante_tipo: f.tipo, comprobante_serie: f.serie.trim() || null,
        comprobante_numero: f.numero.trim() || null, comprobante_fecha: f.fecha, foto: f.foto,
        items: items.map((it) => ({ insumo_id: it.insumo_id, cantidad: Number(it.cantidad), costo_real: Number(it.pagado) })),
      }, null, (r) => `✓ Compra ${r.codigo} guardada con ${r.comprobante} por ${soles(r.total)}: el stock ya subió`);
    },

    // al recibir: comprobante, lo pagado por cada insumo y la foto
    hoy() { return new Date().toLocaleDateString('en-CA'); },
    /** '2026-10' → 'Octubre 2026' */
    nombreMes(ym) {
      if (!ym) return '';
      if (ym === 'todo') return 'Todo';
      const t = new Date(ym + '-15T12:00:00').toLocaleDateString('es-PE', { month: 'long', year: 'numeric' }).replace(' de ', ' ');
      return t.charAt(0).toUpperCase() + t.slice(1);
    },
    /** Abre o pliega la lista de vendedores; la compu lo recuerda para la próxima vez. */
    plegarVendedores(abrir) {
      this.vendedoresAbiertos = abrir;
      try { localStorage.setItem('vendedoresAbiertos', abrir ? '1' : '0'); } catch { /* sin almacenamiento: solo por ahora */ }
    },
    /** Vendedores que coinciden con lo buscado (nombre o DNI/RUC); los desactivados solo si se piden. */
    vendedoresVisibles() {
      const q = this.buscarVendedor.trim().toLowerCase();
      return (this.d.todosVendedores || []).filter((v) => (v.activo || this.verDesactivados)
        && (!q || v.nombre.toLowerCase().includes(q) || v.documento.includes(q)));
    },
    abrirRecibir(o) {
      const pagos = {};
      o.items.forEach((it) => { pagos[it.id] = it.costo_estimado; });
      this.f = { orden: o, tipo: null, serie: '', numero: '', fecha: this.hoy(), pagos, vendedor_id: o.vendedor?.id ?? null,
        foto: null, fotoNombre: '', error: null, enviando: false };
      this.ventana = 'recibir';
    },
    vendedorRecibir() { return (this.d.vendedores || []).find((v) => v.id === this.f.vendedor_id); },
    totalRecibir() { return Object.values(this.f.pagos || {}).reduce((t, v) => t + (Number(v) || 0), 0); },
    subtotalFactura() { return Math.round(this.totalRecibir() / 1.18 * 100) / 100; },
    async recibirOrden() {
      const f = this.f;
      if (!f.tipo) return (f.error = 'Elige qué comprobante te dieron');
      if (f.tipo === 'FACTURA' && this.vendedorRecibir()?.tipo_documento !== 'RUC') return (f.error = 'Solo hay factura si el vendedor tiene RUC. Si te dio boleta, elige boleta.');
      if (f.tipo !== 'RECIBO' && !f.foto && (!f.serie.trim() || !/^\d{1,8}$/.test(f.numero.trim())))
        return (f.error = 'Saca la foto de la boleta o factura, o escribe su serie y número');
      if (!f.fecha) return (f.error = 'Escribe la fecha del comprobante');
      if (Object.values(f.pagos).some((v) => v === '' || Number(v) < 0)) return (f.error = 'Escribe cuánto se pagó por cada insumo');
      const o = f.orden;
      await this.enviar(`/compras/ordenes/${o.id}/recibir`, {
        comprobante_tipo: f.tipo, comprobante_serie: f.serie.trim() || null, comprobante_numero: f.numero.trim() || null,
        comprobante_fecha: f.fecha, vendedor_id: f.vendedor_id, foto: f.foto,
        items: o.items.map((it) => ({ id: it.id, costo_real: Number(f.pagos[it.id]) })),
      }, null, (r) => `✓ ${r.codigo} recibida con ${r.comprobante} por ${soles(r.total)}: el stock ya subió`);
    },

    /** Lee la foto elegida y la achica (lado mayor 1600 px, JPG) para que no pese. */
    leerFoto(archivo) {
      return new Promise((ok, mal) => {
        if (!archivo) return ok(null);
        if (!/^image\//.test(archivo.type)) return mal(new Error('Elige una imagen (foto del comprobante)'));
        const img = new Image();
        img.onload = () => {
          const k = Math.min(1, 1600 / Math.max(img.width, img.height));
          const c = document.createElement('canvas');
          c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
          c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
          URL.revokeObjectURL(img.src);
          ok(c.toDataURL('image/jpeg', 0.82));
        };
        img.onerror = () => mal(new Error('No se pudo leer la imagen'));
        img.src = URL.createObjectURL(archivo);
      });
    },
    async elegirFoto(e) {
      try { this.f.foto = await this.leerFoto(e.target.files[0]); this.f.fotoNombre = e.target.files[0]?.name || ''; }
      catch (err) { this.f.error = err.message; }
    },
    async agregarFoto(o, e) {
      try {
        const foto = await this.leerFoto(e.target.files[0]);
        if (!foto) return;
        await this.api(`/compras/ordenes/${o.id}/foto`, { foto });
        this.aviso = { texto: `✓ Foto del comprobante de ${o.codigo} guardada` };
        await this.cargar();
      } catch (err) { this.aviso = { error: true, texto: err.message }; }
    },
    async verFoto(o) {
      // se abre la pestaña al toque (si no, el navegador la bloquea) y luego se le pone la foto
      const pestana = window.open('', '_blank');
      try {
        const r = await this.api(`/compras/ordenes/${o.id}/foto`);
        if (pestana) pestana.location = r.url; else window.location = r.url;
      } catch (e) {
        pestana?.close();
        this.aviso = { error: true, texto: 'No se pudo abrir la foto: ' + e.message };
      }
    },
    abrirAnularOrden(o) {
      this.f = { orden: o, motivo: '', error: null, enviando: false };
      this.ventana = 'anularOrden';
    },
    async anularOrden() {
      const f = this.f;
      if (f.motivo.trim().length < 5) return (f.error = 'Escribe por qué se anula la orden');
      await this.enviar(`/compras/ordenes/${f.orden.id}/anular`, { motivo: f.motivo.trim() }, `✓ Orden ${f.orden.codigo} anulada`);
    },
    resumenItems(o) { return o.items.map((it) => `${num(it.cantidad, 3)} ${it.unidad} ${it.insumo}`).join(' · '); },

    // ------------------------------------------------------------ vendedores
    /** [volver]: se abrió desde "Nueva compra"; al guardar vuelve a la compra con el vendedor elegido. */
    abrirVendedor(v = null, volver = false) {
      this.fOrden = volver ? this.f : null;
      this.f = v
        ? { id: v.id, tipo_documento: v.tipo_documento, documento: v.documento, nombre: v.nombre, telefono: v.telefono || '',
            direccion: v.direccion || '', activo: v.activo, error: null, enviando: false }
        : { id: null, tipo_documento: 'RUC', documento: '', nombre: '', telefono: '', direccion: '', activo: true, error: null, enviando: false };
      this.ventana = 'vendedor';
    },
    cerrarVendedor() {
      if (this.fOrden) { this.f = this.fOrden; this.fOrden = null; this.ventana = 'compra'; } else this.ventana = null;
    },
    async guardarVendedor() {
      const f = this.f;
      const doc = f.documento.replace(/\D/g, '');
      if (f.tipo_documento === 'DNI' && !/^\d{8}$/.test(doc)) return (f.error = 'El DNI debe tener 8 dígitos');
      if (f.tipo_documento === 'RUC' && !/^(10|15|16|17|20)\d{9}$/.test(doc)) return (f.error = 'El RUC debe tener 11 dígitos y empezar en 10, 15, 16, 17 o 20');
      if (f.nombre.trim().length < 3) return (f.error = 'Escribe el nombre o la razón social');
      f.enviando = true; f.error = null;
      try {
        const cuerpo = { tipo_documento: f.tipo_documento, documento: doc, nombre: f.nombre.trim(),
          telefono: f.telefono.trim() || null, direccion: f.direccion.trim() || null, ...(f.id ? { activo: f.activo } : {}) };
        const r = await this.api('/compras/vendedores' + (f.id ? '/' + f.id : ''), cuerpo);
        if (this.fOrden) {
          const compra = this.fOrden;
          await this.cargar();
          this.f = compra; this.fOrden = null; this.f.vendedor_id = r.id; this.ventana = 'compra';
        } else {
          this.ventana = null;
          this.aviso = { texto: `✓ ${r.nombre} ${f.id ? 'actualizado' : 'agregado'}` };
          await this.cargar();
        }
      } catch (e) { f.error = e.message; } finally { f.enviando = false; }
    },

    // ---------------------------------------------------------------- kardex
    // desde Almacén: el kardex de ese producto solo
    verKardex(id, tipo = 'insumo') { this.kardex.tipo = tipo; this.kardex.id = id; this.kardex.solo = tipo === 'producto'; this.ir('kardex'); },
    /** Pide el kardex de lo elegido (insumo, producto o leche) con sus fechas. */
    async datosKardex() {
      const k = this.kardex;
      if (k.tipo !== 'leche' && !k.id) return {};
      const q = new URLSearchParams({ tipo: k.tipo });
      if (k.tipo !== 'leche') q.set('id', k.id);
      if (k.tipo === 'producto' && k.solo) q.set('solo', '1');
      if (k.desde) q.set('desde', k.desde);
      if (k.hasta) q.set('hasta', k.hasta);
      return this.api('/compras/kardex?' + q);
    },
    /**
     * Desde / Hasta del kardex. El calendario avisa con cada número que se escribe ("0002", "0020"…):
     * solo se busca cuando la fecha está completa, medio segundo después y sin recargar toda la
     * pantalla (así no se pierde lo que se está escribiendo). Si quedan al revés, se ordenan solas.
     */
    fechaKardex(campo, valor) {
      clearTimeout(this._fechaKardex);
      if (valor && !(/^\d{4}-\d{2}-\d{2}$/.test(valor) && Number(valor.slice(0, 4)) >= 2000)) return;
      this.kardex[campo] = valor || '';
      this._fechaKardex = setTimeout(async () => {
        const k = this.kardex;
        if (k.desde && k.hasta && k.desde > k.hasta) [k.desde, k.hasta] = [k.hasta, k.desde];
        try { this.d = { ...this.d, ...(await this.datosKardex()) }; } catch (e) { this.aviso = { error: true, texto: e.message }; }
      }, 500);
    },
    tipoKardex(tipo, id = null) { this.kardex.tipo = tipo; this.kardex.id = id; this.kardex.solo = false; this.cargar(); },
    /** Los productos de la pestaña elegida (todos los quesos, o el yogurt y sus sabores). */
    productosDelKardex() {
      const g = this.gruposKardex().find((x) => x.ids.includes(this.kardex.id));
      return (this.d.inv?.productos || []).filter((p) => g?.ids.includes(p.id));
    },
    /** "todos" = toda la familia; un id = solo ese producto. */
    elegirProductoKardex(valor) {
      const g = this.gruposKardex().find((x) => x.ids.includes(this.kardex.id));
      if (valor === 'todos') { this.kardex.solo = false; this.kardex.id = g?.id ?? this.kardex.id; } else { this.kardex.solo = true; this.kardex.id = Number(valor); }
      this.cargar();
    },
    /** Productos que van en un mismo kardex: todos los quesos, todos los yogures (igual que Kardex::producto). */
    gruposKardex() {
      const nombres = { YOGURT: 'Yogurt', QUESO: 'Queso' };
      const grupos = new Map();
      for (const p of this.d.inv?.productos || []) {
        const clave = nombres[p.tipo] ? p.tipo + '|' + p.unidad : 'p' + (p.base_id ?? p.id);
        if (!grupos.has(clave)) grupos.set(clave, { id: p.id, ids: [], nombre: nombres[p.tipo] || p.nombre });
        grupos.get(clave).ids.push(p.id);
      }
      return [...grupos.values()];
    },
    // pestañas: Insumos | Queso (todos) | Yogurt (todos, con sus sabores) | Leche
    pestanasKardex() {
      return [
        { tipo: 'insumo', id: null, nombre: 'Insumos' },
        ...this.gruposKardex().map((g) => ({ tipo: 'producto', id: g.id, nombre: g.nombre })),
        { tipo: 'leche', id: null, nombre: 'Leche' },
      ];
    },
    pestanaKardexActiva(t) {
      return this.kardex.tipo === t.tipo
        && (t.tipo !== 'producto' || !!this.gruposKardex().find((g) => g.id === t.id)?.ids.includes(this.kardex.id));
    },

    // -------------------------------------------------------------- pérdidas
    async abrirMerma(tipo = 'PRODUCTO', productoId = null) {
      this.f = { tipo, producto_id: productoId, lote_id: null, cantidad: '', motivo: null, detalle: '', datos: null, error: null, enviando: false };
      this.ventana = 'merma';
      try {
        this.f.datos = await this.api('/compras/mermas');
        if (tipo === 'PRODUCTO' && !productoId) this.f.producto_id = this.f.datos.productos[0]?.id ?? null;
      } catch (e) { this.f.error = e.message; }
    },
    productoMerma() { return (this.f.datos?.productos || []).find((p) => p.id === this.f.producto_id); },
    hayParaMerma() {
      const f = this.f;
      if (!f.datos) return { cant: 0, u: '' };
      if (f.tipo === 'LECHE') return { cant: f.datos.leche.sin_usar, u: 'L' };
      const p = this.productoMerma();
      return { cant: p?.stock ?? 0, u: p?.unidad ?? '' };
    },
    async guardarMerma() {
      const f = this.f; const hay = this.hayParaMerma();
      if (f.tipo === 'PRODUCTO' && !f.producto_id) return (f.error = 'Elige si es queso o yogurt');
      if (!(Number(f.cantidad) > 0)) return (f.error = 'Escribe cuánto se perdió');
      if (Number(f.cantidad) > hay.cant) return (f.error = `Solo hay ${num(hay.cant, 2)} ${hay.u}`);
      if (!f.motivo) return (f.error = 'Elige el motivo');
      if (f.motivo === 'OTRO' && !f.detalle.trim()) return (f.error = 'Escribe qué pasó');
      const que = f.tipo === 'LECHE' ? 'leche' : this.productoMerma().nombre;
      await this.enviar('/compras/mermas', {
        tipo: f.tipo, producto_id: f.tipo === 'PRODUCTO' ? f.producto_id : null, lote_id: f.lote_id || null,
        cantidad: Number(f.cantidad), motivo: f.motivo, detalle: f.detalle.trim() || null,
      }, null, (r) => `✓ Pérdida registrada: ${num(f.cantidad, 2)} ${hay.u} de ${que} · ahora quedan ${num(r.stock, 2)} ${hay.u}`);
    },
    opcionesKardex() {
      const inv = this.d.inv || {};
      return this.kardex.tipo === 'producto'
        ? (inv.productos || []).map((p) => ({ id: p.id, nombre: p.nombre + ' (' + p.unidad.split(' ')[0] + ')' }))
        : (inv.insumos || []).map((i) => ({ id: i.id, nombre: i.nombre + ' (' + i.unidad + ')' }));
    },
    kardexContable: false,
    /** "Entraron 60 kg (60 kg por compras). Salieron 31.6 kg (31.6 kg para hacer lotes). Ahora quedan 28.4 kg." */
    resumenKardex() {
      const d = this.d; if (!d.item || !d.totales) return '';
      const u = d.item.unidad; const t = d.totales;
      const POR_ENTRA = { INICIAL: 'que ya había', COMPRA: 'por compras', AJUSTE: 'por correcciones', ACOPIO: 'de acopio aceptado', PRODUCCION: 'hechos en lotes' };
      const POR_SALE = { PRODUCCION: 'para hacer lotes', VENTA: 'vendidos', AJUSTE: 'por correcciones', MERMA: 'perdidos' };
      const detalle = (lado, nombres) => (d.resumen || []).filter((r) => r[lado] > 0)
        .map((r) => `${num(r[lado], 3)} ${u} ${nombres[r.tipo] || r.tipo.toLowerCase()}`).join(', ');
      const esc = (s) => String(s).replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
      const partes = [];
      if (this.kardex.desde) partes.push(`Al ${fechaCorta(this.kardex.desde)} había <b>${num(t.inicio, 3)} ${esc(u)}</b>.`);
      partes.push(t.entradas.cantidad > 0
        ? `Entraron <b class="ok">${num(t.entradas.cantidad, 3)} ${esc(u)}</b> (${esc(detalle('entra', POR_ENTRA))}).` : 'No entró nada.');
      partes.push(t.salidas.cantidad > 0
        ? `Salieron <b>${num(t.salidas.cantidad, 3)} ${esc(u)}</b> (${esc(detalle('sale', POR_SALE))}).` : 'No salió nada.');
      partes.push(`${this.kardex.hasta ? 'Al ' + fechaCorta(this.kardex.hasta) + ' quedaban' : 'Ahora quedan'} <b>${num(t.saldo.cantidad, 3)} ${esc(u)}</b>.`);
      return partes.join(' ');
    },
    TIPO_KARDEX: { INICIAL: 'Saldo inicial', ANTERIOR: 'Saldo anterior', COMPRA: 'Compra', PRODUCCION: 'Producción',
      AJUSTE: 'Corrección', VENTA: 'Venta', ACOPIO: 'Acopio', MERMA: 'Pérdida' },
    cu: (v) => 'S/ ' + Number(v || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 4 }),
    /** Qué pasó con el insumo, en palabras de todos los días. "contamos": se escribe cuánto hay y se calcula la diferencia. */
    RAZONES_AJUSTE: [
      { id: 'malogro', texto: 'Se malogró, mojó o derramó', tipo: 'SALIDA', motivo: 'Se malogró' },
      { id: 'vencio', texto: 'Se venció', tipo: 'SALIDA', motivo: 'Se venció' },
      { id: 'contamos', texto: 'Contamos y no cuadra', tipo: null, motivo: 'Conteo' },
      { id: 'devolvieron', texto: 'Nos devolvieron', tipo: 'ENTRADA', motivo: 'Devolución' },
      { id: 'otro', texto: 'Otra cosa', tipo: null, motivo: '' },
    ],
    /** Corregir el stock de un insumo (desde su fila en Almacén): { id, nombre, stock, unidad }. */
    abrirAjuste(item) {
      this.f = { item, razon: null, tipo: 'SALIDA', cantidad: '', hay: '', detalle: '', error: null, enviando: false };
      this.ventana = 'ajuste';
    },
    razonAjuste() { return this.RAZONES_AJUSTE.find((r) => r.id === this.f.razon); },
    /** Al contar: cuánto cambia el stock (positivo sobra, negativo falta). */
    diferenciaConteo() { return this.f.hay === '' ? null : Number(this.f.hay) - Number(this.f.item?.stock || 0); },
    async guardarAjuste() {
      const f = this.f; const i = f.item; const r = this.razonAjuste();
      if (!r) return (f.error = 'Elige qué pasó');
      let tipo = r.tipo ?? f.tipo; let cantidad = Number(f.cantidad);
      if (r.id === 'contamos') {
        const dif = this.diferenciaConteo();
        if (dif === null || !(Number(f.hay) >= 0)) return (f.error = '¿Cuánto hay de verdad? Escríbelo');
        if (Math.abs(dif) < 0.0005) return (f.error = 'Cuadra con lo que dice el sistema: no hay nada que corregir');
        tipo = dif > 0 ? 'ENTRADA' : 'SALIDA'; cantidad = Math.abs(dif);
      } else if (!(cantidad > 0)) return (f.error = 'Escribe la cantidad');
      if (r.id === 'otro' && f.detalle.trim().length < 3) return (f.error = 'Cuenta brevemente qué pasó');
      if (tipo === 'SALIDA' && cantidad > i.stock) return (f.error = `Solo hay ${num(i.stock, 3)} ${i.unidad}`);
      const motivo = r.id === 'contamos'
        ? `Conteo: había ${num(f.hay, 3)} ${i.unidad} (el sistema decía ${num(i.stock, 3)})`
        : [r.motivo, f.detalle.trim()].filter(Boolean).join(' · ');
      await this.enviar('/compras/ajustes', { insumo_id: i.id, tipo, cantidad: Number(cantidad.toFixed(3)), motivo },
        null, (res) => `✓ Corregido · ${res.insumo} queda con ${num(res.stock, 3)} ${i.unidad}`);
    },

    // -------------------------------------------------------------- fórmulas
    // la leche se calcula con el rendimiento mínimo, así la cantidad pedida sale segura
    unidadProducto(p) { return String(p?.unidad || '').split(' ')[0]; }, // "quesos de 1 kg" → "quesos"
    /** Lo que se cuenta por unidad (envases) va entero: no existe medio envase. Igual que en el servidor. */
    // ---------------------------------------------------------- recetario
    /** Cada base (Queso Paria, Yogurt natural…) con sus sabores, filtrado por tipo. */
    recetasBase() {
      const todos = this.d.productos || [];
      return todos.filter((p) => !p.base_id || !todos.some((b) => b.id === p.base_id))
        .filter((p) => this.recetaFiltro === 'TODOS' || (p.tipo || 'OTRO') === this.recetaFiltro)
        .map((p) => ({ p, sabores: todos.filter((x) => x.base_id === p.id) }));
    },
    recetaActual() { return (this.d.productos || []).find((p) => p.id === this.recetaAbierta); },
    /** La base y sus sabores, para pasar de uno a otro dentro de la ficha. */
    familiaReceta(p) {
      const todos = this.d.productos || [];
      const base = p.base_id ? todos.find((b) => b.id === p.base_id) || p : p;
      return [base, ...todos.filter((x) => x.base_id === base.id)];
    },
    verReceta(p) {
      if (this.recetaAbierta === p.id) { this.recetaAbierta = null; return; }
      this.recetaAbierta = p.id;
      this.$nextTick(() => document.getElementById('ficha-receta')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    },
    /** Color de cada receta: el de la fruta en los sabores, crema o dorado en quesos y yogurt. */
    colorReceta(p) {
      const n = ((p?.variante || '') + ' ' + (p?.nombre || '')).toLowerCase();
      const frutas = [['fresa', '#e5566f'], ['frambuesa', '#d6336c'], ['mora', '#8e3f8f'], ['arándano', '#5b5fc7'], ['maracuy', '#f0b429'],
        ['lúcuma', '#c98a3c'], ['lucuma', '#c98a3c'], ['plátano', '#f2d14a'], ['platano', '#f2d14a'], ['durazno', '#f4a261'],
        ['mango', '#f7a325'], ['piña', '#e9c46a'], ['coco', '#e9e4d8'], ['vainilla', '#efe1b0'], ['guanábana', '#cfe3c0'],
        ['aguaymanto', '#f39c12'], ['chirimoya', '#b5cf8a'], ['camu', '#c0392b'], ['orégano', '#7aa65a'], ['ají', '#e76f51'],
        ['hierbas', '#6a994e'], ['aceituna', '#556b2f'], ['pimienta', '#5c4a3d']];
      const f = frutas.find(([k]) => n.includes(k));
      if (f) return f[1];
      if (p?.tipo === 'QUESO') return n.includes('mantecoso') ? '#f3e2a9' : n.includes('andino') ? '#e7b54a' : '#f1cf6b';
      if (p?.tipo === 'YOGURT') return '#e9e3d3';
      return '#9fc5e8';
    },
    /** Dibujo de la receta (SVG): rueda de queso con su tajada, vasito de yogurt o botella. */
    dibujoReceta(p) {
      const c = this.colorReceta(p);
      if (p?.tipo === 'QUESO') return `<svg viewBox="0 0 120 90" aria-hidden="true">
        <ellipse cx="60" cy="76" rx="48" ry="9" fill="rgba(0,0,0,.28)"/>
        <path d="M14 40 v22 a46 14 0 0 0 92 0 v-22" fill="${c}" style="filter:brightness(.8)"/>
        <ellipse cx="60" cy="40" rx="46" ry="14" fill="${c}"/>
        <path d="M60 40 L104 36 L104 58 L60 62 Z" fill="${c}" style="filter:brightness(.68)"/>
        <path d="M60 40 L104 36 A46 14 0 0 0 96 31 Z" fill="#0f1a24" opacity=".5"/>
        <circle cx="36" cy="38" r="3.4" fill="rgba(0,0,0,.18)"/><circle cx="50" cy="45" r="2.3" fill="rgba(0,0,0,.18)"/>
        <circle cx="28" cy="57" r="3" fill="rgba(0,0,0,.16)"/><circle cx="80" cy="51" r="2.6" fill="rgba(0,0,0,.2)"/>
        <circle cx="46" cy="64" r="2.1" fill="rgba(0,0,0,.16)"/><circle cx="92" cy="47" r="1.8" fill="rgba(0,0,0,.2)"/></svg>`;
      if (p?.tipo === 'YOGURT') return `<svg viewBox="0 0 120 90" aria-hidden="true">
        <ellipse cx="60" cy="83" rx="30" ry="5" fill="rgba(0,0,0,.28)"/>
        <path d="M34 22 L40 80 H80 L86 22 Z" fill="#f4f1ea"/>
        <path d="M37 46 L40 80 H80 L83 46 Z" fill="${c}"/>
        <path d="M37 46 q11 -6 23 0 t23 0" fill="${c}" style="filter:brightness(1.08)"/>
        <rect x="30" y="15" width="60" height="9" rx="3" fill="#2e8b57"/>
        <path d="M45 30 L47 76" stroke="#fff" stroke-width="3" stroke-linecap="round" opacity=".45"/>
        ${p.base_id ? `<circle cx="89" cy="22" r="11" fill="${c}" stroke="#0f1a24" stroke-width="2"/><path d="M89 11 q4 -6 9 -4" stroke="#4cc38a" stroke-width="2.5" fill="none"/>` : ''}</svg>`;
      return `<svg viewBox="0 0 120 90" aria-hidden="true"><ellipse cx="60" cy="83" rx="26" ry="5" fill="rgba(0,0,0,.28)"/>
        <rect x="42" y="18" width="36" height="64" rx="10" fill="${c}"/><rect x="50" y="8" width="20" height="12" rx="3" fill="#2e8b57"/></svg>`;
    },
    /** 0,02 kg → "20 g"; 0,01 L → "10 ml"; envases enteros. */
    cantidadBonita(c, unidad) {
      const u = String(unidad || '').trim();
      if (u === 'kg' && c > 0 && c < 1) return num(c * 1000, c * 1000 < 10 ? 1 : 0) + ' g';
      if (u === 'L' && c > 0 && c < 1) return num(c * 1000, c * 1000 < 10 ? 1 : 0) + ' ml';
      return num(c, c >= 100 ? 0 : 2) + ' ' + u;
    },
    /** Insumos de la receta para [litros], con lo que hay en almacén. */
    insumosReceta(p, litros) {
      return (p.insumos || []).map((i) => {
        const necesita = this.cantidadInsumo(i.por_100l, litros, i.unidad);
        return { ...i, necesita, alcanza: i.stock + 1e-9 >= necesita,
          cubre: necesita > 0 ? Math.min(1, i.stock / necesita) : 1,
          propio: (p.propios || []).some((x) => x.insumo_id === i.id) };
      });
    },
    /** Lo que sale de [litros] de leche: "13–13,3 quesos". */
    salenReceta(p, litros) {
      const a = p.rendimiento_min * litros, b = p.rendimiento_max * litros;
      return num(a, 1) + (Math.abs(b - a) > 0.05 ? '–' + num(b, 1) : '') + ' ' + this.unidadProducto(p);
    },
    /** Cada paso con su ícono y lo que dice de temperatura y de tiempo (sacado del mismo texto). */
    pasosReceta(p) {
      // el primero que calza en el texto (los más específicos primero: "Cortar la cuajada" es cortar, no cuajo)
      const ICONOS = [[/filtr/i, '🧺'], [/pasteuri/i, '🔥'], [/incub/i, '🌡️'], [/cort/i, '🔪'], [/suero|desagu/i, '💧'],
        [/mol(er|ino)/i, '⚙️'], [/salmuera|salar|\bsal\b/i, '🧂'], [/mold/i, '🧱'], [/prens/i, '⚖️'], [/cuaj/i, '🧪'],
        [/enfri/i, '❄️'], [/agreg|disolv/i, '➕'], [/madur|orear|secar/i, '⏳'], [/envas|empac|envolver/i, '📦'], [/bat|agit|amas/i, '🥄']];
      const n = (x) => Number(String(x).replace(',', '.'));
      return (p.procedimiento || []).map((texto, k) => {
        // "nunca más de 35 °C" es un límite, no una temperatura del paso
        const temps = [...texto.matchAll(/(\d+(?:[.,]\d+)?)(?:\s*[–-]\s*(\d+(?:[.,]\d+)?))?\s*°C/g)]
          .filter((m) => !/(más de|máx\.?|menos de)\s*$/i.test(texto.slice(Math.max(0, m.index - 12), m.index)))
          .map((m) => ({ txt: m[0].replace(/\s+/g, ' '), valor: m[2] ? (n(m[1]) + n(m[2])) / 2 : n(m[1]) }));
        // largo = esperar (desaguar, prensar solo, madurar): de 8 horas para arriba no es trabajo en la tina
        const tiempos = [...texto.matchAll(/(\d+(?:[.,]\d+)?)(?:\s*[–-]\s*(\d+(?:[.,]\d+)?))?\s*(minutos?|horas?|días?|semanas?)/gi)]
          .map((m) => ({ txt: m[0], min: n(m[1]) * ({ m: 1, h: 60, d: 1440, s: 10080 }[m[3][0].toLowerCase()] || 1) }))
          .map((t) => ({ ...t, largo: t.min >= 480 }));
        return { n: k + 1, texto, icono: (ICONOS.find(([r]) => r.test(texto)) || [null, '•'])[1], temps, tiempos };
      });
    },
    /** Curva de temperatura: un punto por cada °C que dicen los pasos, en orden y parejos (viewBox 0 0 320 120). */
    curvaReceta(p) {
      const temps = this.pasosReceta(p).flatMap((x) => x.temps.map((t) => ({ ...t, n: x.n })));
      if (temps.length < 2) return null;
      const max = Math.max(90, ...temps.map((t) => t.valor));
      const X = (k) => 34 + (k / (temps.length - 1)) * 266, Y = (t) => 98 - (t / max) * 76;
      const puntos = temps.map((t, k) => ({ x: X(k), y: Y(t.valor), t: t.txt.replace(' °C', '°'), n: t.n,
        color: t.valor >= 60 ? '#ef6b6b' : t.valor >= 30 ? '#e8b44c' : '#7fd4e0' }));
      const linea = puntos.map((q, k) => (k ? 'L' : 'M') + q.x.toFixed(1) + ' ' + q.y.toFixed(1)).join(' ');
      return { puntos, linea, area: linea + ` L${puntos.at(-1).x.toFixed(1)} 100 L${puntos[0].x.toFixed(1)} 100 Z`,
        guias: [0, 30, 60, 90].filter((t) => t <= max).map((t) => ({ t, y: Y(t) })) };
    },
    /** La curva lista para pintar (SVG con guías de 0/30/60/90 °C, área y un punto por paso). */
    svgCurva(p) {
      const c = this.curvaReceta(p);
      if (!c) return '';
      const id = 'calor-' + p.id;
      return `<svg viewBox="0 0 320 120" role="img" aria-label="Temperatura paso a paso">
        <defs><linearGradient id="${id}" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stop-color="#ef6b6b" stop-opacity=".45"/><stop offset="1" stop-color="#7fd4e0" stop-opacity=".04"/></linearGradient></defs>
        ${c.guias.map((g) => `<line x1="26" x2="310" y1="${g.y}" y2="${g.y}" class="guia"/><text x="0" y="${g.y + 3}" class="eje">${g.t}°</text>`).join('')}
        <path d="${c.area}" fill="url(#${id})"/>
        <path d="${c.linea}" class="linea"/>
        ${c.puntos.map((q) => `<circle cx="${q.x}" cy="${q.y}" r="5" fill="${q.color}"/>
          <text x="${q.x}" y="${q.y - 9}" class="valor">${q.t}</text>
          <text x="${q.x}" y="116" class="eje centro">paso ${q.n}</text>`).join('')}</svg>`;
    },
    /** Tiempo de trabajo aproximado (minutos y horas de los pasos) y lo que tarda aparte (días, semanas). */
    tiempoReceta(p) {
      const pasos = this.pasosReceta(p);
      const corto = pasos.reduce((t, x) => t + x.tiempos.filter((y) => !y.largo).reduce((a, y) => a + y.min, 0), 0);
      const largo = pasos.flatMap((x) => x.tiempos.filter((y) => y.largo).map((y) => y.txt));
      return { texto: corto ? (corto < 90 ? Math.round(corto) + ' min' : '≈ ' + num(corto / 60, corto / 60 < 10 ? 1 : 0) + ' h') : '—', largo };
    },
    imprimirReceta() {
      document.documentElement.classList.add('imprimir-receta');
      setTimeout(() => { window.print(); document.documentElement.classList.remove('imprimir-receta'); }, 50);
    },
    cantidadInsumo(por100, litros, unidad) {
      const c = (por100 * litros) / 100;
      return ['un', 'und', 'unidad', 'unidades'].includes(String(unidad).trim().toLowerCase()) ? Math.ceil(c - 1e-9) : c;
    },
    /**
     * Varios productos a la vez: la leche y los insumos que comparten (el cultivo
     * láctico) se suman. { elegidos, litros, insumos, ok, motivo }
     */
    planVarios() {
      const elegidos = (this.d.productos || []).map((p) => {
        const q = Number(this.varios[p.id]) || 0;
        return { p, q, litros: q > 0 && p.rendimiento_min > 0 ? Math.ceil(q / p.rendimiento_min - 1e-9) : 0 };
      }).filter((e) => e.q > 0);
      const litros = elegidos.reduce((t, e) => t + e.litros, 0);
      const porInsumo = {};
      elegidos.forEach((e) => e.p.insumos.forEach((i) => {
        porInsumo[i.id] ??= { nombre: i.nombre, unidad: i.unidad, stock: i.stock, necesita: 0, productos: [] };
        porInsumo[i.id].necesita += this.cantidadInsumo(i.por_100l, e.litros, i.unidad);
        porInsumo[i.id].productos.push(e.p.nombre);
      }));
      const insumos = Object.values(porInsumo).sort((a, b) => b.productos.length - a.productos.length || a.nombre.localeCompare(b.nombre));
      const falta = [];
      if (litros > this.d.tanque) falta.push(`leche (${num(litros - this.d.tanque, 1)} L)`);
      insumos.filter((i) => i.necesita > i.stock + 1e-9).forEach((i) => falta.push(`${i.nombre.toLowerCase()} (${num(i.necesita - i.stock, 3)} ${i.unidad})`));
      const ok = elegidos.length > 0 && !falta.length;
      const motivo = !elegidos.length ? 'Escribe cuánto quieres hacer (deja en blanco lo que no).'
        : falta.length ? `Falta ${falta.join(', ')}. Baja alguna cantidad o consigue lo que falta.` : '';
      return { elegidos, litros, insumos, ok, motivo };
    },
    /** Producir: cada base con sus sabores, separados en Quesos / Yogurt / Otros. */
    gruposProducir() {
      const todos = this.d.productos || [];
      const familias = todos.filter((p) => !p.base_id || !todos.some((b) => b.id === p.base_id))
        .map((base) => ({ base, items: [base, ...todos.filter((x) => x.base_id === base.id)] }));
      // los quesos van todos juntos en un solo "Quesos" que se despliega (como el yogurt con sus sabores)
      const quesos = familias.filter((fa) => fa.base.tipo === 'QUESO');
      const juntos = quesos.length > 1
        ? [{ base: { id: 'QUESOS', nombre: 'Quesos', tipo: 'QUESO', unidad: quesos[0].base.unidad }, items: quesos.flatMap((fa) => fa.items), mezcla: true }]
        : quesos;
      return [['QUESO', '🧀 Quesos'], ['YOGURT', '🥛 Yogurt'], ['OTRO', 'Otros']]
        .map(([tipo, nombre]) => ({ tipo, nombre, familias: tipo === 'QUESO' ? juntos : familias.filter((fa) => (fa.base.tipo || 'OTRO') === tipo) }))
        .filter((g) => g.familias.length);
    },
    /** Cómo se llama cada renglón dentro de su lista: "Queso Paria", "Fresa", "Natural". */
    nombreEnFamilia(fa, x) {
      if (fa.mezcla) return x.nombre;
      return x.base_id ? x.variante : (fa.items.length > 1 ? 'Natural' : x.nombre);
    },
    /** Lo que dice la familia cerrada: lo elegido ("60 litros natural · 40 fresa") o cuántos sabores tiene. */
    resumenFamilia(fa) {
      const u = /litro/i.test(fa.base.unidad) ? 'L' : this.unidadProducto(fa.base);
      const elegidos = fa.items.filter((x) => Number(this.varios[x.id]) > 0).map((x) => num(Number(this.varios[x.id]), 0) + ' ' +
        (fa.mezcla ? x.nombre.replace(/^queso\s+/i, '') : u + ' ' + (x.base_id ? x.variante.toLowerCase() : 'natural')));
      if (elegidos.length) return elegidos.join(' · ');
      return fa.items.length + (fa.mezcla ? ' tipos de queso' : fa.base.tipo === 'YOGURT' ? ' sabores' : ' variedades') + ' · toca para elegir';
    },
    /** Litros de leche que usa lo elegido de [p] (0 si no se hace). */
    litrosDe(p) { return this.planVarios().elegidos.find((e) => e.p.id === p.id)?.litros || 0; },
    /** Lo más que se puede hacer de [p] con la leche que queda (sin contar lo que ya usa p) y sus insumos. */
    maximoDe(p) {
      const libre = Math.max(0, (this.d.tanque || 0) - (this.planVarios().litros - this.litrosDe(p)));
      const litros = p.alcanza_litros === null || p.alcanza_litros === undefined ? libre : Math.min(libre, p.alcanza_litros);
      return Math.floor(litros * p.rendimiento_min + 1e-9);
    },
    /** − / + de a 1 (quesos) o de a 5 (litros de yogurt). */
    sumarVarios(p, signo) {
      const paso = /litro/i.test(p.unidad) ? 5 : 1;
      const n = Math.max(0, (Number(this.varios[p.id]) || 0) + signo * paso);
      this.varios[p.id] = n ? String(n) : '';
    },
    /** El tanque dibujado: un tramo por producto elegido (de su color) y lo que queda libre. */
    tanqueProducir() {
      const plan = this.planVarios(), tanque = Math.max(this.d.tanque || 0, 0.0001);
      const total = Math.max(tanque, plan.litros);
      return {
        tramos: plan.elegidos.map((e) => ({ id: e.p.id, nombre: e.p.nombre, litros: e.litros, ancho: (e.litros / total) * 100, color: this.colorReceta(e.p) })),
        libre: Math.max(0, (this.d.tanque || 0) - plan.litros), pasa: plan.litros > (this.d.tanque || 0) + 1e-9,
        marca: (tanque / total) * 100, // dónde termina el tanque cuando se pide más de lo que hay
        usado: Math.min(100, (plan.litros / total) * 100), // dónde está la superficie (la ola)
      };
    },
    /** Los litros del tanque cuentan subiendo o bajando (½ s) hasta [hasta]; sin animación si el sistema la pide quieta. */
    animarUso(hasta) {
      // el valor en curso vive fuera de Alpine (ANIM_USO): leerlo de this haría que cada cuadro vuelva a disparar el x-effect
      cancelAnimationFrame(ANIM_USO.cuadro);
      const desde = ANIM_USO.valor, t0 = performance.now();
      const fijar = (v) => { ANIM_USO.valor = v; this.usoAnimado = v; };
      if (matchMedia('(prefers-reduced-motion: reduce)').matches || desde === hasta) { fijar(hasta); return; }
      const paso = (t) => {
        const k = Math.min(1, Math.max(0, (t - t0) / 500)); // el cuadro puede traer una hora un poco anterior a t0
        fijar(k < 1 ? desde + (hasta - desde) * (1 - Math.pow(1 - k, 3)) : hasta);
        if (k < 1) ANIM_USO.cuadro = requestAnimationFrame(paso);
      };
      ANIM_USO.cuadro = requestAnimationFrame(paso);
    },
    textoVarios(plan) { return plan.elegidos.map((e) => `${num(e.q, 0)} ${this.unidadProducto(e.p)} de ${e.p.nombre}`).join(' y '); },
    abrirProducirVarios() {
      this.f = { plan: this.planVarios(), error: null, enviando: false };
      this.ventana = 'producir-varios';
    },
    async confirmarProducirVarios() {
      const f = this.f;
      f.enviando = true; f.error = null;
      try {
        const r = await this.api('/planta/lotes/varios', { lotes: f.plan.elegidos.map((e) => ({ producto_id: e.p.id, litros: e.litros })) });
        this.ventana = null;
        this.varios = {};
        await this.ir('lotes');
        this.aviso = { texto: `✓ ${r.lotes.length === 1 ? 'Lote iniciado' : r.lotes.length + ' lotes iniciados a la vez'}: ` + r.lotes.map((l) => `${l.codigo} ${l.producto} (${num(l.litros, 0)} L)`).join(', ') +
          '. Se descontaron la leche y los insumos. Ciérralos aquí cuando terminen.' };
      } catch (e) { f.error = e.message; } finally { f.enviando = false; }
    },

    // ----------------------------------------------------- lotes en producción
    cierre: {}, // lote => cantidad que salió
    /** Lo esperado con sus decimales (redondeado engañaba: 8.55 L se veía "9 a 9"). */
    esperadoLote(l) {
      const u = l.unidad.split(' ')[0];
      return Number(l.esperado_min) === Number(l.esperado_max)
        ? `${num(l.esperado_min, 2)} ${u}` : `${num(l.esperado_min, 2)} a ${num(l.esperado_max, 2)} ${u}`;
    },
    /** Lo que se da por bueno al cerrar: 3 % menos o más que lo esperado (igual que el servidor). */
    aceptadoLote(l) {
      return `${num(l.esperado_min * 0.97, 2)} a ${num(l.esperado_max * 1.03, 2)} ${l.unidad.split(' ')[0]}`;
    },
    async cerrarLote(l) {
      const obtenido = Number(this.cierre[l.id] ?? '');
      const u = l.unidad.split(' ')[0];
      if (!(obtenido >= 0) || this.cierre[l.id] === '' || this.cierre[l.id] == null) {
        this.aviso = { error: true, texto: `Escribe cuántos ${u} salieron del lote ${l.codigo}` }; return;
      }
      // igual que el servidor: fuera de lo esperado si sale 3 % menos o 3 % más
      const medio = (Number(l.esperado_min) + Number(l.esperado_max)) / 2;
      const desvio = medio > 0 ? ((obtenido - medio) / medio) * 100 : 0;
      const fuera = obtenido < l.esperado_min * 0.97 || obtenido > l.esperado_max * 1.03;
      if (!(await this.preguntar({
        icono: fuera ? '⚠️' : '✅', tono: fuera ? 'aviso' : '', titulo: `¿Cerrar el lote ${l.codigo}?`,
        texto: `Entran ${num(obtenido, 2)} ${u} de ${l.producto} al stock y ya se pueden vender.` +
          (fuera ? ` Salió fuera de lo esperado (se acepta de ${this.aceptadoLote(l)}): revisa que la cantidad esté bien escrita.` : ''),
        filas: [
          { k: 'Leche usada', v: num(l.litros_leche, 2) + ' L' },
          { k: 'Se esperaba', v: this.esperadoLote(l) },
          { k: 'Salió', v: `${num(obtenido, 2)} ${u}`, clase: fuera ? 'mal' : 'ok' },
          { k: 'Diferencia', v: (desvio > 0 ? '+' : '') + num(desvio, 1) + ' %', clase: fuera ? 'mal' : 'ok' },
        ],
        si: 'Sí, cerrar lote', no: 'Todavía no',
      }))) return;
      try {
        const r = await this.api(`/planta/lotes/${l.id}/cerrar`, { obtenido });
        await this.cargar();
        this.aviso = {
          icono: r.fuera_referencia ? '⚠' : '✓', titulo: `Lote ${r.codigo} cerrado`,
          texto: `Entraron ${num(obtenido, 2)} ${u} de ${l.producto} al stock. Ya se pueden vender.`,
          detalle: `Diferencia con lo esperado: ${r.desvio_pct > 0 ? '+' : ''}${num(r.desvio_pct, 1)} %` + (r.fuera_referencia ? ' · salió fuera de lo esperado' : ' · dentro de lo esperado'),
        };
      } catch (e) { this.aviso = { error: true, texto: e.message }; }
    },
    abrirFormula(p) {
      this.f = {
        producto: p,
        insumos: p.insumos.map((i) => ({ insumo_id: i.id, por_100l: i.por_100l })),
        pasos: p.procedimiento.join('\n'),
        error: null, enviando: false,
      };
      this.ventana = 'formula';
    },
    unidadInsumo(id) { return (this.d.insumos || []).find((i) => i.id === Number(id))?.unidad || ''; },
    async guardarFormula() {
      const f = this.f;
      const filas = f.insumos.filter((x) => x.insumo_id);
      if (filas.some((x) => !(Number(x.por_100l) > 0))) return (f.error = 'Cada insumo necesita una cantidad mayor a 0');
      const ids = filas.map((x) => Number(x.insumo_id));
      if (new Set(ids).size !== ids.length) return (f.error = 'Hay un insumo repetido');
      await this.enviar(`/admin/formulas/${f.producto.id}`, {
        insumos: filas.map((x) => ({ insumo_id: Number(x.insumo_id), por_100l: Number(x.por_100l) })),
        procedimiento: f.pasos.split(/\r?\n/).map((x) => x.trim()).filter(Boolean),
      }, `✓ Fórmula de ${f.producto.nombre} guardada · los próximos lotes ya la usan`);
    },

    // --------------------------------------------------------------- común
    async enviar(ruta, cuerpo, textoOk, textoDesde = null) {
      this.f.enviando = true; this.f.error = null;
      try {
        const r = await this.api(ruta, cuerpo);
        this.ventana = null;
        this.aviso = { texto: textoDesde ? textoDesde(r) : textoOk };
        await this.cargar();
      } catch (e) { this.f.error = e.message; } finally { this.f.enviando = false; }
    },
  };
}
