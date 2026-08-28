#!/usr/bin/env bash
# Genera los íconos de la app desde los SVG de esta carpeta.
#
#   ./generar-iconos.sh
#
# Requiere `rsvg-convert` (brew install librsvg) y Pillow (pip install pillow).
# Los PNG generados se versionan: el hosting y las tiendas no corren build.
set -euo pipefail
cd "$(dirname "$0")"

RES=../android/app/src/main/res
IOS=../ios/App/App/Assets.xcassets/AppIcon.appiconset

echo "→ Android · ícono legacy (pre-Android 8)"
for par in "mdpi 48" "hdpi 72" "xhdpi 96" "xxhdpi 144" "xxxhdpi 192"; do
  set -- $par
  rsvg-convert -w "$2" -h "$2" icon.svg -o "$RES/mipmap-$1/ic_launcher.png"
  printf "   %-8s %sx%s\n" "$1" "$2" "$2"
done

echo "→ Android · ícono redondo (lanzadores legacy con máscara circular)"
for par in "mdpi 48" "hdpi 72" "xhdpi 96" "xxhdpi 144" "xxxhdpi 192"; do
  set -- $par
  rsvg-convert -w "$2" -h "$2" icon.svg -o "/tmp/_round_$1.png"
  python3 - "$2" "/tmp/_round_$1.png" "$RES/mipmap-$1/ic_launcher_round.png" <<'PY'
import sys
from PIL import Image, ImageDraw
n, src, dst = int(sys.argv[1]), sys.argv[2], sys.argv[3]
# Se recorta a mano porque estos lanzadores NO aplican máscara: usan el PNG tal cual.
img = Image.open(src).convert('RGBA')
mask = Image.new('L', (n * 4, n * 4), 0)
ImageDraw.Draw(mask).ellipse([0, 0, n * 4 - 1, n * 4 - 1], fill=255)
img.putalpha(mask.resize((n, n), Image.LANCZOS))
img.save(dst)
PY
  rm -f "/tmp/_round_$1.png"
done

echo "→ Android · primer plano del ícono adaptativo (Android 8+)"
# El lienzo es 108dp aunque sólo el 66% central esté garantizado.
for par in "mdpi 108" "hdpi 162" "xhdpi 216" "xxhdpi 324" "xxxhdpi 432"; do
  set -- $par
  rsvg-convert -w "$2" -h "$2" icon-foreground.svg -o "$RES/mipmap-$1/ic_launcher_foreground.png"
  printf "   %-8s %sx%s\n" "$1" "$2" "$2"
done

echo "→ iOS · 1024x1024 sin canal alfa"
rsvg-convert -w 1024 -h 1024 icon.svg -o /tmp/_ios.png
python3 - "/tmp/_ios.png" "$IOS/AppIcon-512@2x.png" <<'PY'
import sys
from PIL import Image
# App Store RECHAZA íconos con transparencia: se aplana sobre el navy de marca.
img = Image.open(sys.argv[1]).convert('RGBA')
fondo = Image.new('RGB', img.size, (4, 9, 26))
fondo.paste(img, mask=img.split()[3])
fondo.save(sys.argv[2])
PY
rm -f /tmp/_ios.png

echo "→ Ficha de tienda · 512x512 (Google Play)"
rsvg-convert -w 512 -h 512 icon.svg -o /tmp/_play.png
python3 - "/tmp/_play.png" "play-icon-512.png" <<'PY'
import sys
from PIL import Image
img = Image.open(sys.argv[1]).convert('RGBA')
fondo = Image.new('RGB', img.size, (4, 9, 26))
fondo.paste(img, mask=img.split()[3])
fondo.save(sys.argv[2])
PY
rm -f /tmp/_play.png

echo
echo "listo."
