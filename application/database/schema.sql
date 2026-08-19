-- =============================================================================
-- Satrak — Plataforma de Tracking · Esquema MySQL (spec §7)
-- utf8mb4 · InnoDB · multi-tenant por company_id
--
-- PIN (decisión §2 del prompt): longitud 4–10 caracteres, numérico por defecto
-- pero se acepta alfanumérico, único por empresa. La columna es VARCHAR(10);
-- la longitud mínima (4) y el formato se validan en cliente y servidor.
--
-- Import:
--   mysql -u USER -p NOMBRE_DB < database/schema.sql
--   (o `php bin/import_schema.php` usando la config de config/config.php)
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- Empresas (tenants)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS companies (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  slug          VARCHAR(120) NOT NULL UNIQUE,
  status        ENUM('active','suspended') NOT NULL DEFAULT 'active',
  device_quota  INT UNSIGNED NOT NULL DEFAULT 0,      -- cupo máximo de dispositivos
  person_quota  INT UNSIGNED NOT NULL DEFAULT 0,      -- cupo máximo de personas rastreadas
  modules       SET('fleet','people') NOT NULL DEFAULT 'fleet',  -- módulos contratados
  emergency_email VARCHAR(150) NULL,                   -- guardia: recibe siempre pánico y SOS
  timezone      VARCHAR(40) NOT NULL DEFAULT 'America/Argentina/Buenos_Aires',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Usuarios
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id    BIGINT UNSIGNED NULL,                  -- NULL = super admin
  driver_id     BIGINT UNSIGNED NULL,                  -- set si role='driver'
  person_id     BIGINT UNSIGNED NULL,                  -- set si role='person'
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('super_admin','company_admin','operator','driver','person') NOT NULL,
  status        ENUM('active','disabled') NOT NULL DEFAULT 'active',
  last_login_at DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (company_id), INDEX (role), INDEX (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Personas (maestro). Un conductor es el PERFIL DE CONDUCCIÓN de una persona.
-- `password_hash` es la clave de la app móvil (no da acceso al panel web; para
-- eso se le crea un `user` con role='person').
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS people (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id      BIGINT UNSIGNED NOT NULL,
  first_name      VARCHAR(80) NOT NULL,
  last_name       VARCHAR(80) NOT NULL,
  dni             VARCHAR(20) NULL,
  phone           VARCHAR(20) NULL,
  email           VARCHAR(150) NULL,
  job_title       VARCHAR(80) NULL,                    -- cargo (informativo)
  password_hash   VARCHAR(255) NULL,                   -- acceso a la app móvil
  password_set_at DATETIME NULL,
  consent_at      DATETIME NULL,                       -- consentimiento informado (Ley 25.326)
  consent_note    VARCHAR(255) NULL,
  status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_person_dni (company_id, dni),
  INDEX idx_people_company (company_id),
  INDEX idx_people_status (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Conductores (perfil de conducción de una persona: licencia + PIN)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS drivers (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id    BIGINT UNSIGNED NOT NULL,
  person_id     BIGINT UNSIGNED NULL UNIQUE,           -- persona titular del perfil
  first_name    VARCHAR(80) NOT NULL,
  last_name     VARCHAR(80) NOT NULL,
  dni           VARCHAR(20) NULL,
  license_number VARCHAR(30) NULL,
  phone         VARCHAR(20) NULL,
  email         VARCHAR(150) NULL,
  pin           VARCHAR(10) NULL,                      -- PIN 4–10 (validación en app); único por empresa
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_driver_pin (company_id, pin),          -- PIN único por empresa
  INDEX (company_id)
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
  INDEX (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Dispositivos
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS devices (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id      BIGINT UNSIGNED NOT NULL,
  imei            VARCHAR(64) NOT NULL UNIQUE,     -- IMEI del equipo, o install_id de la app
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
  INDEX (company_id), INDEX (has_pin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Asignación dispositivo ↔ vehículo (1:1 activa, con historial)
-- Regla (en código): un device y un vehicle no pueden tener más de una fila
-- con unassigned_at IS NULL.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS device_vehicle_assignments (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id    BIGINT UNSIGNED NOT NULL,
  device_id     BIGINT UNSIGNED NOT NULL,
  vehicle_id    BIGINT UNSIGNED NOT NULL,
  assigned_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unassigned_at DATETIME NULL,                         -- NULL = asignación activa
  INDEX (company_id), INDEX (device_id), INDEX (vehicle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Vínculo dispositivo ↔ conductor (allowlist PIN y/o conductor por defecto)
-- Dispositivo CON PIN: 0..N vínculos activos = allowlist (is_default=0).
-- Dispositivo SIN PIN: exactamente 1 vínculo activo con is_default=1.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS device_driver_links (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id    BIGINT UNSIGNED NOT NULL,
  device_id     BIGINT UNSIGNED NOT NULL,
  driver_id     BIGINT UNSIGNED NOT NULL,
  is_default    TINYINT(1) NOT NULL DEFAULT 0,         -- true: conductor por defecto (dispositivos SIN PIN)
  linked_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unlinked_at   DATETIME NULL,                         -- NULL = vínculo activo
  INDEX (company_id), INDEX (device_id), INDEX (driver_id)
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
  INDEX (device_id, ts), INDEX (company_id, ts), INDEX (driver_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Eventos crudos del dispositivo (los escribe el módulo de captura)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS device_events (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id   BIGINT UNSIGNED NOT NULL,
  device_id    BIGINT UNSIGNED NOT NULL,
  ts           DATETIME NOT NULL,
  event_type   VARCHAR(30) NOT NULL,                   -- sos, ignition_on, ignition_off, pin_set, pin_cleared, power_cut, low_battery
  pin_code     VARCHAR(10) NULL,
  raw          JSON NULL,
  processed    TINYINT(1) NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (device_id, ts), INDEX (processed)
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
  INDEX (company_id, started_at), INDEX (device_id), INDEX (driver_id), INDEX (vehicle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Estado del procesador por dispositivo (§12, idempotencia)
-- Mantiene el PIN vigente (§8), el conductor resuelto, el cursor de la última
-- posición ya procesada y el viaje abierto. Permite que `bin/processor.php`
-- corra cada minuto sin reprocesar ni duplicar.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS processor_state (
  device_id         BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  company_id        BIGINT UNSIGNED NOT NULL,
  current_pin       VARCHAR(10) NULL,                    -- PIN vigente por dispositivo
  current_driver_id BIGINT UNSIGNED NULL,                -- conductor resuelto vigente
  last_position_id  BIGINT UNSIGNED NULL,                -- cursor: última posición procesada
  open_trip_id      BIGINT UNSIGNED NULL,                -- viaje en curso (NULL = sin viaje)
  alert_state       JSON NULL,                           -- motor de alertas: {speeding, idle_since, idle_fired, inside:[geofence_id]}
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (company_id)
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
  INDEX (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Alcance de una geocerca, por tipo (sin filas de un tipo = todos los de ese tipo)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS geofence_targets (
  geofence_id BIGINT UNSIGNED NOT NULL,
  target_type ENUM('vehicle','person') NOT NULL,
  target_id   BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (geofence_id, target_type, target_id),
  INDEX idx_gt_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Reglas de alerta
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS alert_rules (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id   BIGINT UNSIGNED NOT NULL,
  type         ENUM('speed','geofence_enter','geofence_exit','offline','sos','idle',
                    'panic','no_movement','off_post','mission_late','mission_missed',
                    'low_battery','app_offline','out_of_shift') NOT NULL,
  params       JSON NULL,        -- speed:{max_kmh} geofence:{geofence_id} offline:{minutes} idle:{minutes}
  channels     JSON NOT NULL,    -- ["inapp","email"]
  recipients   JSON NULL,        -- emails extra
  active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (company_id, type)
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
  person_id       BIGINT UNSIGNED NULL,
  type            VARCHAR(30) NOT NULL,
  severity        ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
  message         VARCHAR(255) NOT NULL,
  lat             DECIMAL(10,7) NULL, lon DECIMAL(10,7) NULL,
  ts              DATETIME NOT NULL,
  acknowledged_at DATETIME NULL,
  acknowledged_by BIGINT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (company_id, ts), INDEX (type), INDEX (acknowledged_at)
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
  INDEX (user_id, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Auditoría
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
