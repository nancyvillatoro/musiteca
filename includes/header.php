<?php
/** Encabezado común de las vistas administrativas. Requiere $tituloPagina opcional. */
require_once __DIR__ . '/csrf.php';
$tituloPagina = $tituloPagina ?? 'Musiteca';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()) ?>">
  <title><?= htmlspecialchars($tituloPagina) ?> · Musiteca</title>
  <link rel="icon" type="image/svg+xml" href="assets/img/logo.svg">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
  <script>
    window.ES_ADMIN = <?= json_encode(esAdministrador()) ?>;
    window.USUARIO_NOMBRE = <?= json_encode($_SESSION['usuario_nombre'] ?? '') ?>;
  </script>
</head>
<body>
<a href="#contenidoPrincipal" class="skip-link">Saltar al contenido principal</a>
<div class="d-flex">
