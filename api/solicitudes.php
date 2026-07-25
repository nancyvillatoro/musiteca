<?php
/**
 * Endpoint AJAX para 'Solicitar instrumento' (individual o en préstamo
 * múltiple), 'Reportar problema' y el cierre (regreso/devolución) de
 * préstamos activos.
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
$accion = $_REQUEST['accion'] ?? '';

/**
 * Calcula la fecha de devolución esperada a partir del valor recibido del
 * formulario, cayendo al plazo por defecto (PRESTAMO_DIAS_PLAZO_DEFAULT)
 * cuando no se indicó una fecha válida. Centraliza una regla que antes
 * solo vivía en 'crear_solicitud' para que también la use el préstamo
 * múltiple sin duplicar código (DRY).
 */
function calcularFechaDevolucion(string $fechaDevolucion): string
{
    if ($fechaDevolucion === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDevolucion)) {
        return date('Y-m-d', strtotime('+' . PRESTAMO_DIAS_PLAZO_DEFAULT . ' days'));
    }
    return $fechaDevolucion;
}

/**
 * Registra un préstamo (cabecera en `prestamos`) junto con el detalle de
 * cada instrumento incluido (una fila por instrumento en `solicitudes`),
 * en una única transacción atómica: o se registran todos los instrumentos
 * y se marcan como "en_uso", o no se registra ninguno.
 *
 * Es el punto único de escritura para "prestar instrumentos": tanto la
 * solicitud de un solo instrumento (flujo histórico) como el préstamo
 * múltiple nuevo pasan por aquí, evitando duplicar la validación de
 * disponibilidad y el manejo de la transacción (principio DRY / única
 * fuente de verdad para la integridad del préstamo).
 *
 * @param int[] $instrumentoIds IDs de instrumentos a incluir en el préstamo.
 * @return array{
 *   ok: bool,
 *   error?: string,
 *   codigoHttp?: int,
 *   mensaje?: string,
 *   prestamo_id?: int
 * }
 */
function registrarPrestamo(
    PDO $pdo,
    array $instrumentoIds,
    string $solicitante,
    string $ubicacionDestino,
    string $observaciones,
    string $fechaDevolucion,
    ?int $creadoPor
): array {
    $instrumentoIds = array_values(array_unique(array_filter(array_map('intval', $instrumentoIds))));

    if ($solicitante === '') {
        return ['ok' => false, 'error' => 'El solicitante es obligatorio.', 'codigoHttp' => 422];
    }
    if (empty($instrumentoIds)) {
        return ['ok' => false, 'error' => 'Agrega al menos un instrumento al préstamo.', 'codigoHttp' => 422];
    }

    $pdo->beginTransaction();
    try {
        // Bloquea las filas de TODOS los instrumentos del préstamo (FOR UPDATE)
        // antes de decidir nada: evita que dos préstamos concurrentes (doble
        // clic, dos operadores a la vez, etc.) puedan "ganar" el mismo
        // instrumento, igual que ya protegía el flujo de un solo instrumento.
        $marcadores = implode(',', array_fill(0, count($instrumentoIds), '?'));
        $stmt = $pdo->prepare("SELECT id, nombre, num_inventario, estado FROM instrumentos WHERE id IN ($marcadores) AND activo = 1 FOR UPDATE");
        $stmt->execute($instrumentoIds);

        $porId = [];
        foreach ($stmt->fetchAll() as $fila) {
            $porId[(int)$fila['id']] = $fila;
        }

        $faltantes = array_diff($instrumentoIds, array_keys($porId));
        if (!empty($faltantes)) {
            $pdo->rollBack();
            $mensaje = count($instrumentoIds) === 1
                ? 'Instrumento no encontrado.'
                : 'Uno o más instrumentos ya no existen en el catálogo.';
            return ['ok' => false, 'error' => $mensaje, 'codigoHttp' => 404];
        }

        $noDisponibles = array_filter($porId, static fn(array $i): bool => $i['estado'] !== 'disponible');
        if (!empty($noDisponibles)) {
            $pdo->rollBack();
            if (count($instrumentoIds) === 1) {
                $mensaje = 'El instrumento ya tiene un préstamo activo (o no está disponible) y no puede volver a prestarse hasta que se registre su devolución.';
            } else {
                $nombres = implode(', ', array_map(
                    static fn(array $i): string => "{$i['nombre']} (N° {$i['num_inventario']})",
                    $noDisponibles
                ));
                $mensaje = "Los siguientes instrumentos ya no están disponibles y se quitaron del préstamo: {$nombres}. Ningún instrumento fue registrado; revisa la lista e inténtalo de nuevo.";
            }
            return ['ok' => false, 'error' => $mensaje, 'codigoHttp' => 409];
        }

        $stmt = $pdo->prepare('
            INSERT INTO prestamos (solicitante, ubicacion_destino, observaciones, fecha_devolucion_esperada, creado_por)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$solicitante, $ubicacionDestino !== '' ? $ubicacionDestino : null, $observaciones !== '' ? $observaciones : null, $fechaDevolucion, $creadoPor]);
        $prestamoId = (int)$pdo->lastInsertId();

        $insertarDetalle = $pdo->prepare('
            INSERT INTO solicitudes (prestamo_id, instrumento_id, solicitante, ubicacion_destino, observaciones, estado, fecha_devolucion_esperada, creado_por)
            VALUES (?, ?, ?, ?, ?, "activo", ?, ?)
        ');
        $marcarEnUso = $pdo->prepare("UPDATE instrumentos SET estado = 'en_uso' WHERE id = ?");

        foreach ($instrumentoIds as $instrumentoId) {
            $insertarDetalle->execute([
                $prestamoId, $instrumentoId, $solicitante,
                $ubicacionDestino !== '' ? $ubicacionDestino : null,
                $observaciones !== '' ? $observaciones : null,
                $fechaDevolucion, $creadoPor,
            ]);
            $marcarEnUso->execute([$instrumentoId]);
        }

        $pdo->commit();

        $cantidad = count($instrumentoIds);
        $mensaje = $cantidad === 1
            ? 'Solicitud registrada. El instrumento quedó marcado como "Actualmente asignado".'
            : "Préstamo registrado con {$cantidad} instrumentos. Todos quedaron marcados como \"Actualmente asignado\".";

        return ['ok' => true, 'mensaje' => $mensaje, 'prestamo_id' => $prestamoId];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Recalcula y persiste el estado agregado de la cabecera `prestamos` a
 * partir de sus detalles en `solicitudes` (activo / parcial / finalizado).
 * Se llama después de finalizar cualquier detalle; si el detalle no
 * pertenece a un préstamo (registro histórico sin prestamo_id), no hace nada.
 */
function actualizarEstadoPrestamo(PDO $pdo, ?int $prestamoId): void
{
    if (!$prestamoId) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total, SUM(estado = 'finalizado') AS finalizados
        FROM solicitudes
        WHERE prestamo_id = ?
    ");
    $stmt->execute([$prestamoId]);
    $conteo = $stmt->fetch();

    $total       = (int)($conteo['total'] ?? 0);
    $finalizados = (int)($conteo['finalizados'] ?? 0);

    if ($total === 0) {
        return;
    }

    $nuevoEstado = 'activo';
    if ($finalizados === $total) {
        $nuevoEstado = 'finalizado';
    } elseif ($finalizados > 0) {
        $nuevoEstado = 'parcial';
    }

    $stmt = $pdo->prepare('UPDATE prestamos SET estado = ? WHERE id = ?');
    $stmt->execute([$nuevoEstado, $prestamoId]);
}

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

        if ($condicionDevolucion !== '' && !in_array($condicionDevolucion, CONDICIONES_DEVOLUCION, true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'El estado de devolución seleccionado no es válido.']);
            break;
        }

        if (in_array($condicionDevolucion, CONDICIONES_DEVOLUCION_CON_OBSERVACION_OBLIGATORIA, true) && $observacionesDevolucion === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Describe en observaciones el desgaste, daño o faltante encontrado al recibir el instrumento.']);
            break;
        }

        if ($decisionIncidencia !== '' && !in_array($decisionIncidencia, DECISIONES_DEVOLUCION_INCIDENCIA, true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'La decisión seleccionada para la incidencia no es válida.']);
            break;
        }

        $stmt = $pdo->prepare('SELECT instrumento_id, prestamo_id FROM solicitudes WHERE id = ?');
        $stmt->execute([$solicitudId]);
        $row = $stmt->fetch();

        if (!$row) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Solicitud no encontrada.']);
            break;
        }

        $pdo->beginTransaction();
        try {
            // Si el instrumento tiene incidencias reportadas durante este
            // préstamo y aún sin resolver, la devolución no se puede cerrar
            // "a ciegas": se exige antes una decisión explícita (disponible o
            // mantenimiento). El bloqueo de fila (FOR UPDATE) evita que dos
            // devoluciones concurrentes decidan de forma distinta sobre las
            // mismas incidencias.
            $stmt = $pdo->prepare("SELECT id FROM incidencias WHERE solicitud_id = ? AND estado = 'abierta' FOR UPDATE");
            $stmt->execute([$solicitudId]);
            $incidenciasAbiertas = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($incidenciasAbiertas) && $decisionIncidencia === '') {
                $pdo->rollBack();
                http_response_code(422);
                echo json_encode([
                    'ok' => false,
                    'error' => 'Este instrumento tiene una incidencia reportada durante el préstamo. Indica si el problema fue resuelto o si el instrumento debe enviarse a mantenimiento.',
                    'requiere_decision_incidencia' => true,
                ]);
                break;
            }

            $estadoInstrumentoFinal = 'disponible';

            if (!empty($incidenciasAbiertas)) {
                $enviarAMantenimiento = $decisionIncidencia === 'mantenimiento';
                $estadoInstrumentoFinal = $enviarAMantenimiento ? 'en_reparacion' : 'disponible';

                // Todas las incidencias abiertas de este préstamo se cierran con
                // la misma decisión: no tendría sentido devolver el instrumento
                // resolviendo solo una parte de lo reportado durante el mismo período.
                $stmt = $pdo->prepare("
                    UPDATE incidencias
                    SET estado = ?, decision_devolucion = ?, fecha_decision = NOW()
                    WHERE solicitud_id = ? AND estado = 'abierta'
                ");
                $stmt->execute([$enviarAMantenimiento ? 'en_atencion' : 'resuelta', $decisionIncidencia, $solicitudId]);
            }

            $stmt = $pdo->prepare('
                UPDATE solicitudes
                SET estado = "finalizado", fecha_regreso = NOW(),
                    condicion_devolucion = ?, observaciones_devolucion = ?
                WHERE id = ?
            ');
            $stmt->execute([
                $condicionDevolucion !== '' ? $condicionDevolucion : null,
                $observacionesDevolucion !== '' ? $observacionesDevolucion : null,
                $solicitudId,
            ]);

            $stmt = $pdo->prepare('UPDATE instrumentos SET estado = ? WHERE id = ?');
            $stmt->execute([$estadoInstrumentoFinal, $row['instrumento_id']]);

            actualizarEstadoPrestamo($pdo, $row['prestamo_id'] !== null ? (int)$row['prestamo_id'] : null);

            $pdo->commit();

            $mensaje = $estadoInstrumentoFinal === 'en_reparacion'
                ? 'Devolución registrada. El instrumento se envió a mantenimiento y no estará disponible para préstamo hasta que se resuelva la incidencia.'
                : 'El instrumento fue registrado como devuelto.';
            echo json_encode(['ok' => true, 'mensaje' => $mensaje]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo finalizar la solicitud.']);
        }
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
