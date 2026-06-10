# Deploy en Hostinger — Satrak

Despliegue de los **dos** proyectos del repo:

| Proyecto | Dominio | Document root | Código privado (fuera de la web) |
|---|---|---|---|
| Sitio institucional | `satrak.online` | `~/public_html` | `~` (home) |
| Plataforma de tracking | `app.satrak.online` | `~/public_html/app` | `~/satrak-private/satrak-app` |

> `~` = tu home de Hostinger, p.ej. `/home/u123456789`.

El **código privado vive fuera del document root**: nadie puede pedir por web
`vendor/`, `config/`, `src/`, etc. Solo se sirve lo que está en los document roots.

---

## 0) Requisitos (una vez)

- Plan con **SSH + Composer** (Business/Cloud). Confirmado ✓.
- Subdominio `app.satrak.online` creado, con Document Root = `public_html/app` ✓.
- Acceso SSH habilitado en hPanel (**Avanzado → Acceso SSH**).

---

## 1) Base de datos MySQL (en hPanel) — **solo accesible desde el hosting**

1. hPanel → **Bases de datos → MySQL**. Creá **una base y un usuario** para el tracking
   (y opcionalmente otra para los leads del sitio):
   - Tracking: base `satrak_app`, usuario `satrak_app`, contraseña fuerte.
   - (Opcional) Sitio: base `satrak_site` para los leads del formulario.
2. **Local-only (lo pediste):** Hostinger expone MySQL en `localhost` y **no** acepta
   conexiones remotas salvo que las habilites. Para mantenerlo cerrado:
   - **NO** uses **Bases de datos → MySQL remoto / Remote MySQL**.
   - Si alguna vez agregaste una IP/`%` ahí, **eliminala** (dejá la lista vacía).
   - En `config.php` el host siempre es **`localhost`** (conexión por socket local).
   > Con eso, la base solo es accesible desde el propio servidor. No hace falta nada más.
3. Anotá nombre de base, usuario y contraseña: van en `config.php` (paso 4).

---

## 2) Traer el código al servidor (una vez)

Por SSH, cloná el repo en una carpeta de trabajo (no en la web):

```bash
cd ~
git clone <URL_DEL_REPO> satrak-repo
```

Copiá y ajustá la config del deploy:

```bash
cd ~/satrak-repo
cp deploy/deploy.config.example.sh deploy/deploy.config.sh
# Normalmente NO hace falta editar nada: usa $HOME automáticamente.
# Revisalo si tu Composer se invoca distinto o cambiaste rutas.
nano deploy/deploy.config.sh
```

---

## 3) Desplegar

```bash
cd ~/satrak-repo
bash deploy/deploy.sh all      # site + app   (o: site | app)
```

El script: hace `git pull`, corre `composer install --no-dev -o`, publica lo público
a cada document root y el código privado fuera de la web, y crea `config.php` desde la
plantilla **solo si no existe** (nunca pisa el real ni los logs).

Para futuras actualizaciones, alcanza con repetir este único comando.

---

## 4) Completar `config.php` (una vez por proyecto)

El script deja un `config.php` a partir de la plantilla. Editá los reales:

**Tracking** — `~/satrak-private/satrak-app/config/config.php`:
```php
'app' => ['env' => 'production', 'debug' => false, 'base_url' => 'https://app.satrak.online', ...],
'db'  => ['host' => 'localhost', 'name' => 'satrak_app', 'user' => 'satrak_app', 'pass' => '••••', ...],
'smtp'=> ['host' => '...', 'user' => '...', 'pass' => '...', 'from' => 'alertas@satrak.online', ...],
'cron'=> ['token' => '<token largo aleatorio>'],
```

**Sitio** — `~/config/config.php`: completá `contact.*`, `smtp.*` y (si usás leads) `db.*`
con `host => 'localhost'`. Dejá `env => 'production'`, `debug => false`.

> `config.php` está en `.gitignore`: nunca se versiona ni se sobrescribe en el deploy.

---

## 5) Importar el esquema del tracking

Cuando exista `database/schema.sql` (Fase 1), importalo en la base del tracking:

```bash
mysql -h localhost -u satrak_app -p satrak_app < ~/satrak-private/satrak-app/database/schema.sql
```
o por **phpMyAdmin** (hPanel → Bases de datos → phpMyAdmin → Importar).

(El sitio institucional solo necesita importar su `schema.sql` si vas a guardar leads en DB.)

---

## 6) SSL / HTTPS

hPanel → **Seguridad → SSL**: activá **Let's Encrypt** para `satrak.online` y para
`app.satrak.online`. Verificá que el `.htaccess` redirija a HTTPS (ya viene configurado).

---

## 7) Cron del tracking (en hPanel → Avanzado → Cron Jobs)

El procesador y la purga corren por **cron CLI real**. Usá la ruta PRIVADA de `bin/`:

```
* * * * *   /usr/bin/php ~/satrak-private/satrak-app/bin/processor.php   >/dev/null 2>&1
30 3 * * *  /usr/bin/php ~/satrak-private/satrak-app/bin/purge.php       >/dev/null 2>&1
```

> Ajustá la ruta de `php` a la que muestre `which php` en tu SSH. Estos scripts se
> agregan en Fase 4/9; dejá el cron listo cuando existan.

---

## 8) Verificación

- `https://satrak.online` → sitio institucional OK.
- `https://app.satrak.online` → splash del tracking; `/health` devuelve JSON con `db.ok = true`.
- Probar que **no** sean accesibles por web (deben dar 403/404):
  `https://app.satrak.online/_satrak_root.php`, `.../config/config.php`.

---

## Checklist de seguridad (resumen, spec §20)

- [ ] Código privado (`vendor/`, `config/`, `src/`) **fuera** del document root.
- [ ] `config.php` con secretos, nunca versionado, `host=localhost`.
- [ ] **Remote MySQL deshabilitado** (lista de hosts remotos vacía).
- [ ] SSL activo en ambos dominios + redirección a HTTPS.
- [ ] `debug=false`, `env=production`; errores logueados en `storage/`, no en pantalla.
- [ ] `/cron/*` (si se usa) solo por token; cron CLI preferido.

---

## Sin SSH (plan B, por si alguna vez hace falta)

Si en algún momento no tenés SSH, se puede generar localmente un `dist/` con
`composer install --no-dev -o` + un script de build y subirlo por **File Manager/FTP**
(lo público a cada document root, lo privado al home). El modelo de carpetas es el mismo.
