# Satrak — Plataforma de Tracking (v1)
## Especificación técnica para desarrollo con Claude Code

> **Qué construir:** el sistema web completo (backend + frontend) de la plataforma de seguimiento satelital de Satrak, multi-empresa, para desplegar en un **subdominio en Hostinger**: `app.satrak.online`.
>
> **Qué NO construir en esta instancia** (ver §22): el módulo de captura de datos de los dispositivos y la app móvil del conductor. Esta plataforma **define el esquema de datos compartido** que esos módulos usarán y consume esos datos; además incluye un **generador de datos de prueba (mock)** para ser demostrable sin el módulo de captura.
>
> **Dominio:** `app.satrak.online` · **Idioma:** es-AR · **TZ:** America/Argentina/Buenos_Aires · **Unidades:** métricas (km, km/h) · **Versión:** 1.0

---

## 1. Alcance

**Incluido (v1)**
- Multi-empresa (multi-tenant) en una sola base con `company_id`.
- Roles: Super Admin (Satrak), Admin de Empresa, Operador, Conductor.
- ABM de empresas, usuarios, vehículos, dispositivos, conductores, asignaciones y PINs.
- Asignación dispositivo↔vehículo (1:1 con historial) y dispositivo↔conductor con **lógica de PIN** (núcleo del sistema, §8).
- Mapa en vivo (Leaflet + OSM, actualización por polling).
- Historial y reproducción de recorridos.
- Geocercas y motor de alertas (velocidad, entrada/salida de geocerca, offline, SOS, detención).
- Registro de alertas + notificaciones in-app + email.
- Reportes por **vehículo** y por **conductor** (vía PIN).
- Portal del conductor (login limitado a ver su propia información y editar su perfil).
- Generador de datos mock.
- Registro de auditoría.

**Excluido (ver §22 y roadmap §23):** módulo de captura/parseo de dispositivos, app móvil del conductor, comandos a dispositivo / corte de motor, facturación online, WhatsApp, 2FA, gestión documental con vencimientos.

---

## 2. Stack y entorno

| Componente | Elección |
|---|---|
| Lenguaje | **PHP 8.1+** |
| Framework | **Slim 4** (router + middlewares + DI container `php-di`) |
| Plantillas | **Twig** (`slim/twig-view`) |
| Base de datos | **MySQL/MariaDB** vía **PDO** (sin ORM pesado; query builder simple o repos con PDO) |
| Auth | Sesiones PHP nativas + hashing `password_hash` (bcrypt/argon2id) |
| Mail | **PHPMailer** vía SMTP |
| Mapas | **Leaflet** + tiles **OpenStreetMap** (sin API key) |
| Frontend | HTML + CSS propio (tokens Satrak) + **JS vanilla** (sin framework, sin build step). Leaflet por CDN o local. |
| Tareas | **Cron** (procesador de alertas/viajes + purga). Ver §12 y §19. |

> **Por qué Slim y no Laravel:** alcance acotado, control fino y despliegue simple en hosting compartido. Slim da routing/middleware/DI sin el peso de Laravel.

### Requisitos Hostinger (confirmar en el plan contratado)
- PHP 8.1+ con `pdo_mysql`, `mbstring`, `openssl`, `curl`, `json`, `fileinfo`.
- **Composer** (disponible vía SSH en planes Business/Cloud; si no, subir `vendor/` ya instalado).
- **Cron jobs** (hPanel los ofrece en Business/Cloud). Si el plan no tiene cron real → usar el **cron de hPanel** apuntando a un endpoint CLI, o un servicio externo (cron-job.org) que pegue a una URL protegida por token. **Esto hay que confirmarlo** (ver §24).
- Subdominio `app.satrak.online` con su propio document root y SSL (Let's Encrypt).

---

## 3. Arquitectura

- **Multi-tenant: base única, columna `company_id`** en toda tabla de negocio. Aislamiento garantizado por un **TenantScope** central (ver §4), nunca confiando solo en el front.
- Capas: `routes` → `middleware` (auth, tenant, rbac, csrf) → `controllers` → `services` → `repositories (PDO)` → MySQL.
- El **procesador** (CLI, vía cron) es independiente del request web: lee `positions`/`device_events` crudos (que escribirá el futuro módulo de captura) y produce `trips`, `alerts`, `notifications`.
- El **mock generator** simula al módulo de captura escribiendo en `positions`/`device_events`.

---

## 4. Multi-tenant: reglas de aislamiento

- Toda query de negocio se filtra por `company_id`.
- El `company_id` se deriva **del usuario en sesión**, nunca de un parámetro del request (excepto el Super Admin, que puede operar “en contexto” de una empresa elegida explícitamente).
- Implementar un **middleware `TenantMiddleware`** que inyecta el `company_id` activo en el request, y un helper de repositorio que lo aplica automáticamente.
- **Super Admin** (`company_id = NULL`): no pertenece a una empresa. Para ver datos de una empresa usa un selector que setea un “company context” en sesión; las acciones quedan auditadas.
- Validación defensiva: antes de leer/escribir cualquier entidad por id, verificar que su `company_id` coincide con el contexto. Acceso cruzado ⇒ 404 (no 403, para no filtrar existencia).

---

## 5. Roles y permisos

Jerarquía: **Super Admin (Satrak) → Admin de Empresa → Operador → Conductor.**

| Capacidad | Super Admin | Admin Empresa | Operador | Conductor |
|---|:--:|:--:|:--:|:--:|
| ABM de empresas + cupo de dispositivos | ✓ | — | — | — |
| Crear/editar admins de empresa | ✓ | — | — | — |
| Operar “en contexto” de una empresa | ✓ | — | — | — |
| ABM usuarios (operador/conductor) de su empresa | ✓* | ✓ | — | — |
| ABM vehículos / dispositivos / conductores | ✓* | ✓ | — | — |
| Asignaciones device↔vehículo y PINs | ✓* | ✓ | — | — |
| Ver mapa en vivo / historial / reportes | ✓* | ✓ | ✓ | solo lo propio |
| Crear/editar geocercas y reglas de alerta | ✓* | ✓ | ✓ | — |
| Reconocer (ACK) alertas | ✓* | ✓ | ✓ | — |
| Ver y editar **su propio** perfil | — | ✓ | ✓ | ✓ |
| Auditoría global | ✓ | — | — | — |
| Auditoría de su empresa | — | ✓ | — | — |

\* El Super Admin lo hace operando en contexto de una empresa.

**Conductor (portal limitado):** inicia sesión en la web y solo puede: ver su propio historial de recorridos y reportes (los atribuidos a él vía PIN o asignación), ver su última posición, y editar los datos de su perfil (contacto, contraseña). **No gestiona nada más** ni ve datos de otros.

Implementar RBAC con un middleware `require_role(...)` / `require_permission(...)` por ruta. Permisos como constantes; mapa rol→permisos en config.

---

## 6. Autenticación y seguridad

- Login email + contraseña; `password_hash`/`password_verify` (argon2id si disponible).
- Recupero de contraseña por email con token de un solo uso y expiración (tabla `password_resets`).
- **CSRF token** en todos los formularios POST (middleware).
- **Sesiones** seguras: cookie `HttpOnly`, `Secure`, `SameSite=Lax`; regenerar id en login; timeout por inactividad (ej. 8 h) configurable.
- Rate-limit de intentos de login por email+IP (bloqueo temporal).
- Salida siempre escapada (Twig autoescape on).
- PDO con prepared statements en todo acceso a datos.
- Cabeceras de seguridad (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, CSP básica permitiendo OSM tiles + Leaflet CDN).
- **Audit log** (`audit_log`) en toda acción de escritura sensible (alta/baja/edición de entidades, cambios de asignación/PIN, ACK de alertas, login). Registra usuario, acción, entidad, diff JSON, IP.
- Errores ocultos en producción (`display_errors=0`), log a archivo fuera del document root.

---

## 7. Modelo de datos (esquema MySQL)

> `utf8mb4`, InnoDB. Todas las tablas de negocio llevan `company_id` (salvo `companies` y el super admin). FKs con `ON DELETE` apropiado o borrado lógico (`status`). Preferir **borrado lógico** en entidades con historial.

```sql
-- Empresas (tenants)
CREATE TABLE companies (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  slug          VARCHAR(120) NOT NULL UNIQUE,
  status        ENUM('active','suspended') NOT NULL DEFAULT 'active',
  device_quota  INT UNSIGNED NOT NULL DEFAULT 0,      -- cupo máximo de dispositivos
  timezone      VARCHAR(40) NOT NULL DEFAULT 'America/Argentina/Buenos_Aires',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Usuarios
CREATE TABLE users (
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
  INDEX (company_id), INDEX (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Conductores
CREATE TABLE drivers (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id    BIGINT UNSIGNED NOT NULL,
  first_name    VARCHAR(80) NOT NULL,
  last_name     VARCHAR(80) NOT NULL,
  dni           VARCHAR(20) NULL,
  license_number VARCHAR(30) NULL,
  phone         VARCHAR(20) NULL,
  email         VARCHAR(150) NULL,
  pin           VARCHAR(10) NULL,                      -- PIN único dentro de la empresa
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_driver_pin (company_id, pin),          -- PIN único por empresa
  INDEX (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Vehículos
CREATE TABLE vehicles (
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

-- Dispositivos
CREATE TABLE devices (
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
  INDEX (company_id), INDEX (has_pin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Asignación dispositivo ↔ vehículo (1:1 activa, con historial)
CREATE TABLE device_vehicle_assignments (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id    BIGINT UNSIGNED NOT NULL,
  device_id     BIGINT UNSIGNED NOT NULL,
  vehicle_id    BIGINT UNSIGNED NOT NULL,
  assigned_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unassigned_at DATETIME NULL,                         -- NULL = asignación activa
  INDEX (company_id), INDEX (device_id), INDEX (vehicle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Regla (en código): un device y un vehicle no pueden tener más de una fila con unassigned_at IS NULL.

-- Vínculo dispositivo ↔ conductor (allowlist PIN y/o conductor por defecto)
CREATE TABLE device_driver_links (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id    BIGINT UNSIGNED NOT NULL,
  device_id     BIGINT UNSIGNED NOT NULL,
  driver_id     BIGINT UNSIGNED NOT NULL,
  is_default    TINYINT(1) NOT NULL DEFAULT 0,         -- true: conductor por defecto (dispositivos SIN PIN)
  linked_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unlinked_at   DATETIME NULL,                         -- NULL = vínculo activo
  INDEX (company_id), INDEX (device_id), INDEX (driver_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Dispositivo CON PIN: 0..N vínculos activos = allowlist de conductores habilitados (is_default=0).
-- Dispositivo SIN PIN: exactamente 1 vínculo activo con is_default=1 = conductor atribuido.

-- Posiciones (las escribe el módulo de captura; alto volumen)
CREATE TABLE positions (
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

-- Eventos crudos del dispositivo (los escribe el módulo de captura)
CREATE TABLE device_events (
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

-- Viajes (los construye el procesador)
CREATE TABLE trips (
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

-- Geocercas
CREATE TABLE geofences (
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

-- Vehículos alcanzados por una geocerca (vacío = todos)
CREATE TABLE geofence_vehicles (
  geofence_id  BIGINT UNSIGNED NOT NULL,
  vehicle_id   BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (geofence_id, vehicle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reglas de alerta
CREATE TABLE alert_rules (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id   BIGINT UNSIGNED NOT NULL,
  type         ENUM('speed','geofence_enter','geofence_exit','offline','sos','idle') NOT NULL,
  params       JSON NULL,        -- speed:{max_kmh} geofence:{geofence_id} offline:{minutes} idle:{minutes}
  channels     JSON NOT NULL,    -- ["inapp","email"]
  recipients   JSON NULL,        -- emails extra
  active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (company_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Alertas generadas (registro)
CREATE TABLE alerts (
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
  INDEX (company_id, ts), INDEX (type), INDEX (acknowledged_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notificaciones in-app
CREATE TABLE notifications (
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

-- Auditoría
CREATE TABLE audit_log (
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

-- Recupero de contraseña
CREATE TABLE password_resets (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email       VARCHAR(150) NOT NULL,
  token_hash  VARCHAR(255) NOT NULL,
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

> **Contrato con el módulo de captura (fuera de alcance):** ese módulo solo escribe en `positions` y `device_events` (incluyendo `device_id`, `company_id`, `ts`, datos GPS y `pin_code` crudo cuando el equipo lo informa). **No** necesita conocer `driver_id`: la resolución del conductor la hace el procesador de esta plataforma (§8). El mock generator (§14) cumple este mismo contrato.

---

## 8. Lógica de PIN y atribución de conductor (núcleo)

Este es el comportamiento diferencial del sistema. Lo ejecuta el **procesador** (§12) al resolver `driver_id` de cada `position`/`device_event` nuevo.

**Definiciones**
- Un **conductor** tiene un `pin` único dentro de su empresa.
- Un **dispositivo con PIN** (`devices.has_pin = 1`) tiene una **allowlist** de conductores habilitados = filas activas en `device_driver_links` con `is_default = 0`.
- Un **dispositivo sin PIN** (`has_pin = 0`) tiene **un** conductor por defecto = única fila activa en `device_driver_links` con `is_default = 1`.

**Algoritmo de resolución de `driver_id` para una posición/evento de un dispositivo `D` de la empresa `C`:**

```
si D.has_pin == 1:
    si la posición/evento trae pin_code:
        buscar conductor T en empresa C con T.pin == pin_code
        si T existe Y T está en la allowlist activa de D:
            driver_id = T.id            # atribuido por PIN
        si no:
            driver_id = NULL            # PIN no reconocido / no habilitado -> "no identificado"
            (registrar nota; opcional alerta de PIN inválido en fase futura)
    si no (no hay pin_code):
        driver_id = NULL                # "conductor no identificado"
si no (D.has_pin == 0):
    driver_id = conductor por defecto activo de D (link is_default=1)  # o NULL si no hay
```

**Persistencia del PIN durante un viaje:** el `pin_code` se informa típicamente al inicio (evento `pin_set`) y se mantiene hasta `pin_cleared`, cambio de PIN, o `ignition_off`. El procesador mantiene el **PIN vigente por dispositivo** y lo aplica a las posiciones siguientes que no traigan `pin_code` explícito, hasta que el PIN se limpie. Las posiciones sin PIN vigente quedan como no identificadas.

**Construcción de viajes (`trips`):** un viaje agrupa posiciones consecutivas de un dispositivo entre arranque y detención (por `ignition` o por umbral de velocidad/tiempo detenido configurable). El `driver_id` del viaje = el conductor vigente al inicio; si cambia el PIN a mitad, **cerrar el viaje y abrir uno nuevo** con el nuevo conductor (así un mismo dispositivo/vehículo puede generar viajes de distintos conductores en el mismo día).

**Ejemplo:** Vehículo con dispositivo PIN, habilitados Ana (PIN 1234) y Beto (PIN 5678). A la mañana Ana ingresa 1234 → sus km/viajes se atribuyen a Ana. A la tarde Beto ingresa 5678 → nuevos viajes atribuidos a Beto. Si alguien maneja sin ingresar PIN → viaje “conductor no identificado”.

---

## 9. Módulos y pantallas

Layout general: barra lateral de navegación (colapsable en mobile) + topbar con buscador, campana de notificaciones y menú de usuario. Identidad Satrak (tema oscuro navy, acentos teal). Selector de empresa visible solo para Super Admin.

### 9.1 Super Admin (Satrak)
- **Empresas:** listado, alta/edición, estado (activa/suspendida), **cupo de dispositivos** (`device_quota`), creación del primer **Admin de Empresa**.
- **Entrar en contexto** de una empresa (ver/operar como si fuera su admin; auditado).
- **Usuarios globales** (super admins).
- **Auditoría global** y panel de estado del sistema (conteos, dispositivos offline, último run del procesador).

### 9.2 Admin de Empresa
- **Dashboard:** KPIs (vehículos activos, dispositivos online/offline, alertas sin reconocer, viajes del día, km del día), mini-mapa, últimas alertas.
- **Vehículos:** ABM. Validar cupo no aplica a vehículos (el cupo es de dispositivos).
- **Dispositivos:** ABM (alta valida contra `device_quota`), marcar `has_pin`, estado, última conexión.
- **Conductores:** ABM, asignar **PIN** (único en empresa), crear opcionalmente su **usuario** rol `driver` para el portal.
- **Asignaciones:**
  - device↔vehículo (activar/reasignar, ver historial).
  - device↔conductores: para dispositivos con PIN, gestionar **allowlist**; para dispositivos sin PIN, elegir **conductor por defecto**.
- **Usuarios:** ABM de operadores y conductores de su empresa.
- **Geocercas** y **reglas de alerta**.
- **Reportes** y **auditoría** de su empresa.

### 9.3 Operador / Monitoreo
- **Mapa en vivo**, **historial**, **alertas** (ACK), **reportes**, **geocercas**. Sin ABM de entidades ni usuarios.

### 9.4 Conductor (portal)
- **Mi actividad:** sus viajes (lista + detalle en mapa), km y tiempos por período.
- **Mi última posición** (si corresponde).
- **Mi perfil:** editar contacto y contraseña. Nada más.

---

## 10. Mapa en vivo (Leaflet + polling)

- Mapa Leaflet con tiles OSM. Marcadores por vehículo/dispositivo con color por estado: **en movimiento** (teal), **detenido** (acero/gris), **alerta** (ámbar), **offline** (atenuado).
- Popup del marcador: vehículo (patente), conductor vigente (o “no identificado”), velocidad, última actualización (mono), ignición.
- **Polling AJAX** cada **15 s** (configurable) a `GET /api/live/positions` → última posición por dispositivo activo de la empresa. Actualizar marcadores sin recargar; mover suavemente.
- Panel lateral con lista de unidades, búsqueda y filtro por estado; clic centra el mapa.
- Indicador “última sincronización hace Xs” y manejo de error de red (reintento).

---

## 11. Historial y reproducción

- Selección de vehículo/dispositivo + rango de fechas.
- Trae la **polilínea** del recorrido (`GET /api/devices/{id}/track?from=&to=`), marcadores de inicio/fin y paradas.
- **Reproducción** con control play/pausa y velocidad (1x/2x/4x); un marcador recorre la traza; panel mostrando velocidad y hora del punto actual (mono).
- Lista de **viajes** del período (de `trips`) con conductor atribuido, km, duración, velocidad máx.

---

## 12. Procesador (cron) y motor de alertas

CLI `bin/processor.php`, idempotente, ejecutado por cron cada **1 minuto**:
1. **Resolver `driver_id`** de posiciones nuevas y mantener PIN vigente por dispositivo (§8).
2. **Construir/cerrar viajes** (`trips`).
3. **Actualizar** `devices.last_position_id` / `last_seen_at`.
4. **Evaluar reglas de alerta** sobre posiciones/eventos nuevos:
   - `speed`: velocidad > umbral.
   - `geofence_enter` / `geofence_exit`: cambio de contención punto-en-geocerca (circle: distancia haversine < radio; polygon: ray casting).
   - `offline`: `last_seen_at` supera minutos configurados (chequeo periódico).
   - `sos`: evento `sos` en `device_events`.
   - `idle`: detenido con motor encendido más de X minutos.
   - Por cada alerta: insertar en `alerts`, crear `notifications` para usuarios de la empresa (admin/operador), y enviar **email** si el canal lo incluye.
5. Marcar `device_events.processed = 1`.

CLI `bin/purge.php`, cron **diario**: borra `positions`/`device_events` con más de **12 meses** (retención configurable). Considerar conservar `trips`/`alerts` agregados más tiempo.

> **Si el plan no tiene cron real:** exponer `GET /cron/run?token=SECRET` y `GET /cron/purge?token=SECRET` (protegidos por token en config, sin sesión) y dispararlos desde el cron de hPanel o un cron externo. Documentarlo en el README.

---

## 13. Reportes

Filtros comunes: empresa (super admin), rango de fechas, vehículo y/o conductor.

- **Por vehículo:** km recorridos, cantidad de viajes, tiempo en movimiento/detenido, velocidad máxima/promedio, excesos de velocidad, eventos de geocerca.
- **Por conductor (vía PIN):** mismos indicadores agrupados por conductor, **incluyendo “conductor no identificado”** como categoría. Este reporte es el que luce el diferencial del PIN.
- **Alertas:** listado filtrable por tipo/severidad/estado (reconocidas o no).
- **Exportación CSV** en v1 (PDF en fase posterior).

---

## 14. Generador de datos mock

CLI `bin/seed_mock.php` (y opción “generar demo” en el panel super admin):
- Crea 1–2 empresas demo con cupo, usuarios (admin/operador/conductores), vehículos, dispositivos (con y sin PIN), asignaciones y PINs.
- Simula al módulo de captura: genera `positions` y `device_events` realistas a lo largo de rutas dentro de Argentina (incluyendo algún recorrido en zona Neuquén), con velocidades, ignición y **secuencias de PIN** (`pin_set`/`pin_cleared`) para demostrar la atribución por conductor, además de algún evento `sos` y excesos de velocidad para disparar alertas.
- Debe poder correr de forma “continua” (modo `--live`, agrega puntos cada X seg) para demostrar el mapa en vivo, o “histórico” (carga N días hacia atrás).

---

## 15. Endpoints internos (AJAX, JSON)

Todos con sesión válida + scope por empresa.

| Método | Ruta | Uso |
|---|---|---|
| GET | `/api/live/positions` | Última posición por dispositivo activo (mapa en vivo) |
| GET | `/api/devices/{id}/track?from=&to=` | Puntos para reproducción |
| GET | `/api/trips?vehicle_id=&driver_id=&from=&to=` | Viajes filtrados |
| GET | `/api/alerts/recent` | Alertas recientes |
| POST | `/api/alerts/{id}/ack` | Reconocer alerta |
| GET | `/api/notifications/unread` | Notificaciones no leídas (campana) |
| POST | `/api/notifications/{id}/read` | Marcar leída |
| GET | `/api/geofences` / POST/PUT/DELETE | CRUD geocercas |

Respuestas JSON consistentes (`{ok, data, error}`), códigos HTTP correctos, validación y CSRF en mutaciones.

---

## 16. Frontend / UI

- Identidad **Satrak** (ver tokens). Tema oscuro navy de base para el panel; superficies con `--grad-orbita`; acentos teal; ámbar solo para alertas/estados.
- Tipografías: Space Grotesk (títulos), Inter (UI/texto), Space Mono (datos: coordenadas, velocidades, horas, PIN, patentes).
- Componentes: sidebar, topbar con campana, tablas con búsqueda/orden/paginación, formularios validados, modales de confirmación, toasts, badges de estado (online/offline/alerta), cards de KPI, el mapa Leaflet.
- **Responsive / mobile-first**, foco de teclado visible, contraste AA, `prefers-reduced-motion` respetado, funciona sin recargas para mapa/alertas vía AJAX y con degradación básica sin JS para ABM.
- Sin framework JS ni build: JS modular vanilla; Leaflet desde CDN (con CSP que lo permita) o servido local.

---

## 17. Estructura de archivos

```
satrak-app/
├── public/
│   ├── index.php                 # bootstrap Slim (único entrypoint web)
│   ├── .htaccess                 # rewrite a index.php + HTTPS + headers
│   └── assets/{css,js,img}/      # tokens.css, app.css, app.js, map.js, leaflet/...
├── src/
│   ├── Application/
│   │   ├── Middleware/{AuthMiddleware,TenantMiddleware,RbacMiddleware,CsrfMiddleware}.php
│   │   ├── Controllers/{Auth,Dashboard,Company,User,Vehicle,Device,Driver,Assignment,
│   │   │                Geofence,AlertRule,Alert,Report,Map,DriverPortal,Api}Controller.php
│   │   └── Support/{helpers.php, Auth.php, Csrf.php, Validator.php}
│   ├── Domain/
│   │   ├── Services/{PinResolver, TripBuilder, AlertEngine, GeofenceMath, ReportService, Mailer}.php
│   │   └── Repositories/{Company,User,Vehicle,Device,Driver,Assignment,Position,Trip,
│   │                     Geofence,AlertRule,Alert,Notification,Audit}Repository.php
│   └── settings.php              # carga config/env
├── templates/                    # Twig
│   ├── layouts/{app.twig, auth.twig}
│   ├── partials/{sidebar,topbar,flash,pagination}.twig
│   └── pages/...                 # dashboard, vehicles/*, devices/*, drivers/*, map, history, reports/*, driver/*
├── bin/
│   ├── processor.php             # cron: resolver PIN + viajes + alertas
│   ├── purge.php                 # cron: retención
│   └── seed_mock.php             # datos demo / simulador de captura
├── database/
│   ├── schema.sql                # DDL §7
│   └── migrations/               # opcional
├── config/
│   ├── config.example.php        # plantilla versionada
│   └── config.php                # real, ignorado por git
├── storage/logs/                 # logs fuera de public
├── vendor/                       # Slim, Twig, PHP-DI, PHPMailer
├── composer.json
├── .gitignore
└── README.md                     # deploy Hostinger + cron + variantes
```

---

## 18. Configuración (`config/config.php`)

```php
return [
  'app' => ['env'=>'production','base_url'=>'https://app.satrak.online','debug'=>false,
            'tz'=>'America/Argentina/Buenos_Aires','locale'=>'es_AR',
            'session_timeout_min'=>480],
  'db'  => ['host'=>'localhost','name'=>'','user'=>'','pass'=>'','charset'=>'utf8mb4'],
  'smtp'=> ['host'=>'','port'=>587,'user'=>'','pass'=>'','secure'=>'tls',
            'from'=>'alertas@satrak.online','from_name'=>'Satrak'],
  'map' => ['live_poll_seconds'=>15,'tile_url'=>'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'],
  'tracking'=>['offline_minutes'=>30,'idle_minutes'=>10,'retention_months'=>12,
               'trip_stop_minutes'=>5],
  'cron'=>['token'=>'CAMBIAR_TOKEN_LARGO'],   // para /cron/run si no hay cron real
];
```

---

## 19. Deployment en Hostinger

1. Crear subdominio `app.satrak.online`; su **document root** debe apuntar a `…/satrak-app/public`. Si no se puede, mover `public/` a la raíz del subdominio y ajustar paths (documentar ambas variantes).
2. `composer install` (vía SSH) o subir `vendor/` ya armado.
3. Crear base MySQL en hPanel; importar `database/schema.sql`.
4. Copiar `config.example.php` → `config.php` y completar DB/SMTP/token.
5. Activar SSL y forzar HTTPS.
6. **Cron jobs** (hPanel):
   - `* * * * * php /home/USER/…/satrak-app/bin/processor.php` (cada minuto)
   - `30 3 * * * php /home/USER/…/satrak-app/bin/purge.php` (diario)
   - Si no hay cron CLI: programar `curl https://app.satrak.online/cron/run?token=…`.
7. Sembrar demo: `php bin/seed_mock.php --days=7` (y `--live` para mostrar el mapa).
8. Crear el primer **Super Admin** (comando de consola `bin/seed_mock.php --admin` o seeder dedicado).

---

## 20. Seguridad (checklist)
- [ ] Scope `company_id` forzado en todo acceso (TenantMiddleware + repos).
- [ ] RBAC por ruta; acceso cruzado ⇒ 404.
- [ ] CSRF en todos los POST/PUT/DELETE; autoescape Twig.
- [ ] PDO prepared statements; validación server-side de todo input.
- [ ] Sesiones endurecidas; rate-limit de login; recupero con token expirable.
- [ ] Cabeceras de seguridad + CSP (permitir OSM/Leaflet).
- [ ] `config.php` y `storage/` fuera del document root; errores ocultos en prod.
- [ ] Endpoint `/cron/*` solo por token, nunca con sesión.
- [ ] Auditoría de escrituras sensibles.

---

## 21. Entregables esperados de Claude Code
- [ ] App Slim 4 funcional con todas las rutas y los 4 roles (§5, §9).
- [ ] `schema.sql` completo (§7) + repos PDO + scope multi-tenant.
- [ ] Lógica de PIN/atribución y construcción de viajes (§8) en `PinResolver`/`TripBuilder`.
- [ ] Mapa en vivo (Leaflet + polling), historial con reproducción.
- [ ] Geocercas + motor de alertas (§12) con notificaciones in-app + email.
- [ ] Reportes por vehículo y por conductor con export CSV.
- [ ] Portal del conductor (solo lectura de lo propio + perfil).
- [ ] `bin/processor.php`, `bin/purge.php`, `bin/seed_mock.php` (mock + modo live).
- [ ] UI con identidad Satrak, responsive y accesible.
- [ ] `config.example.php`, `.htaccess`, `.gitignore`, `README.md` con deploy Hostinger + cron.

---

## 22. Fuera de alcance (v1)
- **Módulo de captura** de los dispositivos (parseo de protocolos, sockets). Esta plataforma define el esquema compartido (`positions`, `device_events`) y lo consume; el mock lo simula.
- **App móvil** del conductor (el conductor solo accede al portal web a ver lo suyo).
- **Comandos a dispositivo / corte de motor** (no se implementa ahora).
- Facturación online, WhatsApp, 2FA, gestión documental con vencimientos.

---

## 23. Roadmap
- **Captura real** de dispositivos escribiendo en el esquema ya definido.
- **App móvil** del conductor (ingreso de PIN / seguimiento) integrada al portal.
- **Comandos** (corte de motor) con cola e interfaz.
- **Documentación con vencimientos** por vehículo y conductor (licencia, VTV, seguro) con alertas.
- WhatsApp como canal de alerta, 2FA, exportación PDF, facturación por suscripción (MercadoPago) con cupos automáticos.

---

## 24. Para confirmar antes de empezar
1. **Plan de Hostinger:** ¿tiene SSH + Composer + **cron** CLI? Define si el procesador corre por cron real o por endpoint `/cron/run` disparado externamente.
2. **Longitud/formato del PIN** (ej. 4 dígitos) y si debe ser numérico.
3. **Política de SOS:** ¿severidad `critical` con email inmediato a admins? (default sí).
4. **Umbrales por defecto** (offline 30 min, idle 10 min, parada de viaje 5 min, retención 12 meses): ¿ok o ajustás?
