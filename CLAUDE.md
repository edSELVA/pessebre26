# Pessebre de l'escola · Especificació per a Claude Code

> Versió consolidada de l'11 nov 2026. Substitueix el document «Decisions aplicació pessebre (9 nov 2026)» allà on hi hagi diferències. El disseny de referència és el **paquet v2** (la v1 és obsoleta i no s'ha d'usar).
> Marques: **(decidit)** = decidit per l'Eduard; **(proposta)** = proposta nostra, es pot canviar; **(pendent)** = obert.

## 0. Com treballem
- Idioma: interfície, comentaris i missatges de commit en **català**. Els noms de variables poden seguir el català del paquet de disseny (ancoratge, llocs, ocupacions…).
- **Fes-ho per fases i no passis a la següent fins que l'Eduard digui «endavant».** Al final de cada fase: què has fet, com es prova, què queda obert.
- Abans d'escriure codi en una fase, repassa aquest document i diu-me les discrepàncies o dubtes. No replantegis allò marcat **(decidit)**.
- Els usuaris no són tècnics. L'Eduard i un altre professor mantindran l'aplicació: el codi ha de ser **petit, llegible i sense màgia**.
- Cada fase s'entrega amb proves automàtiques (PHP) que es poden executar amb una sola ordre, i amb instruccions d'instal·lació de 10 línies com a molt.

## 1. Restriccions tècniques (decidit)
- **PHP estàndard** (compatible 7.4–8.3), **PDO** amb **SQLite o MySQL/MariaDB** triats a `config.php`. Prefereix SQLite. L'hosting definitiu encara no es coneix: no et lliguis a res que no sigui PHP estàndard + PDO.
- **Sense dependències externes** (ni Composer, ni npm en producció), **sense cookies, sense localStorage, sense analítica, sense recursos externs** (ni tipografies ni CDN). Capçalera `Content-Security-Policy: default-src 'self'`.
- **El mapa públic és un fitxer estàtic `snapshot.json`** que es regenera en cada canvi; el servidor només valida codis/enllaços i reserva llocs. Les lectures no toquen la base de dades.
- **No es desa cap dada personal.** Els codis no es vinculen a cap alumne ni classe. No es desa la IP: només una empremta temporal per al límit d'intents (vegeu §7).
- Mobile first. El visor ha de funcionar bé amb zoom i desplaçament tàctils en un mòbil mitjà.
- **Repositori PÚBLIC** (vegeu §9): cap secret, cap dada real.

## 2. Escena i disseny (paquet v2)
El paquet es puja al repositori com a `disseny-origen/pessebre-disseny-v2.zip` (no s'edita). Una eina a `eines/` el converteix a `public_html/disseny/`. Resum de la guia tècnica v2:
- Escena **2400 × 800**, 1 unitat = 1 píxel d'art, `shape-rendering="crispEdges"`. Cada peça té `viewBox 0 0 w h` i un **ancoratge** (centre de la base). Es col·loca amb `translate(x − ax, y − ay)`; mirall horitzontal `matrix(-1 0 0 1 2x 0)`. Escala de les figures **uniforme**, mai s'escalen.
- Ordre de dibuix per **y creixent**. `mapa.json` dona x, y, tipus i zona de cada lloc: 74 cases de classe + 906 participacions (96 cases, 270 elements, 481 persones, 59 animals) + 18 pastors.
- Pila de capes (de darrere cap endavant): `terreny.svg` → `llum-terra.svg` (+ `LLUM-TOLL` de cada casa ocupada, a (x, y+5)) → peces ordenades per y → `llum-aire.svg` (+ `LLUM-HALO` dels fanals, a (x, y−22)). Totes 2400×800 a (0, 0). Si s'incrusten, copiar els `<defs>` de degradats una sola vegada.
- Colors per variables CSS (4 tons per rol: `-clar`, base, `-ombra`, `-fosc`; roba i porta, 3). Personalitzables: cases `facana`, `teulada`, `porta`; adults `roba`, `mocador`; infants `roba2`. Opcions a `paletes.json` (índex d'opció → llista de tons). **El servidor i el snapshot només desen índexs de paleta**, mai colors en cru.
- **Estats d'un lloc:** *tancat* (zona no oberta: no es dibuixa), *lliure* (silueta fantasma: cases opacitat .62, elements .5, amb `--rol` clar), *ocupat* (peça amb colors triats). Les marques a terra `MARCA-PER` (persona) i `MARCA-ANI` (animal) **només es mostren en mode participar**.
- **Zones:** Z0 x 780–1820 · Z1 540–780 i 1820–2020 · Z2 270–540 i 2020–2210 · Z3 0–270 i 2210–2400. Les 74 cases de classe són a Z0 (casc antic). Z0 s'obre des del principi; Z1–Z3 s'obren **automàticament** quan la zona oberta arriba al 75–80 % d'ocupació **real** (les participacions automàtiques no hi compten). L'administració pot forçar-les o endarrerir-les.
- **Moments del dia:** quatre (`dia`, `posta`, `nit`, `neu`) amb `moments/<nom>.css` a l'arrel de l'escena. Només canvien colors i opacitats. A `neu`, l'app ha de nevar les teulades de les cases (barrejar `--teulada*` amb `#F2F7FF` al 82 %, `--teulada-fosc` al 60 %). Mentre una família o una classe personalitza una peça, el previsualitzador va amb **llum neutra**.
- Fer servir `escena-fixa.json` (naixement, Reis, molí, pont, castell…) i `registre.json` (caixa, ancoratge, rols). **Cal verificar contra el zip** els camps reals; si la guia i el zip discrepen, mana el zip i m'ho dius.

## 3. Calendari i qui pot fer què (decidit l'11 nov)
Es comença amb l'escena **sense cap figureta, excepte el naixement i l'escena fixa**, i amb totes les cases en **transparència**.

| Quan | Qui | Què |
|---|---|---|
| Fins al 21 des | **Tutors** (1 enllaç per etapa) | Cada classe tria una casa transparent del **casc antic (Z0)**, la personalitza mínimament (façana, teulada, porta) i la **fixa**. Es fa a la tutoria del 14–21 des. |
| 21 des (torronada) | **Claustre** (1 enllaç, sense codis) | Fins a **50 participacions** en total: els 18 llocs de pastor, l'escena de l'anunciació i elements propers (arbres…). L'enllaç és comú; si algú corre a ocupar llocs, no passa res (pot ser una dinàmica divertida). El **21 des a les 22:00 (Europe/Madrid)** es bloqueja el que s'hagi ocupat. |
| Des del 21 des | **Famílies** (1 codi imprès per família, 1 participació per codi) | Qualsevol lloc lliure d'una zona oberta que no sigui d'una casa de classe, de la zona dels pastors ni dels Reis: figures (persona/animal), cases de la perifèria i elements. Això inclou llocs lliures de Z0 que no siguin cases de classe. |
| Sempre | **Tothom** | Un enllaç de visualització obert. Mirar és obert, participar demana codi. El QR de la felicitació (cara frontal) és el de visualització. |
| 21 des → 5 gen | **Automàtic** | Els Reis avancen cada dia (vegeu §6). |
| 6 gen (Reis) | | S'aturen les escriptures, es congela el mapa i s'esborra la taula de codis (vegeu §8). |

Detalls:
- **Cada lloc té un `grup`** que decideix qui el pot reservar: `poble` (74 cases de classe → tutors), `claustre` (18 pastors + ≈32 llocs propers → enllaç del claustre), `general` (famílies), `reis` (només automàtic).
- Un lloc `claustre` que quedi **lliure** després de les 22:00 del 21 des passa a `general` **(proposta, confirmar)**.
- **Cases de classe sense reservar:** durant les tutories es veuen com a transparents. Quan es tanquen les classes (`classes_tanca`, per defecte 21 des 23:59, editable) **desapareixen de la visualització general** (no es dibuixen, ni com a vidre). Només es veuen en transparent les cases de la perifèria que poden triar les famílies. **L'administració pot, durant tot el Nadal, deixar-les així o afegir-ne alguna sense placa** (variant `-SP`) escollint-ne la peça i els colors.
- **Una classe només pot reservar una casa**; en reservar, desapareix de la llista de la seva etapa.
- Etapes (llistes de classes): llar d'infants (si participa), infantil, primària, secundària + batxillerat (junts), cicles. Màxim 5 enllaços. El tutor tria el nom d'una llista, mai escriu res. La placa mostra el nom. **(pendent)** Llista real de classes i confirmar que no passen de 74.
- Risc acceptat: algú podria reservar per error el nom d'una altra classe. És reversible (l'administració allibera la casa). Si un enllaç es filtra, l'administració el regenera.
- **Retenció temporal:** mentre es tria, el lloc queda retingut **10 min** (tutors) o **5 min** (famílies i claustre). En confirmar, es bloqueja; només l'administració el pot alliberar. Una retenció caducada compta com a lloc lliure.
- **(proposta)** Marge de desfer per a les famílies: no fer-lo de moment (només l'administració retira). **(pendent)** Decisió de l'Eduard.

## 4. Fites, moments del dia i participació automàtica
Quatre moments, en aquest ordre i sense tornar mai enrere: **dia → posta → nit → neu**. Tres canvis. La correspondència amb les fites és aquesta **(decidit)**: comença de dia; salta a la tarda (posta), després a la nit i finalment neu. Neu, com a molt tard, **l'1 de gener**.

**Regla de cada canvi:** salta amb la **primera** d'aquestes dues condicions:
1. **Llindar de participació real** assolit, o
2. **Data límit** arribada. Si el llindar ja s'ha assolit abans, la data no fa res (no avança cap altra fase).

Altres regles:
- Una fita assolida **no es desfà mai**. **Mínim d'un dia entre fites** (paràmetre global). Si la participació és molt ràpida, es pot arribar a neu en pocs dies i no passa res.
- **Llindars relatius:** el llindar de la fita N = la participació real quan va saltar la N−1 + un increment (en % d'un «objectiu de participació» editable, p. ex. 600). Es fixa en el moment en què salta l'anterior.
- El canvi de moment a **posta** té una fita pròpia per a les classes (llindar de cases reservades, % de les 74, **o** el 21 des). **(pendent)** Dates i llindars exactes. Valors d'exemple: posta 21 des; nit ≈ 27 des; neu 1 gen.
- Tot és editable per l'administració: llindars, increments, dates, mínim entre fites i objectiu.
- **Comptador públic** discret: «**312** participacions · propera fita a **400**» amb barra de progrés; abans del 21 des mostra «52 de 74 cases de classe». La data límit és **oculta** al públic. **Només compta participacions reals** (famílies, claustre i classes, mai les automàtiques).

**Participació automàtica (decidit el concepte; detalls pendents d'afinar):**
- Quan una fita salta **per data** (no per participació), l'aplicació **afegeix figures, cases i elements** fins a un nombre objectiu de llocs ocupats per a aquella fase, perquè el pessebre no es vegi pobre.
- **No ho omple tot**: sempre queden llocs lliures. Paràmetres (editables), amb valors per defecte **(proposta)**: objectiu d'ocupació total per fase; **màxim d'automàtics = 40 % dels llocs de cada zona oberta** perquè les famílies mantinguin espai; només a **zones ja obertes**; reparteix en diversos passos al llarg dels dies, no tot de cop.
- Tria peça i colors **a l'atzar amb una llavor fixa** (la llavor és a `config.php`, no al repositori), de manera reproduïble, i proporcional a les categories de cada zona.
- Es desen com a ocupacions amb `origen = 'auto'`, **sense codi**, i l'administració les pot retirar una a una o totes.
- Les automàtiques **no obren zones**, **no compten** al comptador ni als llindars.
- Mai ocupen llocs `poble`, `claustre` ni `reis`.

## 5. Administració (mínima, sense moderació de contingut)
No hi ha text lliure: peces, llocs i paletes són predefinits, per tant no cal aprovar res abans de publicar. Accions:
- Alliberar un lloc (i reactivar el codi si cal); retirar una ocupació automàtica o totes.
- **Afegir una casa sense placa al casc antic** (o deixar les no reservades sense omplir); canviar peça/colors d'una ocupació.
- Regenerar l'enllaç d'una etapa o del claustre; canviar el límit del claustre (50) i les dates de tancament.
- Ajustar llindars, increments, dates, mínim entre fites, objectiu; **forçar o endarrerir franges**; activar la **segona ronda** (mateixos codis) si a principis de gener queden places lliures.
- **Botó d'aturada** que bloqueja temporalment noves participacions.
- Veure comptadors per zona, estat de les fites i les retencions actives; cercar per codi o lloc.
- Generar lots de codis (es mostren **una sola vegada**).
- **Registre intern** «codi o classe → lloc → hora» per aclarir qualsevol cas, sense dades personals.
- Administradors: l'Eduard i l'altre professor. **(proposta)** Accés només de lectura per a l'equip de valors i la directora general, si ho volen.
- Corregir un error d'una família: ho demana a porteria o per correu i l'administració retira la participació.

## 6. Reis
- Comencen el **21 des** al lloc inicial de l'esquerra (peça fixa `REI-CAMELL` / reis de l'escena fixa) i **avancen cada dia** pel camí de primer terme, d'esquerra a dreta, fins a arribar **el 5 gen davant del naixement** (dia de la cavalcada), on es queden la resta de dies.
- La posició es **calcula al visor** a partir de la data del servidor (que ve al snapshot, en dia d'Europe/Madrid) i d'una taula de recorregut (`disseny/reis-cami.json`): una polilínia de punts del camí, repartida en 15 passos. **No cal escriure res al servidor.** **(pendent)** Extreure el camí de primer terme del terreny; si no hi és com a dada, proposa'n una polilínia i m'ho ensenyes.
- Cal poder **simular la data** per provar (només a l'entorn de proves, via `config.php`).

## 7. Arquitectura
**Principis:** les lectures són estàtiques; totes les escriptures passen per un sol `api.php` (POST JSON, `{"a": "acció", …}`). Cada escriptura: bloqueig `flock` → transacció → aplicar → regenerar `snapshot.json` (escriure a `.tmp` i `rename()` atòmic) → alliberar. Els temps van en UTC a la BD i es mostren en Europe/Madrid.

```
pessebre/                      (fora de l'arrel web si es pot; si no, bloquejada amb .htaccess)
├─ config.php                  SECRETS (no al repositori): pebre HMAC, hash de contrasenyes, tipus de BD, llavor
├─ config.exemple.php          valors falsos, SÍ al repositori
├─ dades/pessebre.sqlite · dades/escriptura.lock     (no al repositori)
└─ src/  bd.php · llocs.php · reserva.php · codis.php · snapshot.php · fites.php · auto.php · admin.php · limit.php
public_html/
├─ index.html (visor; mode participar amb #c=CODI o #k=ENLLAÇ_CLAUSTRE) · classes.html (#e=ENLLAÇ_ETAPA) · admin.html · privacitat.html
├─ api.php · snapshot.json (generat) · .htaccess
├─ app/      visor.js · compon.js · participa.js · classes.js · admin.js · qr.js · estil.css
└─ disseny/  peces.svg (sprite) · terreny.svg · llum-terra.svg · llum-aire.svg · moments.css · mapa.json · escena-fixa.json · paletes.json · peces.json · reis-cami.json
eines/     conversió del paquet v2, dades de prova, generació d'etiquetes
proves/    proves automàtiques PHP
```

**Taules**
- `llocs` (id del mapa, tipus, zona, `grup`, x, y): es carrega de `mapa.json`.
- `ocupacions` (**lloc_id PK**, estat retingut/confirmat, `origen` = classe | claustre | familia | auto | admin, classe_id **únic**, codi_id, peça, mirall, colors com a índexs, hash de retenció, caduca, hora): la BD mateixa impedeix doble ocupació i dues cases per classe.
- `enllacos` (id, tipus etapa | claustre, etapa, hash, activa) · `classes` (id, etapa, nom de placa, ordre).
- `codis` (id, hash únic, lot, usos): **només codis de família**; no n'hi ha de pastor.
- `registre`, `config` (clau, valor: aturada, ronda, franges, objectiu, mínim entre fites, límit claustre, `classes_tanca`, `claustre_tanca`, `families_des`, data simulada…), `fites` (número, increment, data límit, llindar fixat, hora assolida), `intents` (empremta, finestra, fallades; dura com a molt 15 min).

**Endpoints** (`POST /api.php`)
- Públic: `tick` (si el snapshot té més de 15 min, aplica fites per data, participació automàtica i retencions caducades; si l'hosting té cron, també es pot cridar des d'allà).
- Tutors (testimoni d'etapa): `classes.llista`, `classes.retenir`, `classes.confirmar`, `classes.deixar`.
- Claustre (testimoni del claustre): `claustre.retenir`, `claustre.confirmar`, `claustre.deixar` (fins al límit total i abans de `claustre_tanca`).
- Famílies (codi): `codi.validar` (no canvia res), `codi.retenir`, `codi.confirmar`, `codi.deixar`.
- Administració (contrasenya): les accions del §5 (`admin.estat`, `admin.cercar`, `admin.alliberar`, `admin.casa_sense_placa`, `admin.enllac`, `admin.config`, `admin.aturada`, `admin.generar_codis`, `admin.retirar_auto`, `admin.tancar`…).
- **En cada confirmació el servidor comprova:** que el lloc existeix, és del `grup` que correspon al testimoni, i és d'una zona oberta; que la peça és de la categoria del lloc; que els colors són índexs vàlids de `paletes.json`; que el codi no està gastat (o hi ha segona ronda); que l'aturada no està activa; que no s'ha passat la data de tancament del seu grup.

**Snapshot** (`snapshot.json`, ≈60–80 KB, ≈12 KB comprimit): versió, hora, data del servidor, moment actual, zones obertes, llums, comptador públic (**mai les dates de les fites**), `o` (ocupades: peça, mirall, índexs de colors, nom de placa si n'hi ha) i `r` (llocs retinguts, només l'id). `Cache-Control: no-cache` al `.htaccess`. En mode participar el visor el refresca cada minut aproximadament.

**Protecció**
- **Codis de família:** 10 caràcters d'un alfabet de 31 sense ambigus (`23456789ABCDEFGHJKMNPQRSTUVWXYZ`), 9 aleatoris amb generador criptogràfic + 1 de control; s'imprimeixen com `K7M-QX4-P9TR`. La BD només desa un **HMAC** amb el pebre de `config.php`. El QR porta el codi **després del `#`** (`https://…/#c=K7MQX4P9TR`): el navegador no l'envia mai al servidor, i no queda als registres d'accés. Els errors de transcripció detectats pel caràcter de control no compten com a intents.
- **Límit d'intents:** empremta = HMAC de la IP amb una sal que canvia cada hora (la vella s'esborra). 10 fallades en 15 min → espera. Fre global si hi ha moltes fallades per minut. **No es desa la IP.**
- **Enllaços d'etapa i del claustre:** testimoni aleatori de 128 bits després del `#`, desat com a hash, regenerable.
- **Administració:** dues contrasenyes (admin i només lectura) amb `password_hash`. Sense cookies no hi ha sessió: la pàgina demana la contrasenya a cada càrrega, la guarda només a memòria i l'envia a cada acció. 5 fallades → espera 15 min. HTTPS obligatori. Cada acció queda al registre.

## 8. Privacitat i tancament
- No hi ha cap cookie, anàlisi ni recurs extern. Línia al peu: «Aquest pessebre no fa servir cookies ni demana noms ni dades personals. [Privacitat]». El text complet i el revisa la DPD (esborrany al document de decisions del 9 nov, §5). **(pendent)** enllaç de la política i correu de contacte.
- Els registres del servidor són els del web de l'escola (mateix tractament). L'aplicació no desa IP.
- **Després de Reis (6 gen):** s'aturen les escriptures, es congela l'últim `snapshot.json` com a pàgina estàtica i s'esborren les taules de codis, intents i registre i els vincles codi→lloc.

## 9. Repositori públic: regles dures
- **Mai** al repositori: `config.php`, `dades/`, codis (reals ni de proves amb format real), llista real de classes, llavor, contrasenyes, pebre, còpies de la BD. Posa-ho a `.gitignore` des del **primer commit** i activa un hook de comprovació (`eines/comprova-secrets.sh`) abans de cada commit.
- Dades d'exemple i de proves **sempre inventades**. La llista real de classes es carrega a l'hosting des d'un fitxer local (`classes.csv`, ignorat).
- La seguretat **no pot dependre que el codi sigui secret** (ja és així: HMAC, hashes, `password_hash`).
- Si un secret s'ha pujat per error: **canviar-lo immediatament** (esborrar-lo en un commit posterior no n'hi ha prou). Deixa-ho escrit al `README`.
- No hi posis els logotips de l'escola ni res amb dades de l'alumnat.
- **(pendent)** Llicència (MIT o cap).
- Proves visuals: el visor amb dades d'exemple es pot publicar a GitHub Pages (només estàtic). El flux complet (tutors, famílies, administració) cal PHP: servidor integrat (`php -S`) per desenvolupar i, més endavant, un subdomini de prova a l'hosting de l'escola.

## 10. Fases (no avancis sense «endavant»)
- **Fase A · Visor públic** a partir del paquet v2: terreny, llum, peces, zoom i desplaçament tàctils, els quatre moments, estats lliure/tancat/ocupat, snapshot d'exemple, mode participar amb marques. Sense servidor.
- **Fase B · Reserva de cases per als tutors** (un enllaç per etapa). **Ha d'estar provada abans del 14 de desembre.**
- **Fase C · Participació de les famílies** (codis, etiquetes amb QR, segona ronda) **i enllaç del claustre**.
- **Fase D · Administració mínima.**
- **Fase E · Fites, moments del dia, participació automàtica, Reis i tancament.**

## 11. Pendents (de l'Eduard)
Llista de classes per etapa (≤ 74) · dades de l'hosting (PHP, BD, subdomini, IP) · enllaç de privacitat i correu · decisió de la DPD · dates i llindars de les fites · quants llocs automàtics per fase · marge de desfer per a famílies · format de les etiquetes adhesives i quantitats · llicència.

## 12. Decisions i descobertes de la Fase A (11 nov 2026)
- **Grup del claustre (decidit):** 18 pastors + els **32 llocs participables més propers a la clariana** (persones, animals i elements; cap casa) = 50. Es calculen a `eines/conversio.php` (`CLAUSTRE_PROPERS`). Són a Z1–Z3; el claustre els veu encara que la zona sigui tancada.
- **Pastors (decidit):** paleta de roba pròpia de **tons de terra** (a `disseny-propi/`, Fase C). La peça `PER-PAS-F` la pot triar **tothom** (també les famílies).
- **Reis (decidit):** davant, el **rei blanc** (barba i cabells blancs); al mig, el **ros**; darrere, el **rei negre** (pell fosca, sense barba, amb **turbant**: peça pròpia `ESC-REI-TURBANT`). S'aturen el 5 gen davant del naixement (`eines/reis-cami-font.json`, `final_x`).
- **Transparència dels llocs lliures (decidit):** cases .32, elements .25 (la guia deia .62 i .5).
- **Noms de placa (decidit):** fins a **8 caràcters** (p. ex. «3r ESO B»).
- **Peces i canvis propis:** van a `disseny-propi/` (el zip no s'edita) i passen per la mateixa llista blanca.
- **Discrepàncies del paquet v2** (mana el zip, corregides a la conversió): no hi ha camp `grup`; els pastors porten `zona: Z0` però són a x 263–427; el camí dels Reis només és a `terreny.py`; el rectangle de `placa` del registre és el mateix per a tots els models i no coincideix amb el dibuix (es pren del dibuix); `CASA-BAIXA-P` no porta placa dibuixada (la dibuixa el visor); els 9 atributs `style` de les capes passen a `disseny/escena.css` per la CSP.

## 13. Decisions de la Fase B (11 nov 2026)
- **Gestió sense pàgina d'administració (decidit):** fins a la Fase D, ordres de terminal a `eines/gestio.php` (installa, classes, enllac, allibera, tanca, aturada, estat).
- **Flux del tutor (decidit):** primer tria la classe, després toca una casa transparent del casc antic (retinguda 10 min), tria façana, teulada i porta amb llum neutra i confirma.
- **Etapes (decidit):** 5, amb llar d'infants (`llar`, `infantil`, `primaria`, `secundaria`, `cicles`).
- **Hosting (pendent):** cal demanar-lo a informàtica. Mentrestant es prova en local: proves de reserva superades amb SQLite i amb MySQL 8.0 (MAMP), amb PHP 8.1 i 8.3 (11 nov).
- **Llista de classes:** fitxer `classes.csv` (`etapa;nom`, noms de 8 caràcters com a molt), que no es puja mai. Exemple inventat a `eines/classes-exemple.txt`.

## 14. Decisions de la Fase C (11 nov 2026)
- **Etiquetes:** format pendent; per defecte A4 de 24 (70×37 mm), es canvia a `pessebre/src/etiquetes.php` (`ETIQUETES`). Cada etiqueta: QR (adreça `#c=CODI`), codi amb guions i instruccions curtes.
- **Codis:** caràcter de control amb **suma ponderada mòdul 31** (detecta tots els caràcters mal copiats i tots els intercanvis de veïns; el Luhn mòdul 31 no ho garantia perquè 31 és senar). Errors de format o de control no compten com a intents.
- **Claustre (decidit):** els seus llocs que quedin lliures a les 22:00 del 21 des **passen a les famílies** (si la zona és oberta). Límit de 50 participacions (`claustre_limit`). El claustre veu els seus llocs encara que la zona sigui tancada.
- **Pastors:** paleta de terra a `disseny-propi/paletes.json` (grup `pastors`), també quan una família ocupa un lloc de pastor alliberat.
- **Desfer (decidit):** no; només l'administració retira.
- **Famílies (decidit):** des del **21 des a les 00:00** fins al 6 gen (`families_des`, `families_fins`). Retenció de 5 minuts. Segona ronda: `ronda 2` (dues figures per codi).
- **Zones:** s'obren automàticament (Z0 → Z1 → Z2 → Z3) quan les obertes arriben al **75 %** d'ocupació real (`llindar_zona`; no compten les automàtiques).
- **QR propi** (`pessebre/src/qr.php`, versions 1–9, nivell M) generat al servidor; comprovat amb un lector real. No hi ha `app/qr.js`.
- **Visor públic:** botó «Participar» per escriure el codi si no es pot escanejar el QR.

## 15. Cases de classe més personalitzables (11 nov 2026, decidit)
- A més de façana, teulada i porta: **flors** de les jardineres (paleta `cases.flor` a `disseny-propi/paletes.json`; només als models que en tenen), **girar** la casa i **treure finestres** (hi queda paret).
- Les finestres es detecten automàticament del dibuix (grups de vidres `llum*`); el conversor genera per a cada una una tapa `pc-CODI-F<n>` que continua la paret del costat. Snapshot: `m` (mirall) i `f` (màscara de bits de finestres tretes).
- A la pàgina dels tutors, les finestres es toquen a la previsualització gran (retallada a la part dibuixada).

## 16. Figures de les famílies més personalitzables (11 nov 2026, decidit)
- **Persones:** a més de roba/mocador, **to de pell** (5), **cabells i barba** (5, on es veuen) i **barret i cistell** (5, palla). Les parts que la peça no dibuixa no surten (el pagès amb barret no té mocador).
- **Animals:** **pelatge** del gat, el gos, la cabra, el cabrit, el conill, el porc i l'ase (4–5 opcions). Ovelles, gallines, ànecs i coloms no (ja tenen variants).
- La primera opció de cada paleta és el color original. Paletes a `disseny-propi/paletes.json` (`persones.*`, `animals.*`); quines parts es poden pintar, a `disseny-propi/personalitzables.json`. Si una paleta no és al grup (p. ex. la pell dels pastors), es fa servir la de persones.

## 17. Participar sense instruccions (11 nov 2026, decidit)
- **Primer pas «Què vols posar al pessebre?»**: Persona, Animal, Casa o Arbre/element (botons amb dibuix i nombre de llocs lliures). Després només es veuen les siluetes d'aquest tipus.
- **Siluetes** de persona (PER-ADU-F) i d'animal (ANI-OVELLA-D) en lloc de les marques de pedra; mateix aspecte que les cases lliures (blanc translúcid amb contorn gris).
- **Arbres:** en un lloc d'arbre es tria l'espècie (11) i en un de mata, la mata (3) (`intercanviables` a `disseny-propi/personalitzables.json`). Colors: fruit del fruiter, flor de l'ametller i de la mata florida, fruit del taronger (paleta `elements`). Parades, pous i fanals no canvien.
- **Girar** per a totes les peces de les famílies (persones, animals, cases i elements).

## 18. Decisions de la Fase D (11 nov 2026)
- **`admin.html` (decidit):** el pessebre + un panell amb pestanyes (Lloc, Estat, Codis, Enllaços, Configuració, Registre). Es toca un lloc i es veu qui l'ocupa (classe, codi #n i lot, claustre, administració) i es pot alliberar (reactivant el codi si cal), canviar peça/colors/finestres/girar o posar-hi una casa sense placa.
- **Contrasenyes (decidit):** administració i **només lectura** (equip de valors, direcció). `password_hash` a config.php (`php eines/gestio.php contrasenya admin|lectura`). Es demanen a cada càrrega, només a memòria; 5 fallades → 15 min.
- **Codis i etiquetes des de l'admin (decidit):** es generen i s'imprimeixen des del navegador (A4 de 24); els codis es veuen un sol cop. També la llista de classes es pot enganxar a l'admin (per si l'hosting no té terminal).
- **Configuració editable:** dates (hora de Barcelona), límit del claustre, llindar de zones, zones forçades (en ordre), segona ronda i aturada. Fites i participació automàtica: a la Fase E.

## 19. Participació anònima (11 nov 2026, decidit)
- **No es desa qui ha posat cada figura.** En confirmar, el vincle codi → lloc (`ocupacions.codi_id`) s'esborra; del codi només queden els usos (per a «un codi, una figura»). Substitueix el «registre codi → lloc → hora» del §5 i el §7: el registre apunta «família», sense codi. Durant la retenció (5 min) el vincle és temporal i desapareix en confirmar o caducar.
- **Corregir un error d'una família:** l'administració allibera la figura (tocant-la al pessebre) i, si la família ha de tornar a participar, reactiva el codi escrivint-lo (pestanya «Codis»).
- Les classes sí que queden vinculades a la seva casa (el nom ja surt a la placa; no són dades personals).

## 20. Decisions de la Fase E (11 nov 2026)
- **Fites (decidit, valors inicials):** posta = 52 de 74 cases de classe (70 %) o el 21 des; nit = participació en saltar la posta + 150 (25 % de l'objectiu 600) o el 27 des; neu = +150 o l'1 gen. Mínim d'un dia entre fites. Taula `fites` (data límit, increment, llindar fixat en saltar l'anterior, hora i motiu del salt). Editables a l'admin (pestanya Fites) mentre no s'han assolit.
- **Participació real:** classes, famílies i claustre (les automàtiques no compten). Les figures que afegeix l'administració sí que compten des del §22.
- **Participació automàtica (decidit):** només quan una fita salta per data; objectiu d'ocupació de les zones obertes 35 / 50 / 60 % (posta / nit / neu), màxim 40 % per zona, 12 figures per hora com a molt, només llocs de famílies, reproduïble amb la llavor de config.php. «Retirar totes» a l'admin (i no se n'afegeixen més fins a la fita següent per data).
- **Tick:** el visor públic el demana en obrir-se; el servidor només treballa si el snapshot té més de 15 minuts.
- **Tancament (decidit):** a `families_fins` (6 gen 00:00) s'aturen les participacions i el snapshot diu `tancat` (el visor amaga «Participar»). L'esborrat (codis, enllaços, intents i registre) el fa l'administració amb «Tancar i esborrar»; després ja no s'accepta cap escriptura.

## 21. Mode demostració (12 nov 2026, decidit)
- **Per ensenyar el pessebre** des de l'ordinador i des del mòbil (mateixa wifi o compartint internet del mòbil): `sh eines/demo.sh` i `http://…:8027/demo`. No cal Claude ni internet.
- **Mai a l'hosting:** tot és a `demo/` (servidor `demo/router.php`, API amb rellotge simulat `demo/api.php`, panell `demo/panell.js`). `public_html/` i `pessebre/` no en saben res (ho comprova `proves/prova-demo.php`). L'API de la demo només funciona amb `'demo' => true` a la seva configuració (`demo/dades/config.php`, creada sola i ignorada pel git), i el servidor només respon a adreces de la xarxa local.
- **Base de dades pròpia** a `demo/dades/` (la de proves no es toca). Les classes són les reals de `classes.csv` si el fitxer local hi és (ignorat pel git); si no, unes d'inventades (65), que són les que fan servir les proves. Contrasenya d'administració de la demo: `demo`.
- **Fases:** 1 Inici (1 des) · 2 Tutories en marxa (16 des, 34 cases) · 3 Torronada del claustre (21 des 18:30; posta per participació el 18 des) · 4 Famílies (24 des) · 5 Fites per data (27 des 01:30: la nit salta per data i comencen les automàtiques) · 6 Tancat (6 gen: neu per data, Reis arribats). Cada fase es munta des de zero amb les funcions reals del servidor i un atzar amb llavor fixa (sempre surt igual).
- **Panell:** fase, dia i hora simulats, «Veure com» (públic, tutor, claustre, família i administració, segons la fase), +1 hora / +1 dia (fites per data i automàtiques, sense participació), reiniciar la fase, les famílies o tot (amb confirmació). En engegar, el rellotge torna a l'hora de la fase més les hores fetes passar amb els botons.

## 22. L'administració afegeix figures i veu el ritme (12 nov 2026, decidit)
- **Afegir figures:** si hi ha poca participació, l'administració (no la de només lectura) pot posar figures, cases i arbres als mateixos llocs que les famílies (zones obertes; llocs del claustre només després de les 22:00 del 21 des), amb el mateix editor i sense codi, fins a Reis. Pestanya «Lloc» → «Afegir figures» → tipus → tocar siluetes una rere l'altra (p. ex. perquè hi juguin els fills de l'equip). El registre diu «administració».
- **Compten com a participació real** (comptador, llindars de les fites i obertura de zones). No hi compten les cases sense placa que l'administració posa als llocs de classe ni les automàtiques. Com que les automàtiques omplen fins a un % d'ocupació, com més figures s'afegeixin, menys automàtiques caldran.
- **Ritme cap a la propera fita** (pestanyes «Estat» i «Fites»): repartiment **lineal** entre l'inici del tram (quan va saltar la fita anterior o, si és més tard, l'obertura de les famílies) i la data límit. Mostra el que tocaria tenir avui, el que hi ha (i el % del previst en el tram), quantes en falten i quantes al dia, el ritme mitjà i de les darreres 24 h, la previsió (saltarà per participació cap a una data, o per data) i, si salta per data, quantes automàtiques s'hi afegirien. Si s'està per sota, «Per anar al ritme previst falten N figures» amb un botó per afegir-ne.

## 23. Noms de les classes a la placa (12 nov 2026, decidit; provisional per a la llar)
- **Sense noms d'animals ni de grups:** les plaques diuen el curs i el grup, sempre dins dels 8 caràcters (p. ex. «1r ESO A»). Una placa amb lletra més petita no es llegiria al mida del pessebre; es podria revisar més endavant.
- **Llar d'infants (provisional):** tres etiquetes per edat, «Llar 0», «Llar 1» i «Llar 2». Pendent de decidir si la llar tindrà només una casa per a tota l'etapa.
- **Infantil:** codis tipus «I3A». **Batxibac:** «1r BB» i «2n BB», sense lletra de grup. **Cicles:** sigla del cicle precedida del curs («1r TAPSD»). Són 64 grups en total, dels 74 llocs de classe: en queden 10 de lliures per a cases sense placa.
- **La llista real és a `classes.csv`** (ignorat pel git, no es puja mai). Es carrega a l'hosting enganxant-la a l'administració o amb `php eines/gestio.php classes classes.csv`.
