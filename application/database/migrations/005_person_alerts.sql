-- =============================================================================
-- 005 · Módulo de Personas (P5): alertas de personal
--
--  · Tipos de alerta nuevos (pánico, sin movimiento, fuera de puesto, misiones,
--    batería, app sin reportar, posición fuera de turno).
--  · Email de guardia por empresa: destino garantizado de las alertas críticas
--    (pánico y SOS), además de admins y operadores.
--  · `geofence_vehicles` se generaliza a `geofence_targets` para que una
--    geocerca pueda apuntar a vehículos y/o a personas.
-- =============================================================================

ALTER TABLE alert_rules MODIFY COLUMN type ENUM(
  'speed','geofence_enter','geofence_exit','offline','sos','idle',
  'panic','no_movement','off_post','mission_late','mission_missed',
  'low_battery','app_offline','out_of_shift'
) NOT NULL;

-- Email de guardia: siempre recibe pánico y SOS, exista o no una regla con
-- destinatarios extra. Es el canal que se pactó para emergencias.
ALTER TABLE companies
  ADD COLUMN emergency_email VARCHAR(150) NULL AFTER modules;

-- Destinatarios de geocerca, polimórficos.
CREATE TABLE IF NOT EXISTS geofence_targets (
  geofence_id BIGINT UNSIGNED NOT NULL,
  target_type ENUM('vehicle','person') NOT NULL,
  target_id   BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (geofence_id, target_type, target_id),
  INDEX idx_gt_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migra lo existente: toda fila de geofence_vehicles es un target de vehículo.
INSERT IGNORE INTO geofence_targets (geofence_id, target_type, target_id)
SELECT geofence_id, 'vehicle', vehicle_id FROM geofence_vehicles;

DROP TABLE IF EXISTS geofence_vehicles;
