-- ============================================================
-- Migración: incidencias sobre instrumentos con préstamo activo
-- Ejecutar solo si tu base de datos "musiteca" ya existía antes de esta
-- actualización (si vas a instalar desde cero, no es necesario: ya está
-- incluida en database/schema.sql).
--
-- Qué agrega a la tabla `incidencias`:
--   - solicitud_id: liga el reporte a la solicitud (detalle de préstamo)
--     que estaba activa al momento de reportar, cuando el instrumento
--     estaba prestado. NULL si se reportó sobre un instrumento que no
--     estaba en préstamo (flujo histórico, sin cambios).
--   - motivo: categoría del reporte (daño físico, mal funcionamiento,
--     piezas faltantes, desgaste, otro). NULL en reportes históricos.
--   - creado_por: usuario del sistema que registró el reporte (además del
--     campo de texto libre `reportado_por` que ya existía).
--   - decision_devolucion / fecha_decision: qué se decidió al devolver el
--     instrumento (volvió a disponible o pasó a mantenimiento) y cuándo.
--
-- Todas las columnas son NULL-ables: ningún reporte existente se pierde ni
-- cambia de significado, y ningún endpoint que ya funcionaba deja de
-- hacerlo.
-- ============================================================

USE musiteca;

ALTER TABLE incidencias
    ADD COLUMN solicitud_id INT NULL AFTER instrumento_id,
    ADD COLUMN motivo VARCHAR(150) NULL AFTER reportado_por,
    ADD COLUMN decision_devolucion ENUM('resuelto','mantenimiento') NULL AFTER estado,
    ADD COLUMN fecha_decision DATETIME NULL AFTER decision_devolucion,
    ADD COLUMN creado_por INT NULL AFTER fecha_decision;

ALTER TABLE incidencias
    ADD CONSTRAINT fk_incidencia_solicitud FOREIGN KEY (solicitud_id) REFERENCES solicitudes(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_incidencia_usuario FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    ADD INDEX idx_incidencias_solicitud (solicitud_id);
