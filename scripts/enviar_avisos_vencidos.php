<?php
/**
 * Envía (si corresponde) un correo de aviso a los administradores con el
 * resumen de préstamos actualmente vencidos. Pensado para ejecutarse una
 * vez al día vía cron; no hace daño si se corre más de una vez el mismo
 * día (simplemente reenvía el resumen actualizado), pero para evitar
 * spam se recomienda una sola ejecución diaria.
 *
 * Uso manual:
 *   php scripts/enviar_avisos_vencidos.php
 *
 * Ejemplo de crontab (todos los días a las 8:00 am, hora del servidor):
 *   0 8 * * * /usr/bin/php /ruta/a/musiteca/scripts/enviar_avisos_vencidos.php >> /ruta/a/musiteca/logs/avisos_vencidos.log 2>&1
 *
 * Requiere que el usuario administrador tenga un correo configurado
 * (Gestión de usuarios) y que estén definidas las variables de entorno
 * MUSITECA_SMTP_HOST, MUSITECA_SMTP_USER, MUSITECA_SMTP_PASS (ver
 * config/correo.php para la lista completa y sus valores por defecto).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo puede ejecutarse desde línea de comandos.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/correo.php';
require_once __DIR__ . '/../includes/servicios/notificaciones_servicio.php';

$pdo = obtenerConexion();
$resultado = notificacionesEnviarAvisoVencidos($pdo);

$marcaTiempo = date('Y-m-d H:i:s');

if (!$resultado['ok']) {
    fwrite(STDERR, "[{$marcaTiempo}] Error: {$resultado['error']}\n");
    exit(1);
}

if (!$resultado['enviado']) {
    fwrite(STDOUT, "[{$marcaTiempo}] No hay préstamos vencidos. No se envió ningún correo.\n");
    exit(0);
}

$destinatarios = implode(', ', $resultado['destinatarios']);
fwrite(STDOUT, "[{$marcaTiempo}] Aviso enviado: {$resultado['totalVencidos']} préstamo(s) vencido(s) → {$destinatarios}\n");
exit(0);
