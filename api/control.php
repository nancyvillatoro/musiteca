<?php
/**
 * Endpoint AJAX para el módulo de Control.
 * Muestra el estado logístico de cada instrumento, cruzando con la
 * solicitud de préstamo activa (si existe).
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

if (!usuarioAutenticado()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesión no válida.']);
    exit;
}

csrfValidarOFallar();

$pdo    = obtenerConexion();
$accion = $_REQUEST['accion'] ?? 'listar';

if ($accion === 'listar') {
    $buscar       = trim($_GET['buscar'] ?? '');
    $estado       = $_GET['estado'] ?? '';
    $fechaInicio  = $_GET['fecha_inicio'] ?? '';
    $fechaFin     = $_GET['fecha_fin'] ?? '';
    $pagina       = max(1, (int)($_GET['pagina'] ?? 1));
    // Ver nota equivalente en api/instrumentos.php: se usa la constante
    // PAGINACION_POR_PAGINA (config/constants.php) en vez de un "10" fijo
    // duplicado en cada endpoint.
    $porPagina    = PAGINACION_POR_PAGINA;
    $offset       = ($pagina - 1) * $porPagina;

    $condiciones = ['i.activo = 1'];
    $params      = [];

    if ($buscar !== '') {
        $condiciones[] = '(i.nombre LIKE ? OR i.num_inventario LIKE ? OR s.solicitante LIKE ? OR u.nombre LIKE ?)';
        $like = "%{$buscar}%";
        array_push($params, $like, $like, $like, $like);
    }
    if ($estado === 'vencido') {
        // "Vencido" no es un estado propio del instrumento sino un préstamo
        // activo cuya fecha de devolución esperada ya pasó.
        $condiciones[] = "i.estado = 'en_uso' AND s.estado = 'activo' AND s.fecha_devolucion_esperada IS NOT NULL AND s.fecha_devolucion_esperada < CURDATE()";
    } elseif ($estado !== '') {
        $condiciones[] = 'i.estado = ?';
        $params[] = $estado;
    }
    if ($fechaInicio !== '') {
        $condiciones[] = 'DATE(COALESCE(s.fecha_solicitud, i.actualizado_en)) >= ?';
        $params[] = $fechaInicio;
    }
    if ($fechaFin !== '') {
        $condiciones[] = 'DATE(COALESCE(s.fecha_solicitud, i.actualizado_en)) <= ?';
        $params[] = $fechaFin;
    }

    $where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

    // Última solicitud activa por instrumento, y su incidencia abierta más
    // reciente (si existe): un instrumento puede reportarse aunque siga
    // prestado, así que el listado necesita mostrar ese aviso sin alterar la
    // consulta base de préstamos.
    $baseFrom = "
        FROM instrumentos i
        LEFT JOIN ubicaciones u ON u.id = i.ubicacion_id
        LEFT JOIN solicitudes s ON s.id = (
            SELECT s2.id FROM solicitudes s2
            WHERE s2.instrumento_id = i.id
            ORDER BY s2.fecha_solicitud DESC LIMIT 1
        )
        LEFT JOIN incidencias inc ON inc.id = (
            SELECT ic.id FROM incidencias ic
            WHERE ic.solicitud_id = s.id AND ic.estado = 'abierta'
            ORDER BY ic.creado_en DESC LIMIT 1
        )
        $where
    ";

    $stmt = $pdo->prepare("SELECT COUNT(*) $baseFrom");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $sql = "
        SELECT i.id, i.num_inventario, i.nombre, i.estado,
               COALESCE(u.nombre, 'Sin asignar') AS ubicacion_base,
               s.id AS solicitud_id, s.solicitante, s.ubicacion_destino, s.fecha_solicitud,
               s.fecha_devolucion_esperada, s.estado AS solicitud_estado,
               (i.estado = 'en_uso' AND s.estado = 'activo' AND s.fecha_devolucion_esperada IS NOT NULL
                    AND s.fecha_devolucion_esperada < CURDATE()) AS vencido,
               (SELECT COUNT(*) FROM incidencias ic2 WHERE ic2.solicitud_id = s.id AND ic2.estado = 'abierta') AS incidencias_abiertas,
               inc.motivo AS incidencia_motivo,
               inc.descripcion AS incidencia_descripcion,
               inc.reportado_por AS incidencia_reportado_por
        $baseFrom
        ORDER BY i.actualizado_en DESC
        LIMIT $porPagina OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $datos = $stmt->fetchAll();

    echo json_encode([
        'ok'           => true,
        'datos'        => $datos,
        'total'        => $total,
        'pagina'       => $pagina,
        'porPagina'    => $porPagina,
        'totalPaginas' => (int)ceil($total / $porPagina),
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Acción no reconocida.']);
