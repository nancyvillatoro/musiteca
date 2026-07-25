<?php
/**
 * Cliente SMTP mínimo, escrito a mano con streams nativos de PHP
 * (stream_socket_client / stream_socket_enable_crypto), sin dependencias
 * externas (Composer/PHPMailer) — mismo criterio arquitectónico que
 * includes/xlsx_writer.php para generar .xlsx sin librerías de terceros.
 *
 * Soporta:
 *   - Conexión en texto plano + STARTTLS (puerto 587, típico) o
 *     SSL/TLS directo desde el inicio (puerto 465).
 *   - Autenticación AUTH LOGIN (usuario/contraseña en base64).
 *   - Un destinatario o varios, cuerpo en texto plano.
 *
 * No pretende ser un MTA completo: no maneja adjuntos, HTML, ni colas de
 * reintento. Es suficiente para avisos internos del sistema (vencimientos).
 */

/**
 * @param string[] $destinatarios Lista de correos a los que se enviará.
 * @return array{ok:bool, error?:string}
 */
function smtpEnviarCorreo(array $destinatarios, string $asunto, string $cuerpo): array
{
    $destinatarios = array_values(array_filter(array_map('trim', $destinatarios)));
    if (empty($destinatarios)) {
        return ['ok' => false, 'error' => 'No hay destinatarios para el envío.'];
    }
    if (!smtpConfigurado()) {
        return ['ok' => false, 'error' => 'El servidor de correo (SMTP) no está configurado.'];
    }

    $usarSslDirecto = SMTP_SEGURIDAD === 'ssl';
    $prefijo = $usarSslDirecto ? 'ssl://' : '';
    $contexto = stream_context_create([
        'ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
        ],
    ]);

    $socket = @stream_socket_client(
        "{$prefijo}" . SMTP_HOST . ':' . SMTP_PORT,
        $codigoError,
        $mensajeError,
        15,
        STREAM_CLIENT_CONNECT,
        $contexto
    );
    if (!$socket) {
        return ['ok' => false, 'error' => "No fue posible conectar al servidor SMTP: {$mensajeError}"];
    }
    stream_set_timeout($socket, 15);

    try {
        $respuesta = smtpLeerRespuesta($socket);
        if (!smtpCodigoEsperado($respuesta, '220')) {
            return ['ok' => false, 'error' => "El servidor SMTP no respondió correctamente al conectar: {$respuesta}"];
        }

        $nombreLocal = 'localhost';
        smtpEnviarComando($socket, "EHLO {$nombreLocal}");
        $respuesta = smtpLeerRespuesta($socket);
        if (!smtpCodigoEsperado($respuesta, '250')) {
            return ['ok' => false, 'error' => "El servidor SMTP rechazó el saludo EHLO: {$respuesta}"];
        }

        if (SMTP_SEGURIDAD === 'tls' && !$usarSslDirecto) {
            smtpEnviarComando($socket, 'STARTTLS');
            $respuesta = smtpLeerRespuesta($socket);
            if (!smtpCodigoEsperado($respuesta, '220')) {
                return ['ok' => false, 'error' => "El servidor SMTP rechazó STARTTLS: {$respuesta}"];
            }
            if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                return ['ok' => false, 'error' => 'No fue posible establecer la capa de cifrado TLS con el servidor SMTP.'];
            }
            // Tras STARTTLS hay que reiniciar la sesión con un nuevo EHLO.
            smtpEnviarComando($socket, "EHLO {$nombreLocal}");
            $respuesta = smtpLeerRespuesta($socket);
            if (!smtpCodigoEsperado($respuesta, '250')) {
                return ['ok' => false, 'error' => "El servidor SMTP rechazó el EHLO posterior a STARTTLS: {$respuesta}"];
            }
        }

        smtpEnviarComando($socket, 'AUTH LOGIN');
        $respuesta = smtpLeerRespuesta($socket);
        if (!smtpCodigoEsperado($respuesta, '334')) {
            return ['ok' => false, 'error' => "El servidor SMTP no admite autenticación AUTH LOGIN: {$respuesta}"];
        }

        smtpEnviarComando($socket, base64_encode(SMTP_USUARIO));
        $respuesta = smtpLeerRespuesta($socket);
        if (!smtpCodigoEsperado($respuesta, '334')) {
            return ['ok' => false, 'error' => "El servidor SMTP rechazó el usuario: {$respuesta}"];
        }

        smtpEnviarComando($socket, base64_encode(SMTP_PASSWORD));
        $respuesta = smtpLeerRespuesta($socket);
        if (!smtpCodigoEsperado($respuesta, '235')) {
            return ['ok' => false, 'error' => "El servidor SMTP rechazó las credenciales de autenticación: {$respuesta}"];
        }

        smtpEnviarComando($socket, 'MAIL FROM:<' . SMTP_REMITENTE_CORREO . '>');
        $respuesta = smtpLeerRespuesta($socket);
        if (!smtpCodigoEsperado($respuesta, '250')) {
            return ['ok' => false, 'error' => "El servidor SMTP rechazó el remitente: {$respuesta}"];
        }

        foreach ($destinatarios as $destinatario) {
            smtpEnviarComando($socket, "RCPT TO:<{$destinatario}>");
            $respuesta = smtpLeerRespuesta($socket);
            if (!smtpCodigoEsperado($respuesta, ['250', '251'])) {
                return ['ok' => false, 'error' => "El servidor SMTP rechazó al destinatario {$destinatario}: {$respuesta}"];
            }
        }

        smtpEnviarComando($socket, 'DATA');
        $respuesta = smtpLeerRespuesta($socket);
        if (!smtpCodigoEsperado($respuesta, '354')) {
            return ['ok' => false, 'error' => "El servidor SMTP rechazó el inicio de datos: {$respuesta}"];
        }

        $fecha = date('r');
        $nombreRemitente = smtpCodificarEncabezado(SMTP_REMITENTE_NOMBRE);
        $asuntoCodificado = smtpCodificarEncabezado($asunto);
        $cuerpoEscapado = str_replace("\n.", "\n..", $cuerpo); // "byte-stuffing" del protocolo SMTP

        $mensaje  = "Date: {$fecha}\r\n";
        $mensaje .= "From: {$nombreRemitente} <" . SMTP_REMITENTE_CORREO . ">\r\n";
        $mensaje .= 'To: ' . implode(', ', $destinatarios) . "\r\n";
        $mensaje .= "Subject: {$asuntoCodificado}\r\n";
        $mensaje .= "MIME-Version: 1.0\r\n";
        $mensaje .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $mensaje .= "Content-Transfer-Encoding: 8bit\r\n";
        $mensaje .= "\r\n";
        $mensaje .= $cuerpoEscapado;
        $mensaje .= "\r\n.";

        smtpEnviarComando($socket, $mensaje);
        $respuesta = smtpLeerRespuesta($socket);
        if (!smtpCodigoEsperado($respuesta, '250')) {
            return ['ok' => false, 'error' => "El servidor SMTP rechazó el mensaje: {$respuesta}"];
        }

        smtpEnviarComando($socket, 'QUIT');

        return ['ok' => true];
    } finally {
        fclose($socket);
    }
}

/** @param resource $socket */
function smtpEnviarComando($socket, string $comando): void
{
    fwrite($socket, $comando . "\r\n");
}

/** @param resource $socket */
function smtpLeerRespuesta($socket): string
{
    $lineas = '';
    while (($linea = fgets($socket, 515)) !== false) {
        $lineas .= $linea;
        // Una respuesta multilínea usa "-" tras el código; termina cuando
        // aparece un espacio (p. ej. "250 " en vez de "250-").
        if (isset($linea[3]) && $linea[3] === ' ') {
            break;
        }
    }
    return $lineas;
}

/**
 * @param string|string[] $codigosEsperados
 */
function smtpCodigoEsperado(string $respuesta, $codigosEsperados): bool
{
    $codigosEsperados = (array)$codigosEsperados;
    $codigo = substr($respuesta, 0, 3);
    return in_array($codigo, $codigosEsperados, true);
}

/** Codifica encabezados con caracteres no ASCII (acentos, ñ) según RFC 2047. */
function smtpCodificarEncabezado(string $texto): string
{
    if (preg_match('/^[\x20-\x7E]*$/', $texto)) {
        return $texto; // Solo ASCII imprimible: no requiere codificación
    }
    return '=?UTF-8?B?' . base64_encode($texto) . '?=';
}
