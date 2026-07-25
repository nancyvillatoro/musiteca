<?php
/**
 * Conexión centralizada a MySQL mediante PDO.
 *
 * Las credenciales se leen de variables de entorno cuando existen (forma
 * recomendada para producción: definirlas en la configuración del
 * servidor/contenedor) y caen a valores por defecto de desarrollo local en
 * caso contrario. Así se evita tener credenciales fijas dentro del código
 * fuente versionado.
 */

require_once __DIR__ . '/constants.php';

define('DB_HOST', getenv('MUSITECA_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('MUSITECA_DB_NAME') ?: 'musiteca');
define('DB_USER', getenv('MUSITECA_DB_USER') ?: 'root');
define('DB_PASS', getenv('MUSITECA_DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// ------------------------------------------------------------
// Manejo global de errores: nunca se muestra el detalle técnico
// directamente al usuario. Se registra en el log del servidor y se
// responde con un mensaje profesional, en el formato adecuado según
// si la petición proviene de un endpoint de /api/ (JSON) o de una
// página normal (HTML).
// ------------------------------------------------------------
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

function esPeticionApi(): bool
{
    return strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false;
}

function responderErrorFatal(string $detalleTecnico, string $mensajeUsuario = 'Ocurrió un problema al procesar tu solicitud. Intenta de nuevo en unos momentos; si el problema continúa, repórtalo en el módulo de Soporte técnico.'): void
{
    error_log('[Musiteca] ' . $detalleTecnico);

    if (!headers_sent()) {
        http_response_code(500);
    }

    if (esPeticionApi()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => $mensajeUsuario]);
    } else {
        require __DIR__ . '/../includes/pagina_error.php';
    }
    exit;
}

set_exception_handler(function (Throwable $e) {
    responderErrorFatal($e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
});

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) {
    // Los avisos y notices no deben interrumpir la ejecución ni imprimirse
    // en pantalla; solo se registran para diagnóstico interno.
    if (!(error_reporting() & $errno)) {
        return false;
    }
    error_log("[Musiteca][PHP] $errstr en $errfile:$errline");
    return true;
});

function obtenerConexion(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $opciones = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opciones);
        } catch (PDOException $e) {
            responderErrorFatal(
                'No se pudo conectar a la base de datos: ' . $e->getMessage(),
                'No fue posible conectar con la base de datos. Verifica que el servicio de MySQL esté activo o contacta al área de Desarrollo de Sistemas.'
            );
        }
    }

    return $pdo;
}
