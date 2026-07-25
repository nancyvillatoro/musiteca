/**
 * Módulo de Soporte técnico: envío de reportes por AJAX (con captura de
 * pantalla opcional) y listado de los reportes más recientes.
 */

(function () {
  const form = document.getElementById('formSoporte');
  if (!form) return;

  const tabla = document.getElementById('tablaReportesBody');
  const btnRefrescar = document.getElementById('btnRefrescarReportes');
  const inputCaptura = document.getElementById('inputCaptura');
  const previsualizacion = document.getElementById('previsualizacionCaptura');

  const etiquetasModulo = { plataforma_web: 'Plataforma Web', aplicacion_operativa: 'Aplicación Operativa' };
  const etiquetasEstado = { pendiente: 'Pendiente', en_atencion: 'En atención', resuelto: 'Resuelto' };
  const clasesEstado = { pendiente: 'badge-en_reparacion', en_atencion: 'badge-regular', resuelto: 'badge-bueno' };

  // ---------- Validación personalizada ----------
  // validarFormulario/limpiarValidacion viven en main.js (compartidas).
  limpiarValidacionAlEscribir(form);

  // ---------- Vista previa de la captura seleccionada ----------
  inputCaptura.addEventListener('change', () => {
    const archivo = inputCaptura.files[0];
    if (!archivo) {
      previsualizacion.classList.add('d-none');
      return;
    }
    const lector = new FileReader();
    lector.onload = (e) => {
      previsualizacion.src = e.target.result;
      previsualizacion.classList.remove('d-none');
    };
    lector.readAsDataURL(archivo);
  });

  // ---------- Protección de cambios sin guardar (navegación fuera de la página) ----------
  let formularioSucio = false;
  form.addEventListener('input', () => { formularioSucio = true; });
  form.addEventListener('change', () => { formularioSucio = true; });

  // ---------- Listado de reportes ----------
  async function cargarReportes() {
    tabla.innerHTML = `<tr><td colspan="5"><div class="estado-lista"><span class="spinner-border spinner-border-sm"></span> Cargando reportes…</div></td></tr>`;
    try {
      const resp = await fetch('api/soporte.php?accion=listar');
      const data = await resp.json();
      if (!data.ok) throw new Error(data.error || 'No se pudieron obtener los reportes.');

      if (data.datos.length === 0) {
        tabla.innerHTML = `<tr><td colspan="5"><div class="estado-lista"><i class="bi bi-inbox"></i> Aún no se han registrado reportes.</div></td></tr>`;
        return;
      }

      tabla.innerHTML = data.datos.map((r) => {
        const desc = r.descripcion.length > 80 ? r.descripcion.slice(0, 80) + '…' : r.descripcion;
        return `
        <tr>
          <td class="small">${sanearHTML(r.creado_en)}</td>
          <td class="small">${sanearHTML(etiquetasModulo[r.modulo] || r.modulo)}</td>
          <td class="small" title="${sanearHTML(r.descripcion)}">${sanearHTML(desc)}</td>
          <td>
            ${window.ES_ADMIN ? `
              <select class="form-select form-select-sm selector-estado-soporte" data-id="${r.id}" style="width: auto; font-size: 0.8rem; padding: 0.2rem 0.5rem; min-height: auto;">
                <option value="pendiente" ${r.estado === 'pendiente' ? 'selected' : ''}>Pendiente</option>
                <option value="en_atencion" ${r.estado === 'en_atencion' ? 'selected' : ''}>En atención</option>
                <option value="resuelto" ${r.estado === 'resuelto' ? 'selected' : ''}>Resuelto</option>
              </select>
            ` : `
              <span class="badge-estado ${clasesEstado[r.estado] || 'badge-regular'}">${sanearHTML(etiquetasEstado[r.estado] || r.estado)}</span>
            `}
          </td>
          <td>
            ${r.captura_archivo
              ? `<a href="uploads/soporte/${sanearHTML(r.captura_archivo)}" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-image"></i></a>`
              : '<span class="text-muted small">—</span>'}
          </td>
        </tr>`;
      }).join('');
    } catch (err) {
      tabla.innerHTML = `<tr><td colspan="5"><div class="estado-lista estado-error"><i class="bi bi-exclamation-triangle"></i> ${sanearHTML(err.message)}</div></td></tr>`;
    }
  }

  btnRefrescar.addEventListener('click', cargarReportes);

  // ---------- Envío del formulario ----------
  form.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    if (!validarFormulario(form, 'Revisa los campos marcados en rojo antes de enviar.')) return;

    const boton = document.getElementById('btnEnviarReporte');
    boton.disabled = true;
    const textoOriginal = boton.innerHTML;
    boton.innerHTML = '<span class="spinner-border spinner-border-sm-inline spinner-border-sm"></span> Enviando…';

    try {
      const formData = new FormData(form);
      formData.append('accion', 'crear');

      const resp = await fetch('api/soporte.php', { method: 'POST', body: formData });
      const data = await resp.json();

      mostrarToast(data.mensaje || data.error, data.ok ? 'success' : 'danger');

      if (data.ok) {
        form.reset();
        previsualizacion.classList.add('d-none');
        formularioSucio = false;
        cargarReportes();
      }
    } catch (err) {
      mostrarToast('No fue posible enviar el reporte. Verifica tu conexión e intenta de nuevo.', 'danger');
    } finally {
      boton.disabled = false;
      boton.innerHTML = textoOriginal;
    }
  });

  // Aviso de cambios sin guardar al salir de la página con el formulario lleno
  window.addEventListener('beforeunload', (evento) => {
    if (formularioSucio) {
      evento.preventDefault();
      evento.returnValue = '';
    }
  });

  // Manejador del cambio de estado por el administrador
  document.addEventListener('change', async (evento) => {
    const select = evento.target.closest('.selector-estado-soporte');
    if (!select) return;

    const id = select.dataset.id;
    const nuevoEstado = select.value;

    select.disabled = true;
    try {
      const formData = new FormData();
      formData.append('accion', 'actualizar_estado');
      formData.append('id', id);
      formData.append('estado', nuevoEstado);

      const resp = await fetch('api/soporte.php', {
        method: 'POST',
        body: formData
      });
      const data = await resp.json();

      mostrarToast(data.mensaje || data.error, data.ok ? 'success' : 'danger');
      if (!data.ok) {
        cargarReportes();
      }
    } catch (err) {
      mostrarToast('No fue posible actualizar el estado del reporte.', 'danger');
      cargarReportes();
    } finally {
      select.disabled = false;
    }
  });

  cargarReportes();
})();
