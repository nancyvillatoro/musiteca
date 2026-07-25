<?php
/**
 * Endpoint AJAX para el módulo de Inventario.
 * Acciones soportadas (parámetro "accion"):
 *   listar      (GET)  -> listado paginado con búsqueda y filtro de condición
 *   detalle     (GET)  -> expediente completo de un instrumento + bitácora
 *   guardar     (POST) -> crea o actualiza un instrumento
 *   eliminar    (POST) -> da de baja un instrumento
 *   listar_baja (GET)  -> listado paginado de instrumentos dados de baja (admin)
 *   restaurar   (POST) -> restaura un instrumento dado de baja al catálogo (admin)
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
$accion = $_REQUEST['accion'] ?? 'listar';

switch ($accion) {

    case 'listar':
        $buscar     = trim($_GET['buscar'] ?? '');
        $condicion  = $_GET['condicion'] ?? '';
        $estado     = $_GET['estado'] ?? '';
        $pagina     = max(1, (int)($_GET['pagina'] ?? 1));
        // Antes este valor estaba hardcodeado (10) en paralelo a la constante
        // PAGINACION_POR_PAGINA ya definida en config/constants.php: cambiar
        // el tamaño de página exigía editar dos archivos distintos (y aquí y
        // en api/control.php) en vez de uno solo (violación de DRY). Ahora
        // ambos endpoints leen el mismo valor centralizado.
        $porPagina  = PAGINACION_POR_PAGINA;
        $offset     = ($pagina - 1) * $porPagina;

        $condiciones = ['i.activo = 1'];
        $params      = [];

        if ($buscar !== '') {
            $condiciones[] = '(i.num_inventario LIKE ? OR i.nombre LIKE ? OR i.marca LIKE ? OR i.modelo LIKE ? OR i.num_serie LIKE ? OR u.nombre LIKE ?)';
            $like = "%{$buscar}%";
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        if ($condicion !== '') {
            $condiciones[] = 'i.condicion = ?';
            $params[] = $condicion;
        }
        // Filtro opcional por estado logístico (p. ej. "disponible"), usado por
        // el buscador del carrito de préstamo múltiple para no ofrecer
        // instrumentos que ya están prestados o en mantenimiento. Parámetro
        // nuevo y opcional: los llamados existentes que no lo envían no
        // cambian su comportamiento.
        if ($estado !== '' && in_array($estado, ESTADOS_INSTRUMENTO, true)) {
            $condiciones[] = 'i.estado = ?';
            $params[] = $estado;
        }

        $where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

        $sqlTotal = "SELECT COUNT(*) FROM instrumentos i LEFT JOIN ubicaciones u ON u.id = i.ubicacion_id $where";
        $stmt = $pdo->prepare($sqlTotal);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $sql = "
            SELECT i.id, i.num_inventario, i.nombre, i.condicion, i.estado,
                   COALESCE(u.nombre, 'Sin asignar') AS ubicacion
            FROM instrumentos i
            LEFT JOIN ubicaciones u ON u.id = i.ubicacion_id
            $where
            ORDER BY i.actualizado_en DESC
            LIMIT $porPagina OFFSET $offset
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $datos = $stmt->fetchAll();

        echo json_encode([
            'ok'         => true,
            'datos'      => $datos,
            'total'      => $total,
            'pagina'     => $pagina,
            'porPagina'  => $porPagina,
            'totalPaginas' => (int)ceil($total / $porPagina),
        ]);
        break;

    case 'detalle':
        $id = (int)($_GET['id'] ?? 0);

        $stmt = $pdo->prepare("
            SELECT i.*, u.nombre AS ubicacion_nombre
            FROM instrumentos i
            LEFT JOIN ubicaciones u ON u.id = i.ubicacion_id
            WHERE i.id = ? AND i.activo = 1
        ");
        $stmt->execute([$id]);
        $instrumento = $stmt->fetch();

        if (!$instrumento) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Instrumento no encontrado.']);
            break;
        }

        $stmt = $pdo->prepare("
            SELECT solicitante, ubicacion_destino, observaciones, estado, fecha_solicitud, fecha_regreso,
                   condicion_devolucion, observaciones_devolucion
            FROM solicitudes
            WHERE instrumento_id = ?
            ORDER BY fecha_solicitud DESC
            LIMIT 15
        ");
        $stmt->execute([$id]);
        $bitacora = $stmt->fetchAll();

        // Incidencias abiertas ligadas al préstamo activo (si lo hay): el
        // instrumento puede tener reportes pendientes de decisión aunque su
        // estado siga siendo "en_uso" con normalidad.
        $stmt = $pdo->prepare("
            SELECT ic.id, ic.motivo, ic.descripcion, ic.reportado_por, ic.creado_en
            FROM incidencias ic
            INNER JOIN solicitudes s ON s.id = ic.solicitud_id
            WHERE ic.instrumento_id = ? AND ic.estado = 'abierta' AND s.estado = 'activo'
            ORDER BY ic.creado_en DESC
        ");
        $stmt->execute([$id]);
        $incidenciasAbiertas = $stmt->fetchAll();

        // Historial completo de incidencias del instrumento, para consulta y auditoría.
        $stmt = $pdo->prepare("
            SELECT motivo, descripcion, reportado_por, estado, decision_devolucion, fecha_decision, creado_en
            FROM incidencias
            WHERE instrumento_id = ?
            ORDER BY creado_en DESC
            LIMIT 15
        ");
        $stmt->execute([$id]);
        $historialIncidencias = $stmt->fetchAll();

        echo json_encode([
            'ok'                    => true,
            'instrumento'           => $instrumento,
            'bitacora'              => $bitacora,
            'incidencias_abiertas'  => $incidenciasAbiertas,
            'historial_incidencias' => $historialIncidencias,
        ]);
        break;

    case 'guardar':
        requerirAdministrador();
        $id                     = (int)($_POST['id'] ?? 0);
        $numInventario          = trim($_POST['num_inventario'] ?? '');
        $numInventarioAnterior  = trim($_POST['num_inventario_anterior'] ?? '');
        $nombre                 = trim($_POST['nombre'] ?? '');
        $marca                  = trim($_POST['marca'] ?? '');
        $modelo                 = trim($_POST['modelo'] ?? '');
        $numSerie               = trim($_POST['num_serie'] ?? '');
        $ubicacionId            = (int)($_POST['ubicacion_id'] ?? 0) ?: null;
        $condicion              = $_POST['condicion'] ?? 'bueno';
        $estado                 = $_POST['estado'] ?? 'disponible';

        // El manual de referencia (sección 3.7) exige capturar los 8 campos
        // del expediente del instrumento como obligatorios; antes solo se
        // exigían 3. Se exigen ahora también marca, modelo y número de
        // serie. num_inventario_anterior se deja fuera a propósito: ese
        // campo solo aplica a instrumentos que ya tenían un número bajo un
        // esquema de numeración previo, así que forzarlo en instrumentos
        // genuinamente nuevos (que nunca tuvieron un número anterior)
        // llevaría a capturar valores basura ("N/A", "-") solo para poder
        // guardar, en vez de mejorar la calidad del dato. Se valida aquí en
        // el servidor porque data-obligatorio en el formulario (index.php /
        // app/index.php) es una ayuda de UX, no una garantía: cualquier
        // petición directa al endpoint debe respetar la misma regla.
        $camposFaltantes = [];
        if ($numInventario === '') $camposFaltantes[] = 'número de inventario';
        if ($nombre === '') $camposFaltantes[] = 'nombre';
        if ($marca === '') $camposFaltantes[] = 'marca';
        if ($modelo === '') $camposFaltantes[] = 'modelo';
        if ($numSerie === '') $camposFaltantes[] = 'número de serie';
        if (!$ubicacionId) $camposFaltantes[] = 'ubicación';

        if (!empty($camposFaltantes)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Completa los siguientes campos obligatorios: ' . implode(', ', $camposFaltantes) . '.']);
            break;
        }

        if (!in_array($condicion, ['bueno', 'regular', 'malo', 'inservible'], true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'La condición seleccionada no es válida.']);
            break;
        }
        if (!in_array($estado, ['disponible', 'en_uso', 'en_reparacion'], true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'El estado logístico seleccionado no es válido.']);
            break;
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE instrumentos SET
                        num_inventario = ?, num_inventario_anterior = ?, nombre = ?, marca = ?,
                        modelo = ?, num_serie = ?, ubicacion_id = ?, condicion = ?, estado = ?
                    WHERE id = ?
                ");
                $stmt->execute([$numInventario, $numInventarioAnterior, $nombre, $marca, $modelo, $numSerie, $ubicacionId, $condicion, $estado, $id]);

                // Si el instrumento se guarda como "disponible" desde este
                // formulario, cierra cualquier incidencia que hubiera quedado
                // pendiente (abierta/en_atencion) sobre él. Antes esto solo
                // pasaba al usar el botón "Marcar disponible" ligado a una
                // incidencia (ver resolver_incidencia en api/solicitudes.php);
                // si el estado se cambiaba aquí, en la edición manual, la
                // incidencia se quedaba pendiente para siempre, sin ningún
                // botón que la cerrara (ese botón solo aparece mientras el
                // instrumento sigue en mantenimiento).
                if ($estado === 'disponible') {
                    $pdo->prepare("
                        UPDATE incidencias SET estado = 'resuelta'
                        WHERE instrumento_id = ? AND estado != 'resuelta'
                    ")->execute([$id]);
                }

                $mensaje = 'Instrumento actualizado correctamente.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO instrumentos
                        (num_inventario, num_inventario_anterior, nombre, marca, modelo, num_serie, ubicacion_id, condicion, estado)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$numInventario, $numInventarioAnterior, $nombre, $marca, $modelo, $numSerie, $ubicacionId, $condicion, $estado]);
                $id = (int)$pdo->lastInsertId();
                $mensaje = 'Instrumento registrado correctamente.';
            }

            echo json_encode(['ok' => true, 'mensaje' => $mensaje, 'id' => $id]);
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000 || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                http_response_code(409);
                echo json_encode(['ok' => false, 'error' => 'Ya existe un instrumento registrado con ese número de inventario.']);
            } else {
                // Errores de base de datos no previstos: se registran y se
                // responde con el mensaje genérico a través del manejador global.
                throw $e;
            }
        }
        break;

    case 'eliminar':
        requerirAdministrador();
        $id          = (int)($_POST['id'] ?? 0);
        $motivoBaja  = trim($_POST['motivo_baja'] ?? '');

        if (!$id) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Instrumento no válido.']);
            break;
        }

        $stmt = $pdo->prepare('SELECT id, estado FROM instrumentos WHERE id = ? AND activo = 1');
        $stmt->execute([$id]);
        $instrumento = $stmt->fetch();
        if (!$instrumento) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'El instrumento ya no existe o fue eliminado previamente.']);
            break;
        }

        // Regla de negocio que antes no se validaba: un instrumento con un
        // préstamo activo (estado 'en_uso') no puede darse de baja. Si se
        // permitiera, el bien quedaría fuera del catálogo mientras la
        // solicitud/préstamo asociado sigue "activo" en la bitácora, dejando
        // un movimiento abierto sin instrumento válido al que devolver y sin
        // forma de cerrarlo desde el módulo de Control. Se exige primero
        // registrar la devolución (o reportar la incidencia que corresponda).
        if ($instrumento['estado'] === 'en_uso') {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'Este instrumento tiene un préstamo activo. Registra su devolución en el módulo de Control antes de darlo de baja.']);
            break;
        }

        // Baja lógica: nunca se elimina físicamente el registro, para no
        // perder la trazabilidad de su historial de préstamos e incidencias.
        $stmt = $pdo->prepare("
            UPDATE instrumentos
            SET activo = 0, estado = 'baja', fecha_baja = NOW(), motivo_baja = ?
            WHERE id = ?
        ");
        $stmt->execute([$motivoBaja ?: null, $id]);
        echo json_encode(['ok' => true, 'mensaje' => 'Instrumento dado de baja del catálogo.']);
        break;

    case 'listar_baja':
        // Listado de instrumentos dados de baja (activo = 0), para su
        // consulta y eventual restauración. Restringido a administrador:
        // solo el rol que puede dar de baja (acción 'eliminar') puede ver
        // y revertir ese historial.
        requerirAdministrador();
        $buscar    = trim($_GET['buscar'] ?? '');
        $pagina    = max(1, (int)($_GET['pagina'] ?? 1));
        $porPagina = PAGINACION_POR_PAGINA;
        $offset    = ($pagina - 1) * $porPagina;

        $condiciones = ['i.activo = 0'];
        $params      = [];

        if ($buscar !== '') {
            $condiciones[] = '(i.num_inventario LIKE ? OR i.nombre LIKE ? OR i.motivo_baja LIKE ?)';
            $like = "%{$buscar}%";
            array_push($params, $like, $like, $like);
        }

        $where = 'WHERE ' . implode(' AND ', $condiciones);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM instrumentos i $where");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $sql = "
            SELECT i.id, i.num_inventario, i.nombre, i.condicion,
                   COALESCE(u.nombre, 'Sin asignar') AS ubicacion,
                   i.fecha_baja, i.motivo_baja
            FROM instrumentos i
            LEFT JOIN ubicaciones u ON u.id = i.ubicacion_id
            $where
            ORDER BY i.fecha_baja DESC
            LIMIT $porPagina OFFSET $offset
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $datos = $stmt->fetchAll();

        echo json_encode([
            'ok'           => true,
            'datos'        => $datos,
            'total'        => $total,
            'pagina'       => $pagina,
            'porPagina'    => $porPagina,
            'totalPaginas' => (int)ceil($total / $porPagina),
        ]);
        break;

    case 'restaurar':
        requerirAdministrador();
        $id = (int)($_POST['id'] ?? 0);

        if (!$id) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Instrumento no válido.']);
            break;
        }

        $stmt = $pdo->prepare('SELECT id, activo FROM instrumentos WHERE id = ?');
        $stmt->execute([$id]);
        $instrumento = $stmt->fetch();

        if (!$instrumento) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'El instrumento no existe.']);
            break;
        }
        if ((int)$instrumento['activo'] === 1) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'Este instrumento no está dado de baja.']);
            break;
        }

        // Al restaurar, el instrumento vuelve al catálogo activo como
        // "disponible" (no se conoce con certeza en qué estado logístico
        // quedó antes de la baja, y "disponible" es el único punto de
        // partida seguro: no se puede asumir que sigue "en_uso" ni que
        // sigue "en_reparacion"). Se limpian fecha_baja y motivo_baja
        // porque, una vez restaurado, el instrumento ya no está "de baja":
        // dejar esos campos con su último valor se leería como si siguiera
        // dado de baja, aunque activo vuelva a valer 1.
        $stmt = $pdo->prepare("
            UPDATE instrumentos
            SET activo = 1, estado = 'disponible', fecha_baja = NULL, motivo_baja = NULL
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        echo json_encode(['ok' => true, 'mensaje' => 'Instrumento restaurado. Ya está disponible en el catálogo.']);
        break;

    case 'kpis':
        $kpis = $pdo->query("
            SELECT
                COUNT(*) AS total,
                SUM(estado = 'disponible')    AS disponibles,
                SUM(estado = 'en_uso')        AS en_uso,
                SUM(estado = 'en_reparacion') AS en_reparacion
            FROM instrumentos
            WHERE activo = 1
        ")->fetch();

        echo json_encode([
            'ok' => true,
            'kpis' => [
                'total'         => (int)$kpis['total'],
                'disponibles'   => (int)$kpis['disponibles'],
                'en_uso'        => (int)$kpis['en_uso'],
                'en_reparacion' => (int)$kpis['en_reparacion'],
            ],
        ]);
        break;

    case 'buscar_codigo':
        // Utilizado por la app móvil para localizar por número de inventario completo o últimos dígitos
        $codigo = trim($_GET['codigo'] ?? '');
        $stmt = $pdo->prepare("
            SELECT i.id, i.num_inventario, i.nombre, i.marca, i.modelo, i.num_serie, i.condicion, i.estado,
                   COALESCE(u.nombre, 'Sin asignar') AS ubicacion
            FROM instrumentos i
            LEFT JOIN ubicaciones u ON u.id = i.ubicacion_id
            WHERE i.activo = 1 AND (i.num_inventario = ? OR i.num_inventario LIKE ?)
            LIMIT 5
        ");
        $stmt->execute([$codigo, "%{$codigo}"]);
        echo json_encode(['ok' => true, 'resultados' => $stmt->fetchAll()]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Acción no reconocida.']);
}
