/**
 * App operativa (mobile-first): búsqueda de activos, escaneo asistido de
 * etiquetas, solicitud individual/masiva, reporte de incidencias y alta
 * de nuevos instrumentos. Construida con JavaScript vanilla + Bootstrap 5.
 */

(function () {
  // ---------------------------------------------------------------
  // Validación personalizada
  // ---------------------------------------------------------------
  // validarFormulario/limpiarValidacion viven en assets/js/main.js
  // (cargado antes que app.js en app/index.php) y se comparten con el
  // resto de módulos del sistema.
  document.querySelectorAll('#formNuevoApp, #formSolicitudMasiva, #formReportarApp').forEach(limpiarValidacionAlEscribir);

  // Protege los formularios de la app contra cierre accidental con cambios sin guardar
  let guardSolicitudMasiva = { marcarComoGuardado() {} };
  let guardReportarApp = { marcarComoGuardado() {} };
  if (window.protegerFormularioConCambios) {
    guardSolicitudMasiva = protegerFormularioConCambios(document.getElementById('formSolicitudMasiva'), document.getElementById('modalSolicitudMasiva'));
    guardReportarApp = protegerFormularioConCambios(document.getElementById('formReportarApp'), document.getElementById('modalReportarApp'));
  }

  // "Fui yo": autocompleta "Reportado por" con el nombre de quien tiene la
  // sesión abierta (mismo criterio que en el panel de escritorio, ver
  // assets/js/control.js). Sigue siendo texto libre para cuando el reporte
  // viene de alguien sin cuenta en el sistema.
  const btnReportarPorFuiYoApp = document.getElementById('btnReportarPorFuiYoApp');
  const campoReportarPorApp = document.getElementById('appReportarPor');
  if (btnReportarPorFuiYoApp && campoReportarPorApp) {
    btnReportarPorFuiYoApp.addEventListener('click', () => {
      campoReportarPorApp.value = window.USUARIO_NOMBRE || '';
      campoReportarPorApp.classList.remove('is-invalid');
      campoReportarPorApp.removeAttribute('aria-invalid');
      campoReportarPorApp.focus();
    });
  }

  // ---------------------------------------------------------------
  // Navegación entre secciones (con aviso si hay datos sin guardar
  // en el formulario de "Registrar instrumento")
  // ---------------------------------------------------------------
  // El panel "Registrar instrumento" solo existe en el DOM para el rol
  // administrador (ver app/index.php). El resto de la app (buscar,
  // escanear, solicitudes, reportes) debe funcionar igual para el rol
  // operativo aunque este panel no esté presente.
  let nuevoInstrumentoSucio = false;
  const formNuevoAppEl = document.getElementById('formNuevoApp');
  if (formNuevoAppEl) {
    formNuevoAppEl.addEventListener('input', () => { nuevoInstrumentoSucio = true; });
    formNuevoAppEl.addEventListener('change', () => { nuevoInstrumentoSucio = true; });
  }
  const botonesNav = document.querySelectorAll('#appNav .nav-link');
  const paneles = document.querySelectorAll('.app-panel');

  botonesNav.forEach((boton) => {
    boton.addEventListener('click', async (evento) => {
      const panelNuevoEl = document.getElementById('panelNuevo');
      const vieneDesdeNuevo = !!panelNuevoEl && !panelNuevoEl.classList.contains('d-none');
      const vaHaciaOtraSeccion = boton.dataset.panel !== 'panelNuevo';

      if (vieneDesdeNuevo && vaHaciaOtraSeccion && nuevoInstrumentoSucio) {
        const confirmado = await confirmarAccion({
          titulo: 'Cambios sin guardar',
          mensaje: 'Tienes datos capturados en "Registrar instrumento" que no se han guardado. ¿Deseas salir sin guardarlos?',
          tipo: 'warning',
          textoConfirmar: 'Salir sin guardar',
          textoCancelar: 'Seguir aquí',
        });
        if (!confirmado) return;
        nuevoInstrumentoSucio = false;
      }

      botonesNav.forEach((b) => b.classList.remove('active'));
      boton.classList.add('active');
      paneles.forEach((p) => p.classList.add('d-none'));
      document.getElementById(boton.dataset.panel).classList.remove('d-none');
    });
  });

  // ---------------------------------------------------------------
  // Buscador manual de activos
  // ---------------------------------------------------------------
  const appBuscarInput = document.getElementById('appBuscarInput');
  const appBtnBuscar = document.getElementById('appBtnBuscar');
  const appBtnLimpiar = document.getElementById('appBtnLimpiar');
  const appResultados = document.getElementById('appResultados');

  function tarjetaInstrumento(item) {
    const etiquetasEstado = { disponible: 'Disponible', en_uso: 'Actualmente asignado', en_reparacion: 'En mantenimiento' };
    return `
      <div class="card mb-2 shadow-sm rounded-3">
        <div class="card-body py-2 px-3 d-flex justify-content-between align-items-center">
          <div>
            <div class="fw-semibold">${sanearHTML(item.nombre)}</div>
            <div class="small text-muted">N° ${sanearHTML(item.num_inventario)}</div>
            <span class="badge-estado badge-${sanearHTML(item.estado)}">${sanearHTML(etiquetasEstado[item.estado] || item.estado)}</span>
          </div>
          <button class="btn btn-sm btn-oro-musiteca btn-abrir-acciones-app"
                  data-id="${sanearHTML(item.id)}" data-nombre="${sanearHTML(item.nombre)}" data-inv="${sanearHTML(item.num_inventario)}"
                  data-ubicacion="${sanearHTML(item.ubicacion || '')}" data-condicion="${sanearHTML(item.condicion || '')}" data-estado="${sanearHTML(item.estado)}">
            Ver
          </button>
        </div>
      </div>`;
  }

  async function buscarActivos() {
    const termino = appBuscarInput.value.trim();
    appResultados.innerHTML = `<div class="estado-lista"><span class="spinner-border spinner-border-sm"></span> Buscando…</div>`;

    const params = new URLSearchParams({ accion: 'listar', buscar: termino, pagina: 1 });
    const resp = await fetch(`../api/instrumentos.php?${params.toString()}`);
    const data = await resp.json();

    if (!data.ok || data.datos.length === 0) {
      appResultados.innerHTML = `<div class="estado-lista"><i class="bi bi-inbox"></i> Sin resultados.</div>`;
      return;
    }

    appResultados.innerHTML = data.datos.map(tarjetaInstrumento).join('');
  }

  appBtnBuscar.addEventListener('click', buscarActivos);
  appBuscarInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') buscarActivos(); });
  appBtnLimpiar.addEventListener('click', () => {
    appBuscarInput.value = '';
    appResultados.innerHTML = '';
  });

  // ---------------------------------------------------------------
  // Escaneo de etiquetado asistido por cámara
  // Nota: se intenta primero una lectura automática del código mediante la
  // API nativa del navegador BarcodeDetector (Shape Detection API, sin
  // añadir librerías externas de terceros). Si el navegador no la soporta
  // (por ejemplo Safari o Firefox de escritorio) o no logra leer la
  // etiqueta, el flujo cae de forma transparente en la confirmación manual
  // que ya existía: el usuario nunca se queda sin poder continuar.
  // Este mismo punto de entrada (intentarLecturaAutomatica) queda listo
  // para integrarse en el futuro con un lector más robusto (por ejemplo,
  // una librería de OCR) sin tener que rediseñar el flujo de la app.
  // ---------------------------------------------------------------
  const btnActivarCamara = document.getElementById('btnActivarCamara');
  const btnCapturar = document.getElementById('btnCapturar');
  const video = document.getElementById('videoEscaner');
  const canvas = document.getElementById('canvasEscaner');
  const mensajeCamara = document.getElementById('mensajeCamara');
  const fotoCapturada = document.getElementById('fotoCapturada');
  const bloqueConfirmarCodigo = document.getElementById('bloqueConfirmarCodigo');
  const codigoConfirmado = document.getElementById('codigoConfirmado');
  const btnUsarCodigo = document.getElementById('btnUsarCodigo');
  const btnNuevaCaptura = document.getElementById('btnNuevaCaptura');
  const appResultadosEscaneo = document.getElementById('appResultadosEscaneo');
  const indicadorDeteccion = document.getElementById('indicadorDeteccionAutomatica');

  let streamActivo = null;
  let detectorCodigos = null;
  let intervaloDeteccion = null;
  const soportaDeteccionAutomatica = 'BarcodeDetector' in window;

  if (soportaDeteccionAutomatica) {
    try {
      detectorCodigos = new window.BarcodeDetector({
        formats: ['code_128', 'code_39', 'code_93', 'codabar', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'qr_code'],
      });
    } catch (err) {
      detectorCodigos = null;
    }
  }

  function detenerDeteccionAutomatica() {
    if (intervaloDeteccion) {
      clearInterval(intervaloDeteccion);
      intervaloDeteccion = null;
    }
  }

  // Explora continuamente el video en vivo (sin necesidad de que el
  // usuario tome la foto primero) buscando un código legible. Al
  // encontrarlo, captura automáticamente el cuadro actual y confirma el
  // código detectado, ahorrándole al usuario escribirlo a mano.
  function iniciarDeteccionAutomatica() {
    if (!detectorCodigos) return;

    intervaloDeteccion = setInterval(async () => {
      if (video.readyState < 2) return; // aún no hay cuadro disponible
      try {
        const codigos = await detectorCodigos.detect(video);
        if (codigos && codigos.length > 0) {
          const valor = (codigos[0].rawValue || '').trim();
          if (valor) {
            detenerDeteccionAutomatica();
            capturarCuadroActual({ codigoDetectado: valor });
          }
        }
      } catch (err) {
        // Fallo puntual de un cuadro (p. ej. imagen borrosa): se ignora y
        // se sigue intentando con el siguiente cuadro del video.
      }
    }, 400);
  }

  function capturarCuadroActual({ codigoDetectado = '' } = {}) {
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);

    fotoCapturada.src = canvas.toDataURL('image/png');
    fotoCapturada.classList.remove('d-none');
    video.style.display = 'none';

    detenerDeteccionAutomatica();
    if (streamActivo) streamActivo.getTracks().forEach((t) => t.stop());
    btnCapturar.classList.add('d-none');
    btnActivarCamara.classList.remove('d-none');
    btnActivarCamara.innerHTML = '<i class="bi bi-camera"></i> Reactivar cámara';

    bloqueConfirmarCodigo.classList.remove('d-none');

    if (codigoDetectado) {
      codigoConfirmado.value = codigoDetectado;
      if (indicadorDeteccion) {
        indicadorDeteccion.classList.remove('d-none');
        indicadorDeteccion.innerHTML = '<i class="bi bi-check-circle-fill"></i> Código detectado automáticamente. Verifícalo y confirma.';
      }
      mostrarToast('Código detectado automáticamente en la etiqueta.', 'success');
      btnUsarCodigo.focus();
    } else {
      codigoConfirmado.value = '';
      if (indicadorDeteccion) indicadorDeteccion.classList.add('d-none');
      codigoConfirmado.focus();
    }
  }

  btnActivarCamara.addEventListener('click', async () => {
    try {
      streamActivo = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
      video.srcObject = streamActivo;
      video.style.display = 'block';
      mensajeCamara.classList.add('d-none');
      video.play();
      btnActivarCamara.classList.add('d-none');
      btnCapturar.classList.remove('d-none');

      if (soportaDeteccionAutomatica && detectorCodigos) {
        iniciarDeteccionAutomatica();
      }
    } catch (err) {
      mostrarToast('No fue posible acceder a la cámara. Verifica los permisos del navegador.', 'danger');
    }
  });

  // Captura manual: el usuario decide el momento (funciona igual si la
  // detección automática no está disponible o no encontró el código aún).
  btnCapturar.addEventListener('click', () => {
    capturarCuadroActual();
  });

  btnNuevaCaptura.addEventListener('click', () => {
    fotoCapturada.classList.add('d-none');
    bloqueConfirmarCodigo.classList.add('d-none');
    appResultadosEscaneo.innerHTML = '';
    if (indicadorDeteccion) indicadorDeteccion.classList.add('d-none');
    mensajeCamara.classList.remove('d-none');
  });

  btnUsarCodigo.addEventListener('click', async () => {
    const codigo = codigoConfirmado.value.trim();
    if (!codigo) return mostrarToast('Ingresa o confirma el código detectado.', 'warning');

    // Acepta código completo o solo los últimos 4 dígitos
    const soloDigitos = codigo.replace(/\D/g, '').slice(-4);
    const params = new URLSearchParams({ accion: 'buscar_codigo', codigo: codigo.length <= 4 ? soloDigitos : codigo });

    const resp = await fetch(`../api/instrumentos.php?${params.toString()}`);
    const data = await resp.json();

    if (!data.ok || data.resultados.length === 0) {
      appResultadosEscaneo.innerHTML = `<div class="alert alert-warning small">No se encontró ningún instrumento con ese código.</div>`;
      return;
    }

    appResultadosEscaneo.innerHTML = `<div class="fw-semibold small mb-2">Coincidencias encontradas:</div>` +
      data.resultados.map(tarjetaInstrumento).join('');
  });

  // ---------------------------------------------------------------
  // Modal de acciones sobre un instrumento (Solicitar / Reportar / Agregar a solicitud)
  // ---------------------------------------------------------------
  const modalAcciones = new bootstrap.Modal(document.getElementById('modalAppAcciones'));
  const appAccionesDetalle = document.getElementById('appAccionesDetalle');
  const appAccionesTitulo = document.getElementById('appAccionesTitulo');
  let instrumentoSeleccionado = null;
  let listaSolicitud = [];

  document.addEventListener('click', (evento) => {
    const boton = evento.target.closest('.btn-abrir-acciones-app');
    if (!boton) return;

    instrumentoSeleccionado = {
      id: boton.dataset.id,
      nombre: boton.dataset.nombre,
      num_inventario: boton.dataset.inv,
      ubicacion: boton.dataset.ubicacion,
      condicion: boton.dataset.condicion,
      estado: boton.dataset.estado,
    };

    appAccionesTitulo.textContent = instrumentoSeleccionado.nombre;
    appAccionesDetalle.innerHTML = `
      <dt class="col-4">Código</dt><dd class="col-8">${sanearHTML(instrumentoSeleccionado.num_inventario)}</dd>
      <dt class="col-4">Ubicación</dt><dd class="col-8">${sanearHTML(instrumentoSeleccionado.ubicacion || 'N/A')}</dd>
      <dt class="col-4">Condición</dt><dd class="col-8">${sanearHTML(instrumentoSeleccionado.condicion || 'N/A')}</dd>
      <dt class="col-4">Estado</dt><dd class="col-8">${sanearHTML(instrumentoSeleccionado.estado)}</dd>
    `;
    modalAcciones.show();
  });

  document.getElementById('appBtnAgregarSolicitud').addEventListener('click', () => {
    if (!instrumentoSeleccionado) return;
    if (listaSolicitud.some((it) => it.id === instrumentoSeleccionado.id)) {
      mostrarToast('Ese instrumento ya está en la solicitud.', 'warning');
      return;
    }
    listaSolicitud.push(instrumentoSeleccionado);
    mostrarToast(`${sanearHTML(instrumentoSeleccionado.nombre)} agregado a la solicitud (${listaSolicitud.length}).`);
    modalAcciones.hide();
  });

  document.getElementById('appBtnSolicitar').addEventListener('click', () => {
    if (!instrumentoSeleccionado) return;
    listaSolicitud = [instrumentoSeleccionado];
    modalAcciones.hide();
    abrirModalSolicitudMasiva();
  });

  document.getElementById('appBtnReportar').addEventListener('click', () => {
    if (!instrumentoSeleccionado) return;
    document.getElementById('formReportarApp').reset();
    limpiarValidacion(document.getElementById('formReportarApp'));
    document.getElementById('reportarAppId').value = instrumentoSeleccionado.id;
    modalAcciones.hide();
    new bootstrap.Modal(document.getElementById('modalReportarApp')).show();
  });

  // ---------------------------------------------------------------
  // Solicitud masiva
  // ---------------------------------------------------------------
  const modalSolicitudMasiva = new bootstrap.Modal(document.getElementById('modalSolicitudMasiva'));
  const listaSolicitudMasivaEl = document.getElementById('listaSolicitudMasiva');
  const contadorSolicitud = document.getElementById('contadorSolicitud');
  const formSolicitudMasiva = document.getElementById('formSolicitudMasiva');

  function abrirModalSolicitudMasiva() {
    if (listaSolicitud.length === 0) {
      mostrarToast('Agrega al menos un instrumento a la solicitud.', 'warning');
      return;
    }
    limpiarValidacion(formSolicitudMasiva);
    contadorSolicitud.textContent = listaSolicitud.length;
    listaSolicitudMasivaEl.innerHTML = listaSolicitud
      .map((it) => `<li class="list-group-item d-flex justify-content-between"><span>${sanearHTML(it.nombre)}</span><span class="text-muted">${sanearHTML(it.num_inventario)}</span></li>`)
      .join('');
    modalSolicitudMasiva.show();
  }

  // Botón flotante alterno: si el usuario quiere revisar/enviar la solicitud acumulada
  appResultados.addEventListener('dblclick', abrirModalSolicitudMasiva);

  formSolicitudMasiva.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    if (!validarFormulario(formSolicitudMasiva)) return;

    const boton = formSolicitudMasiva.querySelector('button[type="submit"]');
    if (boton) {
      boton.disabled = true;
      var textoOriginal = boton.innerHTML;
      boton.innerHTML = '<span class="spinner-border spinner-border-sm-inline spinner-border-sm"></span> Guardando…';
    }

    try {
      // Un solo préstamo con todos los instrumentos del carrito: se registra
      // en una única operación atómica en el servidor (o se guardan todos, o
      // no se guarda ninguno), en vez del bucle de solicitudes individuales
      // que se usaba antes. Evita dejar el catálogo en un estado a medias si
      // algún instrumento deja de estar disponible justo antes de confirmar.
      const formData = new FormData(formSolicitudMasiva);
      formData.append('accion', 'crear_prestamo');
      listaSolicitud.forEach((item) => formData.append('instrumento_ids[]', item.id));

      const resp = await fetch('../api/solicitudes.php', { method: 'POST', body: formData });
      const data = await resp.json();
      mostrarToast(data.mensaje || data.error, data.ok ? 'success' : 'danger');

      if (data.ok) {
        listaSolicitud = [];
        guardSolicitudMasiva.marcarComoGuardado();
        modalSolicitudMasiva.hide();
        formSolicitudMasiva.reset();
      }
    } finally {
      if (boton) {
        boton.disabled = false;
        boton.innerHTML = textoOriginal;
      }
    }
  });

  // ---------------------------------------------------------------
  // Reporte de incidencias desde la app
  // ---------------------------------------------------------------
  document.getElementById('formReportarApp').addEventListener('submit', async (evento) => {
    evento.preventDefault();
    if (!validarFormulario(evento.target)) return;

    const formData = new FormData(evento.target);
    formData.append('accion', 'crear_incidencia');

    const resp = await fetch('../api/solicitudes.php', { method: 'POST', body: formData });
    const data = await resp.json();
    mostrarToast(data.mensaje || data.error, data.ok ? 'success' : 'danger');

    if (data.ok) {
      guardReportarApp.marcarComoGuardado();
      bootstrap.Modal.getInstance(document.getElementById('modalReportarApp')).hide();
      evento.target.reset();
    }
  });

  // ---------------------------------------------------------------
  // Alta de nuevos instrumentos desde la app (solo administrador)
  // ---------------------------------------------------------------
  const btnLimpiarInstrumentoApp = document.getElementById('btnLimpiarInstrumentoApp');
  if (btnLimpiarInstrumentoApp && formNuevoAppEl) {
    btnLimpiarInstrumentoApp.addEventListener('click', () => {
      setTimeout(() => {
        limpiarValidacion(formNuevoAppEl);
        nuevoInstrumentoSucio = false;
      }, 0);
    });
  }

  if (formNuevoAppEl) {
    formNuevoAppEl.addEventListener('submit', async (evento) => {
      evento.preventDefault();
      if (!validarFormulario(evento.target)) return;

      const boton = document.getElementById('btnGuardarInstrumentoApp');
      if (boton) {
        boton.disabled = true;
        var textoOriginal = boton.innerHTML;
        boton.innerHTML = '<span class="spinner-border spinner-border-sm-inline spinner-border-sm"></span> Guardando…';
      }

      try {
        const formData = new FormData(evento.target);
        formData.append('accion', 'guardar');

        const resp = await fetch('../api/instrumentos.php', { method: 'POST', body: formData });
        const data = await resp.json();
        mostrarToast(data.mensaje || data.error, data.ok ? 'success' : 'danger');

        if (data.ok) {
          evento.target.reset();
          limpiarValidacion(evento.target);
          nuevoInstrumentoSucio = false;
        }
      } finally {
        if (boton) {
          boton.disabled = false;
          boton.innerHTML = textoOriginal;
        }
      }
    });
  }
})();
