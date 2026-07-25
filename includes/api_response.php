<?php
/**
 * Respuestas JSON estandarizadas para los endpoints de /api.
 *
 * Antes cada endpoint repetía manualmente http_response_code() +
 * json_encode(['ok' => ..., ...]) con ligeras variaciones. Centralizarlo
 * garantiza un contrato de respuesta consistente para el frontend:
 *   éxito -> { ok: true, mensaje?, datos?, ... }
 *   error -> { ok: false, error: string }
 */

function jsonOk(array $datosExtra = [], string $mensaje = ''): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $payload = ['ok' => true] + $datosExtra;
    if ($mensaje !== '') {
        $payload['mensaje'] = $mensaje;
    }
    echo json_encode($payload);
    exit;
}

function jsonError(string $mensaje, int $codigoHttp = 400): void
{
    if (!headers_sent()) {
        http_response_code($codigoHttp);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'error' => $mensaje]);
    exit;
}

/**
 * Envuelve la ejecución de una acción de endpoint capturando cualquier
 * PDOException para responder con un mensaje homogéneo, evitando el
 * try/catch repetido en cada archivo de api/.
 */
function ejecutarAccionApi(callable $accion): void
{
    try {
        $accion();
    } catch (PDOException $e) {
        error_log('[Musiteca][API] ' . $e->getMessage());
        if ((int)$e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
            jsonError('El registro ya existe o entra en conflicto con datos existentes.', 409);
        }
        jsonError('Ocurrió un error al procesar la solicitud en la base de datos.', 500);
    }
}
