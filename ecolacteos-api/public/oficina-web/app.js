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
    fOrden: null,   // orden a medio llenar mientras se agrega un vendedor

    // filtros de secciones
    periodo: 'semana',
    semana: null,
    dinero: 'ventas',
    padron: 'proveedores',
    adulteracion: 'todos',
    buscar: '',
    kardex: { tipo: 'insumo', id: null, desde: '', hasta: '' }, // tipo: insumo | producto | leche
    quiero: {},     // producto => cuántos quesos / litros de yogurt se quieren hacer

    soles, num, fechaCorta, hora, TIPO_CLIENTE, ROLES, esCorreo,

    iniciar() {
      if (this.usuario) this.ir(this.menu()[0].pestanas[0].id);
    },

    /**
     * Menú: cada opción agrupa una o más pantallas que se muestran como
     * pestañas arriba (ej. Calidad → Por vehículo | Adulteraciones).
     */
    menu() {
      const admin = this.usuario?.rol === 'ADMIN';
      const compras = [
        { id: 'formulas-grupo', nombre: 'Fórmulas y producción', grupo: 'Compras y almacén',
          // los lotes son de planta: los ve e inicia/cierra el administrador (Compras solo ve las fórmulas)
          pestanas: [{ id: 'formulas', nombre: 'Fórmulas' }, ...(admin ? [{ id: 'lotes', nombre: 'Lotes en producción' }] : [])] },
        { id: 'vender', nombre: 'Vender', grupo: 'Compras y almacén',
          pestanas: [{ id: 'vender', nombre: 'Nueva venta' }, { id: 'movimientos', nombre: 'Últimas ventas y notas' }] },
        { id: 'almacen', nombre: 'Almacén', grupo: 'Compras y almacén',
          pestanas: [{ id: 'inventario', nombre: 'Stock y avisos' }, { id: 'mermas', nombre: 'Pérdidas (mermas)' }] },
        { id: 'kardex', nombre: 'Kardex', grupo: 'Compras y almacén', pestanas: [{ id: 'kardex', nombre: 'Kardex' }] },
        { id: 'ordenes-grupo', nombre: 'Compras de insumos', grupo: 'Compras y almacén',
          pestanas: [{ id: 'ordenes', nombre: 'Órdenes y comprobantes' }, { id: 'vendedores', nombre: 'Vendedores' }] },
      ];
      if (!admin) return compras;
      return [
        { id: 'acopio', nombre: 'Acopio', grupo: 'Administración',
          pestanas: [{ id: 'totales', nombre: 'Totales' }, { id: 'conciliacion', nombre: 'Conciliación campo vs. planta' }] },
        { id: 'dinero-grupo', nombre: 'Dinero', grupo: 'Administración',
          pestanas: [{ id: 'dinero', nombre: 'Ventas y pagos' }, { id: 'costos', nombre: 'Costos y rendimiento' }] },
        { id: 'padron-grupo', nombre: 'Padrón', grupo: 'Administración',
          pestanas: [{ id: 'padron', nombre: 'Proveedores y trabajadores' }, { id: 'solicitudes', nombre: 'Cambios de comunidad' },
            { id: 'sesiones', nombre: 'Sesiones abiertas' }] },
        { id: 'calidad-grupo', nombre: 'Calidad', grupo: 'Administración',
          pestanas: [{ id: 'calidad', nombre: 'Por vehículo' }, { id: 'adulteraciones', nombre: 'Adulteraciones' }] },
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
        if (!['ADMIN', 'COMPRAS'].includes(j.rol)) {
          throw new Error('La web de oficina es para Administración y Compras. Tu rol (' + (ROLES[j.rol] || j.rol) + ') usa la app del celular.');
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
          case 'vendedores': this.d = await this.api('/compras/vendedores'); break;
          case 'movimientos': this.d = await this.api('/compras/movimientos'); break;
          case 'formulas': this.d = await this.api('/compras/formulas'); break;
          case 'mermas': this.d = await this.api('/compras/mermas'); break;
          case 'lotes': this.d = await this.api('/planta/lotes'); this.cierre = {}; break;
          case 'kardex': {
            const inv = await this.api('/compras/inventario');
            const k = this.kardex;
            // insumos o productos: si lo elegido ya no está en la lista, el primero
            const lista = k.tipo === 'producto' ? inv.productos : inv.insumos;
            if (k.tipo !== 'leche' && !lista.some((i) => i.id === k.id)) k.id = lista[0]?.id ?? null;
            const q = new URLSearchParams({ tipo: k.tipo });
            if (k.tipo !== 'leche') q.set('id', k.id ?? '');
            if (k.desde) q.set('desde', k.desde);
            if (k.hasta) q.set('hasta', k.hasta);
            this.d = { inv, ...(k.tipo === 'leche' || k.id ? await this.api('/compras/kardex?' + q) : {}) };
            break;
          }
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
      const pregunta = t.programado
        ? `¿Anular el traslado de ${f.proveedor.nombre} a ${t.destino}?\nSe queda en ${t.origen}.`
        : `¿Anular el traslado de ${f.proveedor.nombre} a ${t.destino}?\nVuelve a ${t.origen} y lo recoge el vehículo de esa ruta.`;
      if (!confirm(pregunta)) return;
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
      this.f = { vendedor_id: null, items: [{ insumo_id: null, cantidad: '' }], error: null, enviando: false };
      this.ventana = 'orden';
    },
    insumoDe(id) { return (this.d.inv?.insumos || []).find((i) => i.id === id); },
    costoEstimadoOrden() {
      return (this.f.items || []).reduce((t, it) => t + (Number(it.cantidad) || 0) * (this.insumoDe(it.insumo_id)?.costo || 0), 0);
    },
    async emitirOrden() {
      const f = this.f;
      if (!f.vendedor_id) return (f.error = 'Elige a quién se le compra');
      const items = f.items.filter((it) => it.insumo_id);
      if (!items.length) return (f.error = 'Elige al menos un insumo');
      if (items.some((it) => !(Number(it.cantidad) > 0))) return (f.error = 'Escribe la cantidad de cada insumo');
      if (new Set(items.map((it) => it.insumo_id)).size !== items.length) return (f.error = 'Hay un insumo repetido: junta las cantidades');
      await this.enviar('/compras/ordenes', { vendedor_id: f.vendedor_id, items: items.map((it) => ({ insumo_id: it.insumo_id, cantidad: Number(it.cantidad) })) },
        null, (r) => `✓ Orden ${r.codigo} emitida · costo estimado ${soles(r.costo_estimado)}. Cuando llegue, toca "Llegó" y registra el comprobante.`);
    },

    // al recibir: comprobante, lo pagado por cada insumo y la foto
    hoy() { return new Date().toLocaleDateString('en-CA'); },
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
      if (f.tipo !== 'RECIBO' && !f.serie.trim()) return (f.error = 'Escribe la serie (ej. F001 o B001)');
      if (f.tipo !== 'RECIBO' && !/^\d{1,8}$/.test(f.numero.trim())) return (f.error = 'Escribe el número del comprobante (solo dígitos)');
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
    /** [volver]: se abrió desde "Nueva orden"; al guardar vuelve a la orden con el vendedor elegido. */
    abrirVendedor(v = null, volver = false) {
      this.fOrden = volver ? this.f : null;
      this.f = v
        ? { id: v.id, tipo_documento: v.tipo_documento, documento: v.documento, nombre: v.nombre, telefono: v.telefono || '',
            direccion: v.direccion || '', activo: v.activo, error: null, enviando: false }
        : { id: null, tipo_documento: 'RUC', documento: '', nombre: '', telefono: '', direccion: '', activo: true, error: null, enviando: false };
      this.ventana = 'vendedor';
    },
    cerrarVendedor() {
      if (this.fOrden) { this.f = this.fOrden; this.fOrden = null; this.ventana = 'orden'; } else this.ventana = null;
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
          this.d = { ...this.d, ...(await this.api('/compras/ordenes')) };
          this.f = this.fOrden; this.fOrden = null; this.f.vendedor_id = r.id; this.ventana = 'orden';
        } else {
          this.ventana = null;
          this.aviso = { texto: `✓ ${r.nombre} ${f.id ? 'actualizado' : 'agregado'}` };
          await this.cargar();
        }
      } catch (e) { f.error = e.message; } finally { f.enviando = false; }
    },

    // ---------------------------------------------------------------- kardex
    verKardex(id, tipo = 'insumo') { this.kardex.tipo = tipo; this.kardex.id = id; this.ir('kardex'); },
    tipoKardex(tipo, id = null) { this.kardex.tipo = tipo; this.kardex.id = id; this.cargar(); },
    // pestañas: Insumos | Queso Paria | Yogurt natural | Leche
    pestanasKardex() {
      return [
        { tipo: 'insumo', id: null, nombre: 'Insumos' },
        ...(this.d.inv?.productos || []).map((p) => ({ tipo: 'producto', id: p.id, nombre: p.nombre })),
        { tipo: 'leche', id: null, nombre: 'Leche' },
      ];
    },
    pestanaKardexActiva(t) { return this.kardex.tipo === t.tipo && (t.tipo !== 'producto' || this.kardex.id === t.id); },

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
      const POR_ENTRA = { INICIAL: 'que ya había', COMPRA: 'por compras', AJUSTE: 'por ajustes', ACOPIO: 'de acopio aceptado', PRODUCCION: 'hechos en lotes' };
      const POR_SALE = { PRODUCCION: 'para hacer lotes', VENTA: 'vendidos', AJUSTE: 'por mermas o ajustes', MERMA: 'perdidos' };
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
      AJUSTE: 'Ajuste', VENTA: 'Venta', ACOPIO: 'Acopio', MERMA: 'Pérdida' },
    cu: (v) => 'S/ ' + Number(v || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 4 }),
    abrirAjuste() {
      this.f = { tipo: 'SALIDA', cantidad: '', motivo: '', error: null, enviando: false };
      this.ventana = 'ajuste';
    },
    async guardarAjuste() {
      const f = this.f; const i = this.d.item;
      if (!(Number(f.cantidad) > 0)) return (f.error = 'Escribe la cantidad');
      if (f.motivo.trim().length < 3) return (f.error = 'Escribe el motivo (merma, vencido, conteo físico…)');
      if (f.tipo === 'SALIDA' && Number(f.cantidad) > i.stock) return (f.error = `Solo hay ${num(i.stock, 3)} ${i.unidad}`);
      await this.enviar('/compras/ajustes', { insumo_id: i.id, tipo: f.tipo, cantidad: Number(f.cantidad), motivo: f.motivo.trim() },
        null, (r) => `✓ Ajuste registrado · ${r.insumo} queda con ${num(r.stock, 3)} ${i.unidad}`);
    },

    // -------------------------------------------------------------- fórmulas
    // "quiero hacer 10 quesos": la leche se calcula con el rendimiento mínimo, así esa cantidad sale segura
    unidadProducto(p) { return p.unidad.split(' ')[0]; }, // "quesos de 1 kg" → "quesos"
    cantidadQuiero(p) { return this.quiero[p.id] ?? 10; },
    litrosPara(p) {
      const q = Number(this.cantidadQuiero(p)) || 0;
      return p.rendimiento_min > 0 ? Math.ceil(q / p.rendimiento_min - 1e-9) : 0;
    },
    necesita(i, p) { return (i.por_100l * this.litrosPara(p)) / 100; },
    faltantes(p) { return p.insumos.filter((i) => this.necesita(i, p) > i.stock + 1e-9); },
    /** ¿Se puede hacer la cantidad pedida? { ok, motivo } */
    sePuede(p) {
      const q = Number(this.cantidadQuiero(p)) || 0; const u = this.unidadProducto(p);
      if (q <= 0) return { ok: false, motivo: `Escribe cuántos ${u} quieres hacer.` };
      const falta = [];
      if (this.litrosPara(p) > this.d.tanque) falta.push(`leche (${num(this.litrosPara(p) - this.d.tanque, 1)} L)`);
      this.faltantes(p).forEach((i) => falta.push(`${i.nombre.toLowerCase()} (${num(this.necesita(i, p) - i.stock, 3)} ${i.unidad})`));
      return falta.length
        ? { ok: false, motivo: `Para hacer ${num(q, 0)} ${u} falta ${falta.join(', ')}. Hay que conseguirlo antes de producir.` }
        : { ok: true, motivo: '' };
    },
    abrirProducir(p) {
      this.f = { producto: p, cantidad: Number(this.cantidadQuiero(p)), litros: this.litrosPara(p), error: null, enviando: false };
      this.ventana = 'producir';
    },
    async confirmarProducir() {
      const f = this.f;
      f.enviando = true; f.error = null;
      try {
        const r = await this.api('/planta/lotes', { producto_id: f.producto.id, litros: f.litros });
        this.ventana = null;
        await this.ir('lotes'); // para verlo en producción y cerrarlo al terminar
        this.aviso = { texto: `✓ Lote ${r.codigo} iniciado: ${num(f.cantidad, 0)} ${this.unidadProducto(f.producto)} de ${f.producto.nombre} con ` +
          `${num(f.litros, 0)} L de leche. Se descontaron la leche y los insumos. Cuando termine, ciérralo aquí con lo que salió.` };
      } catch (e) { f.error = e.message; } finally { f.enviando = false; }
    },

    // ----------------------------------------------------- lotes en producción
    cierre: {}, // lote => cantidad que salió
    async cerrarLote(l) {
      const obtenido = Number(this.cierre[l.id] ?? '');
      const u = l.unidad.split(' ')[0];
      if (!(obtenido >= 0) || this.cierre[l.id] === '' || this.cierre[l.id] == null) {
        this.aviso = { error: true, texto: `Escribe cuántos ${u} salieron del lote ${l.codigo}` }; return;
      }
      if (!confirm(`¿Cerrar el lote ${l.codigo} con ${num(obtenido, 2)} ${u}?\nEntran al stock y ya se pueden vender.`)) return;
      try {
        const r = await this.api(`/planta/lotes/${l.id}/cerrar`, { obtenido });
        await this.cargar();
        this.aviso = { texto: `✓ ${r.codigo} cerrado: entraron ${num(obtenido, 2)} ${u} al stock (desvío ${num(r.desvio_pct, 1)} % contra lo esperado)` };
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
