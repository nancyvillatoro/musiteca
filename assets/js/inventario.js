/**
 * Módulo de Inventario: listado paginado, búsqueda, filtro por condición,
 * alta/edición mediante modal y expediente de detalle en offcanvas.
 */

(function () {
  const tabla       = document.getElementById('tablaInventarioBody');
  const paginacion  = document.getElementById('paginacionInventario');
  const inputBuscar = document.getElementById('buscarInventario');
  const selectCond  = document.getElementById('filtroCondicion');
  const btnLimpiar  = document.getElementById('btnLimpiarInventario');
  const btnExportar = document.getElementById('btnExportarInventario');
  const modalInstrumentoEl = document.getElementById('modalNuevoInstrumento');
  const modalInstrumento = modalInstrumentoEl ? new bootstrap.Modal(modalInstrumentoEl) : null;
  const formInstrumento = document.getElementById('formInstrumento');

  if (!tabla) return; // La vista de inventario no está presente en esta página

  let paginaActual = 1;
  let temporizadorBusqueda = null;

  // ---------- Refresco de indicadores KPI en tiempo real ----------
  async function refrescarKPIs() {
    try {
      const resp = await fetch('api/instrumentos.php?accion=kpis');
      const data = await resp.json();
      if (!data.ok) return;
      const el = (id) => document.getElementById(id);
      if (el('kpiTotal')) el('kpiTotal').textContent = data.kpis.total;
      if (el('kpiDisponibles')) el('kpiDisponibles').textContent = data.kpis.disponibles;
      if (el('kpiEnUso')) el('kpiEnUso').textContent = data.kpis.en_uso;
      if (el('kpiReparacion')) el('kpiReparacion').textContent = data.kpis.en_reparacion;
    } catch (e) { /* silencioso: no interrumpe la operación del usuario */ }
  }
  window.refrescarKPIs = refrescarKPIs;

  // ---------- Validación personalizada ----------
  // validarFormulario/limpiarValidacion viven ahora en main.js (utilidades
  // compartidas por todos los módulos). #formInstrumento solo existe en el
  // DOM para el rol administrador (el modal completo se oculta para el rol
  // operativo en index.php), así que se protege con el "if".
  if (formInstrumento) {
    limpiarValidacionAlEscribir(formInstrumento);
  }

  const badgeCondicion = (valor) => {
    const etiquetas = { bueno: 'Bueno', regular: 'Regular', malo: 'Malo', inservible: 'Inservible' };
    return `<span class="badge-estado badge-${valor}">${etiquetas[valor] || valor}</span>`;
  };

  const badgeEstado = (valor) => {
    const etiquetas = { disponible: 'Disponible', en_uso: 'Actualmente asignado', en_reparacion: 'En mantenimiento' };
    return `<span class="badge-estado badge-${valor}">${etiquetas[valor] || valor}</span>`;
  };

  async function cargarInventario(pagina = 1) {
    paginaActual = pagina;
    const params = new URLSearchParams({
      accion: 'listar',
      buscar: inputBuscar.value.trim(),
      condicion: selectCond.value,
      pagina: pagina,
    });

    tabla.innerHTML = `<tr><td colspan="6"><div class="estado-lista"><span class="spinner-border spinner-border-sm"></span> Cargando inventario…</div></td></tr>`;

    try {
      const resp = await fetch(`api/instrumentos.php?${params.toString()}`);
      const data = await resp.json();

      if (!data.ok) throw new Error(data.error || 'Error al consultar el inventario.');

      if (data.datos.length === 0) {
        tabla.innerHTML = `<tr><td colspan="6"><div class="estado-lista"><i class="bi bi-inbox"></i> Sin resultados para los criterios seleccionados.</div></td></tr>`;
      } else {
        tabla.innerHTML = data.datos.map((row) => `
          <tr>
            <td class="fw-semibold">${sanearHTML(row.num_inventario)}</td>
            <td>${sanearHTML(row.nombre)}</td>
            <td>${sanearHTML(row.ubicacion)}</td>
            <td>${badgeCondicion(row.condicion)}</td>
            <td>${badgeEstado(row.estado)}</td>
            <td class="text-end col-acciones">
              <div class="acciones-grupo">
                <button class="btn btn-sm btn-icono-tabla btn-outline-secondary btn-detalle" data-id="${row.id}" title="Detalles" aria-label="Ver detalles de ${sanearHTML(row.nombre)}">
                  <i class="bi bi-eye" aria-hidden="true"></i>
                </button>
                ${window.ES_ADMIN ? `
                  <button class="btn btn-sm btn-icono-tabla btn-outline-secondary btn-editar" data-id="${row.id}" title="Editar" aria-label="Editar ${sanearHTML(row.nombre)}">
                    <i class="bi bi-pencil" aria-hidden="true"></i>
                  </button>
                  <button class="btn btn-sm btn-icono-tabla btn-outline-danger btn-eliminar" data-id="${row.id}" data-nombre="${sanearHTML(row.nombre)}" data-inv="${sanearHTML(row.num_inventario)}" title="Eliminar" aria-label="Dar de baja ${sanearHTML(row.nombre)}">
                    <i class="bi bi-trash" aria-hidden="true"></i>
                  </button>
                ` : ''}
              </div>
            </td>
          </tr>
        `).join('');
      }

      renderPaginacion(data.pagina, data.totalPaginas);
      actualizarResumenListado('resumenInventario', data.pagina, data.porPagina, data.total);
    } catch (err) {
      tabla.innerHTML = `<tr><td colspan="6"><div class="estado-lista estado-error"><i class="bi bi-exclamation-triangle"></i> ${sanearHTML(err.message)}</div></td></tr>`;
    }
  }

  function renderPaginacion(actual, totalPaginas) {
    paginacion.innerHTML = '';
    if (totalPaginas <= 1) return;

    for (let i = 1; i <= totalPaginas; i++) {
      const li = document.createElement('li');
      li.className = `page-item ${i === actual ? 'active' : ''}`;
      li.innerHTML = `<button class="page-link">${i}</button>`;
      li.querySelector('button').addEventListener('click', () => cargarInventario(i));
      paginacion.appendChild(li);
    }
  }

  // ---------- Búsqueda y filtros ----------
  inputBuscar.addEventListener('input', () => {
    clearTimeout(temporizadorBusqueda);
    temporizadorBusqueda = setTimeout(() => cargarInventario(1), 350);
  });
  selectCond.addEventListener('change', () => cargarInventario(1));
  btnLimpiar.addEventListener('click', () => {
    inputBuscar.value = '';
    selectCond.value = '';
    cargarInventario(1);
  });

  // ---------- Exportación (botón oculto en el DOM para el rol operativo:
  // ver index.php) ----------
  if (btnExportar) {
    btnExportar.addEventListener('click', () => {
      window.location.href = 'api/export_inventario.php';
    });
  }

  // ---------- Alta / edición (solo administrador: #modalNuevoInstrumento y
  // #formInstrumento no existen en el DOM para el rol operativo) ----------
  let guardInstrumento = { marcarComoGuardado() {} };

  if (formInstrumento && modalInstrumentoEl) {
    modalInstrumentoEl.addEventListener('show.bs.modal', (evento) => {
      const boton = evento.relatedTarget;
      const esEdicion = boton && boton.classList.contains('btn-editar');
      document.getElementById('tituloModalInstrumento').innerHTML = esEdicion
        ? '<i class="bi bi-pencil"></i> Editar instrumento'
        : '<i class="bi bi-plus-circle"></i> Registrar instrumento';
      limpiarValidacion(formInstrumento);
    });

    // Protege el formulario: si hay cambios sin guardar, confirma antes de cerrar el modal
    guardInstrumento = protegerFormularioConCambios(formInstrumento, modalInstrumentoEl);

    modalInstrumentoEl.addEventListener('hidden.bs.modal', () => {
      formInstrumento.reset();
      document.getElementById('instrumentoId').value = '';
    });

    formInstrumento.addEventListener('submit', async (evento) => {
      evento.preventDefault();

      if (!validarFormulario(formInstrumento, 'Revisa los campos marcados en rojo antes de guardar.')) return;

      const botonGuardar = document.getElementById('btnGuardarInstrumento');
      botonGuardar.disabled = true;
      botonGuardar.innerHTML = '<span class="spinner-border spinner-border-sm-inline spinner-border-sm"></span> Guardando…';

      const formData = new FormData(formInstrumento);
      formData.append('accion', 'guardar');

      try {
        const resp = await fetch('api/instrumentos.php', { method: 'POST', body: formData });
        const data = await resp.json();

        mostrarToast(data.mensaje || data.error, data.ok ? 'success' : 'danger');
        if (data.ok) {
          guardInstrumento.marcarComoGuardado();
          modalInstrumento.hide();
          cargarInventario(paginaActual);
          refrescarKPIs();
        }
      } finally {
        botonGuardar.disabled = false;
        botonGuardar.innerHTML = '<i class="bi bi-save"></i> Guardar instrumento';
      }
    });
  }

  document.addEventListener('click', async (evento) => {
    const botonEditar = evento.target.closest('.btn-editar');
    const botonDetalle = evento.target.closest('.btn-detalle');
    const botonEliminar = evento.target.closest('.btn-eliminar');

    if (botonEditar && formInstrumento && modalInstrumento) {
      const id = botonEditar.dataset.id;
      const resp = await fetch(`api/instrumentos.php?accion=detalle&id=${id}`);
      const data = await resp.json();
      if (!data.ok) return mostrarToast(data.error, 'danger');

      const i = data.instrumento;
      formInstrumento.reset();
      document.getElementById('instrumentoId').value = i.id;
      document.getElementById('numInventario').value = i.num_inventario;
      document.getElementById('numInventarioAnterior').value = i.num_inventario_anterior || '';
      document.getElementById('nombreInstrumento').value = i.nombre;
      document.getElementById('marcaInstrumento').value = i.marca || '';
      document.getElementById('modeloInstrumento').value = i.modelo || '';
      document.getElementById('numSerie').value = i.num_serie || '';
      document.getElementById('ubicacionId').value = i.ubicacion_id || '';
      document.getElementById('condicionInstrumento').value = i.condicion;
      document.getElementById('estadoInstrumento').value = i.estado;

      modalInstrumento.show();
    }

    if (botonDetalle) {
      mostrarDetalle(botonDetalle.dataset.id);
    }

    if (botonEliminar) {
      const confirmado = await confirmarAccion({
        titulo: 'Dar de baja instrumento',
        mensaje: `¿Confirmas dar de baja <strong>${botonEliminar.dataset.nombre}</strong> (N° ${botonEliminar.dataset.inv})? Esta acción no se puede deshacer.`,
        tipo: 'danger',
        textoConfirmar: 'Eliminar',
        textoCancelar: 'Cancelar',
      });
      if (!confirmado) return;

      const formData = new FormData();
      formData.append('accion', 'eliminar');
      formData.append('id', botonEliminar.dataset.id);

      const resp = await fetch('api/instrumentos.php', { method: 'POST', body: formData });
      const data = await resp.json();
      mostrarToast(data.mensaje || data.error, data.ok ? 'success' : 'danger');
      if (data.ok) {
        cargarInventario(paginaActual);
        refrescarKPIs();
      }
    }
  });

  // ---------- Detalle / trazabilidad ----------
  async function mostrarDetalle(id) {
    const contenedor = document.getElementById('contenidoDetalle');
    const offcanvasEl = document.getElementById('offcanvasDetalle');
    const offcanvas = bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl);

    contenedor.innerHTML = `<div class="estado-lista"><span class="spinner-border spinner-border-sm"></span> Cargando expediente…</div>`;
    offcanvas.show();

    const resp = await fetch(`api/instrumentos.php?accion=detalle&id=${id}`);
    const data = await resp.json();
    if (!data.ok) {
      contenedor.innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
      return;
    }

    const i = data.instrumento;
    const etiquetasCondicionDevolucion = { excelente: 'Excelente', bueno: 'Bueno', desgaste: 'Con desgaste', danado: 'Dañado', incompleto: 'Incompleto' };
    const historial = data.bitacora.map((h) => {
      const condicionBadge = h.condicion_devolucion
        ? `<span class="badge-estado badge-${sanearHTML(h.condicion_devolucion)}">${sanearHTML(etiquetasCondicionDevolucion[h.condicion_devolucion] || h.condicion_devolucion)}</span>`
        : '';
      return `
      <div class="border rounded p-2 mb-2 small">
        <div class="fw-semibold"><i class="bi bi-clock-history"></i> Préstamo</div>
        <div>Solicitante: ${sanearHTML(h.solicitante)}</div>
        <div>Ubicación: ${sanearHTML(h.ubicacion_destino || '—')}</div>
        <div>Inicio: ${sanearHTML(h.fecha_solicitud || '—')}</div>
        <div>Regreso: ${sanearHTML(h.fecha_regreso || 'En curso')}</div>
        ${condicionBadge ? `<div class="mt-1">Estado de entrega: ${condicionBadge}</div>` : ''}
        ${h.observaciones_devolucion ? `<div class="text-muted">Observaciones de devolución: ${sanearHTML(h.observaciones_devolucion)}</div>` : ''}
      </div>
    `;
    }).join('') || '<p class="text-muted small">Sin movimientos registrados.</p>';

    const etiquetasMotivoIncidencia = { dano_fisico: 'Daño físico', mal_funcionamiento: 'Mal funcionamiento', piezas_faltantes: 'Piezas faltantes', desgaste: 'Desgaste por uso', otro: 'Otro' };
    const etiquetasDecisionIncidencia = { resuelto: 'Resuelto (volvió a disponible)', mantenimiento: 'Enviado a mantenimiento' };

    const incidenciasAbiertas = data.incidencias_abiertas || [];
    const avisoIncidencia = incidenciasAbiertas.length > 0 ? `
      <div class="alert alert-warning small mb-3">
        <i class="bi bi-exclamation-triangle-fill"></i>
        Este instrumento tiene ${incidenciasAbiertas.length > 1 ? `${incidenciasAbiertas.length} incidencias reportadas` : 'una incidencia reportada'} durante el préstamo activo.
        La decisión (disponible o mantenimiento) se toma al registrar la devolución.
      </div>
    ` : '';

    // Etiqueta de estado por incidencia: antes el historial solo mostraba
    // motivo/descripción/fecha sin indicar si seguía pendiente o ya se
    // había resuelto, lo que confundía al revisar el expediente (la
    // condición del instrumento es un campo aparte y puede seguir
    // marcando "bueno" aunque haya una incidencia sin resolver). Las
    // pendientes (abierta/en_atencion) se muestran primero y con borde
    // de aviso para que se distingan de un vistazo del resto del
    // historial ya cerrado.
    const etiquetasEstadoIncidencia = { abierta: 'Pendiente', en_atencion: 'En atención', resuelta: 'Resuelta' };
    const historialIncidenciasOrdenado = [...(data.historial_incidencias || [])].sort((a, b) => {
      const pendienteA = a.estado === 'resuelta' ? 1 : 0;
      const pendienteB = b.estado === 'resuelta' ? 1 : 0;
      return pendienteA - pendienteB;
    });
    // Antes solo existía forma de resolver una incidencia mientras el
    // instrumento seguía "En mantenimiento" (botón "Marcar disponible").
    // Si el estado se corregía a mano desde el formulario de edición sin
    // pasar por ahí, la incidencia se quedaba pendiente sin ningún botón
    // visible para cerrarla. Este indicador permite ofrecer esa acción
    // también cuando el instrumento ya está disponible/en uso.
    const hayIncidenciaPendiente = historialIncidenciasOrdenado.some((h) => h.estado !== 'resuelta');

    const historialIncidencias = historialIncidenciasOrdenado.map((h) => {
      const pendiente = h.estado !== 'resuelta';
      const badgeEstadoIncidencia = `<span class="badge-estado ${pendiente ? 'badge-incidencia' : 'badge-bueno'}">${sanearHTML(etiquetasEstadoIncidencia[h.estado] || h.estado)}</span>`;
      return `
      <div class="border rounded p-2 mb-2 small ${pendiente ? 'border-warning' : ''}">
        <div class="fw-semibold d-flex align-items-center justify-content-between gap-2">
          <span><i class="bi bi-exclamation-triangle"></i> ${sanearHTML(etiquetasMotivoIncidencia[h.motivo] || h.motivo || 'Incidencia')}</span>
          ${badgeEstadoIncidencia}
        </div>
        <div>Reportado por: ${sanearHTML(h.reportado_por)}</div>
        <div>Fecha del reporte: ${sanearHTML(h.creado_en)}</div>
        <div>Descripción: ${sanearHTML(h.descripcion)}</div>
        ${h.decision_devolucion ? `<div>Decisión al devolver: ${sanearHTML(etiquetasDecisionIncidencia[h.decision_devolucion] || h.decision_devolucion)}</div>` : ''}
        ${h.fecha_decision ? `<div>Fecha de la decisión: ${sanearHTML(h.fecha_decision)}</div>` : ''}
      </div>
    `;
    }).join('') || '<p class="text-muted small">Sin incidencias registradas.</p>';

    contenedor.innerHTML = `
      <div class="mb-3">
        <div class="text-muted small">N° Inventario</div>
        <div class="fw-bold fs-5">${sanearHTML(i.num_inventario)}</div>
      </div>
      <div class="row g-2 small mb-3">
        <div class="col-6"><span class="text-muted">Instrumento</span><br>${sanearHTML(i.nombre)}</div>
        <div class="col-6"><span class="text-muted">Marca</span><br>${sanearHTML(i.marca || '—')}</div>
        <div class="col-6"><span class="text-muted">Modelo</span><br>${sanearHTML(i.modelo || '—')}</div>
        <div class="col-6"><span class="text-muted">N° Serie</span><br>${sanearHTML(i.num_serie || '—')}</div>
        <div class="col-6"><span class="text-muted">Ubicación</span><br>${sanearHTML(i.ubicacion_nombre || 'Sin asignar')}</div>
        <div class="col-6"><span class="text-muted">Condición</span><br>${badgeCondicion(i.condicion)}</div>
        <div class="col-12"><span class="text-muted">Estado actual</span><br>${badgeEstado(i.estado)}</div>
      </div>
      ${avisoIncidencia}
      <div class="d-flex gap-2 mb-4 flex-wrap">
        ${i.estado === 'en_reparacion' ? `
          <button class="btn btn-sm btn-success flex-grow-1 btn-resolver-incidencia" data-id="${i.id}" data-nombre="${sanearHTML(i.nombre)}">
            <i class="bi bi-check2-circle"></i> Marcar disponible
          </button>
        ` : `
          <button class="btn btn-sm btn-primary-musiteca flex-grow-1 btn-abrir-solicitar" data-id="${i.id}" data-nombre="${sanearHTML(i.nombre)}" data-inv="${sanearHTML(i.num_inventario)}">
            <i class="bi bi-hand-index-thumb"></i> Solicitar
          </button>
          ${hayIncidenciaPendiente ? `
            <button class="btn btn-sm btn-outline-success flex-grow-1 btn-resolver-incidencia" data-id="${i.id}" data-nombre="${sanearHTML(i.nombre)}">
              <i class="bi bi-check2-circle"></i> Marcar incidencia resuelta
            </button>
          ` : ''}
        `}
        <button class="btn btn-sm btn-outline-danger flex-grow-1 btn-abrir-reportar" data-id="${i.id}" data-nombre="${sanearHTML(i.nombre)}" data-inv="${sanearHTML(i.num_inventario)}" data-estado="${sanearHTML(i.estado)}">
           <i class="bi bi-exclamation-triangle"></i> Reportar
        </button>
      </div>
      <h6 class="fw-bold" style="color:var(--musiteca-guinda-oscuro);"><i class="bi bi-clock-history"></i> Historial de préstamos</h6>
      ${historial}
      <h6 class="fw-bold mt-4" style="color:var(--musiteca-guinda-oscuro);"><i class="bi bi-exclamation-triangle"></i> Historial de incidencias</h6>
      ${historialIncidencias}
    `;
  }

  window.actualizarInventarioExterno = () => cargarInventario(paginaActual);
  window.mostrarDetalleInstrumento = mostrarDetalle;

  cargarInventario(1);
})();
