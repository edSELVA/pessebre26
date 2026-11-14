# Pessebre col·laboratiu de l'escola

Pessebre digital on cada classe, el claustre i les famílies hi afegeixen una peça. Mirar és obert; participar demana un codi.
Tot el que està decidit és a [CLAUDE.md](CLAUDE.md). Estat: **Fase E** feta (fites, moments del dia, participació automàtica, Reis i tancament). Falta instal·lar-ho a l'hosting.

## Provar-ho (cal PHP 7.4–8.3)

```
./prova                     # totes les proves automàtiques
sh eines/servidor.sh        # servidor de proves a http://localhost:8026
```

Exemples al visor: `#s=inici`, `#s=classes`, `#s=posta`, `#s=nit`, `#s=neu`. Mode participar: `#c=PROVA` (famílies) o `#k=PROVA` (claustre).
En macOS, si no hi ha `php` al PATH, s'usa el de MAMP o XAMPP (o indica'l amb `PHP=/camí/a/php`).

En local:

```
php eines/gestio.php installa                          # crea config.php de proves i la base de dades
php eines/gestio.php usuari eduard admin               # primer compte d'administració (demana la contrasenya)
php eines/gestio.php classes eines/classes-exemple.txt # classes inventades (la llista real: classes.csv, mai al repositori)
```

Després, tot es fa des d'`admin.html` (usuari i contrasenya): codis de classe per als tutors i enllaç amb QR del claustre («Claustre i classes»), codis de família amb etiquetes («Codis»), comptes de l'equip («Comptes»), configuració, fites i ritme. Tothom participa des del mateix enllaç amb el seu codi (botó «Participar» o QR). Per terminal també: `codis-classe pendents`, `enllac claustre`, `codis 30`, `usos N`, `calendari CLAU "AAAA-MM-DD hh:mm"`, `allibera C014`, `aturada si|no`, `estat`. Proves amb MySQL: defineix `PESSEBRE_MYSQL_DSN`, `PESSEBRE_MYSQL_USUARI` i `PESSEBRE_MYSQL_CLAU`.

Logo de l'escola al cel (no es puja mai al repositori): posa el PNG a `disseny-propi/logo/` i `php eines/logo.php` (genera `public_html/disseny/logo.svg`).

## Demostració (per ensenyar-lo)

```
sh eines/demo.sh
```

Obre `http://localhost:8027/demo` (o, des del mòbil a la mateixa wifi, l'adreça que escriu). Pestanya **DEMO** a l'esquerra: fase (inici, tutories, torronada, famílies, fites per data, tancat), «Veure com» (públic, tutor, claustre, família, administració; usuari `demo`, contrasenya `demo-pessebre`), deixar passar el temps i reiniciar (fase, famílies o tot). Té la seva pròpia base de dades a `demo/dades/` (no es puja mai). **La carpeta `demo/` no va mai a l'hosting.**

Logo de l'escola al cel (no es puja mai al repositori): posa el PNG a `disseny-propi/logo/` i `php eines/logo.php` (genera `public_html/disseny/logo.svg`).

## Instal·lació (hosting, a partir de la Fase B)

1. Puja `public_html/` a l'arrel web i `pessebre/` (i `eines/`) fora de l'arrel web. Si `pessebre/` és en un altre lloc, canvia la línia `$PESSEBRE` d'`api.php`.
2. Copia `pessebre/config.exemple.php` a `pessebre/config.php` i canvia-hi tots els `CANVIA-AIXO` i les rutes.
3. `php eines/gestio.php installa` i `usuari NOM admin`. La resta (classes, codis, comptes de l'equip) es fa des d'`admin.html`.
4. Cal PHP 7.4 o superior amb PDO SQLite (o MySQL) i HTTPS.

## Estructura

- `disseny-origen/` paquet de disseny v2 (no s'edita) → `php eines/converteix.php` → `public_html/disseny/`
- `public_html/` visor (`index.html`, `app/`), disseny convertit, exemples · `pessebre/` servidor (a partir de la Fase B)
- `eines/` conversió, dades d'exemple, servidor de proves, comprovador de secrets · `proves/` proves automàtiques
- `demo/` mode demostració (només en local, mai a l'hosting)

## Repositori públic: secrets

Aquest repositori és **públic**. Mai s'hi posa `config.php`, la base de dades, codis, la llista real de classes, contrasenyes, el pebre ni la llavor.
Activa el comprovador abans del primer commit: `git config core.hooksPath .githooks`.
**Si un secret s'ha pujat per error, canvia'l immediatament** (pebre, llavor, contrasenyes, enllaços): esborrar-lo en un commit posterior no n'hi ha prou, perquè queda a l'historial.
