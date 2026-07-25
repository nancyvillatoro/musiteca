<?php
/**
 * Control de sesión y autenticación del personal administrador.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/session.php';

iniciarSesionSegura();

function usuarioAutenticado(): bool
{
    return isset($_SESSION['usuario_id']);
}

function requerirSesion(): void
{
    if (!usuarioAutenticado()) {
        header('Location: ' . rutaRelativaLogin());
        exit;
    }
}

function rutaRelativaLogin(): string
{
    // Permite que módulos anidados (por ejemplo /app) redirijan al login
    // correcto sin hardcodear "../login.php" en cada controlador.
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    return str_contains($script, '/app/') ? '../login.php' : 'login.php';
}

/**
 * IP del cliente, considerando proxies/balanceadores comunes. Se usa solo
 * para el control de intentos de login, nunca como mecanismo único de
 * seguridad (las cabeceras pueden falsificarse).
 */
function obtenerIpCliente(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $clave) {
        if (!empty($_SERVER[$clave])) {
            $partes = explode(',', $_SERVER[$clave]);
            return trim($partes[0]);
        }
    }
    return '0.0.0.0';
}

/**
 * Indica si el usuario/IP está actualmente bloqueado por exceso de intentos
 * fallidos, y en tal caso cuántos minutos restan de bloqueo.
 */
function loginBloqueado(string $usuario): ?int
{
    $pdo = obtenerConexion();
    $ip  = obtenerIpCliente();

    $stmt = $pdo->prepare('
        SELECT COUNT(*) AS intentos, MAX(creado_en) AS ultimo
        FROM intentos_login
        WHERE exitoso = 0
          AND creado_en >= (NOW() - INTERVAL ? MINUTE)
          AND (usuario = ? OR ip = ?)
    ');
    $stmt->execute([LOGIN_VENTANA_MINUTOS, $usuario, $ip]);
    $fila = $stmt->fetch();

    if ((int)($fila['intentos'] ?? 0) < LOGIN_MAX_INTENTOS) {
        return null;
    }

    $minutosTranscurridos = (time() - strtotime($fila['ultimo'])) / 60;
    $minutosRestantes = (int)ceil(LOGIN_BLOQUEO_MINUTOS - $minutosTranscurridos);

    return $minutosRestantes > 0 ? $minutosRestantes : null;
}

function registrarIntentoLogin(string $usuario, bool $exitoso): void
{
    $pdo = obtenerConexion();
    $stmt = $pdo->prepare('INSERT INTO intentos_login (usuario, ip, exitoso) VALUES (?, ?, ?)');
    $stmt->execute([$usuario, obtenerIpCliente(), $exitoso ? 1 : 0]);
}

/**
 * @return array{ok:bool, error?:string}
 */
function intentarLogin(string $usuario, string $password): array
{
    $minutosRestantes = loginBloqueado($usuario);
    if ($minutosRestantes !== null) {
        return [
            'ok'    => false,
            'error' => "Demasiados intentos fallidos. Intenta de nuevo en {$minutosRestantes} minuto(s).",
        ];
    }

    $pdo = obtenerConexion();
    $stmt = $pdo->prepare('SELECT id, usuario, password_hash, nombre_completo, rol FROM usuarios WHERE usuario = ? AND activo = 1');
    $stmt->execute([$usuario]);
    $row = $stmt->fetch();

    if ($row && password_verify($password, $row['password_hash'])) {
        registrarIntentoLogin($usuario, true);
        require_once __DIR__ . '/servicios/usuarios_servicio.php';
        usuariosRegistrarAcceso($pdo, (int)$row['id']);

        session_regenerate_id(true);
        $_SESSION['usuario_id']            = $row['id'];
        $_SESSION['usuario_nombre']        = $row['nombre_completo'];
        $_SESSION['usuario_rol']           = $row['rol'];
        $_SESSION['_ultima_actividad']     = time();
        $_SESSION['_ultima_regeneracion']  = time();

        return ['ok' => true];
    }

    registrarIntentoLogin($usuario, false);

    return ['ok' => false, 'error' => 'Usuario o contraseña incorrectos. Verifica tus datos e intenta de nuevo.'];
}

function cerrarSesion(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $parametros = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $parametros['path'], $parametros['domain'], $parametros['secure'], $parametros['httponly']);
    }

    session_destroy();
}

function esAdministrador(): bool
{
    return ($_SESSION['usuario_rol'] ?? '') === ROL_ADMINISTRADOR;
}

// esPeticionApi() ya está definida en config/database.php (se usa allí para
// el manejador global de errores) y se reutiliza aquí para no duplicarla.

function requerirAdministrador(): void
{
    requerirSesion();
    if (!esAdministrador()) {
        if (esPeticionApi()) {
            require_once __DIR__ . '/api_response.php';
            jsonError('No tienes permisos para realizar esta acción.', 403);
        }
        header('Location: index.php');
        exit;
    }
}
