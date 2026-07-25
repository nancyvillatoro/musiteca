<?php
/**
 * Crea (o resetea la contraseña de) un usuario administrador desde línea
 * de comandos. Nunca debe ejecutarse a través del navegador: por eso
 * verifica que corre bajo el SAPI "cli" y además queda bloqueado por
 * .htaccess si alguien intentara pedirlo por HTTP.
 *
 * Uso:
 *   php scripts/crear_admin.php
 *   php scripts/crear_admin.php --usuario=admin --nombre="Nombre Apellido" --correo=correo@dominio.mx
 *
 * La contraseña siempre se solicita de forma interactiva (nunca por
 * argumento de línea de comandos, para que no quede en el historial de
 * la shell ni en logs de procesos).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo puede ejecutarse desde línea de comandos.');
}

require_once __DIR__ . '/../config/database.php';

function leerArgumento(array $argv, string $nombre, ?string $default = null): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$nombre}=")) {
            return substr($arg, strlen("--{$nombre}="));
        }
    }
    return $default;
}

function preguntar(string $etiqueta, ?string $default = null): string
{
    $sufijo = $default !== null ? " [{$default}]" : '';
    fwrite(STDOUT, "{$etiqueta}{$sufijo}: ");
    $respuesta = trim((string)fgets(STDIN));
    return $respuesta === '' ? ($default ?? '') : $respuesta;
}

function preguntarPassword(string $etiqueta): string
{
    fwrite(STDOUT, "{$etiqueta}: ");
    if (stripos(PHP_OS, 'WIN') === 0) {
        // No hay forma nativa de ocultar la entrada en Windows sin extensiones extra.
        return trim((string)fgets(STDIN));
    }
    system('stty -echo');
    $password = trim((string)fgets(STDIN));
    system('stty echo');
    fwrite(STDOUT, PHP_EOL);
    return $password;
}

$usuario = leerArgumento($argv, 'usuario') ?: preguntar('Usuario (login)', 'admin');
$nombre  = leerArgumento($argv, 'nombre') ?: preguntar('Nombre completo', 'Administrador Musiteca');
$correo  = leerArgumento($argv, 'correo') ?: preguntar('Correo electrónico (opcional)', '');

if (!preg_match('/^[a-zA-Z0-9._-]+$/', $usuario) || musitecaLongitudTexto($usuario) < 3) {
    fwrite(STDERR, "Error: el usuario debe tener al menos 3 caracteres y solo letras, números, puntos, guiones y guiones bajos.\n");
    exit(1);
}

$password  = preguntarPassword('Contraseña (mínimo 8 caracteres)');
$password2 = preguntarPassword('Confirma la contraseña');

if ($password !== $password2) {
    fwrite(STDERR, "Error: las contraseñas no coinciden.\n");
    exit(1);
}
if (musitecaLongitudTexto($password) < 8) {
    fwrite(STDERR, "Error: la contraseña debe tener al menos 8 caracteres.\n");
    exit(1);
}

$pdo = obtenerConexion();

$stmt = $pdo->prepare('SELECT id FROM usuarios WHERE usuario = ?');
$stmt->execute([$usuario]);
$existente = $stmt->fetch();

$hash = password_hash($password, PASSWORD_BCRYPT);

if ($existente) {
    $stmt = $pdo->prepare('UPDATE usuarios SET password_hash = ?, nombre_completo = ?, correo = ?, rol = ?, activo = 1 WHERE id = ?');
    $stmt->execute([$hash, $nombre, $correo ?: null, ROL_ADMINISTRADOR, $existente['id']]);
    fwrite(STDOUT, "Listo: se actualizó la contraseña del usuario '{$usuario}' (rol administrador).\n");
} else {
    $stmt = $pdo->prepare('INSERT INTO usuarios (usuario, correo, password_hash, nombre_completo, rol, activo) VALUES (?, ?, ?, ?, ?, 1)');
    $stmt->execute([$usuario, $correo ?: null, $hash, $nombre, ROL_ADMINISTRADOR]);
    fwrite(STDOUT, "Listo: se creó el usuario administrador '{$usuario}'.\n");
}
