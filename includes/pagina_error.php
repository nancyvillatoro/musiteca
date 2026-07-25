<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Musiteca · No fue posible completar la operación</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Outfit:wght@600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --musiteca-guinda: #7A1E3C;
      --musiteca-guinda-oscuro: #4A0E23;
      --musiteca-rojo: #EF4444;
      --musiteca-rojo-alfa: rgba(239, 68, 68, 0.12);
    }
    body {
      background: linear-gradient(135deg, var(--musiteca-guinda) 0%, var(--musiteca-guinda-oscuro) 100%);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      padding: 1.5rem;
    }
    .error-card { max-width: 460px; border-radius: 1.5rem; }
    .error-card h4 { font-family: 'Outfit', sans-serif; color: var(--musiteca-guinda-oscuro); }
    .error-icono {
      width: 4.2rem; height: 4.2rem; border-radius: 50%;
      background: var(--musiteca-rojo-alfa);
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto;
    }
    .error-icono i { font-size: 1.9rem; color: var(--musiteca-rojo); }
    .btn-guinda-error {
      background: var(--musiteca-guinda);
      border-color: var(--musiteca-guinda);
      color: #fff;
      font-weight: 600;
      border-radius: 0.65rem;
    }
    .btn-guinda-error:hover { background: var(--musiteca-guinda-oscuro); border-color: var(--musiteca-guinda-oscuro); color: #fff; }
    .btn-volver-error { border-radius: 0.65rem; }
  </style>
</head>
<body>
  <div class="card error-card shadow-lg border-0">
    <div class="card-body p-4 p-md-5 text-center">
      <div class="error-icono mb-3">
        <i class="bi bi-exclamation-triangle-fill"></i>
      </div>
      <h4 class="fw-bold mb-2">No fue posible completar la operación</h4>
      <p class="text-muted mb-4">
        Ocurrió un problema al procesar tu solicitud. Intenta de nuevo en unos momentos. Si el problema continúa, repórtalo desde el módulo de Soporte técnico.
      </p>
      <div class="d-flex gap-2 justify-content-center">
        <a href="javascript:history.back()" class="btn btn-outline-secondary btn-volver-error">
          <i class="bi bi-arrow-left"></i> Regresar
        </a>
        <a href="javascript:location.reload()" class="btn btn-guinda-error">
          <i class="bi bi-arrow-clockwise"></i> Reintentar
        </a>
      </div>
    </div>
  </div>
</body>
</html>
