-- ============================================================
-- Musiteca - Migración v3: índices para Gestión de usuarios
-- Ejecutar después de migracion_v2_nucleo.sql
-- ============================================================

USE musiteca;

ALTER TABLE usuarios
    ADD UNIQUE INDEX idx_usuarios_correo (correo),
    ADD INDEX idx_usuarios_rol (rol),
    ADD INDEX idx_usuarios_activo (activo);
