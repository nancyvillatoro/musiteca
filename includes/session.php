<?php
/**
 * Arranque centralizado y endurecido de la sesión PHP.
 *
 * Antes: session_start() se invocaba directamente en includes/auth.php sin
 * configurar cookie ni control de inactividad. Ahora toda la app arranca la
 * sesión únicamente a través de iniciarSesionSegura(), lo que permite tener
 * una sola fuente de verdad para el manejo de sesiones.
 */

require_once __DIR__ . '/../config/constants.php';

function iniciarSesionSegura(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $esHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $esHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_name(SESION_NOMBRE_COOKIE);
    ini_set('session.use_strict_mode', '1');

    // El recolector de basura de PHP (session.gc_maxlifetime) purga archivos
    // de sesión en el servidor de forma independiente a la lógica de
    // inactividad de la aplicación (SESION_TIEMPO_INACTIVIDAD). Si el valor
    // por defecto de PHP (1440 s = 24 min) es menor que nuestra ventana de
    // inactividad (30 min), un usuario que tarde en llenar un formulario
    // largo (alta de instrumento, reporte de soporte con imagen) puede
    // perder su sesión y su token CSRF por el GC antes de que la propia app
    // lo hubiera considerado expirado, provocando el error "Tu sesión de
    // formulario expiró" de forma intermitente aunque el usuario siga
    // activo. Se fuerza aquí un mínimo seguro para esta sesión.
    $gcMinimoSegundos = SESION_TIEMPO_INACTIVIDAD + 300; // margen de 5 minutos
    if ((int)ini_get('session.gc_maxlifetime') < $gcMinimoSegundos) {
        ini_set('session.gc_maxlifetime', (string)$gcMinimoSegundos);
    }

    session_start();

    controlarInactividadYRegeneracion();
}

/**
 * Cierra la sesión si el usuario superó el tiempo de inactividad permitido,
 * y regenera periódicamente el identificador de sesión para reducir el
 * riesgo de fijación/robo de sesión (session fixation / hijacking).
 */
function controlarInactividadYRegeneracion(): void
{
    $ahora = time();

    if (isset($_SESSION['usuario_id'])) {
        $ultimaActividad = $_SESSION['_ultima_actividad'] ?? $ahora;

        if (($ahora - $ultimaActividad) > SESION_TIEMPO_INACTIVIDAD) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $parametros = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $parametros['path'], $parametros['domain'], $parametros['secure'], $parametros['httponly']);
            }
            session_destroy();
            session_start();
            $_SESSION['_sesion_expirada'] = true;
            return;
        }

        $ultimaRegeneracion = $_SESSION['_ultima_regeneracion'] ?? 0;
        if (($ahora - $ultimaRegeneracion) > SESION_TIEMPO_REGENERACION) {
            session_regenerate_id(true);
            $_SESSION['_ultima_regeneracion'] = $ahora;
        }
    }

    $_SESSION['_ultima_actividad'] = $ahora;
}
