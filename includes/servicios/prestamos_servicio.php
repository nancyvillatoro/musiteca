<?php
/**
 * Servicio de Préstamos y devoluciones.
 *
 * Concentra la lógica de negocio de "prestar instrumentos" (individual o
 * múltiple) y "cerrar un préstamo" (registrar la devolución), para que
 * api/solicitudes.php actúe únicamente como controlador: traduce la
 * petición HTTP a estas funciones y su resultado (array) a una respuesta
 * JSON. Mismo patrón que includes/servicios/usuarios_servicio.php.
 *
 * Extraer esta lógica a un archivo aparte (sin ningún `echo` ni
 * `http_response_code` dentro) es lo que permite probarla con
 * scripts/pruebas/test_prestamos_servicio.php sin necesitar una petición
 * HTTP real: el script solo necesita un PDO conectado a una base de datos
 * de pruebas y puede invocar registrarPrestamo() / finalizarSolicitud()
 * directamente, revisando después el estado que quedó en las tablas.
 */

require_once __DIR__ . '/../../config/constants.php';

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

/**
 * Cierra (finaliza) una solicitud de préstamo activa: registra el estado de
 * entrega del instrumento, resuelve cualquier incidencia abierta ligada a
 * ese préstamo según la decisión indicada, actualiza el estado del
 * instrumento (disponible o en_reparacion) y recalcula el estado agregado
 * de la cabecera de préstamo (ver actualizarEstadoPrestamo()).
 *
 * Extraído tal cual vivía en el case 'finalizar_solicitud' de
 * api/solicitudes.php (incluida la validación de entrada), para que
 * api/solicitudes.php quede como un controlador delgado y esta función se
 * pueda probar de forma aislada.
 *
 * @return array{
 *   ok: bool,
 *   error?: string,
 *   codigoHttp?: int,
 *   mensaje?: string,
 *   requiere_decision_incidencia?: bool
 * }
 */
function finalizarSolicitud(
    PDO $pdo,
    int $solicitudId,
    string $condicionDevolucion,
    string $observacionesDevolucion,
    string $decisionIncidencia
): array {
    if ($condicionDevolucion !== '' && !in_array($condicionDevolucion, CONDICIONES_DEVOLUCION, true)) {
        return ['ok' => false, 'error' => 'El estado de devolución seleccionado no es válido.', 'codigoHttp' => 422];
    }

    if (in_array($condicionDevolucion, CONDICIONES_DEVOLUCION_CON_OBSERVACION_OBLIGATORIA, true) && $observacionesDevolucion === '') {
        return ['ok' => false, 'error' => 'Describe en observaciones el desgaste, daño o faltante encontrado al recibir el instrumento.', 'codigoHttp' => 422];
    }

    if ($decisionIncidencia !== '' && !in_array($decisionIncidencia, DECISIONES_DEVOLUCION_INCIDENCIA, true)) {
        return ['ok' => false, 'error' => 'La decisión seleccionada para la incidencia no es válida.', 'codigoHttp' => 422];
    }

    $stmt = $pdo->prepare('SELECT instrumento_id, prestamo_id FROM solicitudes WHERE id = ?');
    $stmt->execute([$solicitudId]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['ok' => false, 'error' => 'Solicitud no encontrada.', 'codigoHttp' => 404];
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
            return [
                'ok' => false,
                'error' => 'Este instrumento tiene una incidencia reportada durante el préstamo. Indica si el problema fue resuelto o si el instrumento debe enviarse a mantenimiento.',
                'codigoHttp' => 422,
                'requiere_decision_incidencia' => true,
            ];
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

        return ['ok' => true, 'mensaje' => $mensaje];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
