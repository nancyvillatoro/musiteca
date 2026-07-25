<?php
/**
 * Servicio de Gestión de usuarios.
 *
 * Concentra toda la lógica de negocio (validaciones, reglas de acceso,
 * hashing de contraseñas) para que api/usuarios.php actúe únicamente como
 * controlador: recibe la petición, delega aquí y traduce el resultado a
 * una respuesta JSON.
 */

require_once __DIR__ . '/../../config/constants.php';

const USUARIOS_PASSWORD_MIN_LONGITUD = 8;
const USUARIOS_POR_PAGINA = 10;

/**
 * @return array{ok:bool, error?:string}
 */
function usuariosValidarDatos(PDO $pdo, array $datos, ?int $idExcluir = null, bool $passwordObligatorio = true): array
{
    $usuario = trim($datos['usuario'] ?? '');
    $nombre  = trim($datos['nombre_completo'] ?? '');
    $correo  = trim($datos['correo'] ?? '');
    $rol     = $datos['rol'] ?? '';
    $password = $datos['password'] ?? '';

    if (musitecaLongitudTexto($usuario) < 3) {
        return ['ok' => false, 'error' => 'El nombre de usuario debe tener al menos 3 caracteres.'];
    }
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $usuario)) {
        return ['ok' => false, 'error' => 'El nombre de usuario solo puede contener letras, números, puntos, guiones y guiones bajos.'];
    }
    if ($nombre === '') {
        return ['ok' => false, 'error' => 'El nombre completo es obligatorio.'];
    }
    if (!in_array($rol, ROLES_VALIDOS, true)) {
        return ['ok' => false, 'error' => 'El rol seleccionado no es válido.'];
    }
    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'El correo electrónico no tiene un formato válido.'];
    }
    if (($passwordObligatorio || $password !== '') && musitecaLongitudTexto($password) < USUARIOS_PASSWORD_MIN_LONGITUD) {
        return ['ok' => false, 'error' => 'La contraseña debe tener al menos ' . USUARIOS_PASSWORD_MIN_LONGITUD . ' caracteres.'];
    }

    $sql = 'SELECT id FROM usuarios WHERE usuario = ?';
    $params = [$usuario];
    if ($idExcluir) {
        $sql .= ' AND id != ?';
        $params[] = $idExcluir;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($stmt->fetch()) {
        return ['ok' => false, 'error' => 'Ya existe un usuario registrado con ese nombre de usuario.'];
    }

    if ($correo !== '') {
        $sql = 'SELECT id FROM usuarios WHERE correo = ?';
        $params = [$correo];
        if ($idExcluir) {
            $sql .= ' AND id != ?';
            $params[] = $idExcluir;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetch()) {
            return ['ok' => false, 'error' => 'Ya existe un usuario registrado con ese correo electrónico.'];
        }
    }

    return ['ok' => true];
}

function usuariosContarAdministradoresActivos(PDO $pdo, ?int $excluirId = null): int
{
    $sql = "SELECT COUNT(*) FROM usuarios WHERE rol = ? AND activo = 1";
    $params = [ROL_ADMINISTRADOR];
    if ($excluirId) {
        $sql .= ' AND id != ?';
        $params[] = $excluirId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function usuariosListar(PDO $pdo, string $buscar, string $rol, string $estado, int $pagina): array
{
    $porPagina = USUARIOS_POR_PAGINA;
    $offset    = max(0, $pagina - 1) * $porPagina;

    $condiciones = [];
    $params      = [];

    if ($buscar !== '') {
        $condiciones[] = '(u.usuario LIKE ? OR u.nombre_completo LIKE ? OR u.correo LIKE ?)';
        $like = "%{$buscar}%";
        array_push($params, $like, $like, $like);
    }
    if (in_array($rol, ROLES_VALIDOS, true)) {
        $condiciones[] = 'u.rol = ?';
        $params[] = $rol;
    }
    if ($estado === 'activo' || $estado === 'inactivo') {
        $condiciones[] = 'u.activo = ?';
        $params[] = $estado === 'activo' ? 1 : 0;
    }

    $where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios u $where");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $sql = "
        SELECT u.id, u.usuario, u.correo, u.nombre_completo, u.rol, u.activo,
               u.ultimo_acceso, u.creado_en, c.nombre_completo AS creado_por_nombre
        FROM usuarios u
        LEFT JOIN usuarios c ON c.id = u.creado_por
        $where
        ORDER BY u.nombre_completo ASC
        LIMIT $porPagina OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return [
        'datos'        => $stmt->fetchAll(),
        'total'        => $total,
        'pagina'       => $pagina,
        'porPagina'    => $porPagina,
        'totalPaginas' => (int)ceil($total / $porPagina),
    ];
}

function usuariosObtener(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, usuario, correo, nombre_completo, rol, activo, ultimo_acceso, creado_en FROM usuarios WHERE id = ?');
    $stmt->execute([$id]);
    $fila = $stmt->fetch();
    return $fila ?: null;
}

/**
 * @return array{ok:bool, error?:string, id?:int}
 */
function usuariosCrear(PDO $pdo, array $datos, int $creadoPorId): array
{
    $validacion = usuariosValidarDatos($pdo, $datos, null, true);
    if (!$validacion['ok']) {
        return $validacion;
    }

    $hash = password_hash($datos['password'], PASSWORD_BCRYPT);

    $stmt = $pdo->prepare('
        INSERT INTO usuarios (usuario, correo, password_hash, nombre_completo, rol, activo, creado_por)
        VALUES (?, ?, ?, ?, ?, 1, ?)
    ');
    $stmt->execute([
        trim($datos['usuario']),
        trim($datos['correo'] ?? '') ?: null,
        $hash,
        trim($datos['nombre_completo']),
        $datos['rol'],
        $creadoPorId,
    ]);

    return ['ok' => true, 'id' => (int)$pdo->lastInsertId()];
}

/**
 * @return array{ok:bool, error?:string}
 */
function usuariosActualizar(PDO $pdo, int $id, array $datos): array
{
    $existente = usuariosObtener($pdo, $id);
    if (!$existente) {
        return ['ok' => false, 'error' => 'El usuario no existe o fue eliminado previamente.'];
    }

    $passwordNueva = trim($datos['password'] ?? '');
    $validacion = usuariosValidarDatos($pdo, $datos, $id, false);
    if (!$validacion['ok']) {
        return $validacion;
    }

    // Un rol operativo no puede quedar como el único administrador activo.
    if ($existente['rol'] === ROL_ADMINISTRADOR && $datos['rol'] !== ROL_ADMINISTRADOR) {
        if (usuariosContarAdministradoresActivos($pdo, $id) < 1) {
            return ['ok' => false, 'error' => 'Debe existir al menos un administrador activo en el sistema.'];
        }
    }

    if ($passwordNueva !== '') {
        $stmt = $pdo->prepare('
            UPDATE usuarios SET usuario = ?, correo = ?, nombre_completo = ?, rol = ?, password_hash = ?
            WHERE id = ?
        ');
        $stmt->execute([
            trim($datos['usuario']),
            trim($datos['correo'] ?? '') ?: null,
            trim($datos['nombre_completo']),
            $datos['rol'],
            password_hash($passwordNueva, PASSWORD_BCRYPT),
            $id,
        ]);
    } else {
        $stmt = $pdo->prepare('
            UPDATE usuarios SET usuario = ?, correo = ?, nombre_completo = ?, rol = ?
            WHERE id = ?
        ');
        $stmt->execute([
            trim($datos['usuario']),
            trim($datos['correo'] ?? '') ?: null,
            trim($datos['nombre_completo']),
            $datos['rol'],
            $id,
        ]);
    }

    return ['ok' => true];
}

/**
 * Activa o desactiva (baja lógica) un usuario. Nunca se elimina físicamente
 * un registro de usuarios: se conserva por trazabilidad de quién creó qué
 * instrumentos, solicitudes, incidencias y reportes.
 *
 * @return array{ok:bool, error?:string}
 */
function usuariosCambiarEstado(PDO $pdo, int $id, bool $activo, int $usuarioSesionId): array
{
    if ($id === $usuarioSesionId && !$activo) {
        return ['ok' => false, 'error' => 'No puedes desactivar tu propia cuenta mientras tienes la sesión iniciada.'];
    }

    $existente = usuariosObtener($pdo, $id);
    if (!$existente) {
        return ['ok' => false, 'error' => 'El usuario no existe o fue eliminado previamente.'];
    }

    if (!$activo && $existente['rol'] === ROL_ADMINISTRADOR && usuariosContarAdministradoresActivos($pdo, $id) < 1) {
        return ['ok' => false, 'error' => 'Debe existir al menos un administrador activo en el sistema.'];
    }

    $stmt = $pdo->prepare('UPDATE usuarios SET activo = ? WHERE id = ?');
    $stmt->execute([$activo ? 1 : 0, $id]);

    return ['ok' => true];
}

function usuariosRegistrarAcceso(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?');
    $stmt->execute([$id]);
}
