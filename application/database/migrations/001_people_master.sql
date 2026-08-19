-- =============================================================================
-- 001 · Módulo de Personas (P1): la persona pasa a ser el maestro
--
-- Crea `people` y convierte `drivers` en un PERFIL de una persona mediante
-- `drivers.person_id`. No se toca ninguna FK existente (`trips.driver_id`,
-- `positions.driver_id`, `alerts.driver_id` siguen apuntando a `drivers.id`),
-- así que los datos históricos quedan intactos.
--
-- El backfill de personas a partir de los conductores existentes lo hace la
-- migración 002 (necesita lógica fila por fila).
-- =============================================================================

-- Maestro de personas
CREATE TABLE IF NOT EXISTS people (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id      BIGINT UNSIGNED NOT NULL,
  first_name      VARCHAR(80) NOT NULL,
  last_name       VARCHAR(80) NOT NULL,
  dni             VARCHAR(20) NULL,
  phone           VARCHAR(20) NULL,
  email           VARCHAR(150) NULL,
  job_title       VARCHAR(80) NULL,
  password_hash   VARCHAR(255) NULL,
  password_set_at DATETIME NULL,
  consent_at      DATETIME NULL,
  consent_note    VARCHAR(255) NULL,
  status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_person_dni (company_id, dni),
  INDEX idx_people_company (company_id),
  INDEX idx_people_status (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- El conductor pasa a ser el perfil de conducción de una persona
ALTER TABLE drivers
  ADD COLUMN person_id BIGINT UNSIGNED NULL AFTER company_id,
  ADD UNIQUE KEY uq_driver_person (person_id);

-- Acceso web de la persona (portal propio)
ALTER TABLE users
  ADD COLUMN person_id BIGINT UNSIGNED NULL AFTER driver_id,
  ADD INDEX idx_users_person (person_id);

ALTER TABLE users
  MODIFY COLUMN role ENUM('super_admin','company_admin','operator','driver','person') NOT NULL;

-- Cupo de personas independiente del de dispositivos, y módulos contratados:
-- una empresa puede contratar sólo flota, sólo personal, o ambos. Las empresas
-- existentes quedan con 'fleet', que es lo que tienen hoy.
ALTER TABLE companies
  ADD COLUMN person_quota INT UNSIGNED NOT NULL DEFAULT 0 AFTER device_quota,
  ADD COLUMN modules SET('fleet','people') NOT NULL DEFAULT 'fleet' AFTER person_quota;
