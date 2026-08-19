# Satrak — Módulo de Personas (tracking de personal)

> Extiende `satrak-plataforma-tracking-spec.md`. Todo lo que no se menciona acá se
> mantiene como está (multi-tenant por `company_id`, RBAC, procesador por cron,
> contrato `positions` / `device_events`).
>
> Estado: **completo (P1–P7)**. La plataforma web está desplegada en
> producción; la app móvil vive en [`../mobile/`](../mobile/README.md) y falta
> generar los proyectos nativos y probarla en equipos reales.

---

## 1. Idea central

Hoy la plataforma modela `device → vehicle` (el equipo está fijo en el vehículo) y
usa el **PIN** para saber *quién lo maneja*. Para personas esa indirección sobra:
el equipo **es** de la persona.

Por eso **la persona ocupa el lugar del vehículo**, no el del conductor: es la
entidad rastreada a la que se asigna un dispositivo.

```
device ──1:1 activa──> vehicle          (device_vehicle_assignments)   ← ya existe
device ──1:1 activa──> person           (device_person_assignments)    ← nuevo
```

Y la **persona pasa a ser el maestro**: `driver` se convierte en un *perfil* de una
persona (licencia + PIN), no en una entidad paralela.

```
person ──0..1──> driver   (perfil de conducción: licencia, PIN, allowlist)
       ──0..1──> user     (acceso web: portal de la persona)
       ──0..N──> device   (histórico de equipos; 1 activo)
```

El segundo cambio estructural: **la app móvil es el dispositivo**. Una instalación
de la app se registra como un `device` con `source='app'`, reporta a una API de
ingesta y desde ahí **reutiliza todo el pipeline existente** (procesador, viajes,
geocercas, alertas, mapa, historial, purga). Esto convierte el "módulo de captura"
—excluido en el spec v1 (§22)— en algo que sí construimos, pero solo para app.

---

## 2. Reglas de negocio

1. **Persona** es el maestro de datos personales (nombre, DNI, contacto, foto,
   consentimiento). Un conductor es una persona con perfil de conducción.
2. La persona tiene **contraseña propia** para autenticarse en la app del
   dispositivo. **Una sola sesión activa a la vez**: un login nuevo revoca el
   anterior (y lo audita).
3. La persona tiene **jornada laboral** (horarios de trackeo). **Fuera de la
   jornada no se rastrea**: la app no reporta y el servidor rechaza posiciones
   fuera de turno. Es requisito legal (Ley 25.326) y de batería.
4. Durante la jornada, la persona debe estar **en un puesto definido** (geocerca
   asignada) **o** tener una **misión asignada** (ir de un lugar a otro dentro de
   una ventana horaria). Cualquier otra situación es desvío y dispara alerta.
5. **Botón de pánico** en la app, además del SOS por hardware. Severidad crítica.
6. **Portal de la persona**: ve lo suyo (jornadas, ubicación, misiones, perfil).
7. **Permisos separados** de los de flota: una empresa puede contratar solo
   personas, solo vehículos, o ambos.
8. **Mapa unificado** con filtro por tipo de unidad (vehículos / personas / todas).

---

## 3. Modelo de datos

### 3.1 Tablas nuevas

```sql
-- Maestro de personas
CREATE TABLE people (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id     BIGINT UNSIGNED NOT NULL,
  first_name     VARCHAR(80) NOT NULL,
  last_name      VARCHAR(80) NOT NULL,
  dni            VARCHAR(20) NULL,
  phone          VARCHAR(20) NULL,
  email          VARCHAR(150) NULL,
  job_title      VARCHAR(80) NULL,            -- puesto/cargo (informativo)
  password_hash  VARCHAR(255) NULL,           -- acceso a la app móvil
  password_set_at DATETIME NULL,
  consent_at     DATETIME NULL,               -- consentimiento informado (Ley 25.326)
  consent_note   VARCHAR(255) NULL,
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_person_dni (company_id, dni),
  INDEX (company_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Asignación dispositivo ↔ persona (espejo de device_vehicle_assignments)
CREATE TABLE device_person_assignments (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id    BIGINT UNSIGNED NOT NULL,
  device_id     BIGINT UNSIGNED NOT NULL,
  person_id     BIGINT UNSIGNED NOT NULL,
  assigned_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unassigned_at DATETIME NULL,                -- NULL = activa
  INDEX (company_id), INDEX (device_id), INDEX (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Instalación de la app móvil (sesión del dispositivo). 1 activa por persona.
CREATE TABLE person_app_sessions (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id     BIGINT UNSIGNED NOT NULL,
  person_id      BIGINT UNSIGNED NOT NULL,
  device_id      BIGINT UNSIGNED NOT NULL,     -- devices.source='app'
  install_id     VARCHAR(64) NOT NULL,         -- id de instalación generado por la app
  token_hash     VARCHAR(255) NOT NULL,        -- bearer de la API (sha256 del token)
  platform       ENUM('android','ios') NOT NULL,
  os_version     VARCHAR(20) NULL,
  app_version    VARCHAR(20) NULL,
  model          VARCHAR(60) NULL,
  push_token     VARCHAR(255) NULL,
  battery_pct    TINYINT UNSIGNED NULL,
  perms_ok       TINYINT(1) NULL,              -- permisos de ubicación en segundo plano
  last_seen_at   DATETIME NULL,
  revoked_at     DATETIME NULL,                -- NULL = sesión vigente
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_install (company_id, install_id),
  INDEX (person_id, revoked_at), INDEX (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Jornada laboral: ventanas semanales de trackeo
CREATE TABLE person_shifts (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id   BIGINT UNSIGNED NOT NULL,
  person_id    BIGINT UNSIGNED NOT NULL,
  weekday      TINYINT UNSIGNED NOT NULL,      -- 1=lunes .. 7=domingo (ISO-8601)
  start_time   TIME NOT NULL,
  end_time     TIME NOT NULL,                  -- si end < start, cruza medianoche
  active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (company_id), INDEX (person_id, weekday)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Excepciones puntuales (franco, licencia, turno extra)
CREATE TABLE person_shift_exceptions (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id   BIGINT UNSIGNED NOT NULL,
  person_id    BIGINT UNSIGNED NOT NULL,
  date         DATE NOT NULL,
  kind         ENUM('off','extra') NOT NULL,   -- off: no se trackea; extra: ventana adicional
  start_time   TIME NULL,                      -- requerido si kind='extra'
  end_time     TIME NULL,
  note         VARCHAR(120) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_exc (person_id, date, kind, start_time),
  INDEX (company_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Puesto: dónde debe estar durante la jornada (geocerca)
CREATE TABLE person_posts (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id   BIGINT UNSIGNED NOT NULL,
  person_id    BIGINT UNSIGNED NOT NULL,
  geofence_id  BIGINT UNSIGNED NOT NULL,
  valid_from   DATE NULL,
  valid_to     DATE NULL,                      -- NULL = vigente
  grace_min    SMALLINT UNSIGNED NOT NULL DEFAULT 10,  -- tolerancia fuera del puesto
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (company_id), INDEX (person_id, valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Misión: traslado autorizado de un lugar a otro dentro de una ventana
CREATE TABLE person_missions (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id         BIGINT UNSIGNED NOT NULL,
  person_id          BIGINT UNSIGNED NOT NULL,
  origin_geofence_id BIGINT UNSIGNED NULL,     -- NULL = desde el puesto vigente
  dest_geofence_id   BIGINT UNSIGNED NOT NULL,
  scheduled_start    DATETIME NOT NULL,
  scheduled_end      DATETIME NOT NULL,        -- vencida sin llegar => alerta
  status  ENUM('pending','in_progress','completed','missed','cancelled')
                     NOT NULL DEFAULT 'pending',
  started_at         DATETIME NULL,
  arrived_at         DATETIME NULL,
  vehicle_id         BIGINT UNSIGNED NULL,     -- opcional: viaja en un vehículo de la flota
  notes              VARCHAR(255) NULL,
  created_by         BIGINT UNSIGNED NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (company_id, scheduled_start), INDEX (person_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Destinatarios de geocerca, generalizado (reemplaza geofence_vehicles)
CREATE TABLE geofence_targets (
  geofence_id BIGINT UNSIGNED NOT NULL,
  target_type ENUM('vehicle','person') NOT NULL,
  target_id   BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (geofence_id, target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 3.2 Cambios sobre tablas existentes

```sql
-- Qué rastrea el equipo y cómo reporta
ALTER TABLE devices
  ADD COLUMN kind   ENUM('vehicle','person')  NOT NULL DEFAULT 'vehicle' AFTER company_id,
  ADD COLUMN source ENUM('hardware','app')    NOT NULL DEFAULT 'hardware' AFTER kind,
  ADD INDEX idx_devices_kind (company_id, kind);
-- `imei` para source='app' guarda el install_id (sigue siendo UNIQUE).

-- El conductor pasa a ser un perfil de una persona
ALTER TABLE drivers
  ADD COLUMN person_id BIGINT UNSIGNED NULL AFTER company_id,
  ADD UNIQUE KEY uq_driver_person (person_id);
-- Los datos personales de `drivers` quedan como legado durante una release;
-- la fuente de verdad pasa a `people` (ver §8, migración).

-- Acceso web de la persona (portal)
ALTER TABLE users
  ADD COLUMN person_id BIGINT UNSIGNED NULL AFTER driver_id,
  MODIFY COLUMN role ENUM('super_admin','company_admin','operator','driver','person') NOT NULL;

-- Atribución a persona en el pipeline
ALTER TABLE trips  ADD COLUMN person_id  BIGINT UNSIGNED NULL AFTER driver_id,
                   ADD COLUMN mission_id BIGINT UNSIGNED NULL AFTER person_id,
                   ADD INDEX idx_trips_person (person_id);
ALTER TABLE alerts ADD COLUMN person_id  BIGINT UNSIGNED NULL AFTER driver_id,
                   ADD INDEX idx_alerts_person (person_id);

-- Telemetría que hoy no existe y la app sí manda
ALTER TABLE positions
  ADD COLUMN accuracy_m  SMALLINT UNSIGNED NULL AFTER satellites,
  ADD COLUMN battery_pct TINYINT UNSIGNED NULL AFTER accuracy_m;

-- Tipos de alerta de personas
ALTER TABLE alert_rules MODIFY COLUMN type ENUM(
  'speed','geofence_enter','geofence_exit','offline','sos','idle',
  'panic','no_movement','off_post','mission_late','mission_missed',
  'low_battery','app_offline','out_of_shift'
) NOT NULL;

-- Cupos por tipo (una empresa puede contratar solo personas)
ALTER TABLE companies
  ADD COLUMN person_quota INT UNSIGNED NOT NULL DEFAULT 0 AFTER device_quota;
```

`device_events.event_type` es `VARCHAR(30)`: no requiere ALTER. Tipos nuevos:
`panic`, `panic_cancel`, `shift_start`, `shift_end`, `app_permission_lost`,
`low_battery`, `mission_start`, `mission_arrive`.

---

## 4. Procesador

`bin/processor.php` sigue siendo el único que escribe `trips` y `alerts`. Ramifica
por `devices.kind`.

### 4.1 Viajes de persona (`TripBuilder`)

`isMoving()` hoy prioriza `ignition` y cae a `speed > 0` — con una persona a pie
`ignition` es NULL y el ruido de GPS abriría viajes falsos. Para `kind='person'`:

- **en movimiento** = `speed >= walk_speed_kmh` (default 2) **y** desplazamiento
  respecto del punto anterior `>= min_step_m` (default 25), descartando puntos con
  `accuracy_m > max_accuracy_m` (default 100).
- **cierre** de recorrido por `person_stop_minutes` (default 10, mayor que el de
  vehículos: una persona parada trabajando no terminó su recorrido).
- el recorrido se estampa con `person_id` y, si hay una misión en curso, con
  `mission_id`.

### 4.2 Guardia de jornada (`ShiftGuard`, servicio nuevo)

`ShiftGuard::isWithinShift(personId, ts)` resuelve `person_shifts` +
`person_shift_exceptions` en el timezone de la empresa. Se aplica en dos capas:

1. **Cliente**: la app conoce su jornada y no captura fuera de ella.
2. **Servidor**: la API de ingesta descarta posiciones fuera de turno
   (responde `202` con `dropped: n`, sin guardar). Nada llega a `positions`.

Excepción: el **pánico siempre se acepta**, dentro o fuera de turno.

### 4.3 Motor de alertas de personas

Extiende `AlertEngine` con contexto de persona. Con `kind='person'` **no** se
evalúan `speed` ni `idle` (dependen de ignición/vehículo).

| Tipo | Disparo | Severidad |
|---|---|---|
| `panic` | evento `panic` de la app | critical |
| `sos` | evento `sos` de hardware | critical |
| `no_movement` | sin desplazamiento > `minutes` en jornada (hombre caído) | critical |
| `off_post` | en jornada, con puesto vigente, sin misión en curso, fuera de la geocerca más de `grace_min` | warning |
| `geofence_enter` / `geofence_exit` | igual que hoy, vía `geofence_targets` | warning |
| `mission_late` | misión `in_progress` y `now > scheduled_end` sin llegar | warning |
| `mission_missed` | misión `pending` y `now > scheduled_end` sin iniciar | warning |
| `low_battery` | batería reportada < `pct` (default 15) | warning |
| `app_offline` | en jornada y sin reportar hace más de `minutes` (default 15) | warning |
| `out_of_shift` | posición recibida fuera de turno (indicio de config o abuso) | info |

**Etiqueta del mensaje**: hoy `AlertEngine::fire()` prefija con la patente. Pasa a
prefijar con patente **o** nombre de la persona según `kind`.

**Alcance de geocerca**: `geofenceAppliesTo()` hoy devuelve `true` con lista vacía
—un equipo personal heredaría todas las geocercas de flota—. Con
`geofence_targets` el criterio pasa a ser: sin targets del **mismo tipo**, aplica a
todos los de ese tipo.

---

## 5. API de ingesta (app móvil)

Grupo `/api/app/*`, fuera de la sesión web, autenticado por **bearer opaco**
(`Authorization: Bearer <token>`, se guarda `sha256` en `person_app_sessions`).
Respuestas con el envelope `{ok, data, error}` ya usado (§15 del spec base).
Rate-limit con `RateLimiter` sobre `install_id + IP`.

| Método | Ruta | Qué hace |
|---|---|---|
| `POST` | `/api/app/login` | `{company_slug, dni, password, install_id, platform, os_version, app_version, model}` → crea/reactiva `device` (`kind=person, source=app`), asigna a la persona, **revoca la sesión anterior**, devuelve `{token, person, shifts, post, missions, config}` |
| `POST` | `/api/app/logout` | revoca la sesión vigente |
| `POST` | `/api/app/positions` | **batch** de puntos `[{ts, lat, lon, speed, heading, accuracy_m, battery_pct}]` → filtra por jornada → inserta en `positions` |
| `POST` | `/api/app/panic` | `{ts, lat, lon}` → `device_events` tipo `panic` (siempre aceptado) |
| `POST` | `/api/app/events` | eventos secundarios (`shift_start`, `low_battery`, `app_permission_lost`, …) |
| `GET` | `/api/app/sync` | jornada vigente, puesto, misiones del día, config de muestreo, versión mínima de app |
| `POST` | `/api/app/missions/{id}/start` | marca `in_progress` |
| `POST` | `/api/app/missions/{id}/arrive` | marca `completed` (validando cercanía al destino) |
| `POST` | `/api/app/heartbeat` | `{battery_pct, perms_ok}` → refresca `last_seen_at` |

Notas:
- **Batch + offline**: la app bufferea local y sube por lotes (≤200 puntos). El
  servidor es idempotente por `(device_id, ts)`.
- **Reloj**: se guarda `ts` del dispositivo (como el resto del sistema) y se
  descartan puntos con deriva > 24 h.
- El contrato con `positions` / `device_events` no cambia: el procesador sigue sin
  saber de dónde vinieron los datos.

---

## 6. Permisos y RBAC

Constantes nuevas en `Perm`, para poder vender flota y personal por separado:

```php
public const PEOPLE_MANAGE   = 'people.manage';    // ABM personas, jornadas, puestos
public const PEOPLE_MONITOR  = 'people.monitor';   // ver personas en mapa/historial/reportes
public const MISSIONS_MANAGE = 'missions.manage';  // asignar y cerrar misiones
public const PERSON_PORTAL   = 'person.portal';    // portal de la persona
```

Matriz (`config/permissions.php`):

| Rol | Agrega |
|---|---|
| `super_admin` | `*` (sin cambios) |
| `company_admin` | `people.manage`, `people.monitor`, `missions.manage` |
| `operator` | `people.monitor`, `missions.manage` |
| `person` (nuevo) | `person.portal`, `profile.edit` |

**Dos capas, no una.** El RBAC dice qué puede hacer un ROL; no alcanza para
"contrataciones diferentes", porque la matriz es global y un `company_admin`
siempre tendría `people.manage`. Por eso se agrega `companies.modules`
(`SET('fleet','people')`) y un `Entitlements` que resuelve, por request, qué
módulos contrató la empresa en contexto:

| Permiso | Módulo que lo habilita |
|---|---|
| `fleet.manage`, `assignments.manage` | `fleet` |
| `people.manage`, `people.monitor`, `missions.manage` | `people` |
| el resto (monitoreo, alertas, geocercas, reportes, usuarios, auditoría) | ninguno |

`RbacMiddleware` exige **las dos** condiciones (rol ✱ módulo) y devuelve 403 si
falla cualquiera; el `can()` de Twig usa el mismo criterio para no ofrecer links
que después dan 403. Sin empresa en contexto (vista global del super admin) no
hay gating. Resultado: una empresa que sólo contrató personal no ve Vehículos,
Dispositivos ni Conductores, y una que sólo contrató flota no ve Personas.

---

## 7. UI web

- **Mapa unificado** (`/mapa`): un solo mapa con filtro `Todas / Vehículos / Personas`.
  `MonitoringRepository::livePositions()` pasa a unir también
  `device_person_assignments` + `people`, y el `label` sale de patente o nombre.
  Marcador distinto por tipo; el estado de una persona es
  `en_puesto / en_mision / fuera_de_puesto / sin_señal / fuera_de_turno`
  (hoy `deviceState()` usa ignición, que para personas no aplica).
- **Personas** (`/personas`): ABM + solapas **Jornada**, **Puesto**, **Dispositivo**
  (sesión activa, batería, permisos, botón *revocar*), **Perfil de conductor**
  (crea/edita el `driver` de esa persona: licencia + PIN + allowlist).
- **Misiones** (`/misiones`): calendario/listado, alta, seguimiento, cierre manual.
- **Portal de la persona** (`/mi`): mi día (jornada, puesto, misiones y si en
  este momento se me está rastreando), mi ubicación, mis misiones de las próximas
  dos semanas, y mi perfil con cambio de contraseña del panel. Scope estricto a
  `users.person_id`. Muestra explícitamente qué se registra de ella y desde
  cuándo hay consentimiento: es parte de lo que exige la Ley 25.326.
- **Reportes**: pestaña «Por persona» con jornada prevista vs. tiempo con
  reporte (y su cobertura %), días con actividad, recorridos y km, misiones
  cumplidas/no cumplidas, y episodios de fuera de puesto / sin movimiento /
  pánico. Export CSV. La cobertura usa como numerador el lapso entre el primer y
  el último punto de cada día: es una medida de **presencia reportada**, no de
  horas trabajadas.

---

## 8. Migración de datos

El riesgo está en `drivers`: `trips.driver_id`, `positions.driver_id` y
`alerts.driver_id` lo referencian. **No se tocan esas FKs.**

1. `ALTER` de `drivers` agregando `person_id`.
2. Backfill: por cada `driver` existente se crea una `person` con sus datos
   personales y se setea `drivers.person_id`.
3. La UI pasa a leer datos personales de `people`; `drivers` conserva
   `license_number`, `pin`, `status`, `person_id`.
4. En una release posterior se eliminan las columnas duplicadas de `drivers`.
5. `geofence_vehicles` → `geofence_targets` con `target_type='vehicle'`, y luego
   `DROP TABLE geofence_vehicles`.

⚠️ **`database/migrations/` está vacío**: hoy todo vive en `schema.sql` +
`bin/import_schema.php`, que sirve para crear pero no para evolucionar una base con
datos. Antes de tocar producción hay que agregar un runner mínimo
(`bin/migrate.php` + tabla `schema_migrations` + archivos `NNN_*.sql`).

---

## 9. App móvil (iOS + Android, equipos viejos)

Requisito duro: **funcionar en teléfonos viejos** y reportar ubicación en segundo
plano de forma confiable.

**Stack: React** (decisión del equipo). Con React hay dos caminos y solo uno
sostiene equipos viejos:

| | Piso real | Veredicto |
|---|---|---|
| **Capacitor 6 + React** | **Android 5.1 (API 22)** / **iOS 13** | ✅ elegido |
| React Native / Expo modernos | Android 7 (API 24) / iOS 15.1 | ❌ sube demasiado el piso |
| React Native 0.72 (2023) | Android 6 / iOS 13.4 | ❌ versión sin mantenimiento |

**El WebView no es un problema para el tracking**: la ubicación en segundo plano
la maneja un **plugin nativo** (`@transistorsoft/capacitor-background-geolocation`
o `@capacitor-community/background-geolocation`), que corre fuera del WebView con
el mismo motor nativo que usaría una app Flutter. El WebView solo dibuja la UI.
La app puede seguir reportando con la pantalla apagada y la UI cerrada.

⚠️ **Consecuencia del cambio a React: se pierde iOS 12.** Capacitor exige iOS 13
desde la v3. En la práctica queda afuera el iPhone 6 y anteriores (2014); desde el
iPhone 6s (2015) entra todo. Del lado Android —que es donde realmente están los
equipos viejos de campo— se mantiene **Android 5.1**, casi sin pérdida respecto de
Flutter (API 22 vs 21).

Caveat propio del WebView en Android viejo: en Android 5/6 el System WebView se
actualiza por Play Store, así que el JS moderno funciona, pero en equipos sin
actualizar puede ser antiguo. Hay que compilar a **ES2015 como target máximo**,
mantener el bundle chico y evitar APIs de navegador recientes.

Bonus del camino Capacitor: el mismo código React se puede servir como web para
QA sin compilar nativo en cada iteración.

Alcance:
- Login con **empresa + DNI + contraseña**, sesión única (el server revoca la anterior).
- Servicio de ubicación en segundo plano: en Android, **foreground service** con
  notificación persistente (obligatorio desde Android 8) y exclusión de las
  optimizaciones de batería; en iOS, permiso *Always* + `significant location
  change` como red de seguridad.
- **Solo captura dentro de la jornada**: la app conoce su horario y se apaga sola.
  Es lo que hace sostenible la batería en un equipo viejo.
- Buffer offline (SQLite) + subida por lotes al recuperar señal.
- **Botón de pánico** accesible, con confirmación breve y envío inmediato aunque
  esté fuera de turno o sin conexión (reintento agresivo).
- Pantalla mínima: estado (en turno / fuera de turno), mi puesto, mis misiones con
  *Iniciar* / *Llegué*, y estado de permisos con guía de arreglo.

Riesgos a asumir desde ya:
- **Play Store** exige el formulario de declaración de ubicación en segundo plano,
  divulgación destacada dentro de la app y política de privacidad. Las apps de
  seguimiento de empleados se aprueban, pero con consentimiento explícito visible.
- **App Store** revisa con lupa el uso de `Always`; hay que justificarlo y mostrar
  el consentimiento.
- Los OEM chinos (Xiaomi, Huawei, Oppo) matan servicios en segundo plano de forma
  agresiva. Hay que incluir la guía por fabricante y aceptar que en esos equipos la
  frecuencia real será peor que la configurada.

---

## 10. Fases

| Fase | Alcance | Depende de |
|---|---|---|
| **P1** ✅ | Runner de migraciones + `people` + migración de `drivers` + permisos por módulo contratado + ABM web | — |
| **P2** ✅ | Jornadas, excepciones, puestos y misiones (ABM web + `ShiftGuard`) | P1 |
| **P3** ✅ | API de ingesta `/api/app/*` + `devices.kind/source` + asignación device↔persona | P1 |
| **P4** ✅ | Mapa unificado + historial + estado de persona + portal de la persona | P3 |
| **P5** ✅ | Motor de alertas de personas + reglas nuevas + `geofence_targets` | P2, P3 |
| **P6** ✅ | Reportes por persona (jornada, cumplimiento de puesto, misiones) | P5 |
| **P7** ✅ | App móvil React + Capacitor, en `mobile/` | P3 |

P1–P2 y P3 se pueden hacer en paralelo con la app una vez congelado el contrato de
la API (fin de P3).

---

## 11. Decisiones confirmadas

1. **Jornada: solo por horario configurado.** No hay fichada en la app. La ventana
   de `person_shifts` (+ excepciones) es la única fuente de verdad del trackeo.
   → `person_app_sessions` no lleva marcas de fichada y la app no expone el botón.
2. **Stack de la app: React**, vía **Capacitor 6** + plugin nativo de geolocación
   en segundo plano. Piso resultante: **Android 5.1 (API 22) / iOS 13** — se
   pidió iOS 12, pero Capacitor no baja de 13 (ver §9). Implica QA sobre equipos
   reales antiguos y aceptar frecuencia degradada en OEM con matado agresivo de
   background.
3. **Misiones: las carga solo el operador** desde el panel. La persona solo ve,
   inicia (`/missions/{id}/start`) y marca llegada (`/missions/{id}/arrive`). No
   hay autoasignación desde la app.
4. **Fuera de turno**: la posición se **descarta** (no se guarda ni se oculta).
5. **Login en la app**: empresa + DNI + contraseña (el personal de campo puede no
   tener email propio).
6. **Frecuencia de reporte** en jornada: 60 s en movimiento / 300 s detenido,
   configurable por empresa.
7. **Perfil de conductor**: se crea desde una solapa del formulario de persona. El
   alta de conductor independiente desaparece; `/conductores` pasa a listar los
   perfiles de conducción de las personas.

8. **Pánico: con confirmación en pantalla** (la app pide confirmar antes de
   enviar, para evitar disparos accidentales en el bolsillo) y **email de
   guardia**: `companies.emergency_email` recibe SIEMPRE el pánico y el SOS,
   además de admins y operadores, exista o no una regla configurada.
