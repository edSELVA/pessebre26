# Pessebre col·laboratiu de l'escola

Pessebre digital on cada classe, el claustre i les famílies hi afegeixen una peça. Mirar és obert; participar demana un codi.
Tot el que està decidit és a [CLAUDE.md](CLAUDE.md). Estat: **Fase A** (visor públic amb dades d'exemple).

## Provar-ho (cal PHP 7.4–8.3)

```
./prova                     # totes les proves automàtiques
sh eines/servidor.sh        # servidor de proves a http://localhost:8026
```

Exemples al visor: `#s=inici`, `#s=classes`, `#s=posta`, `#s=nit`, `#s=neu`. Mode participar: `#c=PROVA` (famílies) o `#k=PROVA` (claustre).
En macOS, si no hi ha `php` al PATH, s'usa el de MAMP o XAMPP (o indica'l amb `PHP=/camí/a/php`).

## Instal·lació (hosting, a partir de la Fase B)

1. Puja `public_html/` a l'arrel web i `pessebre/` fora de l'arrel web (o protegeix-la amb `.htaccess`).
2. Copia `pessebre/config.exemple.php` a `pessebre/config.php` i canvia-hi tots els `CANVIA-AIXO`.
3. Comprova que l'hosting té PHP 7.4 o superior amb PDO SQLite (o MySQL) i HTTPS.

## Estructura

- `disseny-origen/` paquet de disseny v2 (no s'edita) → `php eines/converteix.php` → `public_html/disseny/`
- `public_html/` visor (`index.html`, `app/`), disseny convertit, exemples · `pessebre/` servidor (a partir de la Fase B)
- `eines/` conversió, dades d'exemple, servidor de proves, comprovador de secrets · `proves/` proves automàtiques

## Repositori públic: secrets

Aquest repositori és **públic**. Mai s'hi posa `config.php`, la base de dades, codis, la llista real de classes, contrasenyes, el pebre ni la llavor.
Activa el comprovador abans del primer commit: `git config core.hooksPath .githooks`.
**Si un secret s'ha pujat per error, canvia'l immediatament** (pebre, llavor, contrasenyes, enllaços): esborrar-lo en un commit posterior no n'hi ha prou, perquè queda a l'historial.
