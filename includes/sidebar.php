<?php
/**
 * Sidebar de navegación. Requiere $seccionActiva
 * ('inventario'|'control'|'soporte'|'usuarios'|'instrumentos_baja').
 *
 * 'inventario' y 'control' comparten una sola entrada de menú ("Panel
 * principal"): ambas son pestañas de la misma página (index.php), así que
 * antes el menú y las pestañas internas resolvían la misma navegación por
 * duplicado (dos enlaces del menú para las mismas dos vistas que ya se
 * alternan sin recargar con las pestañas Catálogo/Movimientos). El enlace
 * queda activo para ambos valores de $seccionActiva.
 */
$seccionActiva = $seccionActiva ?? 'inventario';
?>
<nav class="sidebar d-flex flex-column p-0" id="sidebarMenu">
  <div class="brand d-flex align-items-center gap-2">
    <img src="assets/img/logo.svg" alt="Musiteca" class="brand-logo">
    <span>Musiteca</span>
  </div>

  <div class="flex-grow-1 py-3">
    <div class="text-uppercase small px-3 mb-2" style="color: rgba(255,255,255,.5); font-size:.7rem; letter-spacing:.08em;">
      Gestión de instrumentos
    </div>
    <a href="index.php" class="nav-link d-flex align-items-center <?= in_array($seccionActiva, ['inventario', 'control'], true) ? 'active' : '' ?>">
      <i class="bi bi-speedometer2"></i> Panel principal
    </a>

    <div class="text-uppercase small px-3 mt-4 mb-2" style="color: rgba(255,255,255,.5); font-size:.7rem; letter-spacing:.08em;">
      Soporte
    </div>
    <a href="soporte.php" class="nav-link d-flex align-items-center <?= $seccionActiva === 'soporte' ? 'active' : '' ?>">
      <i class="bi bi-life-preserver"></i> Reportar anomalía
    </a>
    <a href="app/index.php" class="nav-link d-flex align-items-center" target="_blank">
      <i class="bi bi-phone"></i> Abrir app operativa
    </a>

    <?php if (esAdministrador()): ?>
    <div class="text-uppercase small px-3 mt-4 mb-2" style="color: rgba(255,255,255,.5); font-size:.7rem; letter-spacing:.08em;">
      Administración
    </div>
    <a href="usuarios.php" class="nav-link d-flex align-items-center <?= $seccionActiva === 'usuarios' ? 'active' : '' ?>">
      <i class="bi bi-people"></i> Gestión de usuarios
    </a>
    <a href="instrumentos_baja.php" class="nav-link d-flex align-items-center <?= $seccionActiva === 'instrumentos_baja' ? 'active' : '' ?>">
      <i class="bi bi-arrow-counterclockwise"></i> Instrumentos dados de baja
    </a>
    <?php endif; ?>
  </div>

  <div class="p-3 border-top" style="border-color: rgba(255,255,255,.15) !important;">
    <div class="d-flex align-items-center gap-2 mb-2 text-white">
      <i class="bi bi-person-circle fs-5"></i>
      <div class="small">
        <div class="fw-semibold"><?= htmlspecialchars($_SESSION['usuario_nombre'] ?? '') ?></div>
        <div style="opacity:.7; font-size:.72rem;"><?= htmlspecialchars(ucfirst($_SESSION['usuario_rol'] ?? '')) ?></div>
      </div>
    </div>
    <button type="button" id="btnCerrarSesion" data-href="logout.php" class="btn btn-sm btn-outline-light w-100">
      <i class="bi bi-box-arrow-right"></i> Cerrar sesión
    </button>
  </div>
</nav>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
