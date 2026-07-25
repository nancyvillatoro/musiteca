<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
requerirSesion();

$pdo = obtenerConexion();
$ubicaciones = $pdo->query('SELECT id, nombre FROM ubicaciones ORDER BY nombre')->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()) ?>">
  <title>Musiteca · App operativa</title>
  <link rel="icon" type="image/svg+xml" href="../assets/img/logo.svg">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="../assets/css/style.css" rel="stylesheet">
  <script>
    window.ES_ADMIN = <?= json_encode(esAdministrador()) ?>;
  </script>
</head>
<body class="bg-light">
<div class="app-shell shadow-sm">

  <!-- Encabezado -->
  <div class="app-header">
    <div class="d-flex justify-content-between align-items-center">
      <div class="d-flex align-items-center gap-2">
        <img src="../assets/img/logo.svg" alt="Musiteca" class="brand-logo">
        <div>
          <div class="fw-bold">Musiteca</div>
          <div class="small" style="opacity:.8;">Préstamo y control de inventario</div>
        </div>
      </div>
      <a href="../index.php" class="text-white" title="Ir al panel administrativo">
        <i class="bi bi-box-arrow-right fs-5"></i>
      </a>
    </div>
  </div>

  <!-- Navegación por secciones -->
  <ul class="nav nav-pills nav-justified p-2 gap-1" id="appNav">
    <li class="nav-item">
      <button class="nav-link active" data-panel="panelBuscar"><i class="bi bi-search"></i><br><span class="small">Buscar</span></button>
    </li>
    <li class="nav-item">
      <button class="nav-link" data-panel="panelEscanear"><i class="bi bi-upc-scan"></i><br><span class="small">Escanear</span></button>
    </li>
    <?php if (esAdministrador()): ?>
    <li class="nav-item">
      <button class="nav-link" data-panel="panelNuevo"><i class="bi bi-plus-circle"></i><br><span class="small">Nuevo</span></button>
    </li>
    <?php endif; ?>
  </ul>

  <div class="p-3">

    <!-- ===================== BUSCAR ===================== -->
    <div id="panelBuscar" class="app-panel">
      <label for="appBuscarInput" class="visually-hidden">Buscar instrumento</label>
      <div class="input-group mb-2">
        <input type="text" id="appBuscarInput" class="form-control" placeholder="Inventario, nombre, marca, modelo o serie">
        <button class="btn btn-primary-musiteca" id="appBtnBuscar"><i class="bi bi-search" aria-hidden="true"></i> Buscar</button>
      </div>
      <button class="btn btn-sm btn-outline-secondary w-100 mb-3" id="appBtnLimpiar"><i class="bi bi-eraser" aria-hidden="true"></i> Limpiar</button>
      <div class="text-muted small mb-2">Puedes buscar por código completo o por los últimos 4 dígitos (ej. 4450).</div>

      <div id="appResultados"></div>
    </div>

    <!-- ===================== ESCANEAR ===================== -->
    <div id="panelEscanear" class="app-panel d-none">
      <div class="app-scan-frame d-flex align-items-center justify-content-center mb-3 position-relative overflow-hidden">
        <video id="videoEscaner" class="w-100 h-100" style="object-fit:cover; display:none;" playsinline></video>
        <canvas id="canvasEscaner" class="d-none"></canvas>
        <span id="mensajeCamara" class="text-white small text-center px-3">
          <i class="bi bi-camera fs-3 d-block mb-1"></i>
          Activa la cámara para capturar la etiqueta del instrumento
        </span>
      </div>

      <div class="d-grid gap-2 mb-3">
        <button class="btn btn-outline-secondary" id="btnActivarCamara"><i class="bi bi-camera"></i> Activar cámara</button>
        <button class="btn btn-primary-musiteca d-none" id="btnCapturar"><i class="bi bi-camera-fill"></i> Tomar foto</button>
      </div>
      <div class="text-muted small mb-3" id="textoAyudaDeteccion">
        <i class="bi bi-magic"></i> Al activar la cámara, el sistema intenta leer el código automáticamente. Si tu navegador no lo permite, podrás tomar la foto y confirmar el código manualmente.
      </div>

      <img id="fotoCapturada" class="img-fluid rounded mb-3 d-none border">

      <div id="bloqueConfirmarCodigo" class="d-none">
        <div id="indicadorDeteccionAutomatica" class="alert alert-success small py-2 d-none"></div>
        <label class="form-label small fw-semibold">Confirma el código leído en la etiqueta</label>
        <div class="input-group mb-2">
          <span class="input-group-text bg-white"><i class="bi bi-upc"></i></span>
          <input type="text" id="codigoConfirmado" class="form-control" placeholder="Ej. TLA-0-092-150-034450 o solo 4450">
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-primary-musiteca flex-grow-1" id="btnUsarCodigo"><i class="bi bi-check-lg"></i> Confirmar código</button>
          <button class="btn btn-outline-secondary" id="btnNuevaCaptura"><i class="bi bi-arrow-clockwise"></i> Tomar otra captura</button>
        </div>
        <div class="alert alert-light border small mt-3 mb-0">
          <i class="bi bi-info-circle"></i> Consejos: acerca la cámara a la etiqueta, evita reflejos y encuadra solo la línea del código (TLA o BC-TLA).
        </div>
      </div>

      <div id="appResultadosEscaneo" class="mt-3"></div>
    </div>

    <!-- ===================== NUEVO INSTRUMENTO ===================== -->
    <?php if (esAdministrador()): ?>
    <div id="panelNuevo" class="app-panel d-none">
      <form id="formNuevoApp" novalidate>
        <div class="mb-2">
          <label class="form-label small fw-semibold" for="appNumInventario">Código de inventario (num_inv) *</label>
          <input type="text" class="form-control" id="appNumInventario" name="num_inventario" placeholder="Ej. TLA-0-092-150-034450" data-obligatorio="true" aria-required="true">
          <div class="invalid-feedback">Ingresa el código de inventario.</div>
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold" for="appNumInventarioAnterior">Código anterior (num_inv_anterior)</label>
          <input type="text" class="form-control" id="appNumInventarioAnterior" name="num_inventario_anterior" placeholder="Opcional">
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold" for="appNombreInstrumento">Nombre del instrumento *</label>
          <input type="text" class="form-control" id="appNombreInstrumento" name="nombre" placeholder="Ej. Guitarra acústica" data-obligatorio="true" aria-required="true">
          <div class="invalid-feedback">Ingresa el nombre del instrumento.</div>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-6">
            <label class="form-label small fw-semibold" for="appMarca">Marca</label>
            <input type="text" class="form-control" id="appMarca" name="marca" placeholder="Ej. Yamaha">
          </div>
          <div class="col-6">
            <label class="form-label small fw-semibold" for="appModelo">Modelo</label>
            <input type="text" class="form-control" id="appModelo" name="modelo" placeholder="Ej. FG800">
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold" for="appNumSerie">Número de serie</label>
          <input type="text" class="form-control" id="appNumSerie" name="num_serie">
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold" for="appUbicacionId">Ubicación *</label>
          <select class="form-select" id="appUbicacionId" name="ubicacion_id" data-obligatorio="true" aria-required="true">
            <option value="">Seleccione una ubicación…</option>
            <?php foreach ($ubicaciones as $u): ?>
              <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="invalid-feedback">Selecciona una ubicación.</div>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-semibold" for="appCondicion">Condición *</label>
          <select class="form-select" id="appCondicion" name="condicion" data-obligatorio="true" aria-required="true">
            <option value="">Seleccione una condición…</option>
            <option value="bueno">Bueno</option>
            <option value="regular">Regular</option>
            <option value="malo">Malo</option>
            <option value="inservible">Inservible</option>
          </select>
          <div class="invalid-feedback">Selecciona la condición del instrumento.</div>
        </div>
        <div class="d-grid gap-2">
          <button type="submit" class="btn btn-success" id="btnGuardarInstrumentoApp"><i class="bi bi-save"></i> Guardar instrumento</button>
          <button type="reset" class="btn btn-outline-secondary" id="btnLimpiarInstrumentoApp"><i class="bi bi-eraser"></i> Limpiar</button>
        </div>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ===================== Panel de acciones sobre un instrumento (compartido) ===================== -->
<div class="modal fade" id="modalAppAcciones" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title" id="appAccionesTitulo">Instrumento</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <dl class="row small mb-3" id="appAccionesDetalle"></dl>
        <button class="btn btn-oro-musiteca w-100 mb-2" id="appBtnAgregarSolicitud">
          <i class="bi bi-plus-square"></i> Agregar a solicitud
        </button>
        <button class="btn btn-success w-100 mb-2" id="appBtnSolicitar">
          <i class="bi bi-hand-index-thumb"></i> Solicitar
        </button>
        <button class="btn btn-warning w-100" id="appBtnReportar">
          <i class="bi bi-exclamation-triangle"></i> Reportar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Formulario de solicitud masiva -->
<div class="modal fade" id="modalSolicitudMasiva" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="formSolicitudMasiva" novalidate>
        <div class="modal-header">
          <h6 class="modal-title"><i class="bi bi-list-check"></i> Confirmar solicitud (<span id="contadorSolicitud">0</span>)</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <ul class="list-group mb-3 small" id="listaSolicitudMasiva"></ul>
          <div class="mb-2">
            <label class="form-label small fw-semibold" for="appSolicitudSolicitante">Solicitante *</label>
            <input type="text" class="form-control" id="appSolicitudSolicitante" name="solicitante" data-obligatorio="true" aria-required="true">
            <div class="invalid-feedback">Indica el nombre del solicitante.</div>
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold" for="appSolicitudUbicacionDestino">Ubicación destino</label>
            <input type="text" class="form-control" id="appSolicitudUbicacionDestino" name="ubicacion_destino">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary-musiteca">Registrar solicitud</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="modalReportarApp" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="formReportarApp" novalidate>
        <div class="modal-header">
          <h6 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Reportar problema</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="instrumento_id" id="reportarAppId">
          <div class="mb-2">
            <label class="form-label small fw-semibold" for="appReportarPor">Reportado por *</label>
            <input type="text" class="form-control" id="appReportarPor" name="reportado_por" data-obligatorio="true" aria-required="true">
            <div class="invalid-feedback">Indica quién reporta el problema.</div>
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold" for="appReportarMotivo">Motivo *</label>
            <select class="form-select" id="appReportarMotivo" name="motivo" data-obligatorio="true" aria-required="true">
              <option value="">Selecciona un motivo</option>
              <option value="dano_fisico">Daño físico</option>
              <option value="mal_funcionamiento">Mal funcionamiento</option>
              <option value="piezas_faltantes">Piezas faltantes</option>
              <option value="desgaste">Desgaste por uso</option>
              <option value="otro">Otro</option>
            </select>
            <div class="invalid-feedback">Selecciona el motivo del reporte.</div>
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold" for="appReportarUbicacion">Ubicación</label>
            <input type="text" class="form-control" id="appReportarUbicacion" name="ubicacion">
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold" for="appReportarDescripcion">Descripción *</label>
            <textarea class="form-control" id="appReportarDescripcion" name="descripcion" rows="3" data-obligatorio="true" aria-required="true"></textarea>
            <div class="invalid-feedback">Describe el problema detectado.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-danger">Reportar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer"></div>

<?php require __DIR__ . '/../includes/modal_confirmacion.php'; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script src="app.js"></script>
</body>
</html>
