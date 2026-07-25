/**
 * Módulo de Instrumentos dados de baja: listado paginado, búsqueda y
 * restauración al catálogo activo.
 */

(function () {
  const tabla       = document.getElementById('tablaBajaBody');
  if (!tabla) return; // Esta vista no está presente en la página actual

  const paginacion  = document.getElementById('paginacionBaja');
  const inputBuscar = document.getElementById('buscarBaja');
  const btnLimpiar  = document.getElementById('btnLimpiarBaja');

  let paginaActual = 1;
  let temporizadorBusqueda = null;

  const badgeCondicion = (valor) => {
    const etiquetas = { bueno: 'Bueno', regular: 'Regular', malo: 'Malo', inservible: 'Inservible' };
    return `<span class="badge-estado badge-${valor}">${etiquetas[valor] || valor}</span>`;
  };

  async function cargarBaja(pagina = 1) {
    paginaActual = pagina;
    const params = new URLSearchParams({
      accion: 'listar_baja',
      buscar: inputBuscar.value.trim(),
      pagina: pagina,
    });

    tabla.innerHTML = `<tr><td colspan="7"><div class="estado-lista"><span class="spinner-border spinner-border-sm"></span> Cargando instrumentos dados de baja…</div></td></tr>`;

    try {
      const resp = await fetch(`api/instrumentos.php?${params.toString()}`);
      const data = await resp.json();

      if (!data.ok) throw new Error(data.error || 'Error al consultar el listado.');

      if (data.datos.length === 0) {
        tabla.innerHTML = `<tr><td colspan="7"><div class="estado-lista"><i class="bi bi-inbox"></i> No hay instrumentos dados de baja para los criterios seleccionados.</div></td></tr>`;
      } else {
        tabla.innerHTML = data.datos.map((row) => `
          <tr>
            <td class="fw-semibold">${sanearHTML(row.num_inventario)}</td>
            <td>${sanearHTML(row.nombre)}</td>
            <td>${sanearHTML(row.ubicacion)}</td>
            <td>${badgeCondicion(row.condicion)}</td>
            <td class="small">${sanearHTML(row.fecha_baja || '—')}</td>
            <td class="small" title="${sanearHTML(row.motivo_baja || '')}">${sanearHTML(row.motivo_baja || '—')}</td>
            <td class="text-end col-acciones">
              <button class="btn btn-sm btn-outline-success btn-restaurar" data-id="${row.id}" data-nombre="${sanearHTML(row.nombre)}" data-inv="${sanearHTML(row.num_inventario)}">
                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restaurar
              </button>
            </td>
          </tr>
        `).join('');
      }

      renderPaginacion(data.pagina, data.totalPaginas);
      actualizarResumenListado('resumenBaja', data.pagina, data.porPagina, data.total);
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
      li.querySelector('button').addEventListener('click', () => cargarBaja(i));
      paginacion.appendChild(li);
    }
  }

  // ---------- Búsqueda ----------
  inputBuscar.addEventListener('input', () => {
    clearTimeout(temporizadorBusqueda);
    temporizadorBusqueda = setTimeout(() => cargarBaja(1), 350);
  });
  btnLimpiar.addEventListener('click', () => {
    inputBuscar.value = '';
    cargarBaja(1);
  });

  // ---------- Restaurar ----------
  document.addEventListener('click', async (evento) => {
    const boton = evento.target.closest('.btn-restaurar');
    if (!boton) return;

    const confirmado = await confirmarAccion({
      titulo: 'Restaurar instrumento',
      mensaje: `¿Confirmas restaurar <strong>${boton.dataset.nombre}</strong> (N° ${boton.dataset.inv})? Volverá al catálogo activo como "Disponible".`,
      tipo: 'success',
      textoConfirmar: 'Restaurar',
      textoCancelar: 'Cancelar',
    });
    if (!confirmado) return;

    boton.disabled = true;

    const formData = new FormData();
    formData.append('accion', 'restaurar');
    formData.append('id', boton.dataset.id);

    try {
      const resp = await fetch('api/instrumentos.php', { method: 'POST', body: formData });
      const data = await resp.json();
      mostrarToast(data.mensaje || data.error, data.ok ? 'success' : 'danger');
      if (data.ok) {
        cargarBaja(paginaActual);
      } else {
        boton.disabled = false;
      }
    } catch (err) {
      mostrarToast('No fue posible restaurar el instrumento. Verifica tu conexión e intenta de nuevo.', 'danger');
      boton.disabled = false;
    }
  });

  cargarBaja(1);
})();
