# Satrak — Sitio institucional

Sitio institucional + captación de leads de **Satrak** (seguimiento satelital de vehículos y personal).
PHP plano, sin framework ni build step de frontend. Pensado para hosting compartido (cPanel/Apache).

- **Dominio:** `satrak.online`
- **Idioma:** español (Argentina)
- **Stack:** PHP 8.1+, Apache + `.htaccess`, HTML/CSS/JS vanilla, PHPMailer (vía Composer), MySQL opcional.

---

## Estructura

```
satrak-site/
├── public/          # DocumentRoot (front controller + assets estáticos)
│   ├── index.php    # único punto de entrada
│   ├── .htaccess    # rewrite + HTTPS + cache + headers de seguridad
│   └── assets/      # css, js, img
├── app/             # router, helpers, controllers, views, content
│   ├── content/     # textos editables (servicios, planes, faqs, site)
│   └── views/       # layouts, partials y pages
├── config/          # config.php (real, no se versiona) + config.example.php
├── database/        # schema.sql (tabla leads)
├── storage/         # logs (fuera de public/)
├── vendor/          # Composer (PHPMailer)
└── composer.json
```

El contenido editable (sin tocar plantillas) vive en `app/content/*.php`.

---

## Desarrollo local

Requiere PHP 8.1+.

```bash
cd satrak-site
composer install            # instala PHPMailer en vendor/
php -S localhost:8000 -t public public/index.php
```

> El último argumento (`public/index.php`) hace de router para el servidor embebido, de modo que las URLs limpias (`/contacto`, `/servicios/vehiculos`, etc.) funcionen igual que con Apache.

Abrir <http://localhost:8000>.

En local, `config/config.php` ya viene con `env => local` y `debug => true`. En ese modo el formulario **no** envía mail real: registra el lead en `storage/contact.log` y devuelve éxito, para poder probar el flujo completo.

---

## Configuración

1. Copiar la plantilla: `cp config/config.example.php config/config.php`
2. Completar en `config/config.php`:
   - `contact.to_email` — casilla donde llegan los leads.
   - `contact.whatsapp` — número en formato internacional **sin** `+` (ej. `5492995551234`).
   - `smtp.*` — credenciales SMTP del hosting o de un servicio (Brevo/Zoho).
   - `db.*` — opcional. Si `host` queda vacío, se saltea la persistencia y el lead se envía solo por mail.
3. Editar los datos visibles del sitio (teléfono, email, horario, redes) en `app/content/site.php`.

`config/config.php` está en `.gitignore`: no se versiona con secretos.

---

## Despliegue en hosting con `public_html` (recomendado)

El hosting tiene un `public_html` fijo que no se puede cambiar de lugar. El `index.php` detecta solo dónde está la carpeta `app/`, así que **no hay que editar ningún archivo**: solo separar lo público de lo privado.

### Opción rápida: usar el build

```bash
composer install --no-dev -o     # genera vendor/
bash build-deploy.sh             # genera dist/
```

Esto crea `dist/` con dos carpetas y un `LEEME.txt`:

- **`dist/public_html/`** → subí su **contenido** dentro del `public_html/` del hosting
  (queda `…/public_html/index.php`, `…/public_html/assets/`, etc.).
- **`dist/PRIVADO/`** → subí su **contenido** a la carpeta **padre** de `public_html`
  (tu home, normalmente `/home/TUUSUARIO/`), de modo que quede **fuera de la web**:

```
/home/TUUSUARIO/
├── public_html/        ← contenido de dist/public_html/
│   ├── index.php
│   ├── .htaccess
│   └── assets/ …
├── app/                ┐
├── config/             │  contenido de dist/PRIVADO/
├── vendor/             │  (fuera de la raíz web = más seguro)
├── database/           │
└── storage/            ┘
```

Después:

1. **Config:** en `config/config.php` cargá las credenciales reales (SMTP, casilla de leads, WhatsApp, y DB si la usás). Ya está `config.example.php` como plantilla.
2. **SSL:** activá Let's Encrypt en cPanel y verificá la redirección a HTTPS.
3. **DB (opcional):** importá `database/schema.sql` en phpMyAdmin y completá `db.*`.
4. **Probar:** formulario (mail + DB), botón WhatsApp, 404, responsive y Lighthouse.

### Si tu FTP no te deja subir fuera de `public_html`

Subí **todo** dentro de `public_html/` (incluyendo `app/`, `config/`, `vendor/`, `database/`, `storage/`). El `index.php` igual los detecta y el sitio funciona. Es algo menos seguro: asegurate de que el `.htaccess` esté presente (bloquea `config.php` y archivos sensibles).

### Si el hosting no tiene Composer

Subí la carpeta `vendor/` ya generada en local. El sitio además degrada con elegancia: si falta `vendor/autoload.php` o el SMTP no está configurado, intenta `mail()` nativo.

---

## Assets a generar antes de publicar

Estos binarios no se incluyen y conviene exportarlos desde la marca:

- `public/favicon.ico`
- `public/assets/img/favicon-32.png` (32×32)
- `public/assets/img/apple-touch-icon-180.png` (180×180)
- `public/assets/img/icon-512.png` (512×512)
- `public/assets/img/og-image.png` (1200×630, con marca)

La fuente vectorial del isotipo está en `public/assets/img/favicon.svg` (se puede exportar a los tamaños de arriba). Mientras tanto, el navegador usa ese SVG como favicon.

---

## Seguridad

- Toda salida se escapa con `e()` (`htmlspecialchars`).
- Formulario con token **CSRF**, **honeypot** y **rate-limit** por sesión.
- Persistencia con **PDO + prepared statements**.
- `display_errors=0` en producción; los errores se loguean en `storage/`.
- `config/` vive fuera de `public/`.
- Headers de seguridad en `.htaccess`.

---

## Rutas

| Ruta | Página |
|---|---|
| `/` | Home |
| `/servicios/vehiculos` | Rastreo de vehículos |
| `/servicios/personal` | Rastreo de personal |
| `/cobertura` | Cobertura (diferencial) |
| `/planes` | Planes |
| `/nosotros` | Quiénes somos |
| `/contacto` | Contacto + formulario |
| `/contacto/enviar` | Endpoint POST del formulario |
| `/faq` | Preguntas frecuentes |
| `/privacidad` · `/terminos` | Legales |
| * | 404 |
