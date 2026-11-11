# Pessebre col·laboratiu de l'escola

Pessebre digital on cada classe, el claustre i les famílies hi afegeixen una peça. Mirar és obert; participar demana un codi.
Tot el que està decidit és a [CLAUDE.md](CLAUDE.md). Estat: **Fase C** (famílies amb codi i etiquetes QR, i enllaç del claustre).

## Provar-ho (cal PHP 7.4–8.3)

```
./prova                     # totes les proves automàtiques
sh eines/servidor.sh        # servidor de proves a http://localhost:8026
```

Exemples al visor: `#s=inici`, `#s=classes`, `#s=posta`, `#s=nit`, `#s=neu`. Mode participar: `#c=PROVA` (famílies) o `#k=PROVA` (claustre).
En macOS, si no hi ha `php` al PATH, s'usa el de MAMP o XAMPP (o indica'l amb `PHP=/camí/a/php`).

Cases dels tutors en local (Fase B):

```
php eines/gestio.php installa                          # crea config.php de proves i la base de dades
php eines/gestio.php classes eines/classes-exemple.txt # classes inventades
php eines/gestio.php enllac primaria                   # mostra l'enllaç classes.html#e=… (un sol cop)
```

Famílies i claustre (Fase C): `php eines/gestio.php codis 30` crea un lot de codis i el full d'etiquetes amb QR (a `pessebre/dades/`, esborra'l després d'imprimir); `enllac claustre` dona l'enllaç `#k=…`. Per provar-ho abans del 21 de desembre: `calendari families_des "2026-11-11 00:00"` (i `claustre_des`, `claustre_tanca`).

Altres ordres: `allibera C014`, `tanca "2026-12-21 23:59"`, `aturada si|no`, `ronda 1|2`, `estat`. Proves amb MySQL: defineix `PESSEBRE_MYSQL_DSN`, `PESSEBRE_MYSQL_USUARI` i `PESSEBRE_MYSQL_CLAU`.

## Instal·lació (hosting, a partir de la Fase B)

1. Puja `public_html/` a l'arrel web i `pessebre/` (i `eines/`) fora de l'arrel web. Si `pessebre/` és en un altre lloc, canvia la línia `$PESSEBRE` d'`api.php`.
2. Copia `pessebre/config.exemple.php` a `pessebre/config.php` i canvia-hi tots els `CANVIA-AIXO` i les rutes.
3. `php eines/gestio.php installa`, després `classes classes.csv` (llista real, no es puja mai) i `enllac totes`.
4. Cal PHP 7.4 o superior amb PDO SQLite (o MySQL) i HTTPS.

## Estructura

- `disseny-origen/` paquet de disseny v2 (no s'edita) → `php eines/converteix.php` → `public_html/disseny/`
- `public_html/` visor (`index.html`, `app/`), disseny convertit, exemples · `pessebre/` servidor (a partir de la Fase B)
- `eines/` conversió, dades d'exemple, servidor de proves, comprovador de secrets · `proves/` proves automàtiques

## Repositori públic: secrets

Aquest repositori és **públic**. Mai s'hi posa `config.php`, la base de dades, codis, la llista real de classes, contrasenyes, el pebre ni la llavor.
Activa el comprovador abans del primer commit: `git config core.hooksPath .githooks`.
**Si un secret s'ha pujat per error, canvia'l immediatament** (pebre, llavor, contrasenyes, enllaços): esborrar-lo en un commit posterior no n'hi ha prou, perquè queda a l'historial.
