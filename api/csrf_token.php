<?php
/**
 * Endpoint mínimo de apoyo al manejo de CSRF en el cliente.
 *
 * No expone nada que el usuario no pueda ya leer de su propia página (el
 * token vive en el <meta name="csrf-token"> de cada vista). Su único
 * propósito es permitir que assets/js/main.js recupere el token vigente de
 * la sesión activa cuando una petición fue rechazada con 419, sin tener que
 * forzar una recarga completa de la página. Si la sesión ya no es válida
 * (por ejemplo, expiró de verdad), responde 401 y el cliente redirige a
 * login en vez de reintentar indefinidamente.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

if (!usuarioAutenticado()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesión no válida.']);
    exit;
}

echo json_encode(['ok' => true, 'token' => csrfToken()]);
