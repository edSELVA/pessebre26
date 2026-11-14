#!/bin/sh
# Prova el paquet en un Apache de veritat (el de MAMP, amb php-cgi), com a l'hosting. NOMÉS per desenvolupar (macOS + MAMP).
#   sh eines/prova-apache.sh
# Fa un paquet nou i el munta dues vegades: A) pessebre/ fora de l'arrel web, B) pessebre/ dins de l'arrel web.
# A cada una: instal·la amb installa.php i comprova capçaleres, bloqueigs (.htaccess), l'API i l'administració.
cd "$(dirname "$0")/.." || exit 1
HTTPD=/Applications/MAMP/Library/bin/httpd
PHPCGI=$(ls -d /Applications/MAMP/bin/php/php8*/bin/php-cgi | tail -1)
PHP=$(sh eines/php.sh) || exit 1
[ -x "$HTTPD" ] && [ -x "$PHPCGI" ] || { echo "Cal MAMP (Apache i php-cgi)."; exit 1; }
TMP=$(mktemp -d /tmp/pessebre-apache.XXXXXX)
OK=0; KO=0
comprova() { if [ "$1" = "$2" ]; then OK=$((OK+1)); else KO=$((KO+1)); echo "  ✗ $3 (esperat $1, real $2)"; fi; }
conte() { if printf '%s' "$1" | grep -q "$2"; then OK=$((OK+1)); else KO=$((KO+1)); echo "  ✗ $3"; fi; }

SORTIDA=$("$PHP" eines/paquet.php) || { echo "$SORTIDA"; exit 1; }
NOM=$(printf '%s\n' "$SORTIDA" | sed -n 's/^Paquet a punt: paquet\/\([^ ]*\) .*/\1/p')
CLAU=$(printf '%s\n' "$SORTIDA" | grep -E '^ +[0-9a-f]{32}$' | tr -d ' ')

prova() {   # $1 = A o B, $2 = port
  DIS=$1; PORT=$2; FCGI=$((PORT + 1000)); D="$TMP/$DIS"
  mkdir -p "$D"; cp -R "paquet/$NOM/" "$D/"
  if [ "$DIS" = B ]; then mv "$D/pessebre" "$D/public_html/pessebre"; fi
  cat > "$D/httpd.conf" <<EOF
ServerRoot "/Applications/MAMP/Library"
Listen 127.0.0.1:$PORT
ServerName localhost
PidFile "$D/httpd.pid"
ErrorLog "$D/error.log"
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule access_compat_module modules/mod_access_compat.so
LoadModule mime_module modules/mod_mime.so
LoadModule dir_module modules/mod_dir.so
LoadModule headers_module modules/mod_headers.so
LoadModule filter_module modules/mod_filter.so
LoadModule deflate_module modules/mod_deflate.so
LoadModule proxy_module modules/mod_proxy.so
LoadModule proxy_fcgi_module modules/mod_proxy_fcgi.so
LoadModule unixd_module modules/mod_unixd.so
TypesConfig "/Applications/MAMP/conf/apache/mime.types"
DocumentRoot "$D/public_html"
DirectoryIndex index.html
<Directory "$D/public_html">
  AllowOverride All
  Require all granted
</Directory>
<FilesMatch "\.php$">
  SetHandler "proxy:fcgi://127.0.0.1:$FCGI"
</FilesMatch>
ProxyFCGIBackendType GENERIC
EOF
  PHP_FCGI_CHILDREN=2 "$PHPCGI" -b 127.0.0.1:$FCGI >/dev/null 2>&1 &
  echo $! > "$D/fcgi.pid"
  "$HTTPD" -f "$D/httpd.conf" -k start || { echo "Apache no arrenca"; cat "$D/error.log"; return; }
  sleep 1
  U="http://127.0.0.1:$PORT"
  echo "$DIS) pessebre/ $( [ "$DIS" = A ] && echo 'fora' || echo 'dins') de l'arrel web"

  # Instal·lació
  comprova 200 "$(curl -s -o /dev/null -w '%{http_code}' $U/installa.php)" "$DIS: installa.php demana la clau"
  conte "$(curl -s -d clau=dolenta $U/installa.php)" "no és correcta" "$DIS: una clau dolenta no entra"
  conte "$(curl -s -d clau=$CLAU $U/installa.php)" "Comprovacions" "$DIS: amb la clau surten les comprovacions"
  R=$(curl -s --data-urlencode clau=$CLAU -d installa=1 --data-urlencode url_base=$U/ -d bd=sqlite -d usuari=eduard \
       --data-urlencode contrasenya=prova-apache-1234 --data-urlencode contrasenya2=prova-apache-1234 $U/installa.php)
  conte "$R" "Pessebre instal·lat" "$DIS: s'instal·la"
  comprova 403 "$(curl -s -o /dev/null -w '%{http_code}' $U/installa.php)" "$DIS: després, installa.php ja no fa res"
  P=$( [ "$DIS" = A ] && echo "$D/pessebre" || echo "$D/public_html/pessebre" )
  comprova "no" "$( [ -f "$P/clau-installacio.txt" ] && echo si || echo no )" "$DIS: la clau d'instal·lació s'ha esborrat"
  comprova "-rw-------" "$(ls -l "$P/config.php" | cut -c1-10)" "$DIS: config.php només el pot llegir el propietari"

  # Capçaleres i memòria cau
  H=$(curl -s -D - -o /dev/null $U/)
  conte "$H" "Content-Security-Policy: default-src 'self'" "$DIS: CSP"
  conte "$H" "X-Content-Type-Options: nosniff" "$DIS: nosniff"
  conte "$H" "Referrer-Policy: no-referrer" "$DIS: Referrer-Policy"
  conte "$(curl -s -D - -o /dev/null $U/snapshot.json)" "Cache-Control: no-cache" "$DIS: snapshot.json sense memòria cau"
  conte "$(curl -s -o /dev/null -w '%{http_code}' $U/app/)" "40[34]" "$DIS: no es llisten carpetes (403 o 404, mai la llista)"
  comprova 403 "$(curl -s -o /dev/null -w '%{http_code}' $U/.htaccess)" "$DIS: .htaccess no es pot llegir"
  if [ "$DIS" = B ]; then
    for f in pessebre/config.php pessebre/config.exemple.php pessebre/dades/pessebre.sqlite pessebre/src/app.php; do
      comprova 403 "$(curl -s -o /dev/null -w '%{http_code}' $U/$f)" "B: $f bloquejat"
    done
  fi

  # API i administració
  conte "$(curl -s -H 'Content-Type: application/json' -d '{"a":"tick"}' $U/api.php)" '"ok":true' "$DIS: l'API respon"
  comprova 405 "$(curl -s -o /dev/null -w '%{http_code}' $U/api.php)" "$DIS: l'API només accepta POST"
  conte "$(curl -s -H 'Content-Type: application/json' -d '{"a":"admin.estat","usuari":"eduard","contrasenya":"prova-apache-1234"}' $U/api.php)" '"rol":"admin"' "$DIS: el primer compte entra a l'administració"
  conte "$(curl -s -H 'Content-Type: application/json' -d '{"a":"res"' $U/api.php)" '"codi":"json"' "$DIS: JSON trencat → error net, sense rutes"
  comprova 200 "$(curl -s -o /dev/null -w '%{http_code}' $U/snapshot.json)" "$DIS: snapshot.json creat"

  "$HTTPD" -f "$D/httpd.conf" -k stop; kill "$(cat "$D/fcgi.pid")" 2>/dev/null; sleep 1
}

prova A 8041
prova B 8042
rm -rf "$TMP" "paquet/$NOM" "paquet/$NOM.zip"
echo "Apache: $OK bé, $KO malament"
[ "$KO" -eq 0 ]
