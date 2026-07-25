-- ============================================================
-- Migración: agrega la tabla `reportes_soporte`
-- Ejecutar solo si tu base de datos "musiteca" ya existía antes de esta
-- actualización (si vas a instalar desde cero, no es necesario: ya está
-- incluida en database/schema.sql).
-- ============================================================

USE musiteca;

CREATE TABLE IF NOT EXISTS reportes_soporte (
    id INT AUTO_INCREMENT PRIMARY KEY,
    modulo ENUM('plataforma_web','aplicacion_operativa') NOT NULL,
    num_inventario VARCHAR(40) NULL,
    descripcion TEXT NOT NULL,
    captura_archivo VARCHAR(255) NULL,
    estado ENUM('pendiente','en_atencion','resuelto') NOT NULL DEFAULT 'pendiente',
    creado_por INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reporte_usuario FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB;
