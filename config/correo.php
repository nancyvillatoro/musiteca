<?php
/**
 * Configuración del envío de correo (avisos automáticos de préstamos
 * vencidos). Mismo criterio que config/database.php: las credenciales se
 * leen de variables de entorno del servidor, nunca quedan fijas en el
 * código fuente versionado.
 *
 * Si SMTP_HOST queda vacío (no se configuró ninguna variable de entorno),
 * el sistema sigue funcionando con normalidad: simplemente el script de
 * avisos (scripts/enviar_avisos_vencidos.php) informa que no hay servidor
 * de correo configurado en vez de intentar enviar y fallar a medias.
 *
 * Variables de entorno esperadas:
 *   MUSITECA_SMTP_HOST        Servidor SMTP, p. ej. "smtp.gmail.com"
 *   MUSITECA_SMTP_PORT        Puerto (587 = STARTTLS, 465 = SSL directo). Default: 587
 *   MUSITECA_SMTP_SEGURIDAD   "tls" (STARTTLS) | "ssl" (SSL directo) | "" (sin cifrar, no recomendado)
 *   MUSITECA_SMTP_USER        Usuario/cuenta con la que se autentica
 *   MUSITECA_SMTP_PASS        Contraseña (para Gmail, una "contraseña de aplicación", no la contraseña normal de la cuenta)
 *   MUSITECA_SMTP_FROM        Correo remitente que verán los destinatarios (por defecto, el mismo MUSITECA_SMTP_USER)
 *   MUSITECA_SMTP_FROM_NOMBRE Nombre visible del remitente (por defecto, APP_NOMBRE)
 */

require_once __DIR__ . '/constants.php';

define('SMTP_HOST', getenv('MUSITECA_SMTP_HOST') ?: '');
define('SMTP_PORT', (int)(getenv('MUSITECA_SMTP_PORT') ?: 587));
define('SMTP_SEGURIDAD', getenv('MUSITECA_SMTP_SEGURIDAD') ?: 'tls');
define('SMTP_USUARIO', getenv('MUSITECA_SMTP_USER') ?: '');
define('SMTP_PASSWORD', getenv('MUSITECA_SMTP_PASS') ?: '');
define('SMTP_REMITENTE_CORREO', getenv('MUSITECA_SMTP_FROM') ?: SMTP_USUARIO);
define('SMTP_REMITENTE_NOMBRE', getenv('MUSITECA_SMTP_FROM_NOMBRE') ?: APP_NOMBRE);

/** True solo si hay lo mínimo indispensable para intentar enviar correo. */
function smtpConfigurado(): bool
{
    return SMTP_HOST !== '' && SMTP_USUARIO !== '' && SMTP_PASSWORD !== '' && SMTP_REMITENTE_CORREO !== '';
}
