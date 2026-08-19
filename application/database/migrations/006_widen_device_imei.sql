-- =============================================================================
-- 006 · `devices.imei` pasa a VARCHAR(64)
--
-- La columna nació para un IMEI (15 dígitos), pero desde P3 también guarda el
-- `install_id` de una instalación de la app, que en un equipo real es un UUID
-- de 36 caracteres. Con VARCHAR(20) el login desde la app fallaba con
-- "Data too long for column 'imei'": la app no podía registrarse nunca.
--
-- Se amplía en vez de truncar o hashear: un identificador recortado puede
-- colisionar entre equipos y mezclaría los recorridos de dos personas.
-- El índice UNIQUE se mantiene.
-- =============================================================================

ALTER TABLE devices
  MODIFY COLUMN imei VARCHAR(64) NOT NULL;
