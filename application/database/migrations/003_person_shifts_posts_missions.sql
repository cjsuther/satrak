-- =============================================================================
-- 003 · Módulo de Personas (P2): jornada, puesto y misiones
--
-- Reglas de negocio (spec §2):
--   · Sólo se rastrea DENTRO de la jornada configurada (no hay fichada).
--   · Durante la jornada la persona debe estar en su PUESTO (geocerca) o tener
--     una MISIÓN vigente que la autorice a moverse de un lugar a otro.
-- =============================================================================

-- Jornada laboral: ventanas semanales de trackeo.
-- Si end_time <= start_time, la ventana cruza la medianoche.
CREATE TABLE IF NOT EXISTS person_shifts (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id   BIGINT UNSIGNED NOT NULL,
  person_id    BIGINT UNSIGNED NOT NULL,
  weekday      TINYINT UNSIGNED NOT NULL,          -- 1=lunes .. 7=domingo (ISO-8601)
  start_time   TIME NOT NULL,
  end_time     TIME NOT NULL,
  active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_shifts_company (company_id),
  INDEX idx_shifts_person (person_id, weekday, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Excepciones puntuales: franco/licencia ('off') o turno extra ('extra').
CREATE TABLE IF NOT EXISTS person_shift_exceptions (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id   BIGINT UNSIGNED NOT NULL,
  person_id    BIGINT UNSIGNED NOT NULL,
  date         DATE NOT NULL,
  kind         ENUM('off','extra') NOT NULL,
  start_time   TIME NULL,                          -- requerido si kind='extra'
  end_time     TIME NULL,
  note         VARCHAR(120) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_exc_company (company_id),
  INDEX idx_exc_person_date (person_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Puesto: dónde debe estar durante la jornada.
CREATE TABLE IF NOT EXISTS person_posts (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id   BIGINT UNSIGNED NOT NULL,
  person_id    BIGINT UNSIGNED NOT NULL,
  geofence_id  BIGINT UNSIGNED NOT NULL,
  grace_min    SMALLINT UNSIGNED NOT NULL DEFAULT 10,   -- tolerancia fuera del puesto
  valid_from   DATE NULL,
  valid_to     DATE NULL,                               -- NULL = vigente
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_posts_company (company_id),
  INDEX idx_posts_person (person_id, valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Misión: traslado autorizado, dentro de una ventana horaria.
-- Las carga el operador (decisión §11.3); la persona sólo inicia y marca llegada.
CREATE TABLE IF NOT EXISTS person_missions (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id         BIGINT UNSIGNED NOT NULL,
  person_id          BIGINT UNSIGNED NOT NULL,
  origin_geofence_id BIGINT UNSIGNED NULL,        -- NULL = desde el puesto vigente
  dest_geofence_id   BIGINT UNSIGNED NOT NULL,
  scheduled_start    DATETIME NOT NULL,
  scheduled_end      DATETIME NOT NULL,
  status  ENUM('pending','in_progress','completed','missed','cancelled')
                     NOT NULL DEFAULT 'pending',
  started_at         DATETIME NULL,
  arrived_at         DATETIME NULL,
  vehicle_id         BIGINT UNSIGNED NULL,        -- opcional: viaja en un vehículo de la flota
  notes              VARCHAR(255) NULL,
  created_by         BIGINT UNSIGNED NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_missions_company (company_id, scheduled_start),
  INDEX idx_missions_person (person_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
