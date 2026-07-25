<?php
/**
 * Endpoint AJAX para el módulo de Soporte técnico.
 * Acciones:
 *   listar (GET)  -> últimos reportes registrados
 *   crear  (POST) -> registra un nuevo reporte, con captura de pantalla opcional
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

try {
    switch ($accion) {

        case 'listar':
            $stmt = $pdo->query("
                SELECT r.id, r.modulo, r.num_inventario, r.descripcion, r.captura_archivo,
                       r.estado, r.creado_en, u.nombre_completo AS creado_por_nombre
                FROM reportes_soporte r
                LEFT JOIN usuarios u ON u.id = r.creado_por
                ORDER BY r.creado_en DESC
                LIMIT 20
            ");
            echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
            break;

        case 'crear':
            $modulo        = $_POST['modulo'] ?? '';
            $numInventario = trim($_POST['num_inventario'] ?? '');
            $descripcion   = trim($_POST['descripcion'] ?? '');

            if (!in_array($modulo, MODULOS_SOPORTE, true) || $descripcion === '') {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'El módulo afectado y la descripción son obligatorios.']);
                break;
            }

            // ---------- Captura de pantalla (opcional) ----------
            $nombreArchivo = null;

            if (!empty($_FILES['captura']['name']) && $_FILES['captura']['error'] === UPLOAD_ERR_OK) {
                $tmpPath = $_FILES['captura']['tmp_name'];

                if ($_FILES['captura']['size'] > SOPORTE_UPLOAD_TAMANO_MAXIMO) {
                    http_response_code(422);
                    echo json_encode(['ok' => false, 'error' => 'La imagen no debe superar 5 MB.']);
                    break;
                }

                // Verifica el tipo de archivo real leyendo su contenido
                // (magic bytes), no la extensión del nombre enviado por el
                // cliente ni el Content-Type declarado por el navegador:
                // ambos pueden falsificarse fácilmente. Antes solo se
                // validaba la extensión del nombre de archivo, lo que
                // permitía subir cualquier binario renombrado con
                // extensión ".jpg" y que igual se guardara en el servidor.
                $mimeReal = false;
                if (function_exists('finfo_open')) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    if ($finfo) {
                        $mimeReal = finfo_file($finfo, $tmpPath);
                        finfo_close($finfo);
                    }
                }

                if ($mimeReal === false || !isset(SOPORTE_UPLOAD_MIME_PERMITIDOS[$mimeReal])) {
                    http_response_code(422);
                    echo json_encode(['ok' => false, 'error' => 'La captura debe ser una imagen válida (jpg, png, gif o webp).']);
                    break;
                }

                // La extensión del archivo guardado se deriva del tipo MIME
                // real detectado, no del nombre que envió el cliente.
                $extension = SOPORTE_UPLOAD_MIME_PERMITIDOS[$mimeReal];

                $directorioDestino = SOPORTE_UPLOAD_DIR;
                if (!is_dir($directorioDestino)) {
                    mkdir($directorioDestino, 0755, true);
                }

                $nombreArchivo = 'soporte_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                $rutaDestino = $directorioDestino . $nombreArchivo;

                if (!move_uploaded_file($tmpPath, $rutaDestino)) {
                    http_response_code(500);
                    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar la imagen adjunta.']);
                    break;
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO reportes_soporte (modulo, num_inventario, descripcion, captura_archivo, creado_por)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$modulo, $numInventario ?: null, $descripcion, $nombreArchivo, $_SESSION['usuario_id']]);

            echo json_encode([
                'ok' => true,
                'mensaje' => 'Reporte enviado al área de Desarrollo de Sistemas.',
                'id' => (int)$pdo->lastInsertId(),
            ]);
            break;

        case 'actualizar_estado':
            requerirAdministrador();
            $id = (int)($_POST['id'] ?? 0);
            $estado = $_POST['estado'] ?? '';

            if (!$id || !in_array($estado, ['pendiente', 'en_atencion', 'resuelto'], true)) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'ID o estado no válido.']);
                break;
            }

            $stmt = $pdo->prepare("UPDATE reportes_soporte SET estado = ? WHERE id = ?");
            $stmt->execute([$estado, $id]);

            echo json_encode([
                'ok' => true,
                'mensaje' => 'El estado del reporte ha sido actualizado.',
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Acción no reconocida.']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    $mensaje = (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), '1146') !== false)
        ? 'La tabla "reportes_soporte" no existe todavía. Ejecuta database/migracion_reportes_soporte.sql en phpMyAdmin.'
        : 'Error de base de datos al procesar el reporte.';
    echo json_encode(['ok' => false, 'error' => $mensaje]);
}
