/**
 * Utilidades comunes de interfaz: toasts, menú lateral responsive y
 * cambio de pestañas Inventario / Control.
 */

/**
 * Protección CSRF centralizada del lado del cliente.
 *
 * En vez de modificar cada llamada fetch() de inventario.js, control.js,
 * soporte.js y app.js para adjuntar el token manualmente, se intercepta
 * window.fetch una sola vez: cualquier petición con método distinto de
 * GET/HEAD recibe automáticamente la cabecera X-CSRF-Token leída del
 * <meta name="csrf-token"> impreso por includes/header.php / app/index.php.
 */
(function protegerPeticionesConCSRF() {
  const metaToken = document.querySelector('meta[name="csrf-token"]');
  if (!metaToken) return;

  let token = metaToken.content;
  const fetchOriginal = window.fetch.bind(window);
  let refrescoEnCurso = null;

  // Ruta relativa correcta según si la página vive en la raíz o en /app/.
  function rutaApi(nombreArchivo) {
    return (window.location.pathname.includes('/app/') ? '../api/' : 'api/') + nombreArchivo;
  }

  // Pide un token vigente a la sesión actual. Si la sesión ya expiró de
  // verdad (401), no hay token que valga: se informa al llamador para que
  // redirija a login en vez de reintentar en un bucle sin sentido.
  function refrescarTokenCSRF() {
    if (!refrescoEnCurso) {
      refrescoEnCurso = fetchOriginal(rutaApi('csrf_token.php'))
        .then((resp) => resp.json().then((data) => ({ status: resp.status, data })))
        .catch(() => ({ status: 0, data: null }))
        .finally(() => { refrescoEnCurso = null; });
    }
    return refrescoEnCurso;
  }

  window.fetch = function fetchConCSRF(recurso, opciones = {}) {
    const metodo = (opciones.method || 'GET').toUpperCase();
    if (metodo === 'GET' || metodo === 'HEAD') {
      return fetchOriginal(recurso, opciones);
    }

    const headers = new Headers(opciones.headers || {});
    if (!headers.has('X-CSRF-Token')) {
      headers.set('X-CSRF-Token', token);
    }

    return fetchOriginal(recurso, { ...opciones, headers }).then(async (respuesta) => {
      if (respuesta.status !== 419) {
        return respuesta;
      }

      // Token desincronizado (p. ej. el servidor lo renovó por inactividad
      // cercana al límite): se intenta refrescar UNA sola vez y reintentar
      // la misma petición antes de mostrarle cualquier error al usuario.
      const { status: statusRefresco, data: datosRefresco } = await refrescarTokenCSRF();

      if (statusRefresco === 401) {
        // La sesión sí expiró de verdad: no hay token que reintentar.
        if (window.manejarSesionExpirada) window.manejarSesionExpirada({ status: 401 });
        return respuesta;
      }

      if (!datosRefresco || !datosRefresco.ok || !datosRefresco.token || datosRefresco.token === token) {
        return respuesta;
      }

      token = datosRefresco.token;
      metaToken.setAttribute('content', token);

      const headersReintento = new Headers(opciones.headers || {});
      headersReintento.set('X-CSRF-Token', token);
      return fetchOriginal(recurso, { ...opciones, headers: headersReintento });
    });
  };
})();

/**
 * Sesión expirada por inactividad (SESION_TIEMPO_INACTIVIDAD): si un fetch
 * autenticado responde 401, se redirige a login en vez de dejar la
 * interfaz en un estado inconsistente.
 */
function manejarSesionExpirada(respuesta) {
  if (respuesta.status === 401) {
    window.location.href = (window.location.pathname.includes('/app/') ? '../login.php' : 'login.php') + '?expirada=1';
    return true;
  }
  return false;
}
window.manejarSesionExpirada = manejarSesionExpirada;

/**
 * Sanitizador HTML global para prevenir vulnerabilidades XSS.
 */
function sanearHTML(str) {
  if (str === null || str === undefined) return '';
  const temporal = document.createElement('div');
  temporal.textContent = str;
  // Además de escapar &, < y > (que ya resuelve textContent -> innerHTML),
  // escapamos comillas: el valor saneado se usa tanto dentro de texto como
  // dentro de atributos data-* en todos los módulos (inventario, control,
  // soporte y la app operativa), y un valor con comillas podría romper el
  // atributo HTML si no se escapa aquí.
  return temporal.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
window.sanearHTML = sanearHTML;

/**
 * Validación personalizada compartida (sin validación nativa del navegador).
 * Antes estaba duplicada casi al carácter en inventario.js, control.js,
 * usuarios.js, soporte.js y app.js; se centraliza aquí para tener una sola
 * fuente de verdad sobre cómo se marca y anuncia un campo obligatorio vacío.
 *
 * Marca con .is-invalid + aria-invalid los campos [data-obligatorio="true"]
 * vacíos, enfoca el primero y muestra un toast de advertencia. Devuelve
 * true si el formulario es válido.
 */
function validarFormulario(form, mensajeError = 'Revisa los campos marcados en rojo antes de continuar.') {
  let esValido = true;
  let primerCampoInvalido = null;

  form.querySelectorAll('[data-obligatorio="true"]').forEach((campo) => {
    const valor = (campo.value || '').trim();
    if (valor === '') {
      campo.classList.add('is-invalid', 'campo-shake');
      campo.setAttribute('aria-invalid', 'true');
      setTimeout(() => campo.classList.remove('campo-shake'), 350);
      esValido = false;
      if (!primerCampoInvalido) primerCampoInvalido = campo;
    } else {
      campo.classList.remove('is-invalid');
      campo.removeAttribute('aria-invalid');
    }
  });

  if (!esValido && primerCampoInvalido) {
    primerCampoInvalido.focus();
    mostrarToast(mensajeError, 'warning');
  }

  return esValido;
}
window.validarFormulario = validarFormulario;

function limpiarValidacion(form) {
  form.querySelectorAll('.is-invalid').forEach((campo) => {
    campo.classList.remove('is-invalid');
    campo.removeAttribute('aria-invalid');
  });
}
window.limpiarValidacion = limpiarValidacion;

/**
 * Vincula un formulario para que, apenas el usuario corrija un campo
 * marcado en rojo, el estado de error desaparezca (mismo comportamiento
 * que antes se repetía módulo por módulo).
 */
function limpiarValidacionAlEscribir(form) {
  if (!form) return;
  ['input', 'change'].forEach((evt) => {
    form.addEventListener(evt, (evento) => {
      if (evento.target.classList.contains('is-invalid') && (evento.target.value || '').trim() !== '') {
        evento.target.classList.remove('is-invalid');
        evento.target.removeAttribute('aria-invalid');
      }
    });
  });
}
window.limpiarValidacionAlEscribir = limpiarValidacionAlEscribir;

/**
 * Texto compartido "Mostrando X-Y de Z" para listados paginados
 * (Catálogo, Movimientos, Usuarios). Si el contenedor no existe en la
 * página actual, no hace nada.
 */
function actualizarResumenListado(elementoId, pagina, porPagina, total) {
  const el = document.getElementById(elementoId);
  if (!el) return;
  if (!total) {
    el.textContent = 'Sin resultados';
    return;
  }
  const desde = (pagina - 1) * porPagina + 1;
  const hasta = Math.min(pagina * porPagina, total);
  el.textContent = `Mostrando ${desde}–${hasta} de ${total}`;
}
window.actualizarResumenListado = actualizarResumenListado;

function mostrarToast(mensaje, tipo = 'success') {
  const contenedor = document.getElementById('toastContainer');
  if (!contenedor) return;

  const iconos = {
    success: 'bi-check-circle-fill text-success',
    danger:  'bi-x-circle-fill text-danger',
    warning: 'bi-exclamation-triangle-fill text-warning',
  };

  const toastEl = document.createElement('div');
  toastEl.className = 'toast align-items-center border-0 shadow';
  toastEl.setAttribute('role', 'alert');
  toastEl.innerHTML = `
    <div class="d-flex">
      <div class="toast-body">
        <i class="bi ${iconos[tipo] || iconos.success} me-2"></i>${mensaje}
      </div>
      <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>`;
  contenedor.appendChild(toastEl);

  const toast = new bootstrap.Toast(toastEl, { delay: 4000 });
  toast.show();
  toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
}

/**
 * Modal de confirmación reutilizable (reemplazo de confirm() nativo).
 * Uso: const ok = await confirmarAccion({ titulo, mensaje, tipo, textoConfirmar });
 * tipo: 'danger' | 'warning' | 'info' | 'success'
 */
function confirmarAccion({ titulo = '¿Confirmar acción?', mensaje = '¿Deseas continuar?', tipo = 'warning', textoConfirmar = 'Confirmar', textoCancelar = 'Cancelar' } = {}) {
  return new Promise((resolve) => {
    const modalEl = document.getElementById('modalConfirmacionGlobal');
    if (!modalEl) {
      console.error('No se encontró el modal de confirmación global (#modalConfirmacionGlobal) en esta página.');
      resolve(false);
      return;
    }

    const iconos = {
      danger:  { clase: 'bi-trash3-fill',            color: 'confirm-icono-danger'  },
      warning: { clase: 'bi-exclamation-triangle-fill', color: 'confirm-icono-warning' },
      info:    { clase: 'bi-info-circle-fill',       color: 'confirm-icono-info'    },
      success: { clase: 'bi-check-circle-fill',      color: 'confirm-icono-success' },
    };
    const botonColor = { danger: 'btn-danger', warning: 'btn-warning', info: 'btn-primary-musiteca', success: 'btn-success' };
    const config = iconos[tipo] || iconos.warning;

    const iconoWrap = modalEl.querySelector('#confirmIcono');
    iconoWrap.className = `confirm-modal-icono mb-3 ${config.color}`;
    iconoWrap.querySelector('i').className = `bi ${config.clase}`;

    modalEl.querySelector('#confirmTitulo').textContent = titulo;
    modalEl.querySelector('#confirmMensaje').innerHTML = mensaje;

    const btnAceptar = modalEl.querySelector('#confirmBtnAceptar');
    const btnCancelar = modalEl.querySelector('#confirmBtnCancelar');
    btnAceptar.textContent = textoConfirmar;
    btnCancelar.textContent = textoCancelar;
    btnAceptar.className = `btn px-4 ${botonColor[tipo] || 'btn-warning'}`;

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

    let fueAceptado = false;
    const limpiar = () => {
      btnAceptar.removeEventListener('click', onAceptar);
      btnCancelar.removeEventListener('click', onCancelar);
      modalEl.removeEventListener('hidden.bs.modal', onOculto);
    };
    const onAceptar = () => { fueAceptado = true; modal.hide(); };
    const onCancelar = () => { modal.hide(); };
    const onOculto = () => {
      limpiar();
      resolve(fueAceptado);
    };

    btnAceptar.addEventListener('click', onAceptar);
    btnCancelar.addEventListener('click', onCancelar);
    modalEl.addEventListener('hidden.bs.modal', onOculto);

    modal.show();
  });
}
window.confirmarAccion = confirmarAccion;

/**
 * Seguimiento de "cambios sin guardar" en formularios dentro de modales.
 * Marca el formulario como "sucio" al detectar cualquier entrada del
 * usuario y, si se intenta cerrar el modal, pide confirmación mediante
 * confirmarAccion() en lugar del diálogo nativo del navegador.
 *
 * Devuelve un objeto con marcarComoGuardado(), que debe llamarse justo
 * antes de cerrar el modal tras un guardado exitoso, para no disparar
 * la confirmación de salida sobre datos que ya se guardaron.
 */
function protegerFormularioConCambios(formEl, modalEl) {
  if (!formEl || !modalEl) return { marcarComoGuardado() {} };

  let sucio = false;

  formEl.addEventListener('input', () => { sucio = true; });
  formEl.addEventListener('change', () => { sucio = true; });

  modalEl.addEventListener('hide.bs.modal', function onHide(evento) {
    if (sucio) {
      evento.preventDefault();
      confirmarAccion({
        titulo: 'Cambios sin guardar',
        mensaje: 'Tienes información capturada en este formulario. ¿Deseas salir sin guardar los cambios?',
        tipo: 'warning',
        textoConfirmar: 'Salir sin guardar',
        textoCancelar: 'Seguir editando',
      }).then((confirmado) => {
        if (confirmado) {
          sucio = false;
          bootstrap.Modal.getInstance(modalEl).hide();
        }
      });
    }
  });

  modalEl.addEventListener('hidden.bs.modal', () => { sucio = false; });

  // Reinicia el estado "sucio" cada vez que el modal se vuelve a abrir (ej. al precargar datos de edición)
  modalEl.addEventListener('shown.bs.modal', () => { sucio = false; });

  return {
    marcarComoGuardado() { sucio = false; },
  };
}
window.protegerFormularioConCambios = protegerFormularioConCambios;

document.addEventListener('DOMContentLoaded', () => {
  // Sidebar responsive
  const btnToggle = document.getElementById('btnToggleSidebar');
  const sidebar = document.getElementById('sidebarMenu');
  const overlay = document.getElementById('sidebarOverlay');
  const alternarSidebar = () => {
    const abierto = sidebar.classList.toggle('show');
    if (overlay) overlay.classList.toggle('show');
    if (btnToggle) btnToggle.setAttribute('aria-expanded', String(abierto));
  };
  if (btnToggle && sidebar) {
    btnToggle.addEventListener('click', alternarSidebar);
  }
  if (overlay && sidebar) {
    overlay.addEventListener('click', alternarSidebar);
  }

  // Cerrar sesión con modal de confirmación propio (en vez de ir directo al enlace)
  const btnCerrarSesion = document.getElementById('btnCerrarSesion');
  if (btnCerrarSesion) {
    btnCerrarSesion.addEventListener('click', async (evento) => {
      evento.preventDefault();
      const confirmado = await confirmarAccion({
        titulo: 'Cerrar sesión',
        mensaje: '¿Seguro que deseas salir de la plataforma Musiteca?',
        tipo: 'info',
        textoConfirmar: 'Cerrar sesión',
        textoCancelar: 'Cancelar',
      });
      if (confirmado) window.location.href = btnCerrarSesion.dataset.href || 'logout.php';
    });
  }

  // Cambio de pestañas Inventario / Control
  const tabInventario = document.getElementById('tabInventarioBtn');
  const tabControl = document.getElementById('tabControlBtn');
  const panelInventario = document.getElementById('panelInventario');
  const panelControl = document.getElementById('panelControl');

  if (tabInventario && tabControl) {
    tabInventario.addEventListener('click', () => {
      tabInventario.classList.add('active');
      tabControl.classList.remove('active');
      panelInventario.classList.remove('d-none');
      panelControl.classList.add('d-none');
      history.replaceState(null, '', 'index.php?vista=inventario');
    });

    tabControl.addEventListener('click', () => {
      tabControl.classList.add('active');
      tabInventario.classList.remove('active');
      panelControl.classList.remove('d-none');
      panelInventario.classList.add('d-none');
      history.replaceState(null, '', 'index.php?vista=control');
      if (window.cargarControl) window.cargarControl(1);
    });
  }
});
