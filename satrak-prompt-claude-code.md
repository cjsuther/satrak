# Prompt de arranque — Satrak Plataforma de Tracking (para Claude Code)

> Pegá este documento como instrucción inicial en Claude Code, junto al archivo **`satrak-plataforma-tracking-spec.md`** (la especificación completa, que es la **fuente de verdad**). Este prompt define **cómo** construir y en **qué orden**.

---

## Contexto

Vas a desarrollar **Satrak**, una plataforma web multi-empresa de seguimiento satelital de vehículos y personal, para desplegar en un **subdominio de Hostinger** (`app.satrak.online`).

**Antes de escribir código, leé completo `satrak-plataforma-tracking-spec.md`.** Ese documento manda; si algo de este prompt parece contradecirlo, preguntá antes de avanzar.

**Lo que NO se construye ahora:** el módulo de captura de datos de los dispositivos y la app móvil del conductor. Esta plataforma define el esquema compartido (`positions`, `device_events`) y lo consume; un **generador mock** simula la captura.

## Stack y convenciones (no desviarse sin avisar)

- **PHP 8.1+**, **Slim 4** (router + middleware + `php-di`), **Twig** (`slim/twig-view`), **PDO** (sin ORM), **PHPMailer** (SMTP).
- Frontend: **HTML + CSS propio + JS vanilla**, **sin build step**, **sin framework JS**. Mapas con **Leaflet + OpenStreetMap**. Tiempo real por **polling AJAX**.
- Multi-tenant: **una sola base** con `company_id`, scope forzado por middleware/repositorios (nunca confiar en el front).
- Identidad visual **Satrak** (tokens en la spec §3): tema oscuro navy, acento teal, ámbar solo para alertas; Space Grotesk / Inter / Space Mono.
- Idioma es-AR, TZ `America/Argentina/Buenos_Aires`, unidades métricas.

## Decisiones confirmadas (resuelven §24 de la spec)

1. **Cron:** se da de alta desde el panel de Hostinger (hPanel). El procesador corre por **cron CLI real**: `bin/processor.php` cada minuto y `bin/purge.php` diario. El endpoint `/cron/run?token=` queda como **opcional** (no prioritario).
2. **PIN:** longitud **4 a 10 caracteres**; por defecto 4 numéricos, pero se acepta **alfanumérico**; **único por empresa**. Validar en cliente y servidor; `devices.has_pin` indica si el equipo identifica por PIN.
3. **SOS:** severidad `critical` con email inmediato a admins/operadores de la empresa.
4. **Umbrales por defecto:** offline 30 min, idle 10 min, corte de viaje 5 min detenido, retención 12 meses. Todos configurables en `config.php`.

## Cómo quiero que trabajes

- **Una fase por vez, en el orden de abajo.** No empieces una fase sin terminar la anterior.
- Al cerrar cada fase: (a) asegurate de que **compila y corre**, (b) verificá el **criterio de aceptación**, (c) hacé un **commit** con mensaje claro, (d) dame un **resumen corto** de qué hiciste y **cómo probarlo**, y esperá mi OK antes de seguir.
- **Invariantes que nunca se rompen** en ninguna fase: scope por `company_id` en todo acceso; CSRF en todas las mutaciones; autoescape de salida; PDO con prepared statements; acceso cruzado entre empresas ⇒ **404**; secretos solo en `config.php` (ignorado por git); auditoría de escrituras sensibles.
- Si encontrás una ambigüedad o una mejora razonable que se aparta de la spec, **proponémela y esperá confirmación**; no la apliques de una.
- Código legible y comentado donde la lógica no sea obvia (sobre todo PIN/atribución y motor de alertas).

---

## Orden de construcción (fases)

### Fase 0 — Bootstrap
**Objetivo:** proyecto Slim corriendo con identidad base.
- `composer.json` (slim/slim, slim/twig-view, php-di/php-di, phpmailer/phpmailer), estructura de carpetas (spec §17), `public/index.php` (bootstrap Slim + DI + Twig + error handler), `.htaccess` (rewrite a index.php, HTTPS, headers de seguridad), `config.example.php` + `config.php`, loader de settings, `helpers.php`, conexión PDO, `templates/layouts/app.twig` + `auth.twig`, `assets/css/tokens.css` y `app.css` con la identidad Satrak.
- **Aceptación:** `composer install` ok; la home muestra una pantalla simple con la identidad Satrak; PDO conecta a la base.

### Fase 1 — Esquema de base de datos
**Objetivo:** modelo de datos completo.
- `database/schema.sql` con todas las tablas de la spec §7 (con el ajuste de PIN 4–10). Script/instrucción de import. `bin/create_admin.php` para crear el primer **Super Admin**.
- **Aceptación:** el schema importa sin errores; existe un super admin para loguear en la fase siguiente.

### Fase 2 — Auth + Multi-tenant + RBAC + seguridad base
**Objetivo:** entrar al sistema de forma segura y aislada.
- Login/logout, recupero de contraseña (token + email), sesiones endurecidas (HttpOnly/Secure/SameSite, regeneración, timeout), `CsrfMiddleware`, `AuthMiddleware`, `TenantMiddleware` (deriva `company_id` de la sesión; **context switch** para super admin), `RbacMiddleware` (rol/permiso por ruta, matriz spec §5), `audit_log` base, rate-limit de login. Sidebar/topbar reales con menú según rol, flash messages.
- **Aceptación:** el super admin loguea; rutas protegidas redirigen; CSRF activo; acceso a entidad de otra empresa ⇒ 404; el login y las acciones quedan en `audit_log`.

### Fase 3 — ABM (gestión)
**Objetivo:** administrar todo el dominio. Construir en este sub-orden:
1. **Empresas** + `device_quota` (super admin) y alta del **Admin de Empresa**; context switch para operar dentro de una empresa.
2. **Usuarios** de la empresa (operador / conductor; el conductor opcionalmente con usuario para el portal).
3. **Conductores** (con **PIN** validado 4–10, único por empresa).
4. **Vehículos** (patente única por empresa).
5. **Dispositivos** (alta **valida el cupo** `device_quota`; flag `has_pin`).
6. **Asignaciones:** device↔vehículo (1:1 activa, con historial) y device↔conductores (allowlist para equipos con PIN / conductor por defecto para equipos sin PIN).
- Tablas con búsqueda/orden/paginación, formularios validados server-side, modales de confirmación; todo scopeado y auditado.
- **Aceptación:** el super admin crea empresa+cupo+admin; el admin crea todas las entidades respetando cupo, unicidad de PIN y de patente; las asignaciones respetan “una sola activa” y guardan historial.

### Fase 4 — Procesador: PIN + viajes (con seed mínimo)
**Objetivo:** atribución por conductor y viajes funcionando. **Núcleo del sistema.**
- `bin/seed_mock.php` (versión mínima): genera `positions` y `device_events` para las entidades ya creadas, incluyendo secuencias `pin_set`/`pin_cleared` para poder probar la atribución. *(El mock completo se pule en la Fase 9.)*
- `PinResolver` (algoritmo spec §8, manteniendo el **PIN vigente por dispositivo**), `TripBuilder` (arma/cierra viajes; **un cambio de PIN parte el viaje**), actualización de `devices.last_position_id`/`last_seen_at`. `bin/processor.php` orquestando todo, idempotente.
- **Aceptación:** corriendo el processor sobre datos mock, cada posición queda con el `driver_id` correcto (por PIN, por defecto, o **no identificado**); los `trips` son coherentes; un cambio de PIN genera un viaje nuevo; `last_seen_at` se actualiza.

### Fase 5 — Mapa en vivo + historial/reproducción
**Objetivo:** ver las unidades y sus recorridos.
- Endpoints `GET /api/live/positions` y `GET /api/devices/{id}/track`. `map.js` con Leaflet+OSM: marcadores por estado (movimiento/detenido/alerta/offline), popups (patente, conductor vigente, velocidad, última actualización en mono), **polling 15s**, panel lateral con búsqueda/filtro. Pantalla de **historial** con polilínea, inicio/fin/paradas y **reproducción** (play/pausa, 1x/2x/4x) + lista de viajes.
- **Aceptación:** con `seed_mock.php --live`, el mapa se actualiza solo; el historial dibuja y reproduce un recorrido; los viajes muestran el conductor atribuido.

### Fase 6 — Geocercas + motor de alertas + notificaciones
**Objetivo:** detectar y avisar.
- CRUD de **geocercas** (circle/polygon dibujadas en Leaflet) + `geofence_vehicles`; CRUD de `alert_rules`. `AlertEngine` dentro del processor para `speed`, `geofence_enter`/`exit`, `offline`, `sos`, `idle`; `GeofenceMath` (haversine para círculos, ray casting para polígonos). Generación de `alerts` + `notifications` (in-app) + **email** (PHPMailer). Campana de notificaciones en topbar; pantalla de alertas con **ACK**.
- **Aceptación:** reglas configurables disparan las alertas correctas sobre datos mock (exceso, geocerca, sos, offline, idle); llega notificación in-app + email; el ACK funciona y queda auditado.

### Fase 7 — Reportes
**Objetivo:** explotar los datos.
- `ReportService`: reporte **por vehículo** y **por conductor** (incluye categoría “conductor no identificado”), reporte de **alertas**; filtros por fecha/vehículo/conductor; **export CSV**.
- **Aceptación:** los números cierran contra `trips`/`alerts`; el CSV exporta correcto; el reporte por conductor refleja la atribución por PIN.

### Fase 8 — Portal del conductor
**Objetivo:** acceso limitado del conductor.
- Vistas: “mi actividad” (mis viajes + detalle en mapa), mi última posición, **mi perfil** (editar contacto y contraseña). Scope estricto al `driver_id` del usuario.
- **Aceptación:** un usuario rol `driver` solo ve lo propio; cualquier otra ruta/entidad ⇒ 404.

### Fase 9 — Mock completo, pulido y deploy
**Objetivo:** demo reproducible y listo para Hostinger.
- `bin/seed_mock.php` completo: `--days=N` (histórico) y `--live` (continuo), rutas realistas en Argentina (incluí un recorrido en zona Neuquén), eventos `sos`, excesos y demo de PIN con varios conductores. `bin/purge.php` (retención 12 meses). `README.md` con deploy en Hostinger (document root al `/public`, import de schema, config, **alta de cron desde hPanel**, SSL). Repaso final de checklist de seguridad (spec §20), accesibilidad (AA, foco visible, `prefers-reduced-motion`) y responsive.
- **Aceptación:** demo end-to-end reproducible desde cero; checklist de seguridad completo; el README permite desplegar en Hostinger sin pasos faltantes.

---

## Definición de “terminado” (global)
Todas las fases cerradas y verificadas; los cuatro roles funcionando con su scope; la lógica de PIN/atribución y el motor de alertas probados con datos mock; mapa en vivo, historial, geocercas, alertas (in-app + email), reportes (vehículo y conductor con CSV) y portal del conductor operativos; identidad Satrak aplicada; responsive y accesible; `README.md`, `config.example.php`, `.htaccess`, `.gitignore` y crons documentados; checklist de seguridad de la spec §20 cumplido.

## Arranque
Empezá confirmándome que leíste la spec y que el plan de fases te cierra. Después arrancá con la **Fase 0** y seguí el ciclo: construir → correr → verificar criterio → commit → resumen → esperar OK.
