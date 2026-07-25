<?php
require_once __DIR__ . '/includes/auth.php';
requerirAdministrador(); // módulo exclusivo del rol administrador

$tituloPagina  = 'Gestión de usuarios';
$seccionActiva = 'usuarios';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-grow-1" id="contenidoPrincipal">
  <div class="topbar d-flex align-items-center justify-content-between px-3 px-md-4 py-3">
    <div class="d-flex align-items-center gap-2">
      <button class="btn btn-sm btn-outline-secondary d-lg-none" id="btnToggleSidebar" aria-label="Abrir menú de navegación" aria-controls="sidebarMenu" aria-expanded="false">
        <i class="bi bi-list" aria-hidden="true"></i>
      </button>
      <div>
        <h5 class="mb-0 fw-bold" style="color:var(--musiteca-guinda-oscuro);">Gestión de usuarios</h5>
        <div class="text-muted small">Alta, edición y control de acceso del personal administrador y operativo</div>
      </div>
    </div>
    <div>
      <button class="btn btn-primary-musiteca" data-bs-toggle="modal" data-bs-target="#modalUsuario">
        <i class="bi bi-person-plus"></i> Nuevo usuario
      </button>
    </div>
  </div>

  <div class="p-3 p-md-4">
    <div class="card card-panel">
      <div class="card-body p-3 p-lg-4">

        <!-- Filtros -->
        <div class="filtros-toolbar mb-3">
          <div class="filtros-buscador">
            <div class="input-group">
              <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
              <input type="text" class="form-control" id="buscarUsuarios" placeholder="Buscar por usuario, nombre o correo…" aria-label="Buscar usuarios">
            </div>
          </div>
          <div class="filtros-controles">
            <select class="form-select" id="filtroRolUsuarios" aria-label="Filtrar por rol">
              <option value="">Todos los roles</option>
              <option value="administrador">Administrador</option>
              <option value="operativo">Operativo</option>
            </select>
            <select class="form-select" id="filtroEstadoUsuarios" aria-label="Filtrar por estado">
              <option value="">Todos los estados</option>
              <option value="activo">Activos</option>
              <option value="inactivo">Inactivos</option>
            </select>
          </div>
          <div class="filtros-acciones">
            <button class="btn btn-outline-secondary" id="btnLimpiarUsuarios" title="Limpiar filtros">
              <i class="bi bi-x-lg"></i> Limpiar
            </button>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-musiteca align-middle">
            <thead>
              <tr>
                <th scope="col">Usuario</th>
                <th scope="col">Nombre completo</th>
                <th scope="col">Rol</th>
                <th scope="col">Último acceso</th>
                <th scope="col">Estado</th>
                <th scope="col" class="text-end col-acciones">Acciones</th>
              </tr>
            </thead>
            <tbody id="tablaUsuariosBody">
              <tr><td colspan="6" class="text-center text-muted py-4">Cargando…</td></tr>
            </tbody>
          </table>
        </div>

        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
          <div class="text-muted small" id="resumenUsuarios" aria-live="polite"></div>
          <nav aria-label="Paginación de usuarios">
            <ul class="pagination justify-content-end mb-0" id="paginacionUsuarios"></ul>
          </nav>
        </div>
      </div>
    </div>
  </div>
</main>

<!-- ============================================================
     MODAL: Alta / edición de usuario
     ============================================================ -->
<div class="modal fade" id="modalUsuario" tabindex="-1" aria-labelledby="tituloModalUsuario">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="formUsuario" novalidate>
        <div class="modal-header">
          <h5 class="modal-title" id="tituloModalUsuario"><i class="bi bi-person-plus"></i> Nuevo usuario</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="usuarioId">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold" for="usuarioUsuario">Usuario *</label>
              <input type="text" class="form-control" name="usuario" id="usuarioUsuario" data-obligatorio="true" autocomplete="off">
              <div class="invalid-feedback">Ingresa un nombre de usuario (mínimo 3 caracteres).</div>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold" for="usuarioNombre">Nombre completo *</label>
              <input type="text" class="form-control" name="nombre_completo" id="usuarioNombre" data-obligatorio="true">
              <div class="invalid-feedback">Ingresa el nombre completo.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold" for="usuarioCorreo">Correo electrónico</label>
              <input type="email" class="form-control" name="correo" id="usuarioCorreo" autocomplete="off">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold" for="usuarioRol">Rol *</label>
              <select class="form-select" name="rol" id="usuarioRol" data-obligatorio="true">
                <option value="">Seleccionar rol</option>
                <option value="operativo">Operativo</option>
                <option value="administrador">Administrador</option>
              </select>
              <div class="invalid-feedback">Selecciona el rol del usuario.</div>
            </div>
            <div class="col-md-12">
              <label class="form-label small fw-semibold" for="usuarioPassword">Contraseña <span id="passwordObligatoriaIndicador">*</span></label>
              <div class="input-group">
                <input type="password" class="form-control" name="password" id="usuarioPassword" autocomplete="new-password">
                <button type="button" class="btn btn-outline-secondary" id="btnTogglePasswordUsuario" tabindex="-1" aria-label="Mostrar contraseña" aria-pressed="false">
                  <i class="bi bi-eye" aria-hidden="true"></i>
                </button>
                <div class="invalid-feedback">La contraseña debe tener al menos 8 caracteres.</div>
              </div>
              <div class="form-text" id="passwordAyuda">Mínimo 8 caracteres.</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary-musiteca" id="btnGuardarUsuario"><i class="bi bi-save"></i> Guardar usuario</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer"></div>

<?php require __DIR__ . '/includes/footer.php'; ?>
<script src="assets/js/usuarios.js"></script>
