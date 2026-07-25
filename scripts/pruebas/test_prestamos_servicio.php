<?php
/**
 * Pruebas de integración para includes/servicios/prestamos_servicio.php:
 * registrarPrestamo(), finalizarSolicitud() y actualizarEstadoPrestamo().
 *
 * Es la lógica más frágil del sistema (transacciones con bloqueo de filas,
 * préstamos múltiples, cierre de incidencias al devolver), así que se
 * prueba contra una base de datos MySQL real en vez de simularla: son
 * pruebas de integración, no pruebas unitarias puras, a propósito (el
 * proyecto no usa librerías externas ni un motor de BD alterno como
 * SQLite, y varias de estas consultas dependen de sintaxis específica de
 * MySQL/InnoDB como `FOR UPDATE`).
 *
 * ADVERTENCIA: este script BORRA Y RECREA todas las tablas de la base de
 * datos de pruebas en cada ejecución. NUNCA apunta por defecto a la base
 * "musiteca" de desarrollo/producción; usa una base separada
 * ("musiteca_test" por defecto) y se niega a correr si detecta que el
 * nombre resuelto es "musiteca".
 *
 * Uso:
 *   php scripts/pruebas/test_prestamos_servicio.php
 *
 * Variables de entorno opcionales (si no se definen, usa MUSITECA_DB_HOST /
 * MUSITECA_DB_USER / MUSITECA_DB_PASS como base y agrega el sufijo
 * "_test" al nombre de la base):
 *   MUSITECA_TEST_DB_HOST, MUSITECA_TEST_DB_NAME,
 *   MUSITECA_TEST_DB_USER, MUSITECA_TEST_DB_PASS
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo puede ejecutarse desde línea de comandos.');
}

$dbHost = getenv('MUSITECA_TEST_DB_HOST') ?: (getenv('MUSITECA_DB_HOST') ?: 'localhost');
$dbName = getenv('MUSITECA_TEST_DB_NAME') ?: 'musiteca_test';
$dbUser = getenv('MUSITECA_TEST_DB_USER') ?: (getenv('MUSITECA_DB_USER') ?: 'root');
$dbPass = getenv('MUSITECA_TEST_DB_PASS') ?: (getenv('MUSITECA_DB_PASS') ?: '');

if ($dbName === 'musiteca') {
    fwrite(STDERR, "Error: MUSITECA_TEST_DB_NAME no puede ser \"musiteca\" (borraría datos reales). Usa una base dedicada a pruebas, por ejemplo \"musiteca_test\".\n");
    exit(1);
}

fwrite(STDOUT, "== Pruebas de includes/servicios/prestamos_servicio.php ==\n");
fwrite(STDOUT, "Base de datos de pruebas: {$dbUser}@{$dbHost}/{$dbName}\n");
fwrite(STDOUT, "(se recrean todas las tablas de esta base en cada corrida)\n\n");

// ------------------------------------------------------------
// 1) Prepara la base de datos de pruebas: la crea si no existe, borra
//    cualquier tabla previa y vuelve a cargar el esquema consolidado
//    (database/schema.sql), para partir siempre de un estado limpio y
//    conocido.
// ------------------------------------------------------------
try {
    $pdoServidor = new PDO(
        "mysql:host={$dbHost};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdoServidor->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    // Orden inverso a las dependencias de llave foránea, para poder borrar
    // sin desactivar FOREIGN_KEY_CHECKS.
    foreach (['reportes_soporte', 'incidencias', 'solicitudes', 'prestamos', 'instrumentos', 'ubicaciones', 'intentos_login', 'usuarios'] as $tabla) {
        $pdo->exec("DROP TABLE IF EXISTS `{$tabla}`");
    }

    $schemaSql = file_get_contents(__DIR__ . '/../../database/schema.sql');
    if ($schemaSql === false) {
        throw new RuntimeException('No se pudo leer database/schema.sql');
    }
    // El esquema consolidado crea y selecciona la base "musiteca" por
    // nombre fijo; aquí ya estamos conectados a la base de pruebas, así
    // que se quitan esas dos líneas y se ejecuta el resto tal cual.
    $schemaSql = preg_replace('/^CREATE DATABASE.*$/m', '', $schemaSql);
    $schemaSql = preg_replace('/^USE musiteca;\s*$/m', '', $schemaSql);

    $pdoMultiSentencia = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ]
    );
    $pdoMultiSentencia->exec($schemaSql);
} catch (Throwable $e) {
    fwrite(STDERR, 'No se pudo preparar la base de datos de pruebas: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "Verifica que MySQL esté activo y que las credenciales (MUSITECA_TEST_DB_* o MUSITECA_DB_*) sean correctas.\n");
    exit(1);
}

require_once __DIR__ . '/../../includes/servicios/prestamos_servicio.php';

// ------------------------------------------------------------
// 2) Mini-framework de aserciones (sin dependencias externas, en línea
//    con el resto del proyecto).
// ------------------------------------------------------------
$totalPruebas  = 0;
$pruebasFallidas = 0;

function verificar(bool $condicion, string $descripcion): void
{
    global $totalPruebas, $pruebasFallidas;
    $totalPruebas++;
    if ($condicion) {
        fwrite(STDOUT, "  OK   - {$descripcion}\n");
    } else {
        $pruebasFallidas++;
        fwrite(STDOUT, "  FAIL - {$descripcion}\n");
    }
}

function verificarIguales($esperado, $actual, string $descripcion): void
{
    verificar($esperado === $actual, "{$descripcion} (esperado: " . var_export($esperado, true) . ', obtenido: ' . var_export($actual, true) . ')');
}

// ------------------------------------------------------------
// 3) Fixtures mínimos
// ------------------------------------------------------------
function crearInstrumentoFixture(PDO $pdo, string $estado = 'disponible'): int
{
    static $contador = 0;
    $contador++;
    $stmt = $pdo->prepare("
        INSERT INTO instrumentos (num_inventario, nombre, condicion, estado)
        VALUES (?, ?, 'bueno', ?)
    ");
    $stmt->execute(['TEST-' . $contador . '-' . bin2hex(random_bytes(3)), "Instrumento de prueba {$contador}", $estado]);
    return (int)$pdo->lastInsertId();
}

function estadoInstrumento(PDO $pdo, int $id): string
{
    $stmt = $pdo->prepare('SELECT estado FROM instrumentos WHERE id = ?');
    $stmt->execute([$id]);
    return (string)$stmt->fetchColumn();
}

function contarFilas(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

// ------------------------------------------------------------
// 4) Casos de prueba: registrarPrestamo()
// ------------------------------------------------------------
fwrite(STDOUT, "-- registrarPrestamo() --\n");

// a) Préstamo de un solo instrumento disponible
$instrumentoA = crearInstrumentoFixture($pdo, 'disponible');
$resultado = registrarPrestamo($pdo, [$instrumentoA], 'Juan Pérez', 'Salón 1', '', calcularFechaDevolucion(''), null);
verificar($resultado['ok'] === true, 'préstamo individual de un instrumento disponible se registra correctamente');
verificarIguales('en_uso', estadoInstrumento($pdo, $instrumentoA), 'el instrumento prestado queda en_uso');
verificarIguales(1, contarFilas($pdo, 'SELECT COUNT(*) FROM prestamos WHERE id = ?', [$resultado['prestamo_id'] ?? 0]), 'se creó la cabecera del préstamo');
verificarIguales(1, contarFilas($pdo, 'SELECT COUNT(*) FROM solicitudes WHERE prestamo_id = ? AND instrumento_id = ?', [$resultado['prestamo_id'] ?? 0, $instrumentoA]), 'se creó el detalle de la solicitud ligado al préstamo');

// b) Préstamo múltiple con varios instrumentos disponibles
$instrumentoB1 = crearInstrumentoFixture($pdo, 'disponible');
$instrumentoB2 = crearInstrumentoFixture($pdo, 'disponible');
$resultadoMultiple = registrarPrestamo($pdo, [$instrumentoB1, $instrumentoB2], 'Escuela de Arte', '', '', calcularFechaDevolucion(''), null);
verificar($resultadoMultiple['ok'] === true, 'préstamo múltiple con instrumentos disponibles se registra correctamente');
verificarIguales('en_uso', estadoInstrumento($pdo, $instrumentoB1), 'primer instrumento del préstamo múltiple queda en_uso');
verificarIguales('en_uso', estadoInstrumento($pdo, $instrumentoB2), 'segundo instrumento del préstamo múltiple queda en_uso');
verificarIguales(2, contarFilas($pdo, 'SELECT COUNT(*) FROM solicitudes WHERE prestamo_id = ?', [$resultadoMultiple['prestamo_id'] ?? 0]), 'el préstamo múltiple generó dos filas de detalle bajo la misma cabecera');

// c) No se puede prestar un instrumento que ya está en_uso; no debe quedar ningún registro huérfano
$instrumentoC = crearInstrumentoFixture($pdo, 'en_uso');
$antesSolicitudes = contarFilas($pdo, 'SELECT COUNT(*) FROM solicitudes');
$antesPrestamos    = contarFilas($pdo, 'SELECT COUNT(*) FROM prestamos');
$resultadoOcupado = registrarPrestamo($pdo, [$instrumentoC], 'Alguien', '', '', calcularFechaDevolucion(''), null);
verificar($resultadoOcupado['ok'] === false, 'no se puede prestar un instrumento que ya está en_uso');
verificarIguales(409, $resultadoOcupado['codigoHttp'] ?? null, 'rechazo de instrumento no disponible responde 409');
verificarIguales($antesSolicitudes, contarFilas($pdo, 'SELECT COUNT(*) FROM solicitudes'), 'el intento fallido no crea ninguna fila en solicitudes (rollback correcto)');
verificarIguales($antesPrestamos, contarFilas($pdo, 'SELECT COUNT(*) FROM prestamos'), 'el intento fallido no crea ninguna cabecera de préstamo (rollback correcto)');

// d) Préstamo múltiple donde uno de los instrumentos ya no está disponible: no se registra NINGUNO (todo o nada)
$instrumentoD1 = crearInstrumentoFixture($pdo, 'disponible');
$instrumentoD2 = crearInstrumentoFixture($pdo, 'en_reparacion');
$antesSolicitudes = contarFilas($pdo, 'SELECT COUNT(*) FROM solicitudes');
$resultadoParcial = registrarPrestamo($pdo, [$instrumentoD1, $instrumentoD2], 'Alguien', '', '', calcularFechaDevolucion(''), null);
verificar($resultadoParcial['ok'] === false, 'si uno de varios instrumentos no está disponible, se rechaza el préstamo completo');
verificarIguales('disponible', estadoInstrumento($pdo, $instrumentoD1), 'el instrumento que sí estaba disponible NO queda prestado (atomicidad: todo o nada)');
verificarIguales($antesSolicitudes, contarFilas($pdo, 'SELECT COUNT(*) FROM solicitudes'), 'ninguna fila de solicitudes se crea cuando el préstamo múltiple se rechaza');

// e) Solicitante vacío
$instrumentoE = crearInstrumentoFixture($pdo, 'disponible');
$resultadoSinSolicitante = registrarPrestamo($pdo, [$instrumentoE], '', '', '', calcularFechaDevolucion(''), null);
verificar($resultadoSinSolicitante['ok'] === false, 'el solicitante es obligatorio');
verificarIguales('disponible', estadoInstrumento($pdo, $instrumentoE), 'el instrumento sigue disponible si falta el solicitante');

// f) Lista de instrumentos vacía
$resultadoSinInstrumentos = registrarPrestamo($pdo, [], 'Alguien', '', '', calcularFechaDevolucion(''), null);
verificar($resultadoSinInstrumentos['ok'] === false, 'se rechaza un préstamo sin ningún instrumento');

// g) ID de instrumento inexistente
$resultadoInexistente = registrarPrestamo($pdo, [999999], 'Alguien', '', '', calcularFechaDevolucion(''), null);
verificar($resultadoInexistente['ok'] === false, 'se rechaza un instrumento que no existe en el catálogo');
verificarIguales(404, $resultadoInexistente['codigoHttp'] ?? null, 'instrumento inexistente responde 404');

// h) Fecha de devolución vacía cae al plazo por defecto
$fechaCalculada = calcularFechaDevolucion('');
$fechaEsperada  = date('Y-m-d', strtotime('+' . PRESTAMO_DIAS_PLAZO_DEFAULT . ' days'));
verificarIguales($fechaEsperada, $fechaCalculada, 'fecha de devolución vacía se calcula con el plazo por defecto (' . PRESTAMO_DIAS_PLAZO_DEFAULT . ' días)');

// ------------------------------------------------------------
// 5) Casos de prueba: finalizarSolicitud() y actualizarEstadoPrestamo()
// ------------------------------------------------------------
fwrite(STDOUT, "\n-- finalizarSolicitud() / actualizarEstadoPrestamo() --\n");

// i) + j) Préstamo múltiple: finalizar un instrumento deja la cabecera "parcial"; finalizar el segundo la deja "finalizado"
$instrumentoF1 = crearInstrumentoFixture($pdo, 'disponible');
$instrumentoF2 = crearInstrumentoFixture($pdo, 'disponible');
$prestamoF = registrarPrestamo($pdo, [$instrumentoF1, $instrumentoF2], 'Solicitante F', '', '', calcularFechaDevolucion(''), null);
$stmt = $pdo->prepare('SELECT id FROM solicitudes WHERE prestamo_id = ? ORDER BY id');
$stmt->execute([$prestamoF['prestamo_id']]);
[$solicitudF1, $solicitudF2] = $stmt->fetchAll(PDO::FETCH_COLUMN);

$resultadoFinF1 = finalizarSolicitud($pdo, (int)$solicitudF1, 'bueno', '', '');
verificar($resultadoFinF1['ok'] === true, 'se finaliza la devolución del primer instrumento del préstamo múltiple');
$stmt = $pdo->prepare('SELECT estado FROM prestamos WHERE id = ?');
$stmt->execute([$prestamoF['prestamo_id']]);
verificarIguales('parcial', $stmt->fetchColumn(), 'la cabecera del préstamo queda "parcial" cuando falta devolver un instrumento');

$resultadoFinF2 = finalizarSolicitud($pdo, (int)$solicitudF2, 'bueno', '', '');
verificar($resultadoFinF2['ok'] === true, 'se finaliza la devolución del segundo instrumento del préstamo múltiple');
$stmt = $pdo->prepare('SELECT estado FROM prestamos WHERE id = ?');
$stmt->execute([$prestamoF['prestamo_id']]);
verificarIguales('finalizado', $stmt->fetchColumn(), 'la cabecera del préstamo queda "finalizado" cuando se devolvieron todos los instrumentos');
verificarIguales('disponible', estadoInstrumento($pdo, $instrumentoF1), 'el instrumento devuelto sin incidencia vuelve a disponible');

// l) Condición de devolución inválida
$instrumentoG = crearInstrumentoFixture($pdo, 'disponible');
$prestamoG = registrarPrestamo($pdo, [$instrumentoG], 'Solicitante G', '', '', calcularFechaDevolucion(''), null);
verificar($prestamoG['ok'] === true, 'fixture: préstamo de G se registra para poder probar validaciones de finalizarSolicitud');
$solicitudG = (int)$pdo->query('SELECT id FROM solicitudes WHERE prestamo_id = ' . (int)$prestamoG['prestamo_id'])->fetchColumn();
$resultadoInvalida = finalizarSolicitud($pdo, $solicitudG, 'valor_invalido', '', '');
verificar($resultadoInvalida['ok'] === false, 'se rechaza una condición de devolución que no existe en el catálogo de valores');

// m) Condición "dañado" exige observaciones
$resultadoSinObs = finalizarSolicitud($pdo, $solicitudG, 'danado', '', '');
verificar($resultadoSinObs['ok'] === false, 'condición de devolución "dañado" exige describir observaciones');
$resultadoConObs = finalizarSolicitud($pdo, $solicitudG, 'danado', 'Se rompió una cuerda', '');
verificar($resultadoConObs['ok'] === true, 'con observaciones, "dañado" sí se acepta y finaliza la solicitud');

// n) + o) + p) Instrumento con incidencia abierta ligada al préstamo: exige decisión antes de poder finalizar
$instrumentoH = crearInstrumentoFixture($pdo, 'disponible');
$prestamoH = registrarPrestamo($pdo, [$instrumentoH], 'Solicitante H', '', '', calcularFechaDevolucion(''), null);
verificar($prestamoH['ok'] === true, 'fixture: préstamo de H se registra para poder probar la incidencia abierta');
$solicitudH = (int)$pdo->query('SELECT id FROM solicitudes WHERE prestamo_id = ' . (int)$prestamoH['prestamo_id'])->fetchColumn();
$pdo->prepare("
    INSERT INTO incidencias (instrumento_id, solicitud_id, reportado_por, motivo, descripcion, estado)
    VALUES (?, ?, 'Alguien', 'dano_fisico', 'Se cayó durante el uso', 'abierta')
")->execute([$instrumentoH, $solicitudH]);

$resultadoSinDecision = finalizarSolicitud($pdo, $solicitudH, 'bueno', '', '');
verificar($resultadoSinDecision['ok'] === false, 'no se puede finalizar una solicitud con incidencia abierta sin indicar una decisión');
verificar(!empty($resultadoSinDecision['requiere_decision_incidencia']), 'la respuesta indica explícitamente que falta la decisión sobre la incidencia');

$resultadoMantenimiento = finalizarSolicitud($pdo, $solicitudH, 'bueno', '', 'mantenimiento');
verificar($resultadoMantenimiento['ok'] === true, 'con decisión "mantenimiento" la solicitud se finaliza');
verificarIguales('en_reparacion', estadoInstrumento($pdo, $instrumentoH), 'el instrumento pasa a en_reparacion cuando la decisión es "mantenimiento"');
$stmt = $pdo->prepare("SELECT estado FROM incidencias WHERE solicitud_id = ?");
$stmt->execute([$solicitudH]);
verificarIguales('en_atencion', $stmt->fetchColumn(), 'la incidencia queda "en_atencion" (no "resuelta") cuando se envía a mantenimiento');

$instrumentoI = crearInstrumentoFixture($pdo, 'disponible');
$prestamoI = registrarPrestamo($pdo, [$instrumentoI], 'Solicitante I', '', '', calcularFechaDevolucion(''), null);
verificar($prestamoI['ok'] === true, 'fixture: préstamo de I se registra para poder probar la incidencia resuelta');
$solicitudI = (int)$pdo->query('SELECT id FROM solicitudes WHERE prestamo_id = ' . (int)$prestamoI['prestamo_id'])->fetchColumn();
$pdo->prepare("
    INSERT INTO incidencias (instrumento_id, solicitud_id, reportado_por, motivo, descripcion, estado)
    VALUES (?, ?, 'Alguien', 'desgaste', 'Detalle menor sin importancia', 'abierta')
")->execute([$instrumentoI, $solicitudI]);
$resultadoResuelto = finalizarSolicitud($pdo, $solicitudI, 'bueno', '', 'resuelto');
verificar($resultadoResuelto['ok'] === true, 'con decisión "resuelto" la solicitud se finaliza');
verificarIguales('disponible', estadoInstrumento($pdo, $instrumentoI), 'el instrumento vuelve a disponible cuando la incidencia se marca "resuelto"');
$stmt = $pdo->prepare("SELECT estado FROM incidencias WHERE solicitud_id = ?");
$stmt->execute([$solicitudI]);
verificarIguales('resuelta', $stmt->fetchColumn(), 'la incidencia queda "resuelta" cuando la decisión es "resuelto"');

// Solicitud inexistente
$resultadoNoExiste = finalizarSolicitud($pdo, 999999, '', '', '');
verificar($resultadoNoExiste['ok'] === false, 'se rechaza finalizar una solicitud que no existe');
verificarIguales(404, $resultadoNoExiste['codigoHttp'] ?? null, 'solicitud inexistente responde 404');

// ------------------------------------------------------------
// 6) Resumen
// ------------------------------------------------------------
fwrite(STDOUT, "\n== Resumen: " . ($totalPruebas - $pruebasFallidas) . "/{$totalPruebas} pruebas pasaron ==\n");

if ($pruebasFallidas > 0) {
    fwrite(STDOUT, "{$pruebasFallidas} prueba(s) fallaron.\n");
    exit(1);
}

fwrite(STDOUT, "Todas las pruebas pasaron.\n");
exit(0);
