<?php
require_once __DIR__ . '/includes/auth.php';
requerirSesion();

$pdo = obtenerConexion();

// KPIs en tiempo real
$kpis = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(estado = 'disponible')     AS disponibles,
        SUM(estado = 'en_uso')         AS en_uso,
        SUM(estado = 'en_reparacion')  AS en_reparacion
    FROM instrumentos
    WHERE activo = 1
")->fetch();

$ubicaciones = $pdo->query('SELECT id, nombre FROM ubicaciones ORDER BY nombre')->fetchAll();

$vista = $_GET['vista'] ?? 'inventario';
$vista = in_array($vista, ['inventario', 'control'], true) ? $vista : 'inventario';

$tituloPagina  = 'Gestión de instrumentos';
$seccionActiva = $vista;
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>

<main class="flex-grow-1" id="contenidoPrincipal">
  <!-- Topbar: solo título/contexto de la página. La acción principal de
       cada pestaña (Registrar instrumento / Nuevo préstamo) vive en la
       barra de herramientas de esa misma pestaña, no aquí — antes este
       botón se mostraba fijo incluso con la pestaña Movimientos activa,
       donde no aplica y donde ya existe su propia acción principal. -->
  <div class="topbar d-flex align-items-center px-3 px-md-4 py-3">
    <button class="btn btn-sm btn-outline-secondary d-lg-none me-2" id="btnToggleSidebar" aria-label="Abrir menú de navegación" aria-controls="sidebarMenu" aria-expanded="false">
      <i class="bi bi-list" aria-hidden="true"></i>
    </button>
    <div>
      <h5 class="mb-0 fw-bold" style="color:var(--musiteca-guinda-oscuro);">Gestión de Instrumentos</h5>
      <div class="text-muted small">Gestión del patrimonio, préstamos y mantenimiento de instrumentos musicales</div>
    </div>
  </div>

  <div class="p-3 p-md-4">
    <!-- Avisos operativos: fila de estado "requiere atención". Cada aviso
         está oculto por defecto y solo aparece cuando dashboard.js confirma
         que su conteo es mayor que cero (vencidos / incidencias / soporte).
         Antes estos tres conteos vivían como tarjetas KPI fijas de tamaño
         completo, visibles siempre aunque marcaran 0 la mayoría de los
         días — eso competía visualmente con la información realmente
         prioritaria del día a día. Al volverlos condicionales, un día sin
         pendientes no muestra nada aquí (pantalla más ligera) y un día con
         pendientes los destaca de inmediato, arriba de todo, en vez de
         mezclarse entre tarjetas neutras del mismo tamaño. -->
    <div id="avisosOperativos" class="mb-3">
      <div class="alert alert-vencidos d-none align-items-center justify-content-between flex-wrap gap-2 mb-2" id="avisoVencidos" role="alert">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-alarm fs-5" aria-hidden="true"></i>
          <span id="avisoVencidosTexto"></span>
        </div>
        <button type="button" class="btn btn-sm btn-outline-danger" id="btnVerVencidos">
          Ver préstamos vencidos <i class="bi bi-arrow-right" aria-hidden="true"></i>
        </button>
      </div>
      <div class="alert alert-incidencias d-none flex-column gap-2 mb-2" id="avisoIncidencias" role="alert">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle fs-5" aria-hidden="true"></i>
            <span id="avisoIncidenciasTexto"></span>
          </div>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="btnToggleIncidencias" aria-expanded="false" aria-controls="listaIncidencias">
            Ver detalle <i class="bi bi-chevron-down" aria-hidden="true"></i>
          </button>
        </div>
        <ul class="list-unstyled small mb-0 d-none" id="listaIncidencias"></ul>
      </div>
      <div class="alert alert-soporte d-none align-items-center justify-content-between flex-wrap gap-2 mb-0" id="avisoSoporte" role="alert">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-life-preserver fs-5" aria-hidden="true"></i>
          <span id="avisoSoporteTexto"></span>
        </div>
        <a href="soporte.php" class="btn btn-sm btn-outline-secondary">
          Ver reportes <i class="bi bi-arrow-right" aria-hidden="true"></i>
        </a>
      </div>
    </div>

    <!-- KPI principal + estado de inventario: dos niveles de jerarquía en
         vez de tarjetas idénticas. "Listos para préstamo" es la pregunta
         que el usuario necesita responder primero al empezar su jornada
         (¿tengo instrumentos disponibles?), así que se muestra como
         tarjeta destacada (hero): más grande, con más peso visual. El
         resto de conteos de inventario (patrimonio total, asignados,
         mantenimiento) siguen siendo relevantes pero son información de
         contexto/referencia, no una decisión inmediata — se agrupan en una
         franja compacta de menor peso visual (mismo bloque, tipografía más
         pequeña, sin sombra propia) para que se lean como apoyo y no
         compitan con la tarjeta principal. -->
    <div class="row g-3 mb-4">
      <div class="col-12 col-lg-5">
        <div class="card kpi-hero" title="Instrumentos disponibles para prestar en este momento">
          <span class="kpi-hero-icon"><i class="bi bi-check-circle"></i></span>
          <span class="kpi-hero-texto">
            <span class="kpi-hero-value" id="kpiDisponibles"><?= (int)$kpis['disponibles'] ?></span>
            <span class="kpi-hero-label">Listos para préstamo</span>
          </span>
        </div>
      </div>
      <div class="col-12 col-lg-7">
        <div class="kpi-mini-row">
          <div class="kpi-mini" title="Instrumentos activos en el catálogo del CEMUART">
            <span class="kpi-mini-icon kpi-mini-icon-neutro"><i class="bi bi-collection" aria-hidden="true"></i></span>
            <span class="kpi-mini-texto">
              <span class="kpi-mini-value" id="kpiTotal"><?= (int)$kpis['total'] ?></span>
              <span class="kpi-mini-label">Patrimonio total</span>
            </span>
          </div>
          <button type="button" class="kpi-mini kpi-mini-clicable" id="kpiMiniEnUso" title="Ver estos instrumentos en Movimientos">
            <span class="kpi-mini-icon kpi-mini-icon-ambar"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></span>
            <span class="kpi-mini-texto">
              <span class="kpi-mini-value" id="kpiEnUso"><?= (int)$kpis['en_uso'] ?></span>
              <span class="kpi-mini-label">Actualmente asignados</span>
            </span>
          </button>
          <button type="button" class="kpi-mini kpi-mini-clicable" id="kpiMiniMantenimiento" title="Ver estos instrumentos en Movimientos">
            <span class="kpi-mini-icon kpi-mini-icon-rojo"><i class="bi bi-tools" aria-hidden="true"></i></span>
            <span class="kpi-mini-texto">
              <span class="kpi-mini-value" id="kpiReparacion"><?= (int)$kpis['en_reparacion'] ?></span>
              <span class="kpi-mini-label">En mantenimiento</span>
            </span>
          </button>
        </div>
      </div>
    </div>

    <!-- Panel principal con pestañas Inventario / Control -->
    <div class="card card-panel">
      <div class="card-header bg-white border-0 pt-3">
        <ul class="nav nav-tabs-musiteca">
          <li class="nav-item">
            <button class="nav-link <?= $vista === 'inventario' ? 'active' : '' ?>" id="tabInventarioBtn" data-tab="inventario">
              <i class="bi bi-clipboard-data"></i> Catálogo
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link <?= $vista === 'control' ? 'active' : '' ?>" id="tabControlBtn" data-tab="control">
              <i class="bi bi-arrow-left-right"></i> Movimientos
            </button>
          </li>
        </ul>
      </div>

      <div class="card-body">
        <!-- ===================== INVENTARIO ===================== -->
        <div id="panelInventario" class="<?= $vista === 'inventario' ? '' : 'd-none' ?>">
          <div class="filtros-toolbar mb-3">
            <div class="filtros-buscador">
              <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="text" id="buscarInventario" class="form-control" placeholder="Buscar por número de inventario, nombre o ubicación…">
              </div>
            </div>
            <div class="filtros-controles">
              <select id="filtroCondicion" class="form-select">
                <option value="">Todas las condiciones</option>
                <option value="bueno">Bueno</option>
                <option value="regular">Regular</option>
                <option value="malo">Malo</option>
                <option value="inservible">Inservible</option>
              </select>
              <button class="btn btn-outline-secondary" id="btnLimpiarInventario" title="Quitar búsqueda y filtro"><i class="bi bi-eraser"></i> Limpiar</button>
            </div>
            <div class="filtros-acciones">
              <?php if (esAdministrador()): ?>
              <button class="btn btn-primary-musiteca" data-bs-toggle="modal" data-bs-target="#modalNuevoInstrumento"><i class="bi bi-plus-lg"></i> Registrar instrumento</button>
              <button class="btn btn-oro-musiteca" id="btnExportarInventario"><i class="bi bi-file-earmark-excel"></i> Descargar reporte</button>
              <?php endif; ?>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-musiteca align-middle">
              <thead>
                <tr>
                  <th scope="col">N° Inventario</th>
                  <th scope="col">Instrumento</th>
                  <th scope="col">Ubicación</th>
                  <th scope="col">Condición</th>
                  <th scope="col">Estado</th>
                  <th scope="col" class="text-end col-acciones">Acciones</th>
                </tr>
              </thead>
              <tbody id="tablaInventarioBody">
                <tr><td colspan="6" class="text-center text-muted py-4">Cargando…</td></tr>
              </tbody>
            </table>
          </div>
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="text-muted small" id="resumenInventario" aria-live="polite"></div>
            <nav aria-label="Paginación de inventario"><ul class="pagination justify-content-end mb-0" id="paginacionInventario"></ul></nav>
          </div>
        </div>

        <!-- ===================== CONTROL ===================== -->
        <div id="panelControl" class="<?= $vista === 'control' ? '' : 'd-none' ?>">
          <div class="filtros-toolbar mb-3">
            <div class="filtros-buscador">
              <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="text" id="buscarControl" class="form-control" placeholder="Buscar por instrumento, solicitante o ubicación…">
              </div>
            </div>
            <div class="filtros-controles">
              <select id="filtroEstado" class="form-select">
                <option value="">Todos los estados</option>
                <option value="disponible">Disponible</option>
                <option value="en_uso">Actualmente asignados</option>
                <option value="en_reparacion">En mantenimiento</option>
                <option value="vencido">Vencido</option>
              </select>
              <div class="filtro-fecha-grupo">
                <span class="filtro-fecha-etiqueta">Desde</span>
                <input type="date" id="filtroFechaInicio" class="form-control">
              </div>
              <div class="filtro-fecha-grupo">
                <span class="filtro-fecha-etiqueta">Hasta</span>
                <input type="date" id="filtroFechaFin" class="form-control">
              </div>
              <button class="btn btn-outline-secondary" id="btnLimpiarControl" title="Quitar búsqueda y filtros"><i class="bi bi-eraser"></i> Limpiar</button>
            </div>
            <div class="filtros-acciones">
              <button class="btn btn-primary-musiteca" id="btnNuevoPrestamo"><i class="bi bi-plus-lg"></i> Nuevo préstamo</button>
              <?php if (esAdministrador()): ?>
              <button class="btn btn-oro-musiteca" id="btnExportarControl"><i class="bi bi-file-earmark-excel"></i> Descargar reporte</button>
              <?php endif; ?>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-musiteca align-middle">
              <thead>
                <tr>
                  <th scope="col">Instrumento</th>
                  <th scope="col">N° Inventario</th>
                  <th scope="col">Estado</th>
                  <th scope="col">Ubicación</th>
                  <th scope="col">Solicitante</th>
                  <th scope="col">Fecha solicitud</th>
                  <th scope="col" class="text-end col-acciones col-acciones-control">Acciones</th>
                </tr>
              </thead>
              <tbody id="tablaControlBody">
                <tr><td colspan="7" class="text-center text-muted py-4">Cargando…</td></tr>
              </tbody>
            </table>
          </div>
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="text-muted small" id="resumenControl" aria-live="polite"></div>
            <nav aria-label="Paginación de movimientos"><ul class="pagination justify-content-end mb-0" id="paginacionControl"></ul></nav>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<!-- ============================================================
     MODAL: Nuevo / Editar instrumento
     ============================================================ -->
<?php if (esAdministrador()): ?>
<div class="modal fade" id="modalNuevoInstrumento" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
    <div class="modal-content">
      <form id="formInstrumento" novalidate>
        <div class="modal-header">
          <h5 class="modal-title" id="tituloModalInstrumento"><i class="bi bi-plus-circle"></i> Registrar instrumento</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="instrumentoId">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold" for="numInventario">N° de inventario *</label>
              <input type="text" class="form-control" name="num_inventario" id="numInventario" data-obligatorio="true" aria-required="true">
              <div class="invalid-feedback">Ingresa el número de inventario.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold" for="numInventarioAnterior">N° de inventario anterior</label>
              <input type="text" class="form-control" name="num_inventario_anterior" id="numInventarioAnterior">
            </div>
            <div class="col-md-12">
              <label class="form-label small fw-semibold" for="nombreInstrumento">Nombre del instrumento *</label>
              <input type="text" class="form-control" name="nombre" id="nombreInstrumento" data-obligatorio="true" aria-required="true">
              <div class="invalid-feedback">Ingresa el nombre del instrumento.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold" for="marcaInstrumento">Marca *</label>
              <input type="text" class="form-control" name="marca" id="marcaInstrumento" data-obligatorio="true" aria-required="true">
              <div class="invalid-feedback">Ingresa la marca del instrumento.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold" for="modeloInstrumento">Modelo *</label>
              <input type="text" class="form-control" name="modelo" id="modeloInstrumento" data-obligatorio="true" aria-required="true">
              <div class="invalid-feedback">Ingresa el modelo del instrumento.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold" for="numSerie">N° de serie *</label>
              <input type="text" class="form-control" name="num_serie" id="numSerie" data-obligatorio="true" aria-required="true">
              <div class="invalid-feedback">Ingresa el número de serie.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold" for="ubicacionId">Ubicación física *</label>
              <select class="form-select" name="ubicacion_id" id="ubicacionId" data-obligatorio="true" aria-required="true">
                <option value="">Seleccionar ubicación</option>
                <?php foreach ($ubicaciones as $u): ?>
                  <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="invalid-feedback">Selecciona la ubicación física.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold" for="condicionInstrumento">Condición *</label>
              <select class="form-select" name="condicion" id="condicionInstrumento" data-obligatorio="true" aria-required="true">
                <option value="">Seleccionar condición</option>
                <option value="bueno">Bueno</option>
                <option value="regular">Regular</option>
                <option value="malo">Malo</option>
                <option value="inservible">Inservible</option>
              </select>
              <div class="invalid-feedback">Selecciona la condición del instrumento.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold" for="estadoInstrumento">Estado logístico</label>
              <select class="form-select" name="estado" id="estadoInstrumento">
                <option value="disponible">Disponible</option>
                <option value="en_uso">Actualmente asignado</option>
                <option value="en_reparacion">En mantenimiento</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary-musiteca" id="btnGuardarInstrumento"><i class="bi bi-save"></i> Guardar instrumento</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ============================================================
     OFFCANVAS: Detalle / trazabilidad del instrumento
     ============================================================ -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvasDetalle" style="width: 420px;">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title"><i class="bi bi-info-circle"></i> Detalle del instrumento</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body" id="contenidoDetalle">
    <div class="text-center text-muted py-5">Selecciona un instrumento para ver su expediente.</div>
  </div>
</div>

<!-- ============================================================
     MODAL: Nuevo préstamo (uno o varios instrumentos, un solo solicitante)
     ============================================================ -->
<div class="modal fade" id="modalSolicitar" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
    <div class="modal-content">
      <form id="formSolicitar" novalidate>
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-hand-index-thumb"></i> Nuevo préstamo</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label small fw-semibold" for="solicitarSolicitante">Solicitante *</label>
            <input type="text" class="form-control" name="solicitante" id="solicitarSolicitante" data-obligatorio="true" aria-required="true">
            <div class="invalid-feedback">Indica el nombre del solicitante.</div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold" for="solicitarUbicacionDestino">Ubicación destino</label>
              <input type="text" class="form-control" name="ubicacion_destino" id="solicitarUbicacionDestino">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold" for="solicitarFechaDevolucion">Fecha de devolución esperada</label>
              <input type="date" class="form-control" name="fecha_devolucion_esperada" id="solicitarFechaDevolucion">
              <div class="form-text">Si se deja vacía, se calcula a partir del plazo de préstamo por defecto.</div>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold" for="solicitarObservaciones">Observaciones</label>
            <textarea class="form-control" name="observaciones" id="solicitarObservaciones" rows="2"></textarea>
          </div>

          <hr>

          <label class="form-label small fw-semibold mb-2">Instrumentos del préstamo *</label>
          <div class="input-group mb-2">
            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control" id="solicitarBuscarInstrumento" placeholder="Buscar por número de inventario, nombre, marca o modelo…" autocomplete="off">
          </div>
          <div id="solicitarResultadosBusqueda" class="list-group mb-3 d-none" style="max-height: 220px; overflow-y: auto;"></div>

          <div id="solicitarSinInstrumentos" class="text-muted small text-center border rounded-3 py-3 mb-2">
            Aún no has agregado ningún instrumento a este préstamo.
          </div>
          <ul id="solicitarListaInstrumentos" class="list-group mb-1"></ul>
        </div>
        <div class="modal-footer">
          <span class="text-muted small me-auto" id="solicitarContador"></span>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary-musiteca">Confirmar préstamo</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ============================================================
     MODAL: Registrar regreso (devolución) con estado de entrega
     ============================================================ -->
<div class="modal fade" id="modalDevolucion" tabindex="-1">
  <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down">
    <div class="modal-content">
      <form id="formDevolucion" novalidate>
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-box-arrow-in-left"></i> Registrar regreso</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="solicitud_id" id="devolucionSolicitudId">
          <p class="small text-muted mb-3" id="devolucionResumen"></p>

          <div class="alert alert-warning small d-none" id="devolucionIncidenciaAlert" role="alert">
            <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle-fill"></i> Este instrumento tiene una incidencia reportada durante el préstamo</div>
            <div id="devolucionIncidenciaResumen"></div>
          </div>
          <div class="mb-3 d-none" id="devolucionDecisionIncidenciaGrupo">
            <label class="form-label small fw-semibold" for="devolucionDecisionIncidencia">¿Qué se hace con el instrumento? *</label>
            <select class="form-select" name="decision_incidencia" id="devolucionDecisionIncidencia">
              <option value="">Selecciona una opción</option>
              <option value="resuelto">El problema fue resuelto o no es crítico → vuelve a disponible</option>
              <option value="mantenimiento">Requiere revisión o reparación → enviar a mantenimiento</option>
            </select>
            <div class="invalid-feedback">Indica si el instrumento vuelve a disponible o pasa a mantenimiento.</div>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold" for="devolucionCondicion">Estado en el que se entregó *</label>
            <select class="form-select" name="condicion_devolucion" id="devolucionCondicion" data-obligatorio="true" aria-required="true">
              <option value="">Selecciona un estado</option>
              <option value="excelente">Excelente</option>
              <option value="bueno">Bueno</option>
              <option value="desgaste">Con desgaste</option>
              <option value="danado">Dañado</option>
              <option value="incompleto">Incompleto</option>
            </select>
            <div class="invalid-feedback">Selecciona el estado en el que se recibió el instrumento.</div>
          </div>
          <div class="mb-1 d-none" id="devolucionObservacionesGrupo">
            <label class="form-label small fw-semibold" for="devolucionObservaciones">Observaciones *</label>
            <textarea class="form-control" name="observaciones_devolucion" id="devolucionObservaciones" rows="3" placeholder="Describe el desgaste, daño o lo que falta…"></textarea>
            <div class="invalid-feedback">Describe el problema encontrado.</div>
            <div class="form-text">Obligatorio cuando el instrumento no regresó en las mismas condiciones en que se prestó.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-success"><i class="bi bi-box-arrow-in-left"></i> Registrar regreso</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ============================================================
     MODAL: Reportar problema
     ============================================================ -->
<div class="modal fade" id="modalReportar" tabindex="-1">
  <div class="modal-dialog modal-fullscreen-sm-down">
    <div class="modal-content">
      <form id="formReportar" novalidate>
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Reportar problema</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="instrumento_id" id="reportarInstrumentoId">
          <p class="small text-muted mb-3" id="reportarResumen"></p>
          <div class="alert alert-info small d-none" id="reportarAvisoPrestamo">
            <i class="bi bi-info-circle"></i> Este instrumento está actualmente prestado. El préstamo seguirá activo; la decisión de enviarlo a mantenimiento (o no) se tomará al registrar la devolución.
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold" for="reportarPor">Reportado por *</label>
            <div class="input-group">
              <input type="text" class="form-control" name="reportado_por" id="reportarPor" data-obligatorio="true" aria-required="true">
              <button type="button" class="btn btn-outline-secondary" id="btnReportarPorFuiYo" title="Usar mi nombre">Fui yo</button>
              <div class="invalid-feedback">Indica quién reporta el problema.</div>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold" for="reportarMotivo">Motivo *</label>
            <select class="form-select" name="motivo" id="reportarMotivo" data-obligatorio="true" aria-required="true">
              <option value="">Selecciona un motivo</option>
              <option value="dano_fisico">Daño físico</option>
              <option value="mal_funcionamiento">Mal funcionamiento</option>
              <option value="piezas_faltantes">Piezas faltantes</option>
              <option value="desgaste">Desgaste por uso</option>
              <option value="otro">Otro</option>
            </select>
            <div class="invalid-feedback">Selecciona el motivo del reporte.</div>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold" for="reportarUbicacion">Ubicación</label>
            <input type="text" class="form-control" name="ubicacion" id="reportarUbicacion">
          </div>
          <div class="mb-1">
            <label class="form-label small fw-semibold" for="reportarDescripcion">Descripción del problema *</label>
            <textarea class="form-control" name="descripcion" id="reportarDescripcion" rows="3" data-obligatorio="true" aria-required="true"></textarea>
            <div class="invalid-feedback">Describe el problema detectado.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-danger">Reportar problema</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Contenedor de notificaciones tipo toast -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer"></div>

<?php require __DIR__ . '/includes/footer.php'; ?>
<script src="assets/js/inventario.js"></script>
<script src="assets/js/control.js"></script>
<script src="assets/js/dashboard.js"></script>
