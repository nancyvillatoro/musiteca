-- ============================================================
-- Musiteca - Migración v2: núcleo de seguridad, rendimiento
-- y soporte para baja lógica / control de préstamos vencidos.
-- Ejecutar UNA sola vez sobre una base ya creada con schema.sql
-- ============================================================

USE musiteca;

-- ------------------------------------------------------------
-- 1) Bitácora de intentos de inicio de sesión (rate limiting)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS intentos_login (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(50) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    exitoso TINYINT(1) NOT NULL DEFAULT 0,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_intentos_usuario_fecha (usuario, creado_en),
    INDEX idx_intentos_ip_fecha (ip, creado_en)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 2) Baja lógica de instrumentos (en lugar de DELETE físico)
-- ------------------------------------------------------------
ALTER TABLE instrumentos
    ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1 AFTER estado,
    ADD COLUMN fecha_baja DATETIME NULL AFTER activo,
    ADD COLUMN motivo_baja VARCHAR(255) NULL AFTER fecha_baja;

-- El estado ENUM ya soporta 'baja' desde esta migración en adelante.
ALTER TABLE instrumentos
    MODIFY COLUMN estado ENUM('disponible','en_uso','en_reparacion','baja') NOT NULL DEFAULT 'disponible';

-- ------------------------------------------------------------
-- 3) Control de préstamos: fecha de devolución esperada y vencidos
-- ------------------------------------------------------------
ALTER TABLE solicitudes
    ADD COLUMN fecha_devolucion_esperada DATE NULL AFTER fecha_solicitud;

ALTER TABLE solicitudes
    MODIFY COLUMN estado ENUM('activo','finalizado','vencido') NOT NULL DEFAULT 'activo';

-- ------------------------------------------------------------
-- 4) Índices recomendados para los listados/filtros más usados
-- ------------------------------------------------------------
ALTER TABLE instrumentos
    ADD INDEX idx_instrumentos_estado (estado),
    ADD INDEX idx_instrumentos_condicion (condicion),
    ADD INDEX idx_instrumentos_activo (activo),
    ADD INDEX idx_instrumentos_nombre (nombre);

ALTER TABLE solicitudes
    ADD INDEX idx_solicitudes_instrumento_estado (instrumento_id, estado),
    ADD INDEX idx_solicitudes_estado_fecha (estado, fecha_solicitud),
    ADD INDEX idx_solicitudes_devolucion_esperada (fecha_devolucion_esperada);

ALTER TABLE incidencias
    ADD INDEX idx_incidencias_estado (estado);

ALTER TABLE reportes_soporte
    ADD INDEX idx_reportes_estado (estado);

-- ------------------------------------------------------------
-- 5) Gestión de usuarios: campos adicionales para el nuevo módulo
--    (se completa en el módulo de Gestión de usuarios; se agregan
--    aquí las columnas para no requerir otra migración después)
-- ------------------------------------------------------------
ALTER TABLE usuarios
    ADD COLUMN correo VARCHAR(150) NULL AFTER usuario,
    ADD COLUMN ultimo_acceso DATETIME NULL AFTER activo,
    ADD COLUMN creado_por INT NULL AFTER creado_en,
    ADD CONSTRAINT fk_usuario_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL;
