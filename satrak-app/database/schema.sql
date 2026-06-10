-- =============================================================================
-- Satrak — Plataforma de Tracking · Esquema MySQL (DDL)
-- Fuente de verdad: satrak-plataforma-tracking-spec.md §7
--
-- Convenciones (§7):
--   · utf8mb4 + InnoDB en todas las tablas.
--   · Toda tabla de negocio lleva company_id (salvo `companies` y el super admin).
--   · Borrado lógico preferido (columna `status`) en entidades con historial;
--     las asignaciones/vínculos usan `unassigned_at`/`unlinked_at` (NULL = activa).
--   · El módulo de captura (fuera de alcance) SOLO escribe `positions` y
--     `device_events`; el procesador de esta plataforma resuelve `driver_id` (§8).
--   · PIN del conductor: 4 a 10 caracteres, alfanumérico, único por empresa.
--     La columna es VARCHAR(10); la longitud mínima/alfanumérico se valida en la
--     app (cliente + servidor) según config tracking.pin_min_length/max_length.
--
-- Import:
--   mysql -h localhost -u <user> -p <db> < database/schema.sql
--   (o phpMyAdmin → Importar). La base ya debe existir y estar vacía.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';

-- -----------------------------------------------------------------------------
-- Empresas (tenants)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS companies (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  slug          VARCHAR(120) NOT NULL UNIQUE,
  status        ENUM('active','suspended') NOT NULL DEFAULT 'active',
  device_quota  INT UNSIGNED NOT NULL DEFAULT 0,      -- cupo máximo de dispositivos
  timezone      VARCHAR(40) NOT NULL DEFAULT 'America/Argentina/Buenos_Aires',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Conductores (se crea antes que users porque users.driver_id la referencia)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS drivers (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id    BIGINT UNSIGNED NOT NULL,
  first_name    VARCHAR(80) NOT NULL,
  last_name     VARCHAR(80) NOT NULL,
  dni           VARCHAR(20) NULL,
  license_number VARCHAR(30) NULL,
  phone         VARCHAR(20) NULL,
  email         VARCHAR(150) NULL,
  pin           VARCHAR(10) NULL,                      -- PIN único dentro de la empresa (4-10, alfanum.)
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_driver_pin (company_id, pin),          -- PIN único por empresa (NULLs no colisionan)
  INDEX (company_id),
  CONSTRAINT fk_drivers_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Usuarios
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id    BIGINT UNSIGNED NULL,                  -- NULL = super admin
  driver_id     BIGINT UNSIGNED NULL,                  -- set si role='driver'
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('super_admin','company_admin','operator','driver') NOT NULL,
  status        ENUM('active','disabled') NOT NULL DEFAULT 'active',
  last_login_at DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (company_id), INDEX (role),
  CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_users_driver  FOREIGN KEY (driver_id)  REFERENCES drivers (id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Vehículos
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vehicles (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id    BIGINT UNSIGNED NOT NULL,
  plate         VARCHAR(15) NOT NULL,                  -- patente
  brand         VARCHAR(50) NULL,
  model         VARCHAR(50) NULL,
  year          SMALLINT UNSIGNED NULL,
  type          ENUM('auto','moto','camion','utilitario','otro') NOT NULL DEFAULT 'auto',
  color         VARCHAR(30) NULL,
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_plate (company_id, plate),
  INDEX (company_id),
  CONSTRAINT fk_vehicles_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Dispositivos
-- (last_position_id apunta a positions: la FK se agrega al final, por orden.)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS devices (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id      BIGINT UNSIGNED NOT NULL,
  imei            VARCHAR(20) NOT NULL UNIQUE,
  label           VARCHAR(60) NULL,                    -- alias
  model           VARCHAR(50) NULL,
  protocol        VARCHAR(30) NULL,                    -- gt06, teltonika, etc. (informativo)
  sim_iccid       VARCHAR(25) NULL,
  has_pin         TINYINT(1) NOT NULL DEFAULT 0,       -- dispositivo con identificación por PIN
  status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  last_position_id BIGINT UNSIGNED NULL,               -- denormalizado p/ mapa en vivo
  last_seen_at    DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (company_id), INDEX (has_pin),
  CONSTRAINT fk_devices_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Posiciones (las escribe el módulo de captura; alto volumen)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS positions (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id   BIGINT UNSIGNED NOT NULL,
  device_id    BIGINT UNSIGNED NOT NULL,
  ts           DATETIME NOT NULL,                      -- hora del dispositivo
  lat          DECIMAL(10,7) NOT NULL,
  lon          DECIMAL(10,7) NOT NULL,
  speed        SMALLINT UNSIGNED NULL,                 -- km/h
  heading      SMALLINT UNSIGNED NULL,                 -- 0-359
  altitude     SMALLINT NULL,
  ignition     TINYINT(1) NULL,
  satellites   TINYINT UNSIGNED NULL,
  pin_code     VARCHAR(10) NULL,                       -- PIN crudo informado por el equipo
  driver_id    BIGINT UNSIGNED NULL,                   -- resuelto por el procesador
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (device_id, ts), INDEX (company_id, ts), INDEX (driver_id),
  CONSTRAINT fk_positions_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_positions_device  FOREIGN KEY (device_id)  REFERENCES devices (id)   ON DELETE CASCADE,
  CONSTRAINT fk_positions_driver  FOREIGN KEY (driver_id)  REFERENCES drivers (id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- FK diferida: devices.last_position_id → positions.id (referencia circular).
ALTER TABLE devices
  ADD CONSTRAINT fk_devices_last_position
  FOREIGN KEY (last_position_id) REFERENCES positions (id) ON DELETE SET NULL;

-- -----------------------------------------------------------------------------
-- Asignación dispositivo ↔ vehículo (1:1 activa, con historial)
-- Regla "una sola activa" (unassigned_at IS NULL) se garantiza en código.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS device_vehicle_assignments (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id    BIGINT UNSIGNED NOT NULL,
  device_id     BIGINT UNSIGNED NOT NULL,
  vehicle_id    BIGINT UNSIGNED NOT NULL,
  assigned_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unassigned_at DATETIME NULL,                         -- NULL = asignación activa
  INDEX (company_id), INDEX (device_id), INDEX (vehicle_id),
  CONSTRAINT fk_dva_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_dva_device  FOREIGN KEY (device_id)  REFERENCES devices (id)   ON DELETE CASCADE,
  CONSTRAINT fk_dva_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Vínculo dispositivo ↔ conductor (allowlist PIN y/o conductor por defecto)
--   · Dispositivo CON PIN:  0..N vínculos activos con is_default=0 = allowlist.
--   · Dispositivo SIN PIN:  exactamente 1 vínculo activo con is_default=1.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS device_driver_links (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id    BIGINT UNSIGNED NOT NULL,
  device_id     BIGINT UNSIGNED NOT NULL,
  driver_id     BIGINT UNSIGNED NOT NULL,
  is_default    TINYINT(1) NOT NULL DEFAULT 0,         -- true: conductor por defecto (dispositivos SIN PIN)
  linked_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unlinked_at   DATETIME NULL,                         -- NULL = vínculo activo
  INDEX (company_id), INDEX (device_id), INDEX (driver_id),
  CONSTRAINT fk_ddl_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_ddl_device  FOREIGN KEY (device_id)  REFERENCES devices (id)   ON DELETE CASCADE,
  CONSTRAINT fk_ddl_driver  FOREIGN KEY (driver_id)  REFERENCES drivers (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Eventos crudos del dispositivo (los escribe el módulo de captura)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS device_events (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id   BIGINT UNSIGNED NOT NULL,
  device_id    BIGINT UNSIGNED NOT NULL,
  ts           DATETIME NOT NULL,
  event_type   VARCHAR(30) NOT NULL,                   -- sos, ignition_on/off, pin_set, pin_cleared, power_cut, low_battery
  pin_code     VARCHAR(10) NULL,
  raw          JSON NULL,
  processed    TINYINT(1) NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (device_id, ts), INDEX (processed),
  CONSTRAINT fk_device_events_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_device_events_device  FOREIGN KEY (device_id)  REFERENCES devices (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Viajes (los construye el procesador)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS trips (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id   BIGINT UNSIGNED NOT NULL,
  device_id    BIGINT UNSIGNED NOT NULL,
  vehicle_id   BIGINT UNSIGNED NULL,
  driver_id    BIGINT UNSIGNED NULL,                   -- NULL = no identificado
  started_at   DATETIME NOT NULL,
  ended_at     DATETIME NULL,
  start_lat    DECIMAL(10,7) NULL, start_lon DECIMAL(10,7) NULL,
  end_lat      DECIMAL(10,7) NULL, end_lon DECIMAL(10,7) NULL,
  distance_km  DECIMAL(8,2) NULL,
  max_speed    SMALLINT UNSIGNED NULL,
  avg_speed    SMALLINT UNSIGNED NULL,
  duration_sec INT UNSIGNED NULL,
  points_count INT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (company_id, started_at), INDEX (device_id), INDEX (driver_id), INDEX (vehicle_id),
  CONSTRAINT fk_trips_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_trips_device  FOREIGN KEY (device_id)  REFERENCES devices (id)   ON DELETE CASCADE,
  CONSTRAINT fk_trips_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id)  ON DELETE SET NULL,
  CONSTRAINT fk_trips_driver  FOREIGN KEY (driver_id)  REFERENCES drivers (id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Geocercas
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS geofences (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id   BIGINT UNSIGNED NOT NULL,
  name         VARCHAR(80) NOT NULL,
  shape        ENUM('circle','polygon') NOT NULL,
  geometry     JSON NOT NULL,                          -- circle:{lat,lon,radius_m} | polygon:[[lat,lon],...]
  active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (company_id),
  CONSTRAINT fk_geofences_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Vehículos alcanzados por una geocerca (vacío = todos)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS geofence_vehicles (
  geofence_id  BIGINT UNSIGNED NOT NULL,
  vehicle_id   BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (geofence_id, vehicle_id),
  INDEX (vehicle_id),
  CONSTRAINT fk_gv_geofence FOREIGN KEY (geofence_id) REFERENCES geofences (id) ON DELETE CASCADE,
  CONSTRAINT fk_gv_vehicle  FOREIGN KEY (vehicle_id)  REFERENCES vehicles (id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Reglas de alerta
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS alert_rules (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id   BIGINT UNSIGNED NOT NULL,
  type         ENUM('speed','geofence_enter','geofence_exit','offline','sos','idle') NOT NULL,
  params       JSON NULL,        -- speed:{max_kmh} geofence:{geofence_id} offline:{minutes} idle:{minutes}
  channels     JSON NOT NULL,    -- ["inapp","email"]
  recipients   JSON NULL,        -- emails extra
  active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (company_id, type),
  CONSTRAINT fk_alert_rules_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Alertas generadas (registro)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS alerts (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id      BIGINT UNSIGNED NOT NULL,
  rule_id         BIGINT UNSIGNED NULL,
  device_id       BIGINT UNSIGNED NULL,
  vehicle_id      BIGINT UNSIGNED NULL,
  driver_id       BIGINT UNSIGNED NULL,
  type            VARCHAR(30) NOT NULL,
  severity        ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
  message         VARCHAR(255) NOT NULL,
  lat             DECIMAL(10,7) NULL, lon DECIMAL(10,7) NULL,
  ts              DATETIME NOT NULL,
  acknowledged_at DATETIME NULL,
  acknowledged_by BIGINT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (company_id, ts), INDEX (type), INDEX (acknowledged_at),
  CONSTRAINT fk_alerts_company FOREIGN KEY (company_id) REFERENCES companies (id)   ON DELETE CASCADE,
  CONSTRAINT fk_alerts_rule    FOREIGN KEY (rule_id)    REFERENCES alert_rules (id) ON DELETE SET NULL,
  CONSTRAINT fk_alerts_device  FOREIGN KEY (device_id)  REFERENCES devices (id)     ON DELETE SET NULL,
  CONSTRAINT fk_alerts_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id)    ON DELETE SET NULL,
  CONSTRAINT fk_alerts_driver  FOREIGN KEY (driver_id)  REFERENCES drivers (id)     ON DELETE SET NULL,
  CONSTRAINT fk_alerts_ackby   FOREIGN KEY (acknowledged_by) REFERENCES users (id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Notificaciones in-app
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id  BIGINT UNSIGNED NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  alert_id    BIGINT UNSIGNED NULL,
  title       VARCHAR(120) NOT NULL,
  body        VARCHAR(255) NULL,
  read_at     DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, read_at),
  CONSTRAINT fk_notifications_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_user    FOREIGN KEY (user_id)    REFERENCES users (id)     ON DELETE CASCADE,
  CONSTRAINT fk_notifications_alert   FOREIGN KEY (alert_id)   REFERENCES alerts (id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Auditoría (company_id/user_id sin FK estricta: deben sobrevivir a borrados)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id  BIGINT UNSIGNED NULL,
  user_id     BIGINT UNSIGNED NULL,
  action      VARCHAR(60) NOT NULL,
  entity_type VARCHAR(40) NULL,
  entity_id   BIGINT UNSIGNED NULL,
  changes     JSON NULL,
  ip          VARCHAR(45) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (company_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Recupero de contraseña
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email       VARCHAR(150) NOT NULL,
  token_hash  VARCHAR(255) NOT NULL,
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- Fin del esquema. El primer Super Admin se crea con: php bin/create_admin.php
-- =============================================================================
