<?php
/**
 * Endpoint AJAX para el módulo de Gestión de usuarios.
 * Acciones soportadas (parámetro "accion"):
 *   listar          (GET)  -> listado paginado con búsqueda y filtros
 *   detalle         (GET)  -> datos de un usuario
 *   guardar         (POST) -> crea o actualiza un usuario
 *   cambiar_estado  (POST) -> activa / desactiva (baja lógica) un usuario
 *
 * Todo el módulo está restringido a administradores. La lógica de negocio
 * vive en includes/servicios/usuarios_servicio.php; este archivo solo
 * traduce la petición HTTP a esa capa y su resultado a JSON.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/api_response.php';
require_once __DIR__ . '/../includes/servicios/usuarios_servicio.php';

requerirAdministrador(); // corta la ejecución (403) si no aplica

csrfValidarOFallar();

$pdo    = obtenerConexion();
$accion = $_REQUEST['accion'] ?? 'listar';

ejecutarAccionApi(function () use ($pdo, $accion) {
    switch ($accion) {

        case 'listar':
            $buscar  = trim($_GET['buscar'] ?? '');
            $rol     = $_GET['rol'] ?? '';
            $estado  = $_GET['estado'] ?? '';
            $pagina  = max(1, (int)($_GET['pagina'] ?? 1));

            $resultado = usuariosListar($pdo, $buscar, $rol, $estado, $pagina);
            jsonOk($resultado);
            break;

        case 'detalle':
            $id = (int)($_GET['id'] ?? 0);
            $usuario = usuariosObtener($pdo, $id);
            if (!$usuario) {
                jsonError('Usuario no encontrado.', 404);
            }
            jsonOk(['usuario' => $usuario]);
            break;

        case 'guardar':
            $id = (int)($_POST['id'] ?? 0);

            if ($id > 0) {
                $resultado = usuariosActualizar($pdo, $id, $_POST);
                $mensaje   = 'Usuario actualizado correctamente.';
            } else {
                $resultado = usuariosCrear($pdo, $_POST, (int)$_SESSION['usuario_id']);
                $mensaje   = 'Usuario registrado correctamente.';
            }

            if (!$resultado['ok']) {
                jsonError($resultado['error'], 422);
            }
            jsonOk(['id' => $resultado['id'] ?? $id], $mensaje);
            break;

        case 'cambiar_estado':
            $id     = (int)($_POST['id'] ?? 0);
            $activo = (int)($_POST['activo'] ?? 0) === 1;

            if (!$id) {
                jsonError('Usuario no válido.', 422);
            }

            $resultado = usuariosCambiarEstado($pdo, $id, $activo, (int)$_SESSION['usuario_id']);
            if (!$resultado['ok']) {
                jsonError($resultado['error'], 422);
            }
            jsonOk([], $activo ? 'Usuario activado correctamente.' : 'Usuario desactivado correctamente.');
            break;

        default:
            jsonError('Acción no reconocida.', 400);
    }
});
