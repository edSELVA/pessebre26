# Instal·lar el pessebre a l'hosting

**Cal:** PHP 8.2 o 8.3 (funciona des de 7.4, però 7.4 ja no té actualitzacions de seguretat) amb PDO SQLite (o MySQL/MariaDB), **HTTPS** i Apache (amb nginx, vegeu el final). Res més: ni Composer ni Node.

**Abans de res:** puja `diagnosi-hosting.php` a l'arrel web del subdomini, obre'l, fes una captura i esborra'l. Si el disc és de xarxa o el bloqueig de fitxers no funciona, tria **MySQL**. Si la IP que surt no és la teva IP pública, **no l'obris** fins que s'hagi resolt (el límit d'intents bloquejaria tothom).

**Calendari recomanat:** fes primer la instal·lació en un **subdomini de prova** (o al definitiu, abans d'anunciar-lo) i deixa-ho tot **provat abans del 14 de desembre**, quan comencen les tutories. Si a la prova surt alguna cosa, es corregeix i es prepara un paquet nou.

## 1. Preparar el paquet (a l'ordinador)
1. Si vols el logo al cel: `php eines/logo.php` (el PNG és a `disseny-propi/logo/`).
2. `php eines/paquet.php` → crea `paquet/pessebre-AAAAMMDD-HHMM/` (i un `.zip`). **Apunta la clau d'instal·lació** que escriu (també és a `pessebre/clau-installacio.txt` dins del paquet).

## 2. Pujar-lo
- El contingut de `public_html/` va a l'**arrel web** del subdomini (on hi ha d'haver `index.html`).
- `pessebre/` i `eines/` van **al costat**, **fora de l'arrel web** (millor). A IONOS: crea una carpeta (p. ex. `pessebre-web/`), puja-hi `public_html/`, `pessebre/` i `eines/`, i al panell apunta el subdomini a `pessebre-web/public_html`. Si l'hosting no ho permet, posa `pessebre/` dins de l'arrel web: el seu `.htaccess` la bloqueja.
- PHP ha de poder escriure a `pessebre/` (hi crea `config.php` i `dades/`) i a l'arrel web (hi escriu `snapshot.json`).

## 3. Instal·lar (sense terminal)
1. Obre `https://ADREÇA/installa.php` i escriu la clau.
2. Revisa les comprovacions (✗ = cal resoldre-ho; ! = avís) i omple: **l'adreça definitiva** (va dins dels QR), SQLite (recomanat) o MySQL, i **el teu usuari i contrasenya** d'administració.
3. **Esborra `installa.php`** de l'hosting (la clau ja s'esborra sola). L'instal·lador ja no es pot tornar a executar si hi ha `config.php` (tornar a instal·lar canviaria el pebre i **invalidaria tots els codis impresos**). Per actualitzar l'app, puja només els fitxers de codi: **mai** `config.php` ni `dades/`, i no cal la clau.

*Amb terminal (SSH), en lloc del pas 3:* copia `pessebre/config.exemple.php` a `pessebre/config.php`, canvia-hi els `CANVIA-AIXO` (valors aleatoris: `php -r 'echo bin2hex(random_bytes(32)), "\n";'`), les rutes i `url_base`, i fes `php eines/gestio.php installa` i `php eines/gestio.php usuari NOM admin`. Esborra `installa.php` i `pessebre/clau-installacio.txt`.

## 4. HTTPS i privacitat (abans d'obrir-lo)
1. **HTTPS:** quan el subdomini tingui certificat, a `public_html/.htaccess` treu els `#` de les **tres línies del final** (redirigeixen sempre a https) i de la línia `Strict-Transport-Security`. Comprova que `http://ADREÇA` porta sol a `https://ADREÇA`.
2. **Privacitat:** a `public_html/privacitat.html`, posa-hi el **correu de contacte** i el text revisat per la **DPD** (i treu l'avís d'esborrany). No l'obris a les famílies fins que la DPD hi hagi donat el vistiplau.

## 5. Posar-lo en marxa (a `admin.html`)
1. **Configuració**: revisa les dates (classes, torronada, famílies). Per defecte la torronada és el **21 de desembre de 00:00 a 22:00**: posa-hi el dia i l'hora reals. **Figures per codi**: el pessebre té **874 llocs per a famílies** (+ els 50 del claustre que quedin lliures després de la torronada). Calcula *famílies que participaran × figures per codi* i que no passi d'uns **800**; si no, posa 2 o 1 figures per codi **abans d'imprimir** (l'etiqueta diu el número). **Fites**: revisa les dates i l'**objectiu** (amb 3 figures per codi, 600 s'assoleix en un o dos dies).
2. **Configuració → Classes**: enganxa el contingut de `classes.csv`.
3. **Comptes**: crea els de l'equip (administració o només lectura).
4. **Claustre i tutors**: un **codi per etapa** per als tutors (o un de sol per a llar + infantil) i l'**enllaç de la torronada** amb el QR.
5. **Codis**: els lots de codis de les famílies i les etiquetes (A4 de 24). Imprimeix-los **només quan l'adreça sigui la definitiva** (va dins dels QR).

## 6. Prova completa (amb un mòbil i l'ordinador)
1. **Mirar:** obre l'adreça al mòbil; el pessebre es veu, es mou i fa zoom. Prova el botó «Cerca el caganer».
2. **Tutor:** escriu un codi d'etapa a «Participar» → tria una classe → tria una casa → pinta-la → confirma. Surt amb el nom a la placa.
3. **Torronada:** obre l'enllaç (o el QR) del claustre → posa un parell de figures, també fora de la zona dels pastors. *(Només funciona en l'horari de la torronada: per provar-ho abans, canvia temporalment la data a Configuració i torna-la a posar després.)*
4. **Família:** escaneja una etiqueta (o escriu el codi a «Participar») → posa una figura → «Posar-ne una altra» fins a 3 → el codi ja no en deixa posar més. *(Les famílies comencen el 21 de desembre: per provar-ho abans, igual que la torronada.)*
5. **Administració:** comprova a «Estat» i «Registre» que tot hi surt, i **allibera** les cases i figures de prova (i reactiva el codi de prova o fes-ne servir un de llençar).
6. **Seguretat:** `http://` porta a `https://`. Si `pessebre/` és dins de l'arrel web, `https://ADREÇA/pessebre/config.exemple.php` i `https://ADREÇA/pessebre/dades/` han de donar **error 403** (una pàgina en blanc NO és bona senyal: vol dir que el `.htaccess` no s'aplica i la base de dades es podria descarregar).

## Seguretat i manteniment
- Còpia de seguretat **cada dia** del 14 de desembre al 6 de gener: el fitxer `pessebre/dades/pessebre-….sqlite` (per SFTP) o la base de dades MySQL (exportació des del panell). **Mai** al repositori ni en un lloc públic.
- Quan s'obri l'última zona (Z3), a **Fites → Retirar totes les automàtiques**: així les famílies que arribin tard troben lloc.
- Si una contrasenya o un codi s'ha filtrat: canvia'l des de l'administració (comptes, codis d'etapa, enllaç de la torronada).
- Després de Reis: **Fites → Tancar i esborrar**.

## nginx (si no és Apache)
Els `.htaccess` no hi funcionen. Cal, dins del `server { … }`: bloquejar `pessebre/` si és dins de l'arrel web (`location ^~ /pessebre/ { deny all; }`), els fitxers ocults (`location ~ /\. { deny all; }`), afegir les capçaleres de `public_html/.htaccess` (`add_header Content-Security-Policy "default-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'" always;` i les altres), `Cache-Control: no-cache` per a `snapshot.json` i la redirecció de http a https.
