#!/usr/bin/env bash
# =============================================================================
# Satrak — Deploy en Hostinger (se ejecuta EN EL SERVIDOR, vía SSH)
#
#   Uso:   bash deploy/deploy.sh [site|app|all]
#          (sin argumento => all)
#
# Qué hace:
#   - (opcional) git pull de la rama configurada
#   - composer install --no-dev -o en cada proyecto
#   - publica lo PÚBLICO a su document root y el código PRIVADO fuera de la web
#   - nunca pisa config.php existente ni los logs de storage/
#
# Configuración: copiá deploy/deploy.config.example.sh -> deploy/deploy.config.sh
# Requisitos en el servidor: bash, git, php 8.1+, composer, (rsync recomendado).
# =============================================================================
set -euo pipefail

# --------------------------------------------------------------- utilidades
c_blue=$'\033[34m'; c_green=$'\033[32m'; c_yellow=$'\033[33m'; c_red=$'\033[31m'; c_off=$'\033[0m'
log()  { printf '%s→ %s%s\n' "$c_blue" "$*" "$c_off"; }
ok()   { printf '%s✓ %s%s\n' "$c_green" "$*" "$c_off"; }
warn() { printf '%s⚠ %s%s\n' "$c_yellow" "$*" "$c_off"; }
die()  { printf '%s✗ %s%s\n' "$c_red" "$*" "$c_off" >&2; exit 1; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# --------------------------------------------------------------- config
CONFIG="$SCRIPT_DIR/deploy.config.sh"
[ -f "$CONFIG" ] || die "Falta deploy/deploy.config.sh. Copialo de deploy.config.example.sh y ajustalo."
# shellcheck source=/dev/null
source "$CONFIG"

command -v "$PHP_BIN" >/dev/null 2>&1 || die "No se encontró PHP ($PHP_BIN)."
# Composer puede ser 'composer' o requerir 'php composer.phar'. Lo resolvemos en composer_run().
HAS_RSYNC=0; command -v rsync >/dev/null 2>&1 && HAS_RSYNC=1

# Copia el CONTENIDO de un directorio a otro (overlay, sin borrar lo que no esté en origen).
# publish_dir <origen> <destino> [exclude1 exclude2 ...]
publish_dir() {
  local src="$1"; local dest="$2"; shift 2
  [ -d "$src" ] || { warn "No existe origen: $src (se omite)"; return 0; }
  mkdir -p "$dest"
  if [ "$HAS_RSYNC" -eq 1 ]; then
    local args=(-rtl --omit-dir-times)
    local ex; for ex in "$@"; do args+=(--exclude "$ex"); done
    rsync "${args[@]}" "$src"/ "$dest"/
  else
    # Fallback sin rsync: cp -R (no respeta excludes; los borramos luego).
    cp -R "$src"/. "$dest"/
    local ex; for ex in "$@"; do rm -rf "${dest:?}/${ex}"; done
  fi
}

composer_run() {
  local dir="$1"
  log "composer install en $dir"
  ( cd "$dir"
    if command -v composer >/dev/null 2>&1; then
      # shellcheck disable=SC2086
      composer install $COMPOSER_FLAGS
    else
      # shellcheck disable=SC2086
      "$PHP_BIN" "$(command -v composer.phar || echo composer.phar)" install $COMPOSER_FLAGS
    fi )
}

# Crea config.php desde config.example.php solo si NO existe. Nunca pisa el real.
ensure_config() {
  local dir="$1"; local label="$2"
  if [ -f "$dir/config/config.php" ]; then
    ok "$label: config.php ya existe (no se toca)"
  elif [ -f "$dir/config/config.example.php" ]; then
    cp "$dir/config/config.example.php" "$dir/config/config.php"
    warn "$label: creé config.php desde la plantilla — EDITALO con DB/SMTP reales (host=localhost)."
  else
    warn "$label: no hay config.example.php en $dir/config/"
  fi
}

git_update() {
  [ "${GIT_PULL:-0}" = "1" ] || { warn "GIT_PULL=0: despliego lo que ya está en $REPO_DIR"; return 0; }
  [ -d "$REPO_DIR/.git" ] || die "REPO_DIR ($REPO_DIR) no es un repo git. Cloná primero (ver DEPLOY-HOSTINGER.md)."
  log "git pull ($GIT_BRANCH) en $REPO_DIR"
  ( cd "$REPO_DIR" && git fetch --prune origin && git checkout "$GIT_BRANCH" && git pull --ff-only origin "$GIT_BRANCH" )
}

# ----------------------------------------------------------- deploy SITIO
deploy_site() {
  log "==== Sitio institucional (satrak.online) ===="
  local SRC="$REPO_DIR"
  [ -f "$SRC/public/index.php" ] || die "No encuentro $SRC/public/index.php"
  composer_run "$SRC"

  log "Publicando público → $SITE_DOCROOT"
  publish_dir "$SRC/public" "$SITE_DOCROOT"

  log "Publicando privado → $SITE_PRIVATE (fuera de la web)"
  publish_dir "$SRC/app"      "$SITE_PRIVATE/app"
  publish_dir "$SRC/database" "$SITE_PRIVATE/database"
  publish_dir "$SRC/vendor"   "$SITE_PRIVATE/vendor"
  # config: traigo la plantilla pero preservo config.php real.
  publish_dir "$SRC/config"   "$SITE_PRIVATE/config" "config.php"
  mkdir -p "$SITE_PRIVATE/storage"; chmod -R u+rwX "$SITE_PRIVATE/storage" 2>/dev/null || true

  ensure_config "$SITE_PRIVATE" "Sitio"
  ok "Sitio desplegado."
}

# --------------------------------------------------------- deploy TRACKING
deploy_app() {
  log "==== Plataforma de tracking (app.satrak.online) ===="
  local SRC="$REPO_DIR/satrak-app"
  [ -f "$SRC/public/index.php" ] || die "No encuentro $SRC/public/index.php"
  composer_run "$SRC"

  log "Publicando público → $APP_DOCROOT"
  publish_dir "$SRC/public" "$APP_DOCROOT"

  log "Publicando privado → $APP_PRIVATE (fuera de la web)"
  publish_dir "$SRC/src"       "$APP_PRIVATE/src"
  publish_dir "$SRC/templates" "$APP_PRIVATE/templates"
  publish_dir "$SRC/bin"       "$APP_PRIVATE/bin"
  publish_dir "$SRC/database"  "$APP_PRIVATE/database"
  publish_dir "$SRC/vendor"    "$APP_PRIVATE/vendor"
  publish_dir "$SRC/config"    "$APP_PRIVATE/config" "config.php"
  mkdir -p "$APP_PRIVATE/storage/logs" "$APP_PRIVATE/storage/cache"
  chmod -R u+rwX "$APP_PRIVATE/storage" 2>/dev/null || true

  # Decirle al index.php dónde está la raíz privada (modelo split).
  printf '<?php return %s;\n' "$(printf "'%s'" "${APP_PRIVATE//\'/\\\'}")" > "$APP_DOCROOT/_satrak_root.php"
  ok "Escribí $APP_DOCROOT/_satrak_root.php → $APP_PRIVATE"

  ensure_config "$APP_PRIVATE" "Tracking"
  ok "Tracking desplegado."
}

# ------------------------------------------------------------------- main
TARGET="${1:-all}"
git_update
case "$TARGET" in
  site) deploy_site ;;
  app)  deploy_app ;;
  all)  deploy_site; deploy_app ;;
  *)    die "Destino inválido: '$TARGET'. Usá: site | app | all" ;;
esac

echo
ok "Deploy '$TARGET' terminado."
warn "Pendientes manuales (una vez): crear DB MySQL y completar config.php (host=localhost),"
warn "activar SSL/HTTPS, configurar los cron del tracking y NO habilitar 'Remote MySQL'."
echo "Detalle en deploy/DEPLOY-HOSTINGER.md"
