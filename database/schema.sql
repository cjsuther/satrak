-- Satrak — esquema de la tabla de leads (opcional).
-- Importar en phpMyAdmin si se usa persistencia. El sitio funciona sin DB (mail como garantía).

CREATE TABLE IF NOT EXISTS leads (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL,
  email VARCHAR(120) NOT NULL,
  telefono VARCHAR(20) NOT NULL,
  empresa VARCHAR(100) NULL,
  servicio ENUM('vehiculos','personal','ambos') NOT NULL,
  unidades VARCHAR(20) NULL,
  mensaje TEXT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
