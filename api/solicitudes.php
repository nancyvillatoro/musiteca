<?php
/**
 * Endpoint AJAX para 'Solicitar instrumento' (individual o en préstamo
 * múltiple), 'Reportar problema' y el cierre (regreso/devolución) de
 * préstamos activos.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/servicios/prestamos_servicio.php';

if (!usuarioAutenticado()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesión no válida.']);
    exit;
}

csrfValidarOFallar();

$pdo    = obtenerConexion();
$accion = $_REQUEST['accion'] ?? '';

switch ($accion) {

    case 'crear_solicitud':
        // Flujo de un solo instrumento (se conserva tal cual para no romper
        // integraciones existentes). Internamente reutiliza registrarPrestamo()
        // con una lista de un elemento, así que el instrumento queda
        // registrado bajo una cabecera de préstamo igual que cualquier otro.
        $instrumentoId    = (int)($_POST['instrumento_id'] ?? 0);
        $solicitante      = trim($_POST['solicitante'] ?? '');
        $ubicacionDestino = trim($_POST['ubicacion_destino'] ?? '');
        $observaciones    = trim($_POST['observaciones'] ?? '');
        $fechaDevolucion  = calcularFechaDevolucion(trim($_POST['fecha_devolucion_esperada'] ?? ''));

        if (!$instrumentoId || $solicitante === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Instrumento y solicitante son obligatorios.']);
            break;
        }

        try {
            $resultado = registrarPrestamo($pdo, [$instrumentoId], $solicitante, $ubicacionDestino, $observaciones, $fechaDevolucion, $_SESSION['usuario_id'] ?? null);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo registrar la solicitud.']);
            break;
        }

        if (!$resultado['ok']) {
            http_response_code($resultado['codigoHttp'] ?? 400);
            echo json_encode(['ok' => false, 'error' => $resultado['error']]);
            break;
        }

        echo json_encode(['ok' => true, 'mensaje' => $resultado['mensaje']]);
        break;

    case 'crear_prestamo':
        // Préstamo múltiple: un solicitante, varios instrumentos, una sola
        // operación atómica. instrumento_ids[] llega como arreglo desde
        // FormData (un campo repetido); también se acepta una cadena
        // separada por comas por si algún cliente futuro la envía así.
        $instrumentoIdsCrudos = $_POST['instrumento_ids'] ?? [];
        if (is_string($instrumentoIdsCrudos)) {
            $instrumentoIdsCrudos = array_filter(array_map('trim', explode(',', $instrumentoIdsCrudos)), static fn($v) => $v !== '');
        }
        if (!is_array($instrumentoIdsCrudos)) {
            $instrumentoIdsCrudos = [];
        }

        $solicitante      = trim($_POST['solicitante'] ?? '');
        $ubicacionDestino = trim($_POST['ubicacion_destino'] ?? '');
        $observaciones    = trim($_POST['observaciones'] ?? '');
        $fechaDevolucion  = calcularFechaDevolucion(trim($_POST['fecha_devolucion_esperada'] ?? ''));

        try {
            $resultado = registrarPrestamo($pdo, $instrumentoIdsCrudos, $solicitante, $ubicacionDestino, $observaciones, $fechaDevolucion, $_SESSION['usuario_id'] ?? null);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo registrar el préstamo.']);
            break;
        }

        if (!$resultado['ok']) {
            http_response_code($resultado['codigoHttp'] ?? 400);
            echo json_encode(['ok' => false, 'error' => $resultado['error']]);
            break;
        }

        echo json_encode(['ok' => true, 'mensaje' => $resultado['mensaje'], 'prestamo_id' => $resultado['prestamo_id']]);
        break;

    case 'finalizar_solicitud':
        $solicitudId             = (int)($_POST['solicitud_id'] ?? 0);
        $condicionDevolucion     = trim($_POST['condicion_devolucion'] ?? '');
        $observacionesDevolucion = trim($_POST['observaciones_devolucion'] ?? '');
        $decisionIncidencia      = trim($_POST['decision_incidencia'] ?? '');

        try {
            $resultado = finalizarSolicitud($pdo, $solicitudId, $condicionDevolucion, $observacionesDevolucion, $decisionIncidencia);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo finalizar la solicitud.']);
            break;
        }

        if (!$resultado['ok']) {
            http_response_code($resultado['codigoHttp'] ?? 400);
            $respuesta = ['ok' => false, 'error' => $resultado['error']];
            if (!empty($resultado['requiere_decision_incidencia'])) {
                $respuesta['requiere_decision_incidencia'] = true;
            }
            echo json_encode($respuesta);
            break;
        }

        echo json_encode(['ok' => true, 'mensaje' => $resultado['mensaje']]);
        break;

    case 'crear_incidencia':
        $instrumentoId = (int)($_POST['instrumento_id'] ?? 0);
        $reportadoPor  = trim($_POST['reportado_por'] ?? '');
        $motivo        = trim($_POST['motivo'] ?? '');
        $ubicacion     = trim($_POST['ubicacion'] ?? '');
        $descripcion   = trim($_POST['descripcion'] ?? '');

        if (!$instrumentoId || $reportadoPor === '' || $motivo === '' || $descripcion === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Reportado por, motivo y descripción son obligatorios.']);
            break;
        }

        if (!in_array($motivo, MOTIVOS_INCIDENCIA, true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'El motivo seleccionado no es válido.']);
            break;
        }

        $pdo->beginTransaction();
        try {
            // Se bloquea la fila del instrumento para leer su estado real de
            // forma consistente con el resto de operaciones de escritura
            // (préstamo/devolución) y decidir con certeza si está prestado.
            $stmt = $pdo->prepare('SELECT estado FROM instrumentos WHERE id = ? AND activo = 1 FOR UPDATE');
            $stmt->execute([$instrumentoId]);
            $instrumento = $stmt->fetch();

            if (!$instrumento) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Instrumento no encontrado.']);
                break;
            }

            // Si el instrumento está actualmente prestado, el reporte se liga
            // a la solicitud activa y el préstamo continúa sin cambios: la
            // decisión de enviarlo (o no) a mantenimiento se toma hasta la
            // devolución, para no interrumpir un préstamo en curso ni generar
            // estados inconsistentes entre `solicitudes` e `instrumentos`.
            $solicitudActivaId = null;
            if ($instrumento['estado'] === 'en_uso') {
                $stmt = $pdo->prepare("
                    SELECT id FROM solicitudes
                    WHERE instrumento_id = ? AND estado = 'activo'
                    ORDER BY fecha_solicitud DESC LIMIT 1
                ");
                $stmt->execute([$instrumentoId]);
                $solicitudActivaId = $stmt->fetchColumn() ?: null;
            }

            $stmt = $pdo->prepare('
                INSERT INTO incidencias (instrumento_id, solicitud_id, reportado_por, motivo, ubicacion, descripcion, estado, creado_por)
                VALUES (?, ?, ?, ?, ?, ?, "abierta", ?)
            ');
            $stmt->execute([$instrumentoId, $solicitudActivaId, $reportadoPor, $motivo, $ubicacion, $descripcion, $_SESSION['usuario_id'] ?? null]);

            if ($solicitudActivaId) {
                // El préstamo sigue activo: no se toca el estado del instrumento.
                $mensaje = 'Incidencia registrada. El préstamo continúa activo; se pedirá una decisión (disponible o mantenimiento) al registrar la devolución.';
            } else {
                $stmt = $pdo->prepare('UPDATE instrumentos SET estado = "en_reparacion" WHERE id = ?');
                $stmt->execute([$instrumentoId]);
                $mensaje = 'Problema reportado. El instrumento se marcó "En mantenimiento".';
            }

            $pdo->commit();
            echo json_encode(['ok' => true, 'mensaje' => $mensaje]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo registrar el reporte.']);
        }
        break;

    case 'resolver_incidencia':
        $instrumentoId = (int)($_POST['instrumento_id'] ?? 0);

        if (!$instrumentoId) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Instrumento no válido.']);
            break;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('
                UPDATE incidencias SET estado = "resuelta"
                WHERE instrumento_id = ? AND estado != "resuelta"
            ');
            $stmt->execute([$instrumentoId]);

            $stmt = $pdo->prepare('UPDATE instrumentos SET estado = "disponible" WHERE id = ?');
            $stmt->execute([$instrumentoId]);

            $pdo->commit();
            echo json_encode(['ok' => true, 'mensaje' => 'El instrumento fue marcado como disponible nuevamente.']);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo actualizar el estado del instrumento.']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Acción no reconocida.']);
}
