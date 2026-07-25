-- ============================================================
-- Musiteca | H. Ayuntamiento de Tlalnepantla de Baz
-- Esquema de base de datos MySQL (versión consolidada de producción)
--
-- Este archivo reemplaza, de forma acumulada, a: migracion_v2_nucleo.sql,
-- migracion_v3_usuarios.sql, migracion_reportes_soporte.sql,
-- migracion_prestamos_multiples.sql y
-- migracion_incidencias_prestamo_activo.sql. Una instalación NUEVA solo
-- necesita ejecutar este archivo una vez.
-- Los cinco scripts anteriores se conservan en
-- database/migraciones_historicas/ únicamente como referencia para bases
-- de datos que ya estaban en producción antes de esta versión consolidada
-- (o antes de alguna de las funcionalidades que agregaron sobre ella).
--
-- IMPORTANTE: este script YA NO crea usuarios de prueba con
-- contraseñas fijas. Después de ejecutarlo, crea el primer
-- administrador con:
--   php scripts/crear_admin.php
-- ============================================================

CREATE DATABASE IF NOT EXISTS musiteca CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE musiteca;

-- ------------------------------------------------------------
-- Usuarios del sistema (administradores / operadores)
-- ------------------------------------------------------------
CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(50) NOT NULL UNIQUE,
    correo VARCHAR(150) NULL,
    password_hash VARCHAR(255) NOT NULL,
    nombre_completo VARCHAR(120) NOT NULL,
    rol ENUM('administrador','operativo') NOT NULL DEFAULT 'operativo',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    ultimo_acceso DATETIME NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por INT NULL,
    CONSTRAINT fk_usuario_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    UNIQUE INDEX idx_usuarios_correo (correo),
    INDEX idx_usuarios_rol (rol),
    INDEX idx_usuarios_activo (activo)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Bitácora de intentos de inicio de sesión (control de fuerza bruta)
-- ------------------------------------------------------------
CREATE TABLE intentos_login (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(50) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    exitoso TINYINT(1) NOT NULL DEFAULT 0,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_intentos_usuario_fecha (usuario, creado_en),
    INDEX idx_intentos_ip_fecha (ip, creado_en)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Catálogo de ubicaciones físicas de resguardo
-- ------------------------------------------------------------
CREATE TABLE ubicaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Catálogo de instrumentos (patrimonio de Musiteca)
-- ------------------------------------------------------------
CREATE TABLE instrumentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    num_inventario VARCHAR(40) NOT NULL UNIQUE,
    num_inventario_anterior VARCHAR(40) NULL,
    nombre VARCHAR(150) NOT NULL,
    marca VARCHAR(100) NULL,
    modelo VARCHAR(100) NULL,
    num_serie VARCHAR(100) NULL,
    ubicacion_id INT NULL,
    condicion ENUM('bueno','regular','malo','inservible') NOT NULL DEFAULT 'bueno',
    estado ENUM('disponible','en_uso','en_reparacion','baja') NOT NULL DEFAULT 'disponible',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    fecha_baja DATETIME NULL,
    motivo_baja VARCHAR(255) NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_instrumento_ubicacion FOREIGN KEY (ubicacion_id) REFERENCES ubicaciones(id) ON DELETE SET NULL,
    INDEX idx_instrumentos_estado (estado),
    INDEX idx_instrumentos_condicion (condicion),
    INDEX idx_instrumentos_activo (activo),
    INDEX idx_instrumentos_nombre (nombre)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Préstamos (cabecera): un solicitante + una operación que puede agrupar
-- uno o varios instrumentos. El detalle (qué instrumentos y en qué
-- condición se devolvieron) vive en `solicitudes`, una fila por
-- instrumento prestado.
-- ------------------------------------------------------------
CREATE TABLE prestamos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    solicitante VARCHAR(150) NOT NULL,
    ubicacion_destino VARCHAR(150) NULL,
    observaciones TEXT NULL,
    fecha_solicitud DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_devolucion_esperada DATE NULL,
    estado ENUM('activo','parcial','finalizado') NOT NULL DEFAULT 'activo',
    creado_por INT NULL,
    CONSTRAINT fk_prestamo_usuario FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_prestamos_estado (estado),
    INDEX idx_prestamos_fecha (fecha_solicitud)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Solicitudes de préstamo (detalle del préstamo / bitácora de movimientos)
-- Una fila = un instrumento dentro de un préstamo. `prestamo_id` agrupa
-- varias filas bajo una misma operación (préstamo múltiple); puede ser
-- NULL en registros históricos previos a esa funcionalidad.
-- ------------------------------------------------------------
CREATE TABLE solicitudes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prestamo_id INT NULL,
    instrumento_id INT NOT NULL,
    solicitante VARCHAR(150) NOT NULL,
    ubicacion_destino VARCHAR(150) NULL,
    observaciones TEXT NULL,
    estado ENUM('activo','finalizado','vencido') NOT NULL DEFAULT 'activo',
    fecha_solicitud DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_devolucion_esperada DATE NULL,
    fecha_regreso DATETIME NULL,
    condicion_devolucion ENUM('excelente','bueno','desgaste','danado','incompleto') NULL,
    observaciones_devolucion TEXT NULL,
    creado_por INT NULL,
    CONSTRAINT fk_solicitud_prestamo FOREIGN KEY (prestamo_id) REFERENCES prestamos(id) ON DELETE CASCADE,
    CONSTRAINT fk_solicitud_instrumento FOREIGN KEY (instrumento_id) REFERENCES instrumentos(id) ON DELETE CASCADE,
    CONSTRAINT fk_solicitud_usuario FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_solicitudes_instrumento_estado (instrumento_id, estado),
    INDEX idx_solicitudes_estado_fecha (estado, fecha_solicitud),
    INDEX idx_solicitudes_devolucion_esperada (fecha_devolucion_esperada),
    INDEX idx_solicitudes_prestamo (prestamo_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Reportes de incidencias / problemas
--
-- Un instrumento puede reportarse aunque tenga un préstamo activo: en ese
-- caso `solicitud_id` liga el reporte a esa solicitud y el préstamo (y el
-- estado del instrumento) no se alteran. La decisión de si el instrumento
-- vuelve a disponible o pasa a mantenimiento se registra hasta la
-- devolución en `decision_devolucion` / `fecha_decision`.
-- ------------------------------------------------------------
CREATE TABLE incidencias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    instrumento_id INT NOT NULL,
    solicitud_id INT NULL,
    reportado_por VARCHAR(150) NOT NULL,
    motivo VARCHAR(150) NULL,
    ubicacion VARCHAR(150) NULL,
    descripcion TEXT NOT NULL,
    estado ENUM('abierta','en_atencion','resuelta') NOT NULL DEFAULT 'abierta',
    decision_devolucion ENUM('resuelto','mantenimiento') NULL,
    fecha_decision DATETIME NULL,
    creado_por INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_incidencia_instrumento FOREIGN KEY (instrumento_id) REFERENCES instrumentos(id) ON DELETE CASCADE,
    CONSTRAINT fk_incidencia_solicitud FOREIGN KEY (solicitud_id) REFERENCES solicitudes(id) ON DELETE SET NULL,
    CONSTRAINT fk_incidencia_usuario FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_incidencias_estado (estado),
    INDEX idx_incidencias_solicitud (solicitud_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Reportes de soporte técnico (anomalías del sistema)
-- ------------------------------------------------------------
CREATE TABLE reportes_soporte (
    id INT AUTO_INCREMENT PRIMARY KEY,
    modulo ENUM('plataforma_web','aplicacion_operativa') NOT NULL,
    num_inventario VARCHAR(40) NULL,
    descripcion TEXT NOT NULL,
    captura_archivo VARCHAR(255) NULL,
    estado ENUM('pendiente','en_atencion','resuelto') NOT NULL DEFAULT 'pendiente',
    creado_por INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reporte_usuario FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_reportes_estado (estado)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Catálogo mínimo de ubicaciones (dato de referencia, no es
-- información sensible; se conserva como semilla útil)
-- ------------------------------------------------------------
INSERT INTO ubicaciones (nombre) VALUES
 ('Área de Cultura Comunitaria y Escuelas de Arte'),
 ('Escuela del INBA'),
 ('Sin asignar');

-- No se insertan usuarios aquí. Ejecuta después:
--   php scripts/crear_admin.php
-- para crear el primer administrador con una contraseña propia.
