<?php
require_once __DIR__ . '/includes/auth.php';
requerirSesion();

$tituloPagina  = 'Soporte técnico';
$seccionActiva = 'soporte';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-grow-1" id="contenidoPrincipal">
  <div class="topbar d-flex align-items-center gap-2 px-3 px-md-4 py-3">
    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="btnToggleSidebar" aria-label="Abrir menú de navegación" aria-controls="sidebarMenu" aria-expanded="false">
      <i class="bi bi-list" aria-hidden="true"></i>
    </button>
    <div>
      <h5 class="mb-0 fw-bold" style="color:var(--musiteca-guinda-oscuro);">Soporte técnico</h5>
      <div class="text-muted small">Reporte de anomalías del sistema · Área responsable: Desarrollo de Sistemas</div>
    </div>
  </div>

  <div class="p-3 p-md-4">
    <div class="row g-4">
      <!-- ===================== Formulario ===================== -->
      <div class="col-lg-5">
        <div class="card card-panel">
          <div class="card-body p-3 p-lg-4">
            <h6 class="fw-bold mb-3" style="color:var(--musiteca-guinda-oscuro);"><i class="bi bi-send"></i> Nuevo reporte</h6>

            <form id="formSoporte" novalidate enctype="multipart/form-data">
              <div class="mb-3">
                <label class="form-label small fw-semibold" for="soporteModulo">Módulo afectado *</label>
                <select class="form-select" name="modulo" id="soporteModulo" data-obligatorio="true" aria-required="true">
                  <option value="">Seleccione el módulo…</option>
                  <option value="plataforma_web">Plataforma Web</option>
                  <option value="aplicacion_operativa">Aplicación Operativa</option>
                </select>
                <div class="invalid-feedback">Selecciona el módulo afectado.</div>
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold" for="soporteNumInventario">N° de inventario relacionado (si aplica)</label>
                <input type="text" class="form-control" name="num_inventario" id="soporteNumInventario">
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold" for="soporteDescripcion">Descripción breve de la falla *</label>
                <textarea class="form-control" name="descripcion" id="soporteDescripcion" rows="4" data-obligatorio="true" aria-required="true"></textarea>
                <div class="invalid-feedback">Describe brevemente la falla detectada.</div>
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold" for="inputCaptura">Captura de pantalla del error *</label>
                <input type="file" class="form-control" name="captura" id="inputCaptura" accept="image/png,image/jpeg,image/gif,image/webp" data-obligatorio="true" aria-required="true">
                <div class="invalid-feedback">Adjunta una captura de pantalla del error.</div>
                <div class="form-text">Formatos: JPG, PNG, GIF o WEBP · Máximo 5 MB.</div>
                <img id="previsualizacionCaptura" class="img-fluid rounded mt-2 d-none border" alt="Vista previa de la captura de pantalla adjunta" style="max-height:160px;">
              </div>
              <button type="submit" class="btn btn-primary-musiteca w-100" id="btnEnviarReporte">
                <i class="bi bi-send"></i> Enviar reporte
              </button>
            </form>
          </div>
        </div>
      </div>

      <!-- ===================== Listado de reportes ===================== -->
      <div class="col-lg-7">
        <div class="card card-panel">
          <div class="card-body p-3 p-lg-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h6 class="fw-bold mb-0" style="color:var(--musiteca-guinda-oscuro);"><i class="bi bi-clock-history"></i> Reportes recientes</h6>
              <button class="btn btn-sm btn-outline-secondary" id="btnRefrescarReportes" aria-label="Actualizar lista de reportes"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></button>
            </div>
            <div class="table-responsive">
              <table class="table table-musiteca align-middle">
                <thead>
                  <tr>
                    <th scope="col">Fecha</th>
                    <th scope="col">Módulo</th>
                    <th scope="col">Descripción</th>
                    <th scope="col">Estado</th>
                    <th scope="col">Captura</th>
                  </tr>
                </thead>
                <tbody id="tablaReportesBody">
                  <tr><td colspan="5" class="text-center text-muted py-4">Cargando…</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer"></div>

<?php require __DIR__ . '/includes/footer.php'; ?>
<script src="assets/js/soporte.js"></script>
