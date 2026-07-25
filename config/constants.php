<?php
/**
 * Constantes de configuración centralizadas de Musiteca.
 *
 * Cualquier valor que antes estaba "hardcodeado" dentro de los controladores
 * (límites de paginación, tiempos de sesión, extensiones permitidas, roles,
 * etc.) vive ahora aquí. Ajustar el comportamiento del sistema debería
 * significar editar este archivo, no buscar el valor dentro del código.
 */

// ------------------------------------------------------------
// Identidad de la aplicación
// ------------------------------------------------------------
define('APP_NOMBRE', 'Musiteca');
define('APP_ORGANIZACION', 'H. Ayuntamiento de Tlalnepantla de Baz');
define('APP_ZONA_HORARIA', 'America/Mexico_City');
define('APP_URL_BASE', '/'); // Ajustar si la app no vive en la raíz del dominio

date_default_timezone_set(APP_ZONA_HORARIA);

// ------------------------------------------------------------
// Roles del sistema
// ------------------------------------------------------------
define('ROL_ADMINISTRADOR', 'administrador');
define('ROL_OPERATIVO', 'operativo');
const ROLES_VALIDOS = [ROL_ADMINISTRADOR, ROL_OPERATIVO];

// ------------------------------------------------------------
// Catálogos de dominio (evitan strings sueltos repetidos en controladores)
// ------------------------------------------------------------
const CONDICIONES_INSTRUMENTO = ['bueno', 'regular', 'malo', 'inservible'];
const ESTADOS_INSTRUMENTO      = ['disponible', 'en_uso', 'en_reparacion', 'baja'];
const ESTADOS_SOLICITUD        = ['activo', 'finalizado', 'vencido'];
const ESTADOS_PRESTAMO         = ['activo', 'parcial', 'finalizado'];
const ESTADOS_INCIDENCIA       = ['abierta', 'en_atencion', 'resuelta'];
const ESTADOS_REPORTE_SOPORTE  = ['pendiente', 'en_atencion', 'resuelto'];
const MODULOS_SOPORTE          = ['plataforma_web', 'aplicacion_operativa'];

// Estado de entrega registrado al devolver un instrumento prestado.
const CONDICIONES_DEVOLUCION = ['excelente', 'bueno', 'desgaste', 'danado', 'incompleto'];

// Subconjunto de CONDICIONES_DEVOLUCION que exige capturar observaciones
// (el instrumento no volvió en las mismas condiciones en que se prestó).
const CONDICIONES_DEVOLUCION_CON_OBSERVACION_OBLIGATORIA = ['desgaste', 'danado', 'incompleto'];

// Motivo de un reporte de incidencia (crear_incidencia). Se puede reportar
// un instrumento aunque tenga un préstamo activo; el motivo ayuda a
// clasificar el problema sin depender solo de texto libre.
const MOTIVOS_INCIDENCIA = ['dano_fisico', 'mal_funcionamiento', 'piezas_faltantes', 'desgaste', 'otro'];

// Decisión tomada al devolver un instrumento que tiene una incidencia
// reportada durante el préstamo: si el problema se resolvió (o no era
// crítico) el instrumento vuelve a disponible; si requiere revisión, pasa
// a mantenimiento.
const DECISIONES_DEVOLUCION_INCIDENCIA = ['resuelto', 'mantenimiento'];

// ------------------------------------------------------------
// Paginación
// ------------------------------------------------------------
define('PAGINACION_POR_PAGINA', 10);
define('PAGINACION_MAX_POR_PAGINA', 50);

// ------------------------------------------------------------
// Préstamos: plazo de devolución
// ------------------------------------------------------------
define('PRESTAMO_DIAS_PLAZO_DEFAULT', 7); // días hábiles-calendario por defecto si no se especifica

// ------------------------------------------------------------
// Sesión
// ------------------------------------------------------------
define('SESION_NOMBRE_COOKIE', 'musiteca_sid');
define('SESION_TIEMPO_INACTIVIDAD', 30 * 60);      // 30 minutos sin actividad -> se cierra la sesión
define('SESION_TIEMPO_REGENERACION', 15 * 60);     // regenerar ID de sesión cada 15 minutos

// ------------------------------------------------------------
// Control de intentos de inicio de sesión (fuerza bruta)
// ------------------------------------------------------------
define('LOGIN_MAX_INTENTOS', 5);          // intentos fallidos permitidos
define('LOGIN_VENTANA_MINUTOS', 15);      // ventana de tiempo en la que se cuentan los intentos
define('LOGIN_BLOQUEO_MINUTOS', 15);      // minutos de bloqueo al superar el máximo

// ------------------------------------------------------------
// Subida de archivos (reportes de soporte)
// ------------------------------------------------------------
define('SOPORTE_UPLOAD_DIR', __DIR__ . '/../uploads/soporte/');
define('SOPORTE_UPLOAD_TAMANO_MAXIMO', 5 * 1024 * 1024); // 5 MB
const SOPORTE_UPLOAD_MIME_PERMITIDOS = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

// ------------------------------------------------------------
// CSRF
// ------------------------------------------------------------
define('CSRF_CAMPO', 'csrf_token');
define('CSRF_HEADER', 'X-CSRF-Token');

// ------------------------------------------------------------
// Utilidad de texto
// ------------------------------------------------------------
/**
 * Longitud de una cadena, usando mb_strlen() cuando la extensión mbstring
 * está disponible (para contar correctamente nombres con acentos u otros
 * caracteres multibyte) y strlen() como alternativa segura en caso
 * contrario. Antes el sistema llamaba directamente a mb_strlen(): si el
 * servidor no tenía la extensión mbstring habilitada, cualquier validación
 * de longitud (usuario, contraseña) fallaba con un error fatal en lugar de
 * un mensaje de validación, impidiendo por completo el alta de usuarios.
 */
function musitecaLongitudTexto(string $valor): int
{
    return function_exists('mb_strlen') ? mb_strlen($valor) : strlen($valor);
}
