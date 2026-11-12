#!/bin/sh
# DEMOSTRACIÓ del pessebre per ensenyar-lo des d'aquest ordinador i des del mòbil (mateixa wifi o compartint
# internet del mòbil). No cal Claude ni internet. MAI a l'hosting: tot és a demo/ i a demo/dades/ (no es puja).
# Ús: sh eines/demo.sh [port]        Atura amb Ctrl+C. La demo recorda la fase on era.
cd "$(dirname "$0")/.." || exit 1
PHP=$(sh eines/php.sh) || exit 1
PORT=${1:-8027}
"$PHP" demo/prepara.php || exit 1

echo ""
echo "Demo del pessebre (contrasenya d'administració: demo)"
echo "  En aquest ordinador:  http://localhost:$PORT/demo"
for IP in $(ifconfig 2>/dev/null | awk '/inet / && $2 != "127.0.0.1" {print $2}'); do
  echo "  Des del mòbil:        http://$IP:$PORT/demo"
done
echo "Si el mòbil no hi arriba, la wifi pot bloquejar-ho: prova compartint internet del mòbil amb l'ordinador."
echo "Ctrl+C per aturar."
echo ""
PESSEBRE_CONFIG="$PWD/demo/dades/config.php" PHP_CLI_SERVER_WORKERS=4 exec "$PHP" -S "0.0.0.0:$PORT" -t public_html demo/router.php
