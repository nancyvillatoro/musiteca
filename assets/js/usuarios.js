/**
 * Módulo de Gestión de usuarios: listado paginado, búsqueda, filtros por
 * rol/estado, alta/edición mediante modal y baja lógica (activar/desactivar).
 */

(function () {
  const tabla       = document.getElementById('tablaUsuariosBody');
  const paginacion  = document.getElementById('paginacionUsuarios');
  const inputBuscar = document.getElementById('buscarUsuarios');
  const selectRol   = document.getElementById('filtroRolUsuarios');
  const selectEstado = document.getElementById('filtroEstadoUsuarios');
  const btnLimpiar  = document.getElementById('btnLimpiarUsuarios');
  const modalUsuarioEl = document.getElementById('modalUsuario');
  const modalUsuario   = modalUsuarioEl ? new bootstrap.Modal(modalUsuarioEl) : null;
  const formUsuario    = document.getElementById('formUsuario');
  const campoPassword  = document.getElementById('usuarioPassword');
  const indicadorObligatoria = document.getElementById('passwordObligatoriaIndicador');
  const ayudaPassword  = document.getElementById('passwordAyuda');

  if (!tabla) return;

  let paginaActual = 1;
  let temporizadorBusqueda = null;

  // validarFormulario/limpiarValidacion base viven en main.js. Aquí solo se
  // agrega la regla propia de este módulo (contraseña obligatoria solo al
  // crear un usuario nuevo), reutilizando la validación compartida primero.
  function validarFormularioUsuario(form) {
    let esValido = validarFormulario(form, 'Revisa los campos marcados en rojo antes de guardar.');

    const esNuevo = document.getElementById('usuarioId').value === '';
    const passwordInvalida = (esNuevo && campoPassword.value.length < 8)
      || (!esNuevo && campoPassword.value !== '' && campoPassword.value.length < 8);

    if (passwordInvalida) {
      campoPassword.classList.add('is-invalid');
      campoPassword.setAttribute('aria-invalid', 'true');
      if (esValido) campoPassword.focus();
      esValido = false;
    }

    return esValido;
  }

  limpiarValidacionAlEscribir(formUsuario);

  const btnTogglePasswordUsuario = document.getElementById('btnTogglePasswordUsuario');
  btnTogglePasswordUsuario.addEventListener('click', () => {
    const esTexto = campoPassword.type === 'text';
    campoPassword.type = esTexto ? 'password' : 'text';
    btnTogglePasswordUsuario.innerHTML = esTexto ? '<i class="bi bi-eye" aria-hidden="true"></i>' : '<i class="bi bi-eye-slash" aria-hidden="true"></i>';
    btnTogglePasswordUsuario.setAttribute('aria-pressed', String(!esTexto));
    btnTogglePasswordUsuario.setAttribute('aria-label', esTexto ? 'Mostrar contraseña' : 'Ocultar contraseña');
  });

  const badgeRol = (rol) => {
    const etiquetas = { administrador: 'Administrador', operativo: 'Operativo' };
    return `<span class="badge-estado badge-${rol === 'administrador' ? 'malo' : 'disponible'}">${etiquetas[rol] || rol}</span>`;
  };

  const badgeEstadoUsuario = (activo) => activo
    ? '<span class="badge-estado badge-disponible">Activo</span>'
    : '<span class="badge-estado badge-inservible">Inactivo</span>';

  const formatearFecha = (valor) => {
    if (!valor) return '<span class="text-muted">Nunca</span>';
    const fecha = new Date(valor.replace(' ', 'T'));
    if (Number.isNaN(fecha.getTime())) return sanearHTML(valor);
    return fecha.toLocaleString('es-MX', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  };

  async function cargarUsuarios(pagina = 1) {
    paginaActual = pagina;
    const params = new URLSearchParams({
      accion: 'listar',
      buscar: inputBuscar.value.trim(),
      rol: selectRol.value,
      estado: selectEstado.value,
      pagina: pagina,
    });

    tabla.innerHTML = `<tr><td colspan="6"><div class="estado-lista"><span class="spinner-border spinner-border-sm"></span> Cargando usuarios…</div></td></tr>`;

    try {
      const resp = await fetch(`api/usuarios.php?${params.toString()}`);
      if (manejarSesionExpirada(resp)) return;
      const data = await resp.json();
      if (!data.ok) throw new Error(data.error || 'Error al consultar los usuarios.');

      if (data.datos.length === 0) {
        tabla.innerHTML = `<tr><td colspan="6"><div class="estado-lista"><i class="bi bi-people"></i> Sin resultados para los criterios seleccionados.</div></td></tr>`;
      } else {
        tabla.innerHTML = data.datos.map((row) => `
          <tr>
            <td class="fw-semibold">${sanearHTML(row.usuario)}</td>
            <td>${sanearHTML(row.nombre_completo)}</td>
            <td>${badgeRol(row.rol)}</td>
            <td>${formatearFecha(row.ultimo_acceso)}</td>
            <td>${badgeEstadoUsuario(Number(row.activo) === 1)}</td>
            <td class="text-end col-acciones">
              <div class="acciones-grupo">
                <button class="btn btn-sm btn-icono-tabla btn-outline-secondary btn-editar-usuario" data-id="${row.id}" title="Editar" aria-label="Editar usuario ${sanearHTML(row.usuario)}">
                  <i class="bi bi-pencil"></i>
                </button>
                ${Number(row.activo) === 1
                  ? `<button class="btn btn-sm btn-icono-tabla btn-outline-danger btn-desactivar-usuario" data-id="${row.id}" data-usuario="${sanearHTML(row.usuario)}" title="Desactivar" aria-label="Desactivar usuario ${sanearHTML(row.usuario)}"><i class="bi bi-person-dash"></i></button>`
                  : `<button class="btn btn-sm btn-icono-tabla btn-outline-success btn-activar-usuario" data-id="${row.id}" data-usuario="${sanearHTML(row.usuario)}" title="Activar" aria-label="Activar usuario ${sanearHTML(row.usuario)}"><i class="bi bi-person-check"></i></button>`
                }
              </div>
            </td>
          </tr>
        `).join('');
      }

      renderPaginacion(data.pagina, data.totalPaginas);
      actualizarResumenListado('resumenUsuarios', data.pagina, data.porPagina, data.total);
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
      li.querySelector('button').addEventListener('click', () => cargarUsuarios(i));
      paginacion.appendChild(li);
    }
  }

  inputBuscar.addEventListener('input', () => {
    clearTimeout(temporizadorBusqueda);
    temporizadorBusqueda = setTimeout(() => cargarUsuarios(1), 350);
  });
  selectRol.addEventListener('change', () => cargarUsuarios(1));
  selectEstado.addEventListener('change', () => cargarUsuarios(1));
  btnLimpiar.addEventListener('click', () => {
    inputBuscar.value = '';
    selectRol.value = '';
    selectEstado.value = '';
    cargarUsuarios(1);
  });

  // ---------- Alta / edición ----------
  modalUsuarioEl.addEventListener('show.bs.modal', (evento) => {
    const boton = evento.relatedTarget;
    const esEdicion = boton && boton.classList.contains('btn-editar-usuario');
    document.getElementById('tituloModalUsuario').innerHTML = esEdicion
      ? '<i class="bi bi-pencil"></i> Editar usuario'
      : '<i class="bi bi-person-plus"></i> Nuevo usuario';
    indicadorObligatoria.classList.toggle('d-none', esEdicion);
    ayudaPassword.textContent = esEdicion
      ? 'Déjala en blanco para conservar la contraseña actual.'
      : 'Mínimo 8 caracteres.';
    limpiarValidacion(formUsuario);
  });

  const guardUsuario = protegerFormularioConCambios(formUsuario, modalUsuarioEl);

  modalUsuarioEl.addEventListener('hidden.bs.modal', () => {
    formUsuario.reset();
    document.getElementById('usuarioId').value = '';
  });

  formUsuario.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    if (!validarFormularioUsuario(formUsuario)) return;

    const botonGuardar = document.getElementById('btnGuardarUsuario');
    botonGuardar.disabled = true;
    botonGuardar.innerHTML = '<span class="spinner-border spinner-border-sm-inline spinner-border-sm"></span> Guardando…';

    const formData = new FormData(formUsuario);
    formData.append('accion', 'guardar');

    try {
      const resp = await fetch('api/usuarios.php', { method: 'POST', body: formData });
      if (manejarSesionExpirada(resp)) return;
      const data = await resp.json();

      mostrarToast(data.mensaje || data.error, data.ok ? 'success' : 'danger');
      if (data.ok) {
        guardUsuario.marcarComoGuardado();
        modalUsuario.hide();
        cargarUsuarios(paginaActual);
      }
    } finally {
      botonGuardar.disabled = false;
      botonGuardar.innerHTML = '<i class="bi bi-save"></i> Guardar usuario';
    }
  });

  document.addEventListener('click', async (evento) => {
    const botonEditar = evento.target.closest('.btn-editar-usuario');
    const botonDesactivar = evento.target.closest('.btn-desactivar-usuario');
    const botonActivar = evento.target.closest('.btn-activar-usuario');

    if (botonEditar) {
      const id = botonEditar.dataset.id;
      const resp = await fetch(`api/usuarios.php?accion=detalle&id=${id}`);
      const data = await resp.json();
      if (!data.ok) return mostrarToast(data.error, 'danger');

      const u = data.usuario;
      document.getElementById('usuarioId').value = u.id;
      document.getElementById('usuarioUsuario').value = u.usuario;
      document.getElementById('usuarioNombre').value = u.nombre_completo;
      document.getElementById('usuarioCorreo').value = u.correo || '';
      document.getElementById('usuarioRol').value = u.rol;
      campoPassword.value = '';

      guardUsuario.marcarComoGuardado();
      modalUsuario.show();
    }

    if (botonDesactivar) {
      const id = botonDesactivar.dataset.id;
      const nombre = botonDesactivar.dataset.usuario;
      const confirmado = await confirmarAccion({
        titulo: 'Desactivar usuario',
        mensaje: `El usuario "${nombre}" no podrá iniciar sesión hasta que sea reactivado. ¿Deseas continuar?`,
        tipo: 'danger',
        textoConfirmar: 'Desactivar',
      });
      if (!confirmado) return;

      const formData = new FormData();
      formData.append('accion', 'cambiar_estado');
      formData.append('id', id);
      formData.append('activo', '0');

      const resp = await fetch('api/usuarios.php', { method: 'POST', body: formData });
      const data = await resp.json();
      mostrarToast(data.mensaje || data.error, data.ok ? 'success' : 'danger');
      if (data.ok) cargarUsuarios(paginaActual);
    }

    if (botonActivar) {
      const id = botonActivar.dataset.id;
      const formData = new FormData();
      formData.append('accion', 'cambiar_estado');
      formData.append('id', id);
      formData.append('activo', '1');

      const resp = await fetch('api/usuarios.php', { method: 'POST', body: formData });
      const data = await resp.json();
      mostrarToast(data.mensaje || data.error, data.ok ? 'success' : 'danger');
      if (data.ok) cargarUsuarios(paginaActual);
    }
  });

  cargarUsuarios(1);
})();
