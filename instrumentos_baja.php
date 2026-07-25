<?php
require_once __DIR__ . '/includes/auth.php';
requerirAdministrador(); // módulo exclusivo del rol administrador, igual que la propia baja

$tituloPagina  = 'Instrumentos dados de baja';
$seccionActiva = 'instrumentos_baja';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-grow-1" id="contenidoPrincipal">
  <div class="topbar d-flex align-items-center px-3 px-md-4 py-3">
    <button class="btn btn-sm btn-outline-secondary d-lg-none me-2" id="btnToggleSidebar" aria-label="Abrir menú de navegación" aria-controls="sidebarMenu" aria-expanded="false">
      <i class="bi bi-list" aria-hidden="true"></i>
    </button>
    <div>
      <h5 class="mb-0 fw-bold" style="color:var(--musiteca-guinda-oscuro);">Instrumentos dados de baja</h5>
      <div class="text-muted small">Consulta y restauración de instrumentos retirados del catálogo activo</div>
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
              <input type="text" id="buscarBaja" class="form-control" placeholder="Buscar por número de inventario, nombre o motivo de baja…">
            </div>
          </div>
          <div class="filtros-controles">
            <button class="btn btn-outline-secondary" id="btnLimpiarBaja" title="Quitar búsqueda"><i class="bi bi-eraser"></i> Limpiar</button>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-musiteca align-middle">
            <thead>
              <tr>
                <th scope="col">N° Inventario</th>
                <th scope="col">Instrumento</th>
                <th scope="col">Última ubicación</th>
                <th scope="col">Condición</th>
                <th scope="col">Fecha de baja</th>
                <th scope="col">Motivo</th>
                <th scope="col" class="text-end col-acciones">Acciones</th>
              </tr>
            </thead>
            <tbody id="tablaBajaBody">
              <tr><td colspan="7" class="text-center text-muted py-4">Cargando…</td></tr>
            </tbody>
          </table>
        </div>

        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
          <div class="text-muted small" id="resumenBaja" aria-live="polite"></div>
          <nav aria-label="Paginación de instrumentos dados de baja">
            <ul class="pagination justify-content-end mb-0" id="paginacionBaja"></ul>
          </nav>
        </div>
      </div>
    </div>
  </div>
</main>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer"></div>

<?php require __DIR__ . '/includes/footer.php'; ?>
<script src="assets/js/instrumentos_baja.js"></script>
