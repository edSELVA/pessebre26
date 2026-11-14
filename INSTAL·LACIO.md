# Instal·lar el pessebre a l'hosting

**Cal:** PHP 7.4 o més nou amb PDO SQLite (o MySQL/MariaDB), **HTTPS** i Apache (amb nginx, vegeu el final). Res més: ni Composer ni Node.

## 1. Preparar el paquet (a l'ordinador)
1. Si vols el logo al cel: `php eines/logo.php` (el PNG és a `disseny-propi/logo/`).
2. `php eines/paquet.php` → crea `paquet/pessebre-AAAAMMDD-HHMM/` (i un `.zip`). **Apunta la clau d'instal·lació** que escriu.

## 2. Pujar-lo
- El contingut de `public_html/` va a l'**arrel web** del subdomini (on hi ha d'haver `index.html`).
- `pessebre/` i `eines/` van **al costat**, **fora de l'arrel web** (millor). Si l'hosting no ho permet, posa `pessebre/` dins de l'arrel web: el seu `.htaccess` la bloqueja.
- PHP ha de poder escriure a `pessebre/` (hi crea `config.php` i `dades/`) i a l'arrel web (hi escriu `snapshot.json`).

## 3. Instal·lar (sense terminal)
1. Obre `https://ADREÇA/installa.php` i escriu la clau.
2. Revisa les comprovacions (✗ = cal resoldre-ho; ! = avís) i omple: **l'adreça definitiva** (va dins dels QR), SQLite (recomanat) o MySQL, i **el teu usuari i contrasenya** d'administració.
3. **Esborra `installa.php`** de l'hosting (la clau ja s'esborra sola).

*Amb terminal (SSH), en lloc del pas 3:* copia `pessebre/config.exemple.php` a `pessebre/config.php`, canvia-hi els `CANVIA-AIXO` (valors aleatoris: `php -r 'echo bin2hex(random_bytes(32)), "\n";'`), les rutes i `url_base`, i fes `php eines/gestio.php installa` i `php eines/gestio.php usuari NOM admin`. Esborra `installa.php` i `pessebre/clau-installacio.txt`.

## 4. Posar-lo en marxa (a `admin.html`)
1. **Configuració**: revisa les dates (classes, torronada, famílies) i les figures per codi (3).
2. **Configuració → Classes**: enganxa el contingut de `classes.csv`.
3. **Comptes**: crea els de l'equip (administració o només lectura).
4. **Claustre i tutors**: un **codi per etapa** per als tutors (o un de sol per a llar + infantil) i l'**enllaç de la torronada** amb el QR.
5. **Codis**: els lots de codis de les famílies i les etiquetes (A4 de 24).
6. Prova-ho amb un mòbil: mirar, un codi d'etapa i un codi de família.

## Seguretat i manteniment
- **HTTPS obligatori.** Quan funcioni, activa la línia `Strict-Transport-Security` de `public_html/.htaccess`.
- Comprova que **no** es pot obrir `https://ADREÇA/pessebre/config.exemple.php` (si `pessebre/` és dins de l'arrel web).
- Còpia de seguretat: el fitxer `pessebre/dades/pessebre.sqlite` (o la base de dades MySQL). **Mai** al repositori.
- Si una contrasenya o un codi s'ha filtrat: canvia'l des de l'administració (comptes, codis d'etapa, enllaç de la torronada).
- Després de Reis: **Fites → Tancar i esborrar**.

## nginx (si no és Apache)
Els `.htaccess` no hi funcionen. Cal, dins del `server { … }`: bloquejar `pessebre/` si és dins de l'arrel web (`location ^~ /pessebre/ { deny all; }`), els fitxers ocults (`location ~ /\. { deny all; }`), afegir les capçaleres de `public_html/.htaccess` (`add_header Content-Security-Policy "default-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'" always;` i les altres) i `Cache-Control: no-cache` per a `snapshot.json`.
