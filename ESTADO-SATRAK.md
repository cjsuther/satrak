# Estado del proyecto Satrak

> Nota de avance para retomar el trabajo en otra máquina. No es parte del producto;
> es la bitácora de en qué punto quedó la construcción por fases.

## Qué hay en este repo

Dos proyectos en un mismo repositorio:

| Proyecto | Dominio | Carpeta | Stack |
|---|---|---|---|
| Sitio institucional | `satrak.online` | raíz (`public/`, `app/`, `config/`, ...) | PHP plano |
| Plataforma de tracking | `app.satrak.online` | `satrak-app/` | Slim 4 + Twig + PDO |

Fuente de verdad del tracking: **`satrak-plataforma-tracking-spec.md`** (la spec manda).
Orden de construcción: **`satrak-prompt-claude-code.md`** (10 fases, 0→9).
Guía de despliegue: **`deploy/DEPLOY-HOSTINGER.md`** + scripts en `deploy/`.

## Avance por fases

- [x] **Fase 0 — Bootstrap.** Slim corriendo, DI + Twig + error handler, identidad
      Satrak, `public/index.php` (resuelve raíz privada: env `SATRAK_APP_ROOT` /
      `_satrak_root.php` / layout intacto), `/` y `/health`, assets, PDO perezoso.
- [x] **Kit de deploy Hostinger.** `deploy/deploy.sh [site|app|all]`,
      `deploy.config.example.sh`, `DEPLOY-HOSTINGER.md`. Modelo split (código
      privado fuera del document root). MySQL local-only (host=localhost, sin
      Remote MySQL).
- [x] **Fase 1 — Esquema de base de datos.** `satrak-app/database/schema.sql`
      (17 tablas, spec §7, FKs con ON DELETE apropiado, PIN único por empresa) +
      `satrak-app/bin/create_admin.php` (alta CLI del primer super admin, argon2id).
- [ ] **Fase 2 — Auth + multi-tenant + RBAC + CSRF + sesiones + audit_log.** ← SIGUIENTE
- [ ] Fase 3 — ABM (empresas, usuarios, conductores, vehículos, dispositivos, asignaciones).
- [ ] Fase 4 — Procesador: PinResolver + TripBuilder + seed mínimo. **Núcleo.**
- [ ] Fase 5 — Mapa en vivo + historial/reproducción.
- [ ] Fase 6 — Geocercas + motor de alertas + notificaciones (in-app + email).
- [ ] Fase 7 — Reportes (vehículo/conductor + CSV).
- [ ] Fase 8 — Portal del conductor.
- [ ] Fase 9 — Mock completo, pulido, deploy.

## Decisiones / pendientes abiertos

1. **Verificación de DB**: en la máquina de desarrollo no había MySQL ni Docker, así
   que el import real de `schema.sql` quedó **sin probar** contra un MySQL. Falta
   decidir: instalar MySQL local vs probar contra Hostinger. Validado por ahora:
   `php -l` del script OK y chequeo estructural del SQL (17 tablas, paréntesis
   balanceados, sin comas colgantes).
2. **FKs en el schema**: se agregaron FKs explícitas (la guía de §7 las pide aunque
   el DDL de ejemplo solo traía índices). Confirmar si se mantienen o se deja el DDL
   literal sin FKs.

## Cómo retomar en otra máquina

```bash
git clone https://github.com/cjsuther/satrak.git
cd satrak/satrak-app
cp config/config.example.php config/config.php   # editar db/* (host=localhost) y app.*
# instalar dependencias del tracking:
composer install        # requiere extensión zip; en local se usó: php -d extension=zip composer.phar install --prefer-dist
```

Requisitos locales usados: **PHP 8.3**, **Composer 2.x**. `config/config.php` y
`vendor/` NO están versionados (`.gitignore`): se generan en cada máquina.

Servidor de desarrollo del tracking:
```bash
cd satrak-app/public && php -S localhost:8080
# http://localhost:8080/  y  http://localhost:8080/health
```

## Convenciones de trabajo (del prompt)

Una fase por vez: construir → correr → verificar criterio de aceptación → commit →
resumen → esperar OK. Invariantes que nunca se rompen: scope por `company_id`, CSRF
en mutaciones, autoescape, PDO preparado, acceso cruzado entre empresas ⇒ 404,
secretos solo en `config.php`, auditoría de escrituras sensibles.
