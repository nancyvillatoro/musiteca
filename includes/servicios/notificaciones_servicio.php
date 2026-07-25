<?php
/**
 * Servicio de notificaciones por correo: aviso diario de préstamos
 * vencidos a los administradores del sistema.
 *
 * Diseño deliberadamente simple (consistente con el resto del sistema):
 * no persiste un flag "ya se avisó" en la base de datos. Cada ejecución
 * simplemente calcula qué está vencido *ahora mismo* (misma condición SQL
 * que ya usan api/control.php y api/dashboard_resumen.php) y envía un
 * resumen. Ejecutar el script una vez al día (cron) evita duplicar avisos
 * varias veces por el mismo préstamo en el mismo día.
 */

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/correo.php';
require_once __DIR__ . '/../correo_smtp.php';

/**
 * Préstamos actualmente vencidos: mismo criterio que el resto del sistema
 * (instrumento en uso, solicitud activa, fecha esperada de devolución ya
 * pasada).
 */
function notificacionesObtenerPrestamosVencidos(PDO $pdo): array
{
    $sql = "
        SELECT
            i.num_inventario,
            i.nombre AS instrumento,
            s.solicitante,
            s.fecha_devolucion_esperada,
            DATEDIFF(CURDATE(), s.fecha_devolucion_esperada) AS dias_vencido
        FROM solicitudes s
        JOIN instrumentos i ON i.id = s.instrumento_id
        WHERE i.estado = 'en_uso'
          AND s.estado = 'activo'
          AND s.fecha_devolucion_esperada IS NOT NULL
          AND s.fecha_devolucion_esperada < CURDATE()
        ORDER BY s.fecha_devolucion_esperada ASC
    ";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll();
}

/**
 * Correos de administradores activos con dirección registrada. La usuaria
 * configura el suyo desde Gestión de usuarios; el código nunca contiene un
 * correo fijo.
 *
 * @return string[]
 */
function notificacionesObtenerCorreosAdministradores(PDO $pdo): array
{
    $stmt = $pdo->prepare("
        SELECT correo FROM usuarios
        WHERE rol = ? AND activo = 1 AND correo IS NOT NULL AND correo != ''
    ");
    $stmt->execute([ROL_ADMINISTRADOR]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function notificacionesComponerCuerpo(array $vencidos): string
{
    $lineas   = [];
    $lineas[] = 'Aviso automático de ' . APP_NOMBRE . ' — préstamos vencidos';
    $lineas[] = str_repeat('-', 60);
    $lineas[] = '';
    $lineas[] = count($vencidos) === 1
        ? 'Hay 1 préstamo con la fecha de devolución vencida:'
        : 'Hay ' . count($vencidos) . ' préstamos con la fecha de devolución vencida:';
    $lineas[] = '';

    foreach ($vencidos as $fila) {
        $dias = (int)$fila['dias_vencido'];
        $lineas[] = sprintf(
            '- [%s] %s — a cargo de %s. Debía devolverse el %s (%d día%s de retraso).',
            $fila['num_inventario'],
            $fila['instrumento'],
            $fila['solicitante'],
            $fila['fecha_devolucion_esperada'],
            $dias,
            $dias === 1 ? '' : 's'
        );
    }

    $lineas[] = '';
    $lineas[] = 'Este es un aviso generado automáticamente. Consulta el módulo de Movimientos en Musiteca para dar seguimiento.';

    return implode("\n", $lineas);
}

/**
 * Orquesta todo el proceso: consulta vencidos, consulta destinatarios,
 * compone y envía. Pensado para ser llamado desde un script de línea de
 * comandos (cron).
 *
 * @return array{ok:bool, error?:string, enviado?:bool, totalVencidos?:int, destinatarios?:string[]}
 */
function notificacionesEnviarAvisoVencidos(PDO $pdo): array
{
    $vencidos = notificacionesObtenerPrestamosVencidos($pdo);
    if (empty($vencidos)) {
        return ['ok' => true, 'enviado' => false, 'totalVencidos' => 0, 'destinatarios' => []];
    }

    $destinatarios = notificacionesObtenerCorreosAdministradores($pdo);
    if (empty($destinatarios)) {
        return [
            'ok' => false,
            'error' => 'Hay préstamos vencidos, pero ningún administrador activo tiene un correo registrado. Configúralo desde Gestión de usuarios.',
            'totalVencidos' => count($vencidos),
        ];
    }

    if (!smtpConfigurado()) {
        return [
            'ok' => false,
            'error' => 'Hay préstamos vencidos, pero el servidor de correo (SMTP) no está configurado. Define las variables de entorno MUSITECA_SMTP_*.',
            'totalVencidos' => count($vencidos),
            'destinatarios' => $destinatarios,
        ];
    }

    $asunto = count($vencidos) === 1
        ? '[' . APP_NOMBRE . '] 1 préstamo vencido'
        : '[' . APP_NOMBRE . '] ' . count($vencidos) . ' préstamos vencidos';
    $cuerpo = notificacionesComponerCuerpo($vencidos);

    $resultadoEnvio = smtpEnviarCorreo($destinatarios, $asunto, $cuerpo);
    if (!$resultadoEnvio['ok']) {
        return [
            'ok' => false,
            'error' => $resultadoEnvio['error'],
            'totalVencidos' => count($vencidos),
            'destinatarios' => $destinatarios,
        ];
    }

    return [
        'ok' => true,
        'enviado' => true,
        'totalVencidos' => count($vencidos),
        'destinatarios' => $destinatarios,
    ];
}
