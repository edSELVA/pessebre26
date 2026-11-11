#!/bin/sh
# Comprova que no es pugen secrets ni dades reals al repositori (CLAUDE.md §9).
#   sh eines/comprova-secrets.sh             fitxers preparats per al commit (el fa servir el hook pre-commit)
#   sh eines/comprova-secrets.sh --tot       tots els fitxers del repositori
#   sh eines/comprova-secrets.sh --fitxer F  un fitxer concret
# Excepcions conegudes (exemples de documentació): eines/secrets-permesos.txt, una per línia.
set -u
cd "$(dirname "$0")/.." || exit 1
PERMESOS=eines/secrets-permesos.txt
A='23456789ABCDEFGHJKMNPQRSTUVWXYZ'
PATRONS="(^|[^A-Z0-9-])[$A]{3}-[$A]{3}-[$A]{4}([^A-Z0-9-]|$)
[#&]c=[$A]{10}([^A-Z0-9]|$)
\\\$2[aby]\\\$[0-9]{2}\\\$
\\\$argon2
BEGIN [A-Z ]*PRIVATE KEY
(ghp_|gho_|github_pat_)[A-Za-z0-9_]{20,}
AKIA[0-9A-Z]{16}
sk-[A-Za-z0-9_-]{20,}
['\"](pebre|llavor|hash_admin|hash_lectura|clau|contrasenya)['\"][[:space:]]*=>[[:space:]]*['\"][^'\"]{6,}
[0-9a-fA-F]{40,}"

mode=${1:-}
case "$mode" in
  --tot) llista=$(git ls-files) ;;
  --fitxer) llista=$2 ;;
  *) llista=$(git diff --cached --name-only --diff-filter=ACMR) ;;
esac

error=0
avisa() { echo "✗ $1" >&2; error=1; }
contingut() { if [ -z "$mode" ]; then git show ":$1"; else cat "$1"; fi; }

for f in $llista; do
  case "$f" in
    */config.php|config.php) avisa "$f: config.php no es pot pujar mai" ;;
    dades/*|*/dades/*) avisa "$f: la carpeta dades/ no es pot pujar" ;;
    *.sqlite|*.sqlite3|*.db|*.sql|*.csv|*.bak|.env|*.pem|*.key) avisa "$f: tipus de fitxer prohibit (BD, CSV o claus)" ;;
    public_html/snapshot.json) avisa "$f: el snapshot real no es puja" ;;
  esac
  case "$f" in *.zip|*.png|*.jpg|*.svg) continue ;; esac   # binaris i art (els SVG passen per la llista blanca de la conversió)
  case "$f" in eines/comprova-secrets.sh|"$PERMESOS") continue ;; esac   # contenen els patrons mateixos
  [ "$mode" = "" ] || [ -f "$f" ] || continue
  trobats=$(contingut "$f" | grep -nE "$PATRONS" 2>/dev/null | grep -vF -f "$PERMESOS" | grep -v 'CANVIA-AIXO' | head -3)
  [ -n "$trobats" ] && avisa "$f: possible secret o codi real:
$trobats"
done

if [ $error -ne 0 ]; then
  echo "" >&2
  echo "Commit aturat. Si és un secret real que ja s'ha pujat, CANVIA'L (vegeu README)." >&2
  exit 1
fi
exit 0
