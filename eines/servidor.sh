#!/bin/sh
# Servidor de proves: http://localhost:8026  (des del mòbil, a la mateixa wifi: http://IP-DE-L-ORDINADOR:8026)
# Ús: sh eines/servidor.sh [port]
cd "$(dirname "$0")/.." || exit 1
PHP=$(sh eines/php.sh) || exit 1
PORT=${1:-8026}
[ -f public_html/snapshot.json ] || "$PHP" eines/dades-exemple.php
echo "Pessebre de proves a http://localhost:$PORT  (Ctrl+C per aturar)"
echo "Exemples: #s=inici  #s=classes  #s=posta  #s=nit  #s=neu   ·   mode participar: #c=PROVA  o  #k=PROVA"
exec "$PHP" -S "0.0.0.0:$PORT" -t public_html eines/router.php
