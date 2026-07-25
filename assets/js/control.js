/**
 * Módulo de Control: listado de estatus logístico, préstamos (individuales
 * o múltiples), reporte de incidencias y cierre (regreso/devolución) de
 * préstamos activos.
 */

(function () {
  const tabla        = document.getElementById('tablaControlBody');
  if (!tabla) return; // El módulo de control no está presente en esta página

  const paginacion    = document.getElementById('paginacionControl');
  const inputBuscar    = document.getElementById('buscarControl');
  const selectEstado   = document.getElementById('filtroEstado');
  const inputFechaIni  = document.getElementById('filtroFechaInicio');
  const inputFechaFin  = document.getElementById('filtroFechaFin');
  const btnLimpiar     = document.getElementById('btnLimpiarControl');
  const btnExportar    = document.getElementById('btnExportarControl');
  const btnNuevoPrestamo = document.getElementById('btnNuevoPrestamo');

  const modalSolicitarEl = document.getElementById('modalSolicitar');
  const modalSolicitar = new bootstrap.Modal(modalSolicitarEl);
  const formSolicitar = document.getElementById('formSolicitar');

  const modalDevolucionEl = document.getElementById('modalDevolucion');
  const modalDevolucion = new bootstrap.Modal(modalDevolucionEl);
  const formDevolucion = document.getElementById('formDevolucion');

  const modalReportarEl = document.getElementById('modalReportar');
  const modalReportar = new bootstrap.Modal(modalReportarEl);
  const formReportar = document.getElementById('formReportar');

  let paginaActual = 1;
  let temporizador = null;

  // ---------- Validación personalizada ----------
  // validarFormulario/limpiarValidacion viven en main.js (compartidas por
  // todos los módulos); aquí solo se conecta el auto-limpiado por campo.
  [formSolicitar, formDevolucion, formReportar].forEach(limpiarValidacionAlEscribir);

  // Protege los tres formularios: confirma antes de cerrar si hay cambios sin guardar
  const guardSolicitar = protegerFormularioConCambios(formSolicitar, modalSolicitarEl);
  const guardDevolucion = protegerFormularioConCambios(formDevolucion, modalDevolucionEl);
  const guardReportar = protegerFormularioConCambios(formReportar, modalReportarEl);

  modalSolicitarEl.addEventListener('show.bs.modal', () => limpiarValidacion(formSolicitar));
  modalDevolucionEl.addEventListener('show.bs.modal', () => limpiarValidacion(formDevolucion));
  modalReportarEl.addEventListener('show.bs.modal', () => limpiarValidacion(formReportar));

  // "Fui yo": autocompleta con el nombre de quien tiene la sesión abierta,
  // para el caso más común (el mismo operador detecta y reporta el
  // problema). El campo sigue siendo de texto libre para cuando quien
  // reporta es alguien externo (solicitante, maestro, alumno) sin cuenta
  // en el sistema.
  const btnReportarPorFuiYo = document.getElementById('btnReportarPorFuiYo');
  const campoReportarPor = document.getElementById('reportarPor');
  if (btnReportarPorFuiYo && campoReportarPor) {
    btnReportarPorFuiYo.addEventListener('click', () => {
      campoReportarPor.value = window.USUARIO_NOMBRE || '';
      campoReportarPor.classList.remove('is-invalid');
      campoReportarPor.removeAttribute('aria-invalid');
      campoReportarPor.focus();
    });
  }

  const badgeEstado = (valor, vencido) => {
    if (vencido) {
      return '<span class="badge-estado badge-en_reparacion"><i class="bi bi-alarm"></i> Vencido</span>';
    }
    const etiquetas = { disponible: 'Disponible', en_uso: 'Actualmente asignado', en_reparacion: 'En mantenimiento', baja: 'Baja' };
    return `<span class="badge-estado badge-${valor}">${etiquetas[valor] || valor}</span>`;
  };

  async function cargarControl(pagina = 1) {
    paginaActual = pagina;
    const params = new URLSearchParams({
      accion: 'listar',
      buscar: inputBuscar.value.trim(),
      estado: selectEstado.value,
      fecha_inicio: inputFechaIni.value,
      fecha_fin: inputFechaFin.value,
      pagina,
    });

    tabla.innerHTML = `<tr><td colspan="7"><div class="estado-lista"><span class="spinner-border spinner-border-sm"></span> Cargando movimientos…</div></td></tr>`;

    try {
      const resp = await fetch(`api/control.php?${params.toString()}`);
      const data = await resp.json();
      if (!data.ok) throw new Error(data.error || 'Error al consultar el control.');

      if (data.datos.length === 0) {
        tabla.innerHTML = `<tr><td colspan="7"><div class="estado-lista"><i class="bi bi-inbox"></i> Sin movimientos para los criterios seleccionados.</div></td></tr>`;
      } else {
        tabla.innerHTML = data.datos.map((row) => {
          const enPrestamo = row.estado === 'en_uso' && row.solicitud_estado === 'activo';
          const esVencido  = Number(row.vencido) === 1;
          const ubicacion = enPrestamo ? (row.ubicacion_destino || '—') : row.ubicacion_base;
          const solicitante = enPrestamo ? row.solicitante : '—';
          const fecha = enPrestamo ? row.fecha_solicitud : '—';

          const tieneIncidenciaAbierta = enPrestamo && Number(row.incidencias_abiertas) > 0;
          const badgeIncidencia = tieneIncidenciaAbierta
            ? ` <span class="badge-estado badge-incidencia" title="Reportado por: ${sanearHTML(row.incidencia_reportado_por || '—')}"><i class="bi bi-exclamation-triangle-fill"></i> Incidencia</span>`
            : '';

          return `
          <tr>
            <td>${sanearHTML(row.nombre)}</td>
            <td class="fw-semibold">${sanearHTML(row.num_inventario)}</td>
            <td>${badgeEstado(row.estado, esVencido)}${badgeIncidencia}</td>
            <td>${sanearHTML(ubicacion)}</td>
            <td>${sanearHTML(solicitante)}</td>
            <td>${sanearHTML(fecha)}</td>
            <td class="text-end col-acciones col-acciones-control">
              <div class="acciones-grupo">
                ${row.estado === 'disponible' ? `
                  <button class="btn btn-sm btn-primary-musiteca btn-abrir-solicitar" data-id="${row.id}" data-nombre="${sanearHTML(row.nombre)}" data-inv="${sanearHTML(row.num_inventario)}" title="Solicitar instrumento">
                    <i class="bi bi-hand-index-thumb"></i> <span class="d-none d-xl-inline">Solicitar</span>
                  </button>
                ` : ''}
                ${enPrestamo ? `
                  <button class="btn btn-sm btn-outline-success btn-finalizar" data-solicitud="${row.solicitud_id}" data-nombre="${sanearHTML(row.nombre)}" data-inv="${sanearHTML(row.num_inventario)}" data-incidencias="${Number(row.incidencias_abiertas) || 0}" data-incidencia-motivo="${sanearHTML(row.incidencia_motivo || '')}" data-incidencia-descripcion="${sanearHTML(row.incidencia_descripcion || '')}" data-incidencia-reportado="${sanearHTML(row.incidencia_reportado_por || '')}" title="Registrar el regreso del instrumento">
                    <i class="bi bi-box-arrow-in-left"></i> <span class="d-none d-xl-inline">Registrar regreso</span>
                  </button>
                ` : ''}
                ${row.estado === 'en_reparacion' ? `
                  <button class="btn btn-sm btn-outline-success btn-resolver-incidencia" data-id="${row.id}" data-nombre="${sanearHTML(row.nombre)}" title="Marcar como disponible">
                    <i class="bi bi-check2-circle"></i> <span class="d-none d-xl-inline">Disponible</span>
                  </button>
                ` : ''}
                <div class="dropdown">
                  <button class="btn btn-sm btn-icono-tabla btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" title="Más opciones">
                    <i class="bi bi-three-dots"></i>
                  </button>
                  <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                      <button class="dropdown-item btn-ver-detalle" data-id="${row.id}">
                        <i class="bi bi-eye"></i> Ver expediente
                      </button>
                    </li>
                    <li>
                      <button class="dropdown-item btn-abrir-reportar" data-id="${row.id}" data-nombre="${sanearHTML(row.nombre)}" data-inv="${sanearHTML(row.num_inventario)}" data-estado="${sanearHTML(row.estado)}">
                        <i class="bi bi-exclamation-triangle"></i> Reportar problema
                      </button>
                    </li>
                  </ul>
                </div>
              </div>
            </td>
          </tr>`;
        }).join('');
      }

      renderPaginacion(data.pagina, data.totalPaginas);
      actualizarResumenListado('resumenControl', data.pagina, data.porPagina, data.total);
    } catch (err) {
      tabla.innerHTML = `<tr><td colspan="7"><div class="estado-lista estado-error"><i class="bi bi-exclamation-triangle"></i> ${sanearHTML(err.message)}</div></td></tr>`;
    }
  }

  function renderPaginacion(actual, totalPaginas) {
    paginacion.innerHTML = '';
    if (totalPaginas <= 1) return;
    for (let i = 1; i <= totalPaginas; i++) {
      const li = document.createElement('li');
      li.className = `page-item ${i === actual ? 'active' : ''}`;
      li.innerHTML = `<button class="page-link">${i}</button>`;
      li.querySelector('button').addEventListener('click', () => cargarControl(i));
      paginacion.appendChild(li);
    }
  }

  inputBuscar.addEventListener('input', () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => cargarControl(1), 350);
  });
  [selectEstado, inputFechaIni, inputFechaFin].forEach((el) => el.addEventListener('change', () => cargarControl(1)));
  btnLimpiar.addEventListener('click', () => {
    inputBuscar.value = '';
    selectEstado.value = '';
    inputFechaIni.value = '';
    inputFechaFin.value = '';
    cargarControl(1);
  });

  // Botón oculto en el DOM para el rol operativo (ver index.php): la
  // exportación completa de movimientos queda restringida a administrador.
  if (btnExportar) {
    btnExportar.addEventListener('click', () => {
      const params = new URLSearchParams({
        estado: selectEstado.value,
        fecha_inicio: inputFechaIni.value,
        fecha_fin: inputFechaFin.value,
      });
      window.location.href = `api/export_control.php?${params.toString()}`;
    });
  }

  // =====================================================================
  // Préstamo (individual o múltiple): carrito de instrumentos
  // =====================================================================
  const inputBuscarInstrumento   = document.getElementById('solicitarBuscarInstrumento');
  const contenedorResultados     = document.getElementById('solicitarResultadosBusqueda');
  const contenedorSinInstrumentos = document.getElementById('solicitarSinInstrumentos');
  const listaInstrumentosEl      = document.getElementById('solicitarListaInstrumentos');
  const contadorInstrumentos     = document.getElementById('solicitarContador');

  let carritoPrestamo = [];        // [{id, nombre, num_inventario}]
  let ultimosResultados = [];      // última respuesta de búsqueda (para poder refiltrar sin re-pedir al servidor)
  let temporizadorBusquedaInstrumento = null;

  function estaEnCarrito(id) {
    return carritoPrestamo.some((item) => String(item.id) === String(id));
  }

  function renderizarCarrito() {
    if (carritoPrestamo.length === 0) {
      contenedorSinInstrumentos.classList.remove('d-none');
      listaInstrumentosEl.innerHTML = '';
    } else {
      contenedorSinInstrumentos.classList.add('d-none');
      listaInstrumentosEl.innerHTML = carritoPrestamo.map((item) => `
        <li class="list-group-item d-flex justify-content-between align-items-center">
          <div>
            <div class="fw-semibold small">${sanearHTML(item.nombre)}</div>
            <div class="text-muted small">N° ${sanearHTML(item.num_inventario)}</div>
          </div>
          <button type="button" class="btn btn-sm btn-outline-danger btn-quitar-instrumento" data-id="${sanearHTML(item.id)}" title="Quitar del préstamo" aria-label="Quitar ${sanearHTML(item.nombre)} del préstamo">
            <i class="bi bi-x-lg"></i>
          </button>
        </li>
      `).join('');
    }

    contadorInstrumentos.textContent = carritoPrestamo.length === 1
      ? '1 instrumento agregado'
      : `${carritoPrestamo.length} instrumentos agregados`;

    // Si el panel de resultados de búsqueda está visible, se refiltra para
    // no seguir ofreciendo instrumentos que ya están en el carrito.
    if (!contenedorResultados.classList.contains('d-none')) {
      renderizarResultadosBusqueda();
    }
  }

  function agregarInstrumentoAlCarrito(item, { marcarSucio = true } = {}) {
    if (estaEnCarrito(item.id)) {
      mostrarToast('Ese instrumento ya está agregado a este préstamo.', 'warning');
      return;
    }
    carritoPrestamo.push(item);
    renderizarCarrito();
    if (marcarSucio) {
      // El carrito no es un <input>, así que se avisa manualmente al
      // sistema de "cambios sin guardar" (protegerFormularioConCambios
      // escucha 'change' sobre el propio formulario).
      formSolicitar.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  function quitarInstrumentoDelCarrito(id) {
    carritoPrestamo = carritoPrestamo.filter((item) => String(item.id) !== String(id));
    renderizarCarrito();
    formSolicitar.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function reiniciarConstructorPrestamo() {
    carritoPrestamo = [];
    ultimosResultados = [];
    inputBuscarInstrumento.value = '';
    contenedorResultados.classList.add('d-none');
    contenedorResultados.innerHTML = '';
    renderizarCarrito();
  }

  function renderizarResultadosBusqueda() {
    const disponibles = ultimosResultados.filter((item) => !estaEnCarrito(item.id));

    if (disponibles.length === 0) {
      contenedorResultados.innerHTML = `<div class="list-group-item text-muted small">Sin instrumentos disponibles que coincidan con la búsqueda.</div>`;
      return;
    }

    contenedorResultados.innerHTML = disponibles.map((item) => `
      <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center btn-agregar-instrumento" data-id="${sanearHTML(item.id)}" data-nombre="${sanearHTML(item.nombre)}" data-inv="${sanearHTML(item.num_inventario)}">
        <span>
          <span class="fw-semibold small d-block">${sanearHTML(item.nombre)}</span>
          <span class="text-muted small">N° ${sanearHTML(item.num_inventario)}</span>
        </span>
        <i class="bi bi-plus-lg"></i>
      </button>
    `).join('');
  }

  async function buscarInstrumentosDisponibles(termino) {
    if (termino === '') {
      contenedorResultados.classList.add('d-none');
      contenedorResultados.innerHTML = '';
      ultimosResultados = [];
      return;
    }

    contenedorResultados.classList.remove('d-none');
    contenedorResultados.innerHTML = `<div class="list-group-item text-muted small"><span class="spinner-border spinner-border-sm"></span> Buscando…</div>`;

    try {
      const params = new URLSearchParams({ accion: 'listar', buscar: termino, estado: 'disponible', pagina: 1 });
      const resp = await fetch(`api/instrumentos.php?${params.toString()}`);
      const data = await resp.json();
      if (!data.ok) throw new Error(data.error || 'No se pudo buscar instrumentos.');

      ultimosResultados = data.datos;
      renderizarResultadosBusqueda();
    } catch (err) {
      contenedorResultados.innerHTML = `<div class="list-group-item text-danger small"><i class="bi bi-exclamation-triangle"></i> ${sanearHTML(err.message)}</div>`;
    }
  }

  inputBuscarInstrumento.addEventListener('input', () => {
    clearTimeout(temporizadorBusquedaInstrumento);
    const termino = inputBuscarInstrumento.value.trim();
    temporizadorBusquedaInstrumento = setTimeout(() => buscarInstrumentosDisponibles(termino), 300);
  });

  contenedorResultados.addEventListener('click', (evento) => {
    const boton = evento.target.closest('.btn-agregar-instrumento');
    if (!boton) return;
    agregarInstrumentoAlCarrito({ id: boton.dataset.id, nombre: boton.dataset.nombre, num_inventario: boton.dataset.inv });
  });

  listaInstrumentosEl.addEventListener('click', (evento) => {
    const boton = evento.target.closest('.btn-quitar-instrumento');
    if (!boton) return;
    quitarInstrumentoDelCarrito(boton.dataset.id);
  });

  if (btnNuevoPrestamo) {
    btnNuevoPrestamo.addEventListener('click', () => {
      formSolicitar.reset();
      limpiarValidacion(formSolicitar);
      reiniciarConstructorPrestamo();
      modalSolicitar.show();
    });
  }

  function refrescarTodo() {
    cargarControl(paginaActual);
    if (window.actualizarInventarioExterno) window.actualizarInventarioExterno();
    if (window.refrescarKPIs) window.refrescarKPIs();
    // Sin esto, el aviso de "incidencias pendientes" del panel principal
    // seguía mostrando el conteo anterior hasta recargar la página, aunque
    // la incidencia ya se hubiera resuelto en esta misma acción.
    if (window.actualizarResumenOperativo) window.actualizarResumenOperativo();
  }

  formSolicitar.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    if (!validarFormulario(formSolicitar)) return;

    if (carritoPrestamo.length === 0) {
      mostrarToast('Agrega al menos un instrumento al préstamo antes de confirmar.', 'warning');
      contenedorSinInstrumentos.classList.add('campo-shake');
      setTimeout(() => contenedorSinInstrumentos.classList.remove('campo-shake'), 350);
      return;
    }

    const boton = formSolicitar.querySelector('button[type="submit"]');
    boton.disabled = true;
    const textoOriginal = boton.innerHTML;
    boton.innerHTML = '<span class="spinner-border spinner-border-sm-inline spinner-border-sm"></span> Guardando…';

    try {
      const formData = new FormData(formSolicitar);
      formData.append('accion', 'crear_prestamo');
      carritoPrestamo.forEach((item) => formData.append('instrumento_ids[]', item.id));

      const resp = await fetch('api/solicitudes.php', { method: 'POST', body: formData });
      const data = await resp.json();
      mostrarToast(data.mensaje || data.error, data.ok ? 'success' : 'danger');

      if (data.ok) {
        guardSolicitar.marcarComoGuardado();
        modalSolicitar.hide();
        reiniciarConstructorPrestamo();
        refrescarTodo();
      }
    } finally {
      boton.disabled = false;
      boton.innerHTML = textoOriginal;
    }
  });

  // =====================================================================
  // Devolución: estado de entrega + observaciones condicionales
  // =====================================================================
  const CONDICIONES_CON_OBSERVACION_OBLIGATORIA = ['desgaste', 'danado', 'incompleto'];
  const selectCondicionDevolucion = document.getElementById('devolucionCondicion');
  const grupoObservacionesDevolucion = document.getElementById('devolucionObservacionesGrupo');
  const textareaObservacionesDevolucion = document.getElementById('devolucionObservaciones');

  function actualizarVisibilidadObservacionesDevolucion() {
    const requiereObservacion = CONDICIONES_CON_OBSERVACION_OBLIGATORIA.includes(selectCondicionDevolucion.value);
    grupoObservacionesDevolucion.classList.toggle('d-none', !requiereObservacion);
    if (requiereObservacion) {
      textareaObservacionesDevolucion.setAttribute('data-obligatorio', 'true');
    } else {
      textareaObservacionesDevolucion.removeAttribute('data-obligatorio');
      textareaObservacionesDevolucion.classList.remove('is-invalid');
      textareaObservacionesDevolucion.removeAttribute('aria-invalid');
    }
  }

  selectCondicionDevolucion.addEventListener('change', actualizarVisibilidadObservacionesDevolucion);

  // =====================================================================
  // Devolución: decisión sobre incidencias reportadas durante el préstamo
  // =====================================================================
  const ETIQUETAS_MOTIVO_INCIDENCIA = {
    dano_fisico: 'Daño físico',
    mal_funcionamiento: 'Mal funcionamiento',
    piezas_faltantes: 'Piezas faltantes',
    desgaste: 'Desgaste por uso',
    otro: 'Otro',
  };
  const alertaIncidenciaDevolucion = document.getElementById('devolucionIncidenciaAlert');
  const resumenIncidenciaDevolucion = document.getElementById('devolucionIncidenciaResumen');
  const grupoDecisionIncidencia = document.getElementById('devolucionDecisionIncidenciaGrupo');
  const selectDecisionIncidencia = document.getElementById('devolucionDecisionIncidencia');

  /**
   * Muestra (o vuelve a ocultar) el aviso de incidencia pendiente en el modal
   * de devolución, a partir de los datos que ya trae el botón "Registrar
   * regreso" de la fila (sin necesidad de otra llamada al servidor). Si hay
   * una incidencia abierta, exige elegir una decisión antes de poder enviar
   * el formulario, reutilizando la misma validación genérica del resto de
   * la app (validarFormulario + [data-obligatorio]).
   */
  function actualizarBloqueIncidenciaDevolucion(datosBoton) {
    const cantidad = Number(datosBoton.incidencias || 0);
    const tieneIncidencia = cantidad > 0;

    alertaIncidenciaDevolucion.classList.toggle('d-none', !tieneIncidencia);
    grupoDecisionIncidencia.classList.toggle('d-none', !tieneIncidencia);
    selectDecisionIncidencia.value = '';

    if (!tieneIncidencia) {
      selectDecisionIncidencia.removeAttribute('data-obligatorio');
      return;
    }

    selectDecisionIncidencia.setAttribute('data-obligatorio', 'true');
    const motivo = ETIQUETAS_MOTIVO_INCIDENCIA[datosBoton.incidenciaMotivo] || datosBoton.incidenciaMotivo || '—';
    resumenIncidenciaDevolucion.innerHTML = `
      ${cantidad > 1 ? `<div>${cantidad} incidencias reportadas durante este préstamo. Se muestra la más reciente:</div>` : ''}
      <div><strong>Motivo:</strong> ${sanearHTML(motivo)}</div>
      <div><strong>Descripción:</strong> ${sanearHTML(datosBoton.incidenciaDescripcion || '—')}</div>
      <div><strong>Reportado por:</strong> ${sanearHTML(datosBoton.incidenciaReportado || '—')}</div>
    `;
  }

  formDevolucion.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    if (!validarFormulario(formDevolucion)) return;

    const boton = formDevolucion.querySelector('button[type="submit"]');
    boton.disabled = true;
    const textoOriginal = boton.innerHTML;
    boton.innerHTML = '<span class="spinner-border spinner-border-sm-inline spinner-border-sm"></span> Guardando…';

    try {
      const formData = new FormData(formDevolucion);
      formData.append('accion', 'finalizar_solicitud');

      const resp = await fetch('api/solicitudes.php', { method: 'POST', body: formData });
      const data = await resp.json();
      mostrarToast(data.mensaje || data.error, data.ok ? 'success' : 'danger');

      if (data.ok) {
        guardDevolucion.marcarComoGuardado();
        modalDevolucion.hide();
        refrescarTodo();
      }
    } finally {
      boton.disabled = false;
      boton.innerHTML = textoOriginal;
    }
  });

  // ---------- Delegación global: abrir modales / acciones rápidas ----------
  document.addEventListener('click', async (evento) => {
    const botonSolicitar = evento.target.closest('.btn-abrir-solicitar');
    const botonReportar  = evento.target.closest('.btn-abrir-reportar');
    const botonFinalizar = evento.target.closest('.btn-finalizar');
    const botonVerDetalle = evento.target.closest('.btn-ver-detalle');
    const botonResolver  = evento.target.closest('.btn-resolver-incidencia');

    if (botonSolicitar) {
      formSolicitar.reset();
      limpiarValidacion(formSolicitar);
      reiniciarConstructorPrestamo();
      // Atajo: solicitar directamente desde una fila deja el instrumento
      // ya agregado al carrito, para no obligar a volver a buscarlo.
      agregarInstrumentoAlCarrito({
        id: botonSolicitar.dataset.id,
        nombre: botonSolicitar.dataset.nombre,
        num_inventario: botonSolicitar.dataset.inv,
      }, { marcarSucio: false });
      modalSolicitar.show();
    }

    if (botonReportar) {
      // Cierra cualquier otro contenedor abierto (ej. offcanvas de detalle) para evitar solapamientos
      const offcanvasDetalle = bootstrap.Offcanvas.getInstance(document.getElementById('offcanvasDetalle'));
      if (offcanvasDetalle) offcanvasDetalle.hide();

      formReportar.reset();
      limpiarValidacion(formReportar);
      document.getElementById('reportarInstrumentoId').value = botonReportar.dataset.id;
      document.getElementById('reportarResumen').textContent =
        `${botonReportar.dataset.nombre} · N° ${botonReportar.dataset.inv}`;
      // Si el instrumento está prestado, se avisa que el préstamo seguirá
      // activo: la decisión de mantenimiento se toma hasta la devolución.
      document.getElementById('reportarAvisoPrestamo').classList.toggle('d-none', botonReportar.dataset.estado !== 'en_uso');
      modalReportar.show();
    }

    if (botonFinalizar) {
      formDevolucion.reset();
      limpiarValidacion(formDevolucion);
      document.getElementById('devolucionSolicitudId').value = botonFinalizar.dataset.solicitud;
      document.getElementById('devolucionResumen').textContent =
        `${botonFinalizar.dataset.nombre} · N° ${botonFinalizar.dataset.inv}`;
      actualizarVisibilidadObservacionesDevolucion();
      actualizarBloqueIncidenciaDevolucion(botonFinalizar.dataset);
      modalDevolucion.show();
    }

    if (botonVerDetalle && window.mostrarDetalleInstrumento) {
      window.mostrarDetalleInstrumento(botonVerDetalle.dataset.id);
    }

    if (botonResolver) {
      const confirmado = await confirmarAccion({
        titulo: 'Marcar como disponible',
        mensaje: `¿Confirmas que <strong>${botonResolver.dataset.nombre}</strong> ya fue reparado y está listo para préstamo?`,
        tipo: 'success',
        textoConfirmar: 'Marcar disponible',
        textoCancelar: 'Cancelar',
      });
      if (confirmado) resolverIncidencia(botonResolver.dataset.id);
    }
  });

  formReportar.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    if (!validarFormulario(formReportar)) return;

    const boton = formReportar.querySelector('button[type="submit"]');
    boton.disabled = true;
    const textoOriginal = boton.innerHTML;
    boton.innerHTML = '<span class="spinner-border spinner-border-sm-inline spinner-border-sm"></span> Enviando…';

    try {
      const formData = new FormData(formReportar);
      formData.append('accion', 'crear_incidencia');

      const resp = await fetch('api/solicitudes.php', { method: 'POST', body: formData });
      const data = await resp.json();
      mostrarToast(data.mensaje || data.error, data.ok ? 'success' : 'danger');

      if (data.ok) {
        guardReportar.marcarComoGuardado();
        modalReportar.hide();
        refrescarTodo();
      }
    } finally {
      boton.disabled = false;
      boton.innerHTML = textoOriginal;
    }
  });

  async function resolverIncidencia(instrumentoId) {
    const formData = new FormData();
    formData.append('accion', 'resolver_incidencia');
    formData.append('instrumento_id', instrumentoId);

    const resp = await fetch('api/solicitudes.php', { method: 'POST', body: formData });
    const data = await resp.json();
    mostrarToast(data.mensaje || data.error, data.ok ? 'success' : 'danger');

    if (data.ok) {
      refrescarTodo();
      const offcanvasDetalle = bootstrap.Offcanvas.getInstance(document.getElementById('offcanvasDetalle'));
      if (offcanvasDetalle) offcanvasDetalle.hide();
    }
  }

  window.cargarControl = cargarControl;

  // Cargar de inmediato si la pestaña de Control es la vista activa al ingresar
  if (!document.getElementById('panelControl').classList.contains('d-none')) {
    cargarControl(1);
  }
})();
