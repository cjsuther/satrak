-- =============================================================================
-- 004 · Módulo de Personas (P3): el dispositivo de la persona y la app
--
-- La app móvil se registra como un `device` (kind='person', source='app') y
-- escribe en `positions`/`device_events` respetando el contrato existente, así
-- que el procesador, los viajes, las geocercas y el mapa se reutilizan.
-- =============================================================================

-- Qué rastrea el equipo y cómo reporta. Lo existente es flota por hardware.
ALTER TABLE devices
  ADD COLUMN kind   ENUM('vehicle','person') NOT NULL DEFAULT 'vehicle'  AFTER company_id,
  ADD COLUMN source ENUM('hardware','app')   NOT NULL DEFAULT 'hardware' AFTER kind,
  ADD INDEX idx_devices_kind (company_id, kind);

-- Asignación dispositivo ↔ persona (espejo de device_vehicle_assignments).
CREATE TABLE IF NOT EXISTS device_person_assignments (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id    BIGINT UNSIGNED NOT NULL,
  device_id     BIGINT UNSIGNED NOT NULL,
  person_id     BIGINT UNSIGNED NOT NULL,
  assigned_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unassigned_at DATETIME NULL,                      -- NULL = asignación activa
  INDEX idx_dpa_company (company_id),
  INDEX idx_dpa_device (device_id),
  INDEX idx_dpa_person (person_id, unassigned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sesión de la app: una sola activa por persona (login nuevo revoca la anterior).
CREATE TABLE IF NOT EXISTS person_app_sessions (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id     BIGINT UNSIGNED NOT NULL,
  person_id      BIGINT UNSIGNED NOT NULL,
  device_id      BIGINT UNSIGNED NOT NULL,
  install_id     VARCHAR(64) NOT NULL,              -- id de instalación de la app
  token_hash     VARCHAR(64) NOT NULL,              -- sha256 del bearer
  platform       ENUM('android','ios') NOT NULL,
  os_version     VARCHAR(20) NULL,
  app_version    VARCHAR(20) NULL,
  model          VARCHAR(60) NULL,
  push_token     VARCHAR(255) NULL,
  battery_pct    TINYINT UNSIGNED NULL,
  perms_ok       TINYINT(1) NULL,                   -- permisos de ubicación en 2º plano
  last_seen_at   DATETIME NULL,
  revoked_at     DATETIME NULL,                     -- NULL = sesión vigente
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_app_install (company_id, install_id),
  UNIQUE KEY uq_app_token (token_hash),
  INDEX idx_app_person (person_id, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Telemetría que manda la app y el esquema todavía no tenía.
ALTER TABLE positions
  ADD COLUMN accuracy_m  SMALLINT UNSIGNED NULL AFTER satellites,
  ADD COLUMN battery_pct TINYINT UNSIGNED NULL AFTER accuracy_m;

-- NOTA: no se agrega UNIQUE (device_id, ts). Sobre una tabla de alto volumen y
-- con datos históricos ya cargados el ALTER puede fallar por duplicados
-- preexistentes y bloquea la tabla. La ingesta por lotes descarta los `ts` que
-- ya existen para ese dispositivo antes de insertar (ver AppApiController).

-- Atribución a persona en el pipeline.
ALTER TABLE trips
  ADD COLUMN person_id  BIGINT UNSIGNED NULL AFTER driver_id,
  ADD COLUMN mission_id BIGINT UNSIGNED NULL AFTER person_id,
  ADD INDEX idx_trips_person (person_id);

ALTER TABLE alerts
  ADD COLUMN person_id BIGINT UNSIGNED NULL AFTER driver_id,
  ADD INDEX idx_alerts_person (person_id);
