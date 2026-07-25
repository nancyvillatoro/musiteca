<?php
/**
 * Protección CSRF (Cross-Site Request Forgery) centralizada.
 *
 * Patrón "synchronizer token": se genera un token ligado a la sesión, se
 * imprime en cada formulario/página (csrfCampoOculto / csrfToken) y se
 * exige en cada endpoint que modifica datos (csrfValidarOFallar).
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/api_response.php';

function csrfToken(): string
{
    iniciarSesionSegura();

    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

/** Imprime un <input type="hidden"> listo para usarse dentro de un <form>. */
function csrfCampoOculto(): string
{
    return '<input type="hidden" name="' . CSRF_CAMPO . '" value="' . htmlspecialchars(csrfToken()) . '">';
}

/**
 * Obtiene el token recibido en la petición actual, ya sea por POST
 * (formularios / FormData) o por cabecera (fetch con JSON o FormData que
 * agrega X-CSRF-Token automáticamente desde main.js).
 */
function csrfTokenRecibido(): string
{
    if (!empty($_POST[CSRF_CAMPO])) {
        return (string)$_POST[CSRF_CAMPO];
    }

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    foreach ($headers as $nombre => $valor) {
        if (strcasecmp($nombre, CSRF_HEADER) === 0) {
            return (string)$valor;
        }
    }

    return (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
}

function csrfEsValido(): bool
{
    $esperado = $_SESSION['_csrf_token'] ?? '';
    $recibido = csrfTokenRecibido();

    return $esperado !== '' && $recibido !== '' && hash_equals($esperado, $recibido);
}

/**
 * Para usar al inicio de cualquier acción POST de un endpoint /api/.
 * Corta la ejecución con 419 (código convencional para "token expirado") si
 * el token no es válido.
 */
function csrfValidarOFallar(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    if (!csrfEsValido()) {
        jsonError('Tu sesión de formulario expiró. Recarga la página e intenta de nuevo.', 419);
    }
}
