/*
 * Ecolácteos Huata · Oficina (web para Admin y Compras).
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
const TIPO_CLIENTE = { MAYORISTA: 'Mayorista', DIRECTO: 'Vecino de Huata', PLANTA: 'Proveedor de leche' };
const ROLES = { ACOPIADOR: 'Acopiador', CALIDAD: 'Calidad', OPERARIO: 'Operario', COMPRAS: 'Compras', ADMIN: 'Administrador', PRODUCTOR: 'Productor' };
const esCorreo = (s) => /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(String(s || '').trim());

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
    ventana: null,  // formulario/diálogo abierto
    f: {},          // campos del formulario abierto

    // filtros de secciones
    periodo: 'semana',
    semana: null,
    dinero: 'ventas',
    padron: 'proveedores',
    adulteracion: 'todos',
    buscar: '',

    soles, num, fechaCorta, hora, TIPO_CLIENTE, ROLES, esCorreo,

    iniciar() {
      if (this.usuario) this.ir(this.menu()[0].id);
    },

    menu() {
      const admin = this.usuario?.rol === 'ADMIN';
      const compras = [
        { id: 'vender', nombre: 'Vender', grupo: 'Compras y ventas' },
        { id: 'inventario', nombre: 'Inventario', grupo: 'Compras y ventas' },
        { id: 'ordenes', nombre: 'Órdenes de compra', grupo: 'Compras y ventas' },
        { id: 'movimientos', nombre: 'Movimientos', grupo: 'Compras y ventas' },
      ];
      if (!admin) return compras;
      return [
        { id: 'totales', nombre: 'Totales de acopio', grupo: 'Administración' },
        { id: 'dinero', nombre: 'Dinero · ventas y pagos', grupo: 'Administración' },
        { id: 'padron', nombre: 'Padrón', grupo: 'Administración' },
        { id: 'calidad', nombre: 'Calidad por vehículo', grupo: 'Administración' },
        { id: 'adulteraciones', nombre: 'Adulteraciones', grupo: 'Administración' },
        { id: 'solicitudes', nombre: 'Cambios de zona', grupo: 'Administración' },
        { id: 'conciliacion', nombre: 'Conciliación', grupo: 'Administración' },
        { id: 'costos', nombre: 'Costos y rendimiento', grupo: 'Administración' },
        { id: 'sesiones', nombre: 'Sesiones abiertas', grupo: 'Administración' },
        ...compras,
      ];
    },

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
        if (!['ADMIN', 'COMPRAS'].includes(j.rol)) {
          throw new Error('La web de oficina es para Administración y Compras. Tu rol (' + (ROLES[j.rol] || j.rol) + ') usa la app del celular.');
        }
        this.token = j.token;
        this.usuario = { nombre: j.usuario?.nombre, rol: j.rol };
        sessionStorage.setItem('token', this.token);
        sessionStorage.setItem('usuario', JSON.stringify(this.usuario));
        l.clave = '';
        this.ir(this.menu()[0].id);
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
      try {
        switch (this.seccion) {
          case 'totales': this.d = await this.api('/admin/totales?periodo=' + this.periodo); break;
          case 'dinero':
            this.d = this.dinero === 'ventas'
              ? await this.api('/admin/negocio?periodo=' + this.periodo)
              : await this.api('/admin/pagos' + (this.semana ? '?semana=' + this.semana : ''));
            break;
          case 'padron': this.d = await this.api('/admin/padron'); break;
          case 'calidad': case 'adulteraciones': case 'solicitudes': case 'conciliacion': case 'costos': case 'sesiones':
            this.d = await this.api('/admin/' + this.seccion); break;
          case 'vender': this.d = await this.api('/compras/ventas-datos'); this.nuevaVenta(); break;
          case 'inventario': this.d = await this.api('/compras/inventario'); break;
          case 'ordenes':
            this.d = { ...(await this.api('/compras/ordenes')), inv: await this.api('/compras/inventario') };
            break;
          case 'movimientos': this.d = await this.api('/compras/movimientos'); break;
        }
      } catch (e) {
        this.error = e.message;
      } finally {
        this.cargando = false;
      }
    },

    cambiar(campo, valor) { this[campo] = valor; this.cargar(); },
    maximo(lista, campo) { return Math.max(1, ...lista.map((x) => Number(x[campo]) || 0)); },

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
      if (!confirm(`¿${accion} a ${t.nombre}?` + (t.activo ? '\nNo podrá entrar a la app y se cerrará en todos sus celulares.' : ''))) return;
      try {
        await this.api(`/admin/trabajadores/${t.id}/activo`, { activo: !t.activo });
        this.aviso = { texto: t.activo ? `${t.nombre} quedó desactivado` : `✓ ${t.nombre} quedó activo` };
        await this.cargar();
      } catch (e) { this.aviso = { error: true, texto: e.message }; }
    },

    // ---------------------------------------------------- cambios de zona
    async resolverSolicitud(s, aprobar) {
      const pregunta = aprobar
        ? `¿Aprobar el cambio de ${s.proveedor} a ${s.destino}?\nPasa a esa comunidad y al vehículo que la recorre.`
        : `¿Rechazar el cambio de ${s.proveedor} a ${s.destino}?`;
      if (!confirm(pregunta)) return;
      try {
        const r = await this.api(`/admin/solicitudes/${s.id}/resolver`, { aprobar });
        this.aviso = { texto: aprobar
          ? `✓ ${s.proveedor} pasa a ${s.destino} (vehículo ${r.vehiculo}) desde el ${fechaCorta(r.vigente_desde)}`
          : `Se rechazó el cambio de ${s.proveedor}` };
        await this.cargar();
      } catch (e) { this.aviso = { error: true, texto: e.message }; }
    },

    // -------------------------------------------------------------- sesiones
    async cerrarSesiones(u) {
      if (!confirm(`¿Cerrar las ${u.sesiones} sesiones de ${u.nombre}?\nTendrá que volver a ingresar en todos sus dispositivos.`)) return;
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
    nuevaVenta() {
      this.f = { producto: this.d.productos?.[0]?.id ?? null, cliente: null, cantidad: 1, correo: '', error: null, enviando: false };
    },
    productoVenta() { return (this.d.productos || []).find((p) => p.id === this.f.producto); },
    clienteVenta() { return (this.d.clientes || []).find((c) => c.id === this.f.cliente); },
    precioVenta() {
      const p = this.productoVenta(); const c = this.clienteVenta();
      return p && c ? Number(p.precios?.[c.tipo] || 0) : 0;
    },
    elegirCliente(c) { this.f.cliente = c.id; this.f.correo = c.email || ''; },
    async registrarVenta() {
      const f = this.f; const p = this.productoVenta(); const c = this.clienteVenta();
      if (!p) return (f.error = 'Elige el producto');
      if (!c) return (f.error = 'Elige el cliente');
      if (!(f.cantidad > 0)) return (f.error = 'La cantidad debe ser mayor a 0');
      if (f.cantidad > p.stock) return (f.error = `Solo hay ${num(p.stock, 2)} en stock`);
      if (f.correo && !esCorreo(f.correo)) return (f.error = 'Ese correo no es válido (déjalo vacío si no quieres enviarlo)');
      f.enviando = true; f.error = null;
      try {
        const r = await this.api('/compras/ventas', {
          cliente_id: c.id, producto_id: p.id, cantidad: Number(f.cantidad), correo: f.correo ? f.correo.trim() : null,
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

    // ---------------------------------------------------------- nota de venta
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

    // --------------------------------------------------------------- órdenes
    abrirOrden() {
      this.f = { insumo_id: null, cantidad: '', proveedor: '', error: null, enviando: false };
      this.ventana = 'orden';
    },
    async emitirOrden() {
      const f = this.f;
      if (!f.insumo_id) return (f.error = 'Elige el insumo');
      if (!(Number(f.cantidad) > 0)) return (f.error = 'Escribe la cantidad');
      if (!f.proveedor.trim()) return (f.error = 'Escribe el proveedor');
      await this.enviar('/compras/ordenes', { insumo_id: f.insumo_id, cantidad: Number(f.cantidad), proveedor_comercial: f.proveedor.trim() },
        null, (r) => `✓ Orden ${r.codigo} emitida · costo estimado ${soles(r.costo_estimado)}`);
    },
    async recibirOrden(o) {
      if (!confirm(`¿Llegó la orden ${o.codigo}?\nSe suman ${num(o.cantidad, 3)} ${o.unidad} de ${o.insumo} al stock.`)) return;
      try {
        await this.api(`/compras/ordenes/${o.id}/recibir`, {});
        this.aviso = { texto: `✓ ${o.codigo} recibida: el stock de ${o.insumo} ya se actualizó` };
        await this.cargar();
      } catch (e) { this.aviso = { error: true, texto: e.message }; }
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
