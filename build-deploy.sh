#!/usr/bin/env bash
# Arma una carpeta dist/ lista para subir a un hosting con public_html fijo.
# Resultado:
#   dist/public_html/  -> subir su CONTENIDO dentro de public_html/ del hosting
#   dist/PRIVADO/      -> subir su CONTENIDO a la carpeta PADRE de public_html (tu home, p.ej. /home/usuario/)
#
# Uso:  composer install --no-dev -o   (antes, para tener vendor/)
#       bash build-deploy.sh
set -euo pipefail
cd "$(dirname "$0")"

echo "→ Limpiando dist/ anterior"
rm -rf dist
mkdir -p dist/public_html dist/PRIVADO

echo "→ Copiando contenido público a dist/public_html"
cp -R public/. dist/public_html/

echo "→ Copiando código privado a dist/PRIVADO"
cp -R app      dist/PRIVADO/
cp -R config   dist/PRIVADO/
cp -R database dist/PRIVADO/

# No enviar el config.php LOCAL (env=local hace que el form simule el envío sin mandar mail).
# En el hosting se crea config.php a partir de config.example.php con valores de producción.
rm -f dist/PRIVADO/config/config.php
cp -R storage  dist/PRIVADO/ 2>/dev/null || mkdir -p dist/PRIVADO/storage
if [ -d vendor ]; then
  cp -R vendor dist/PRIVADO/
else
  echo "  ⚠  vendor/ no existe. Ejecutá: composer install --no-dev -o"
fi

# Nota de despliegue dentro de dist/
cat > dist/LEEME.txt <<'TXT'
DEPLOY EN HOSTING CON public_html
=================================

1) Subí TODO el contenido de  public_html/  dentro de la carpeta public_html/ del hosting.
   (index.php debe quedar en  .../public_html/index.php)

2) Subí TODO el contenido de  PRIVADO/  a la carpeta PADRE de public_html
   (tu directorio home, normalmente /home/TUUSUARIO/).
   Deben quedar así, FUERA de la web:
        /home/TUUSUARIO/app/
        /home/TUUSUARIO/config/
        /home/TUUSUARIO/vendor/
        /home/TUUSUARIO/database/
        /home/TUUSUARIO/storage/
   El index.php los encuentra solo: no hay que editar nada.

3) Creá  config/config.php  copiando  config.example.php  y completá las
   credenciales reales (SMTP, casilla de leads, WhatsApp, y DB si la usás).
   IMPORTANTE: en producción dejá  'env' => 'production'  y  'debug' => false.
   (Con env=local el formulario NO envía mail: solo lo registra y simula éxito.)

4) Activá SSL (Let's Encrypt) y verificá que redirija a HTTPS.

5) (Opcional) Importá database/schema.sql en phpMyAdmin si vas a guardar leads en MySQL.

¿No podés subir nada fuera de public_html?
  Entonces subí TAMBIÉN las carpetas app/ config/ vendor/ database/ storage/
  DENTRO de public_html/. El sitio igual funciona (index.php lo detecta),
  pero es menos seguro: asegurate de que el .htaccess esté presente.
TXT

echo "→ Listo. Estructura:"
echo
echo "dist/"
echo "├── public_html/   (subir su contenido a public_html del hosting)"
echo "├── PRIVADO/       (subir su contenido a la carpeta padre de public_html)"
echo "└── LEEME.txt"
