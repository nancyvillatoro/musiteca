<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

if (usuarioAutenticado()) {
    header('Location: index.php');
    exit;
}

$error = '';
$esAjax = (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario  = trim($_POST['usuario'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!csrfEsValido()) {
        $error = 'Tu sesión de formulario expiró. Recarga la página e intenta de nuevo.';
    } elseif ($usuario === '' || $password === '') {
        $error = 'Ingresa tu usuario y contraseña.';
    } else {
        $resultado = intentarLogin($usuario, $password);
        if ($resultado['ok']) {
            if ($esAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'redirect' => 'index.php']);
                exit;
            }
            header('Location: index.php');
            exit;
        }
        $error = $resultado['error'];
    }

    if ($esAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Iniciar sesión · Musiteca</title>
  <link rel="icon" type="image/svg+xml" href="assets/img/logo.svg">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="login-page">

  <!-- Panel de marca (oculto en móvil) -->
  <div class="login-panel-brand">
    <i class="bi bi-music-note-beamed nota-decorativa nota-1"></i>
    <i class="bi bi-music-note nota-decorativa nota-2"></i>
    <i class="bi bi-vinyl-fill nota-decorativa nota-3"></i>

    <div class="login-brand-badge">
      <img src="assets/img/logo.svg" alt="Musiteca" class="brand-logo">
    </div>
    <h1>Musiteca</h1>
    <p class="tagline">Administración del patrimonio de instrumentos musicales del Área de Cultura Comunitaria y Escuelas de Arte, H. Ayuntamiento de Tlalnepantla de Baz.</p>

    <ul class="login-feature-list">
      <li><i class="bi bi-collection"></i> Catálogo patrimonial centralizado</li>
      <li><i class="bi bi-arrow-left-right"></i> Movimientos de préstamos y devoluciones</li>
    </ul>
  </div>

  <!-- Panel de formulario -->
  <div class="login-panel-form">
    <div class="login-form-inner">

      <div class="login-mobile-badge">
        <img src="assets/img/logo.svg" alt="Musiteca" class="brand-logo">
      </div>

      <div class="mb-4">
        <h4 class="fw-bold mb-1" style="color:var(--musiteca-guinda-oscuro);">Bienvenido de nuevo</h4>
        <p class="text-muted small mb-0">Ingresa tus credenciales para acceder al panel de Musiteca.</p>
      </div>

      <div class="alert alert-danger py-2 small login-alert <?= $error ? '' : 'd-none' ?>" id="loginAlerta">
        <i class="bi bi-exclamation-circle me-1"></i><span id="loginAlertaTexto"><?= htmlspecialchars($error) ?></span>
      </div>

      <form method="post" novalidate id="formLogin">
        <?= csrfCampoOculto() ?>
        <div class="mb-3">
          <label class="form-label small fw-semibold" for="loginUsuario">Usuario</label>
          <input type="text" name="usuario" id="loginUsuario" class="form-control" autofocus autocomplete="username">
          <div class="invalid-feedback">Ingresa tu usuario.</div>
        </div>
        <div class="mb-4">
          <label class="form-label small fw-semibold" for="loginPassword">Contraseña</label>
          <div class="input-group">
            <input type="password" name="password" id="loginPassword" class="form-control" autocomplete="current-password">
            <button type="button" class="btn btn-toggle-password" id="btnTogglePassword" tabindex="-1" aria-label="Mostrar contraseña" aria-pressed="false">
              <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
            <div class="invalid-feedback">Ingresa tu contraseña.</div>
          </div>
        </div>
        <button type="submit" class="btn btn-primary-musiteca w-100 py-2 fw-semibold" id="btnLoginSubmit">Entrar</button>
      </form>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
  const form = document.getElementById('formLogin');
  const usuario = document.getElementById('loginUsuario');
  const password = document.getElementById('loginPassword');
  const alerta = document.getElementById('loginAlerta');
  const alertaTexto = document.getElementById('loginAlertaTexto');
  const btnSubmit = document.getElementById('btnLoginSubmit');
  const btnToggle = document.getElementById('btnTogglePassword');

  // Mostrar / ocultar contraseña
  btnToggle.addEventListener('click', () => {
    const esTexto = password.type === 'text';
    password.type = esTexto ? 'password' : 'text';
    btnToggle.innerHTML = esTexto ? '<i class="bi bi-eye" aria-hidden="true"></i>' : '<i class="bi bi-eye-slash" aria-hidden="true"></i>';
    btnToggle.setAttribute('aria-pressed', String(!esTexto));
    btnToggle.setAttribute('aria-label', esTexto ? 'Mostrar contraseña' : 'Ocultar contraseña');
  });

  function mostrarError(mensaje) {
    alertaTexto.textContent = mensaje;
    alerta.classList.remove('d-none');
  }

  function ocultarError() {
    alerta.classList.add('d-none');
  }

  function validar() {
    let valido = true;
    [usuario, password].forEach((campo) => {
      if (campo.value.trim() === '') {
        campo.classList.add('is-invalid');
        valido = false;
      } else {
        campo.classList.remove('is-invalid');
      }
    });
    return valido;
  }

  [usuario, password].forEach((campo) => {
    campo.addEventListener('input', () => {
      if (campo.value.trim() !== '') campo.classList.remove('is-invalid');
    });
  });

  form.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    ocultarError();

    if (!validar()) {
      mostrarError('Ingresa tu usuario y contraseña para continuar.');
      return;
    }

    btnSubmit.disabled = true;
    const textoOriginal = btnSubmit.innerHTML;
    btnSubmit.innerHTML = '<span class="spinner-border spinner-border-sm-inline spinner-border-sm"></span> Verificando…';

    try {
      const formData = new FormData(form);
      const resp = await fetch('login.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData,
      });
      const data = await resp.json();

      if (data.ok) {
        btnSubmit.innerHTML = '<i class="bi bi-check-lg"></i> ¡Bienvenido!';
        window.location.href = data.redirect || 'index.php';
      } else {
        mostrarError(data.error || 'No fue posible iniciar sesión.');
        password.value = '';
        password.focus();
        btnSubmit.disabled = false;
        btnSubmit.innerHTML = textoOriginal;
      }
    } catch (err) {
      mostrarError('No fue posible conectar con el servidor. Verifica tu conexión e intenta de nuevo.');
      btnSubmit.disabled = false;
      btnSubmit.innerHTML = textoOriginal;
    }
  });
})();
</script>
</body>
</html>
