<?php
/**
 * Exporta el histórico de movimientos (préstamos e incidencias) aplicando
 * los criterios opcionales de filtrado por estado y/o rango de fechas, en
 * formato .xlsx real (ver includes/xlsx_writer.php: el manual de usuario,
 * sección 3.5, especifica que "Exportar Control Excel" debe entregar un
 * archivo .xlsx, no un .csv). La consulta y los filtros no cambian.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/xlsx_writer.php';
// Exportar el histórico completo de movimientos revela datos de todos los
// solicitantes y ubicaciones de destino: se restringe a administradores,
// igual que el resto de operaciones sensibles del sistema (alta/edición de
// instrumentos, gestión de usuarios).
requerirAdministrador();

$pdo = obtenerConexion();

$estado      = $_GET['estado'] ?? '';
$fechaInicio = $_GET['fecha_inicio'] ?? '';
$fechaFin    = $_GET['fecha_fin'] ?? '';

$condiciones = ['i.activo = 1'];
$params      = [];

if ($estado === 'vencido') {
    $condiciones[] = "i.estado = 'en_uso' AND s.estado = 'activo' AND s.fecha_devolucion_esperada IS NOT NULL AND s.fecha_devolucion_esperada < CURDATE()";
} elseif ($estado !== '') {
    $condiciones[] = 'i.estado = ?';
    $params[] = $estado;
}
if ($fechaInicio !== '') {
    $condiciones[] = 'DATE(s.fecha_solicitud) >= ?';
    $params[] = $fechaInicio;
}
if ($fechaFin !== '') {
    $condiciones[] = 'DATE(s.fecha_solicitud) <= ?';
    $params[] = $fechaFin;
}

$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

$sql = "
    SELECT i.num_inventario, i.nombre, i.estado,
           s.solicitante, s.ubicacion_destino, s.fecha_solicitud, s.fecha_devolucion_esperada,
           s.fecha_regreso, s.estado AS estado_solicitud,
           (i.estado = 'en_uso' AND s.estado = 'activo' AND s.fecha_devolucion_esperada IS NOT NULL
                AND s.fecha_devolucion_esperada < CURDATE()) AS vencido
    FROM solicitudes s
    INNER JOIN instrumentos i ON i.id = s.instrumento_id
    $where
    ORDER BY s.fecha_solicitud DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$encabezados = [
    'N° Inventario', 'Instrumento', 'Estado actual', 'Solicitante',
    'Ubicación destino', 'Fecha de solicitud', 'Devolución esperada', 'Fecha de regreso', 'Estado del préstamo',
];

$filas = [];
while ($fila = $stmt->fetch()) {
    $estadoPrestamo = (int)$fila['vencido'] === 1 ? 'Vencido' : ucfirst($fila['estado_solicitud']);
    $filas[] = [
        $fila['num_inventario'],
        $fila['nombre'],
        str_replace('_', ' ', ucfirst($fila['estado'])),
        $fila['solicitante'],
        $fila['ubicacion_destino'],
        $fila['fecha_solicitud'],
        $fila['fecha_devolucion_esperada'] ?? '—',
        $fila['fecha_regreso'] ?? '—',
        $estadoPrestamo,
    ];
}

exportarXlsx('control_musiteca_' . date('Ymd_His'), $encabezados, $filas, 'Control');
