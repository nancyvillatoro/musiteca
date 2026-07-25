<?php
/**
 * Endpoint AJAX de solo lectura para el panel operativo del dashboard de
 * Gestión de Instrumentos.
 *
 * Este archivo reemplaza la versión anterior, que exponía datos para tres
 * widgets analíticos (préstamos por mes, ranking de instrumentos más
 * solicitados y actividad reciente). Esos widgets se retiraron del
 * dashboard porque no aportaban al flujo operativo diario del sistema
 * (gestión de préstamos); en su lugar, este endpoint expone los conteos
 * operativos que sí son útiles para el personal en el día a día.
 *
 * No expone un conteo de "préstamos activos" (operaciones): en la
 * práctica coincide casi siempre con "Actualmente asignados", el KPI de
 * instrumentos ya visible en la primera fila del dashboard, así que
 * mostrarlo aparte solo agregaba una tarjeta redundante sin una lectura
 * propia (ver revisión de UX del panel principal).
 *
 * No modifica ningún otro endpoint (instrumentos.php / control.php /
 * solicitudes.php / soporte.php), ni el esquema de base de datos, ni la
 * lógica de permisos. Reutiliza el mismo esquema de autenticación que el
 * resto de la API.
 *
 * Acciones soportadas:
 *   resumen (GET) -> préstamos vencidos y reportes de soporte
 *                    pendientes/en atención.
 *
 * Ya no expone el conteo de incidencias abiertas/en atención: el aviso de
 * incidencias del dashboard se retiró (ver revisión de UX del panel
 * principal) porque en la práctica casi siempre describía lo mismo que la
 * tarjeta "En mantenimiento", y los pocos casos donde sí divergía
 * (incidencia reportada durante un préstamo activo, o sin préstamo
 * asociado) siguen visibles y accionables desde la ficha del instrumento
 * en el Catálogo.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

if (!usuarioAutenticado()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesión no válida.']);
    exit;
}

// Endpoint de solo lectura (GET): csrfValidarOFallar() no exige token en
// peticiones GET, igual que en instrumentos.php y control.php.
csrfValidarOFallar();

$pdo = obtenerConexion();

// ---------- Préstamos vencidos ----------
// Misma definición de "vencido" que ya usa api/control.php: un instrumento
// en_uso, con solicitud activa y fecha_devolucion_esperada anterior a hoy.
$prestamosVencidos = (int)$pdo->query("
    SELECT COUNT(*)
    FROM instrumentos i
    JOIN solicitudes s ON s.id = (
        SELECT s2.id FROM solicitudes s2
        WHERE s2.instrumento_id = i.id
        ORDER BY s2.fecha_solicitud DESC LIMIT 1
    )
    WHERE i.activo = 1 AND i.estado = 'en_uso' AND s.estado = 'activo'
      AND s.fecha_devolucion_esperada IS NOT NULL
      AND s.fecha_devolucion_esperada < CURDATE()
")->fetchColumn();

// ---------- Reportes de soporte técnico pendientes o en atención ----------
$soportePendientes = (int)$pdo->query("
    SELECT COUNT(*) FROM reportes_soporte WHERE estado IN ('pendiente', 'en_atencion')
")->fetchColumn();

echo json_encode([
    'ok'                     => true,
    'prestamos_vencidos'     => $prestamosVencidos,
    'soporte_pendientes'     => $soportePendientes,
]);
