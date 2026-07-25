-- ============================================================
-- Migración: préstamos múltiples + estado de devolución
-- Ejecutar solo si tu base de datos "musiteca" ya existía antes de esta
-- actualización (si vas a instalar desde cero, no es necesario: ya está
-- incluida en database/schema.sql).
--
-- Qué agrega:
--   1) Tabla `prestamos`: cabecera de un préstamo (un solicitante, una
--      operación) que puede agrupar uno o varios instrumentos.
--   2) Columna `solicitudes.prestamo_id`: liga cada instrumento prestado
--      (fila existente en `solicitudes`, que pasa a ser el "detalle" del
--      préstamo) con su cabecera en `prestamos`. Es NULL para los
--      préstamos históricos registrados antes de esta migración, así que
--      no se pierde ni se altera ningún dato existente.
--   3) Columnas `solicitudes.condicion_devolucion` y
--      `solicitudes.observaciones_devolucion`: registran el estado en el
--      que el instrumento fue entregado al devolverlo.
--
-- Ningún endpoint ni consulta existente deja de funcionar: todas las
-- columnas nuevas son NULL-ables y `solicitudes` conserva exactamente su
-- estructura y significado actuales (una fila = un instrumento prestado).
-- ============================================================

USE musiteca;

CREATE TABLE IF NOT EXISTS prestamos (
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

ALTER TABLE solicitudes
    ADD COLUMN prestamo_id INT NULL AFTER id,
    ADD COLUMN condicion_devolucion ENUM('excelente','bueno','desgaste','danado','incompleto') NULL AFTER fecha_regreso,
    ADD COLUMN observaciones_devolucion TEXT NULL AFTER condicion_devolucion;

ALTER TABLE solicitudes
    ADD CONSTRAINT fk_solicitud_prestamo FOREIGN KEY (prestamo_id) REFERENCES prestamos(id) ON DELETE CASCADE,
    ADD INDEX idx_solicitudes_prestamo (prestamo_id);
