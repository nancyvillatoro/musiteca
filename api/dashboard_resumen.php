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
 *   resumen (GET) -> préstamos vencidos, incidencias abiertas/en atención
 *                    y reportes de soporte pendientes/en atención.
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

// ---------- Incidencias abiertas o en atención (pendientes) ----------
$incidenciasPendientes = (int)$pdo->query("
    SELECT COUNT(*) FROM incidencias WHERE estado IN ('abierta', 'en_atencion')
")->fetchColumn();

// Detalle breve (máx. 5, más recientes primero) de esas incidencias, con el
// instrumento al que pertenecen. Solo lectura: no agrega ni modifica reglas
// de negocio, únicamente le da un destino concreto al aviso del dashboard,
// que antes mostraba el conteo sin forma de llegar al detalle.
$incidenciasDetalle = $pdo->query("
    SELECT inc.id, inc.motivo, inc.creado_en,
           i.id AS instrumento_id, i.num_inventario, i.nombre
    FROM incidencias inc
    JOIN instrumentos i ON i.id = inc.instrumento_id
    WHERE inc.estado IN ('abierta', 'en_atencion')
    ORDER BY inc.creado_en DESC
    LIMIT 5
")->fetchAll();

// ---------- Reportes de soporte técnico pendientes o en atención ----------
$soportePendientes = (int)$pdo->query("
    SELECT COUNT(*) FROM reportes_soporte WHERE estado IN ('pendiente', 'en_atencion')
")->fetchColumn();

echo json_encode([
    'ok'                     => true,
    'prestamos_vencidos'     => $prestamosVencidos,
    'incidencias_pendientes' => $incidenciasPendientes,
    'incidencias_detalle'    => $incidenciasDetalle,
    'soporte_pendientes'     => $soportePendientes,
]);
