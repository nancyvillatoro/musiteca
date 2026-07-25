<?php
/**
 * Exporta el inventario completo en formato .xlsx real (Excel / OOXML).
 *
 * Antes este endpoint generaba un CSV: el manual de usuario indica de
 * forma explícita que el botón "Exportar Excel" debe entregar un archivo
 * .xlsx (sección 3.5). Se mantiene exactamente la misma consulta y el
 * mismo criterio de columnas; solo cambia la capa de serialización del
 * archivo, delegada a includes/xlsx_writer.php (sin librerías externas).
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/xlsx_writer.php';
// Restringido a administradores: el reporte completo del patrimonio es
// información sensible, igual que el resto de operaciones administrativas
// (alta/edición de instrumentos, gestión de usuarios, exportar Control).
requerirAdministrador();

$pdo = obtenerConexion();

$stmt = $pdo->query("
    SELECT i.num_inventario, i.num_inventario_anterior, i.nombre, i.marca, i.modelo, i.num_serie,
           COALESCE(u.nombre, 'Sin asignar') AS ubicacion, i.condicion, i.estado, i.actualizado_en
    FROM instrumentos i
    LEFT JOIN ubicaciones u ON u.id = i.ubicacion_id
    WHERE i.activo = 1
    ORDER BY i.num_inventario
");

$encabezados = [
    'N° Inventario', 'N° Inventario anterior', 'Instrumento', 'Marca', 'Modelo',
    'N° Serie', 'Ubicación', 'Condición', 'Estado', 'Última actualización',
];

$filas = [];
while ($fila = $stmt->fetch()) {
    $filas[] = [
        $fila['num_inventario'],
        $fila['num_inventario_anterior'],
        $fila['nombre'],
        $fila['marca'],
        $fila['modelo'],
        $fila['num_serie'],
        $fila['ubicacion'],
        ucfirst($fila['condicion']),
        str_replace('_', ' ', ucfirst($fila['estado'])),
        $fila['actualizado_en'],
    ];
}

exportarXlsx('inventario_musiteca_' . date('Ymd_His'), $encabezados, $filas, 'Inventario');
