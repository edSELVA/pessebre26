#!/bin/sh
# Troba l'executable de PHP: variable PHP, el del PATH o el de MAMP/XAMPP (macOS).
if [ -n "${PHP:-}" ]; then echo "$PHP"; exit 0; fi
if command -v php >/dev/null 2>&1; then command -v php; exit 0; fi
for p in /Applications/MAMP/bin/php/php8*/bin/php /Applications/XAMPP/xamppfiles/bin/php; do
  [ -x "$p" ] && { echo "$p"; exit 0; }
done
echo "No trobo PHP. Instal·la'l o indica'l amb PHP=/camí/a/php" >&2
exit 1
