#!/usr/bin/env bash
# =============================================================================
# Satrak — Configuración del deploy en Hostinger (plantilla)
#
# Copiá este archivo a deploy/deploy.config.sh y ajustá lo que haga falta.
# deploy.config.sh está en .gitignore: NO se versiona.
#
# El script corre EN EL SERVIDOR (vía SSH), así que $HOME ya apunta a tu home
# de Hostinger (p.ej. /home/u123456789). Por eso casi nada necesita tocarse.
# =============================================================================

# ----- Origen: checkout del repositorio en el servidor -----------------------
# Cloná una vez el repo en el servidor (ver DEPLOY-HOSTINGER.md) y poné acá su ruta.
REPO_DIR="${REPO_DIR:-$HOME/satrak-repo}"

# Rama a desplegar.
GIT_BRANCH="${GIT_BRANCH:-main}"

# Si es 1, hace `git pull` antes de publicar. Poné 0 para desplegar lo que ya hay.
GIT_PULL="${GIT_PULL:-1}"

# ----- Destinos en el servidor -----------------------------------------------
# Raíz web del dominio principal (sitio institucional satrak.online).
SITE_DOCROOT="${SITE_DOCROOT:-$HOME/public_html}"

# Document root del subdominio app.satrak.online (lo definiste como public_html/app).
APP_DOCROOT="${APP_DOCROOT:-$HOME/public_html/app}"

# Carpeta privada del SITIO institucional. Su index.php detecta el código en el
# PADRE de public_html, por eso va directamente en $HOME (ahí quedan app/ config/
# vendor/ database/ storage/, fuera de la web). No cambiar salvo que sepas lo que hacés.
SITE_PRIVATE="${SITE_PRIVATE:-$HOME}"

# Carpeta privada del TRACKING (fuera de la web). El index.php del tracking es
# flexible: ubica esta ruta vía _satrak_root.php (que escribe este script) o la
# env SATRAK_APP_ROOT. Acá quedan src/ vendor/ config/ templates/ bin/ database/ storage/.
APP_PRIVATE="${APP_PRIVATE:-$HOME/satrak-private/satrak-app}"

# ----- Binarios --------------------------------------------------------------
PHP_BIN="${PHP_BIN:-php}"
# En Hostinger Composer suele invocarse como 'composer' o 'php composer.phar'.
COMPOSER_BIN="${COMPOSER_BIN:-composer}"

# ----- Comportamiento --------------------------------------------------------
# Instalar dependencias de producción (sin dev) y optimizar autoload.
COMPOSER_FLAGS="${COMPOSER_FLAGS:---no-dev --optimize-autoloader --no-interaction}"
