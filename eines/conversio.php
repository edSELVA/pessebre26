<?php
// Funcions de conversió del paquet de disseny v2 → public_html/disseny/.
// El zip es tracta com a dades NO fiables: es llegeix en memòria (no s'extreu al disc),
// cada JSON es valida i cada SVG passa per una llista blanca d'elements i atributs.
// Si alguna cosa no encaixa, es llança una excepció i no s'escriu res.
// Compatible amb PHP 7.4–8.3. Cal l'extensió zip (només a l'ordinador on es converteix).
declare(strict_types=1);
ini_set('serialize_precision', '-1');   // decimals curts a json_encode, sigui quin sigui el php.ini

const ARREL_ZIP = 'pessebre-disseny-v2/';
const MAX_FITXER = 4000000;          // cap fitxer útil del paquet passa de 4 MB
const CLAUSTRE_PROPERS = 32;         // llocs propers a la clariana per al claustre (a més dels 18 pastors) — PROPOSTA
const SVG_NS = 'http://www.w3.org/2000/svg';
const JSON_F = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
const ELEMENTS_SVG = ['svg', 'g', 'defs', 'symbol', 'path', 'use', 'rect', 'ellipse', 'circle',
                      'pattern', 'linearGradient', 'radialGradient', 'stop'];
const ROLS_PERSONALITZABLES = ['facana' => 4, 'teulada' => 4, 'porta' => 3, 'flor' => 3, 'roba' => 3, 'mocador' => 3, 'roba2' => 3,
                               'pell' => 3, 'cabell' => 3, 'palla' => 3, 'camell' => 3, 'blanc' => 3, 'bru' => 3, 'gris' => 3, 'rosa' => 3];
const CATEGORIES = ['casa', 'element', 'persona', 'animal', 'escena', 'decoracio', 'marca'];
const MOMENTS = ['dia', 'posta', 'nit', 'neu'];
// Colors de la silueta fantasma (compon.FANTASMA de la guia v2)
const FANTASMA = ['-clar' => '#FFFDF4', '' => '#FFF3DA', '-ombra' => '#EBDCC0', '-fosc' => '#D8C6A6'];
// Opacitat de la silueta fantasma dels llocs lliures. La guia v2 diu cases .62 i elements .5;
// l'Eduard va triar l'opció més transparent (11 nov 2026).
const OPACITAT_FANTASMA = ['casa' => '.32', 'element' => '.25'];
// Contorn gris fi de les cases lliures, perquè es vegin sobre el paviment del casc antic
const VORA_FANTASMA = ['color' => '#6F675E', 'opacitat' => '.55'];

class ErrorPaquet extends Exception {}

function falla(string $msg): void { throw new ErrorPaquet($msg); }

// ───────────────────────────── lectura del zip

/** Retorna [nom relatiu => contingut] dels fitxers del paquet (sense extreure res al disc). */
function llegeix_zip(string $cami): array
{
    if (!class_exists('ZipArchive')) falla('Cal l\'extensió zip de PHP per convertir el paquet.');
    $z = new ZipArchive();
    if ($z->open($cami) !== true) falla("No es pot obrir el zip: $cami");
    $fitxers = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $st = $z->statIndex($i);
        $nom = $st['name'];
        if (substr($nom, -1) === '/') continue;                       // directori
        if (strpos($nom, '..') !== false || $nom[0] === '/' || strpos($nom, '\\') !== false || strpos($nom, "\0") !== false) {
            falla("Nom de fitxer sospitós al zip: $nom");
        }
        if (strncmp($nom, ARREL_ZIP, strlen(ARREL_ZIP)) !== 0) falla("Fitxer fora de " . ARREL_ZIP . ": $nom");
        $rel = substr($nom, strlen(ARREL_ZIP));
        if (!preg_match('#\.(svg|json|css)$#', $rel)) continue;         // imatges, .py i .md no es fan servir
        if ($st['size'] > MAX_FITXER) falla("Fitxer massa gran: $rel");
        $fitxers[$rel] = $z->getFromIndex($i);
    }
    $z->close();
    return $fitxers;
}

function cal(array $fitxers, string $nom): string
{
    if (!isset($fitxers[$nom])) falla("Falta $nom al paquet");
    return $fitxers[$nom];
}

function llegeix_json(array $fitxers, string $nom): array
{
    try {
        $d = json_decode(cal($fitxers, $nom), true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        falla("$nom no és JSON vàlid: " . $e->getMessage());
    }
    if (!is_array($d)) falla("$nom no és un objecte JSON");
    return $d;
}

// ───────────────────────────── validació de valors

function es_hex($v): bool { return is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/', $v) === 1; }
function es_num($v): bool { return is_int($v) || is_float($v); }
function es_rol($v): bool { return is_string($v) && preg_match('/^[a-z][a-z0-9-]{0,30}$/', $v) === 1; }
function es_codi($v): bool { return is_string($v) && preg_match('/^[A-Z][A-Z0-9-]{1,30}$/', $v) === 1; }

function nums($v, int $n, string $on): array
{
    if (!is_array($v) || count($v) !== $n) falla("$on: s'esperaven $n nombres");
    foreach ($v as $x) if (!es_num($x)) falla("$on: valor no numèric");
    return array_values($v);
}

// ───────────────────────────── SVG: llista blanca

function regles_atributs(): array
{
    $num = '/^-?[0-9]*\.?[0-9]+(px|%)?$/';
    $pintura = '/^(none|#[0-9A-Fa-f]{3,8}|url\(#[A-Za-z0-9_-]+\)|var\(--[a-z0-9-]+, ?#[0-9A-Fa-f]{3,8}\))$/';
    $r = [
        'id' => '/^[A-Za-z][A-Za-z0-9_-]{0,60}$/',
        'class' => '/^[a-z0-9 -]{1,60}$/',
        'd' => '/^[MmLlHhVvZzCcSsQqTtAa0-9.,\s-]*$/',
        'fill' => $pintura, 'stroke' => $pintura, 'stop-color' => $pintura,
        'viewBox' => '/^-?[0-9.]+( -?[0-9.]+){3}$/',
        'href' => '/^#[A-Za-z0-9_-]+$/',
        'transform' => '/^(matrix|translate|scale)\([0-9.,\s-]+\)$/',
        'stroke-linecap' => '/^(round|butt|square)$/',
        'stroke-linejoin' => '/^(round|miter|bevel)$/',
        'shape-rendering' => '/^(crispEdges|auto|geometricPrecision|optimizeSpeed)$/',
        'gradientUnits' => '/^(userSpaceOnUse|objectBoundingBox)$/',
        'patternUnits' => '/^(userSpaceOnUse|objectBoundingBox)$/',
        'data-codi' => '/^[A-Z][A-Z0-9-]+$/',
        'data-ancoratge' => '/^[0-9.]+,[0-9.]+$/',
        'data-capa' => '/^[a-z-]+$/',
        'style' => '/^[^<>"\\\\]*$/',   // es revisa a fons a estil_declaracions()
    ];
    foreach (['fill-opacity', 'stop-opacity', 'stroke-opacity', 'opacity', 'stroke-width', 'offset', 'x', 'y', 'width', 'height',
              'cx', 'cy', 'r', 'rx', 'ry', 'x1', 'y1', 'x2', 'y2'] as $a) {
        $r[$a] = $num;
    }
    return $r;
}

/** Declaracions d'un atribut style, permeses només si són opacitat, transformació o barreja, sense url(). */
function estil_declaracions(string $estil, string $on): array
{
    $out = [];
    foreach (array_filter(array_map('trim', explode(';', $estil))) as $decl) {
        $p = strpos($decl, ':');
        if ($p === false) falla("$on: estil mal format");
        $prop = trim(substr($decl, 0, $p));
        $val = trim(substr($decl, $p + 1));
        if (!in_array($prop, ['opacity', 'transform', 'mix-blend-mode'], true)) falla("$on: propietat d'estil no permesa ($prop)");
        if (!preg_match('/^[a-zA-Z0-9#(),.\s%-]+$/', $val) || stripos($val, 'url') !== false || stripos($val, 'expression') !== false) {
            falla("$on: valor d'estil no permès");
        }
        $out[] = "$prop: $val";
    }
    return $out;
}

/** Carrega i revisa un SVG. Retorna el DOMDocument net (sense comentaris ni espais). */
function neteja_svg(string $text, string $nom): DOMDocument
{
    if (preg_match('/<!DOCTYPE|<!ENTITY|<\?xml-stylesheet|<!\[CDATA\[/i', $text)) falla("$nom: conté DOCTYPE, entitats o CDATA");
    $doc = new DOMDocument();
    $abans = libxml_use_internal_errors(true);
    $ok = $doc->loadXML($text, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($abans);
    if (!$ok || !$doc->documentElement) falla("$nom: XML no vàlid");
    $arrel = $doc->documentElement;
    if ($arrel->namespaceURI !== SVG_NS || $arrel->localName !== 'svg') falla("$nom: l'arrel no és <svg>");
    $regles = regles_atributs();
    revisa_element($arrel, $nom, $regles);
    return $doc;
}

function revisa_element(DOMElement $el, string $nom, array $regles): void
{
    if ($el->namespaceURI !== SVG_NS || !in_array($el->localName, ELEMENTS_SVG, true)) {
        falla("$nom: element no permès <{$el->nodeName}>");
    }
    foreach (iterator_to_array($el->attributes) as $a) {
        $an = $a->nodeName;
        if ($a->namespaceURI !== null || !isset($regles[$an]) || !preg_match($regles[$an], $a->value)) {
            falla("$nom: atribut no permès a <{$el->localName}>: $an");
        }
        if ($an === 'style') estil_declaracions($a->value, $nom);
    }
    foreach (iterator_to_array($el->childNodes) as $f) {
        if ($f instanceof DOMElement) {
            revisa_element($f, $nom, $regles);
        } elseif ($f instanceof DOMText && trim($f->nodeValue) === '') {
            $el->removeChild($f);
        } elseif ($f instanceof DOMComment) {
            $el->removeChild($f);
        } else {
            falla("$nom: contingut no permès dins <{$el->localName}>");
        }
    }
}

/** Treu els atributs style (la CSP no els permet) i els converteix en regles CSS per id. */
function estils_a_css(DOMDocument $doc, string $nom, array &$css): void
{
    foreach (iterator_to_array($doc->getElementsByTagNameNS(SVG_NS, '*')) as $el) {
        if (!$el->hasAttribute('style')) continue;
        if ($el === $doc->documentElement) { $el->removeAttribute('style'); continue; }
        $id = $el->getAttribute('id');
        if ($id === '') falla("$nom: element amb style però sense id");
        $css['#' . $id] = estil_declaracions($el->getAttribute('style'), $nom);
        $el->removeAttribute('style');
    }
}

/** Rectangle [x, y, w, h] que ocupen els camins de rol placa/placa-ombra d'una peça (null si no n'hi ha). */
function placa_dibuixada(DOMDocument $doc): ?array
{
    $x0 = $y0 = PHP_INT_MAX;
    $x1 = $y1 = -1;
    foreach ($doc->getElementsByTagNameNS(SVG_NS, 'path') as $el) {
        if (!in_array($el->getAttribute('class'), ['placa', 'placa-ombra'], true)) continue;
        preg_match_all('/M(\d+) (\d+)h(\d+)v(\d+)/', $el->getAttribute('d'), $m, PREG_SET_ORDER);
        foreach ($m as [, $x, $y, $w, $h]) {
            $x0 = min($x0, (int)$x); $y0 = min($y0, (int)$y);
            $x1 = max($x1, (int)$x + (int)$w); $y1 = max($y1, (int)$y + (int)$h);
        }
    }
    return $x1 < 0 ? null : [$x0, $y0, $x1 - $x0, $y1 - $y0];
}

/**
 * Agrupa el paviment del turó del casc antic (vora, empedrat i llambordes) en <g id="casc-paviment">,
 * perquè el visor el pugui suavitzar mentre les classes encara no hi han posat les cases.
 * L'ombra verda de sota (feixa-ombra) es queda fora del grup.
 */
function agrupa_paviment_casc(DOMDocument $doc): void
{
    $base = null;
    foreach ($doc->getElementsByTagNameNS(SVG_NS, 'path') as $el) {
        if (strpos($el->getAttribute('fill'), 'var(--empedrat,') === 0 && $el->parentNode->localName !== 'pattern') { $base = $el; break; }
    }
    if (!$base) falla('terreny.svg: no es troba el paviment del casc antic');
    $d = $base->getAttribute('d');
    $grup = $doc->createElementNS(SVG_NS, 'g');
    $grup->setAttribute('id', 'casc-paviment');
    $peces = [];
    foreach ($base->parentNode->childNodes as $f) {
        if ($f instanceof DOMElement && $f->localName === 'path' && $f->getAttribute('d') === $d
            && strpos($f->getAttribute('fill'), 'var(--feixa-ombra,') !== 0) $peces[] = $f;
    }
    if (count($peces) !== 3) falla('terreny.svg: el paviment del casc antic no té les 3 capes esperades');
    $base->parentNode->insertBefore($grup, $peces[0]);
    foreach ($peces as $p) $grup->appendChild($p);
}

function ids_de(DOMDocument $doc): array
{
    $ids = [];
    foreach ($doc->getElementsByTagNameNS(SVG_NS, '*') as $el) {
        if ($el->hasAttribute('id')) $ids[] = $el->getAttribute('id');
    }
    return $ids;
}

function fills_defs(DOMDocument $doc): array
{
    $out = [];
    foreach ($doc->documentElement->childNodes as $f) {
        if ($f instanceof DOMElement && $f->localName === 'defs') {
            foreach ($f->childNodes as $d) if ($d instanceof DOMElement) $out[$d->getAttribute('id')] = $doc->saveXML($d);
        }
    }
    return $out;
}

function treu_defs(DOMDocument $doc): void
{
    foreach (iterator_to_array($doc->documentElement->childNodes) as $f) {
        if ($f instanceof DOMElement && $f->localName === 'defs') $doc->documentElement->removeChild($f);
    }
}

function svg_text(DOMDocument $doc): string
{
    return $doc->saveXML($doc->documentElement) . "\n";
}

// ───────────────────────────── conversió

/**
 * Converteix el paquet. $arrel és l'arrel del repositori (on hi ha eines/ i disseny-propi/).
 * Retorna [nom de fitxer de sortida => contingut] i una llista d'avisos.
 * @return array{0: array<string,string>, 1: string[]}
 */
function converteix(string $zip, string $arrel): array
{
    $f = llegeix_zip($zip);
    $avisos = [];
    $sortida = [];

    $registre = valida_registre(llegeix_json($f, 'registre.json'));
    if (count($registre) !== 102) falla('registre.json: s\'esperaven 102 peces i n\'hi ha ' . count($registre));
    // Peces pròpies (disseny-propi/): s'afegeixen al paquet sense tocar-lo
    $propi = json_decode((string)file_get_contents("$arrel/disseny-propi/registre.json"), true);
    if (!is_array($propi)) falla('disseny-propi/registre.json no és JSON vàlid');
    foreach (valida_registre($propi) as $codi => $p) {
        if (isset($registre[$codi])) falla("disseny-propi: la peça $codi ja és al paquet");
        $registre[$codi] = $p;
        $f["peces/$codi.svg"] = (string)file_get_contents("$arrel/disseny-propi/peces/$codi.svg");
    }
    ksort($registre);
    $paletes = valida_paletes(llegeix_json($f, 'paletes.json'), ['cases' => ['facana', 'teulada', 'porta'], 'persones' => ['roba', 'mocador', 'roba2']]);
    // Paletes pròpies (disseny-propi/paletes.json): flors de les cases i roba dels pastors
    $propies = valida_paletes((array)json_decode((string)file_get_contents("$arrel/disseny-propi/paletes.json"), true),
        ['cases' => ['flor'], 'pastors' => ['roba', 'mocador', 'roba2'], 'persones' => ['pell', 'cabell', 'palla'],
         'animals' => ['camell', 'blanc', 'bru', 'gris', 'rosa']]);
    foreach ($propies as $grup => $rols) foreach ($rols as $rol => $v) $paletes[$grup][$rol] = $v;
    // Les cases amb jardineres també tenen el color de les flors personalitzable (després de façana, teulada i porta)
    foreach ($registre as $codi => $p) {
        if ($p['cat'] === 'casa' && in_array('flor', $p['rols'], true) && !in_array('flor', $p['pers'], true)) $registre[$codi]['pers'][] = 'flor';
    }
    // Figures de les famílies: pell, cabells i palla (si la peça els té) i el pelatge principal de cada animal
    $extra = json_decode((string)file_get_contents("$arrel/disseny-propi/personalitzables.json"), true);
    if (!is_array($extra)) falla('disseny-propi/personalitzables.json no és JSON vàlid');
    foreach ($registre as $codi => $p) {
        if ($p['cat'] !== 'persona') continue;
        foreach ($extra['persones'] as $rol) {
            if (isset($paletes['persones'][$rol]) && in_array($rol, $p['rols'], true)) $registre[$codi]['pers'][] = $rol;
        }
    }
    foreach ($extra['pelatge'] as $codi => $rol) {
        if (($registre[$codi]['cat'] ?? '') !== 'animal' || !isset($paletes['animals'][$rol])) falla("personalitzables.json: $codi o $rol no vàlids");
        $registre[$codi]['pers'][] = $rol;
    }
    // Fora les parts personalitzables que la peça no té dibuixades (p. ex. el mocador del pagès amb barret)
    foreach ($registre as $codi => $p) {
        $registre[$codi]['pers'] = array_values(array_filter($p['pers'], function ($rol) use ($p) {
            foreach ($p['rols'] as $r) if ($r === $rol || strncmp($r, "$rol-", strlen($rol) + 1) === 0) return true;
            return false;
        }));
    }

    // Capes 2400×800 i estils de capa
    $css = [];
    $terreny = neteja_svg(cal($f, 'terreny/terreny.svg'), 'terreny.svg');
    $terra = neteja_svg(cal($f, 'llum/llum-terra.svg'), 'llum-terra.svg');
    $aire = neteja_svg(cal($f, 'llum/llum-aire.svg'), 'llum-aire.svg');
    foreach (['terreny.svg' => $terreny, 'llum-terra.svg' => $terra, 'llum-aire.svg' => $aire] as $n => $d) {
        if ($d->documentElement->getAttribute('viewBox') !== '0 0 2400 800') falla("$n: el viewBox no és 0 0 2400 800");
        estils_a_css($d, $n, $css);
    }
    agrupa_paviment_casc($terreny);
    // Els degradats de llum es copien una sola vegada: llum-aire fa servir els de llum-terra.
    $defs_terra = fills_defs($terra);
    foreach (fills_defs($aire) as $id => $xml) {
        if (!isset($defs_terra[$id]) || $defs_terra[$id] !== $xml) falla("llum-aire.svg: el degradat $id no és igual al de llum-terra.svg");
    }
    treu_defs($aire);

    // Sprite de peces + peces de llum (LLUM-TOLL, LLUM-HALO)
    [$sprite, $peces] = sprite_peces($f, $registre, $defs_terra);

    // Ids únics a tot el document final
    $tots = array_merge(ids_de($terreny), ids_de($terra), ids_de($aire), ids_de($sprite));
    $dup = array_keys(array_filter(array_count_values($tots), function ($n) { return $n > 1; }));
    if ($dup) falla('Ids repetits entre capes: ' . implode(', ', $dup));

    // Algunes cases tenen rectangle de placa al registre però no la porten dibuixada: la dibuixa el visor,
    // amb la mateixa mida que les altres (14×5 de text + 1 de vora).
    $sense = [];
    foreach ($peces as $codi => $p) {
        if ($p['placa'] && !in_array('placa', $p['rols'], true)) {
            [$x, $y, $w, $h] = $p['placa'];
            $peces[$codi]['placa'] = [$x + 1, $y + 1, $w - 2, $h - 2];
            $peces[$codi]['placa_propia'] = 1;
            $sense[] = $codi;
        }
    }
    if ($sense) $avisos[] = implode(', ', $sense) . ': el registre diu que té placa però no la porta dibuixada. La dibuixa el visor.';

    $mapa = normalitza_mapa(llegeix_json($f, 'mapa.json'), $registre, $avisos);
    $fixa = valida_escena_fixa(llegeix_json($f, 'escena-fixa.json'), $registre);
    $fixa = canvis_escena_fixa($fixa, "$arrel/disseny-propi/escena-fixa-canvis.json", $registre);
    $reis = cami_reis("$arrel/eines/reis-cami-font.json", $fixa);
    [$moments, $avisos_m] = moments_css($f);
    $avisos = array_merge($avisos, $avisos_m);

    $cap = "<!-- Generat per eines/converteix.php a partir del paquet de disseny v2. No l'editis a mà. -->\n";
    $sortida['terreny.svg'] = $cap . svg_text($terreny);
    $sortida['llum-terra.svg'] = $cap . svg_text($terra);
    $sortida['llum-aire.svg'] = $cap . svg_text($aire);
    $sortida['peces.svg'] = $cap . svg_text($sprite);
    $sortida['peces.json'] = json_objecte_per_linies(['nota' => 'Generat per eines/converteix.php. vb = mida del viewBox, a = ancoratge, pers = rols personalitzables (ordre dels índexs de colors).'], 'peces', $peces);
    $sortida['mapa.json'] = json_llista_per_linies($mapa['cap'], 'llocs', $mapa['llocs']);
    $sortida['escena-fixa.json'] = json_llista_per_linies(['nota' => 'Peces fixes (no participables). ordre = y de dibuix quan no coincideix amb y.'], 'peces', $fixa);
    $sortida['paletes.json'] = json_encode($paletes, JSON_F | JSON_PRETTY_PRINT) . "\n";   // cases, persones i pastors
    $sortida['reis-cami.json'] = json_encode($reis, JSON_F) . "\n";
    $sortida['moments.css'] = "/* Generat per eines/converteix.php a partir de moments/*.css del paquet v2. */\n" . $moments;
    $sortida['escena.css'] = escena_css($css, $registre);
    ksort($sortida);
    return [$sortida, $avisos];
}

function valida_registre(array $r): array
{
    if (!isset($r['peces']) || !is_array($r['peces'])) falla('registre.json: falta peces');
    $out = [];
    foreach ($r['peces'] as $codi => $p) {
        $on = "registre.json → $codi";
        if (!es_codi($codi) || !is_array($p)) falla("$on: codi no vàlid");
        if (!in_array($p['categoria'] ?? null, CATEGORIES, true)) falla("$on: categoria desconeguda");
        $vb = nums($p['viewBox'] ?? null, 4, "$on viewBox");
        if ($vb[0] != 0 || $vb[1] != 0) falla("$on: el viewBox ha de començar a 0 0");
        $rols = $p['rols'] ?? null;
        if (!is_array($rols)) falla("$on: falten rols");
        foreach ($rols as $rol) if (!es_rol($rol)) falla("$on: rol no vàlid");
        $pers = $p['personalitzables'] ?? null;
        if (!is_array($pers)) falla("$on: falten personalitzables");
        foreach ($pers as $rol) if (!isset(ROLS_PERSONALITZABLES[$rol])) falla("$on: rol personalitzable desconegut ($rol)");
        $out[$codi] = [
            'cat' => $p['categoria'],
            'vb' => [$vb[2], $vb[3]],
            'a' => nums($p['ancoratge'] ?? null, 2, "$on ancoratge"),
            'caixa' => nums($p['caixa'] ?? null, 4, "$on caixa"),
            'placa' => ($p['placa'] ?? null) === null ? null : nums($p['placa'], 4, "$on placa"),
            'pers' => array_values($pers),
            'rols' => array_values($rols),
        ];
    }
    ksort($out);
    return $out;
}

function valida_paletes(array $p, array $esperat): array
{
    $out = [];
    foreach ($esperat as $grup => $rols) {
        foreach ($rols as $rol) {
            $on = "paletes.json → $grup.$rol";
            $e = $p[$grup][$rol] ?? null;
            if (!is_array($e) || !is_string($e['nom'] ?? null) || !is_array($e['opcions'] ?? null) || !$e['opcions']) falla("$on: estructura inesperada");
            $opcions = [];
            foreach ($e['opcions'] as $op) {
                if (!is_array($op) || count($op) !== ROLS_PERSONALITZABLES[$rol]) falla("$on: cada opció ha de tenir " . ROLS_PERSONALITZABLES[$rol] . ' tons');
                foreach ($op as $c) if (!es_hex($c)) falla("$on: color no vàlid");
                $opcions[] = array_map('strtoupper', array_values($op));
            }
            $out[$grup][$rol] = ['nom' => strip_tags($e['nom']), 'opcions' => $opcions];
        }
    }
    return $out;
}

/** @return array{0: DOMDocument, 1: array} */
function sprite_peces(array $f, array $registre, array $defs_terra): array
{
    $doc = new DOMDocument('1.0', 'UTF-8');
    $arrel = $doc->createElementNS(SVG_NS, 'svg');
    $doc->appendChild($arrel);
    $defs = $doc->createElementNS(SVG_NS, 'defs');
    $arrel->appendChild($defs);
    $peces = $registre;
    $codis = array_keys($registre);
    $codis[] = 'LLUM-TOLL';
    $codis[] = 'LLUM-HALO';
    foreach ($codis as $codi) {
        $es_llum = strncmp($codi, 'LLUM-', 5) === 0;
        $nom = $es_llum ? "llum/$codi.svg" : "peces/$codi.svg";
        $p = neteja_svg(cal($f, $nom), $nom);
        $r = $p->documentElement;
        if ($r->getAttribute('data-codi') !== $codi) falla("$nom: data-codi no coincideix");
        $vb = array_map('floatval', explode(' ', $r->getAttribute('viewBox')));
        $anc = array_map('floatval', explode(',', $r->getAttribute('data-ancoratge')));
        if ($es_llum) {
            // Les peces de llum porten una còpia dels degradats: s'usen els de llum-terra.
            foreach (fills_defs($p) as $id => $xml) {
                if (!isset($defs_terra[$id]) || $defs_terra[$id] !== $xml) falla("$nom: el degradat $id no és igual al de llum-terra.svg");
            }
            treu_defs($p);
            $peces[$codi] = ['cat' => 'llum', 'vb' => [$vb[2], $vb[3]], 'a' => $anc, 'caixa' => [0, 0, $vb[2], $vb[3]], 'placa' => null, 'pers' => [], 'rols' => []];
        } else {
            $reg = $registre[$codi];
            if ($vb[2] != $reg['vb'][0] || $vb[3] != $reg['vb'][1]) falla("$nom: el viewBox no coincideix amb registre.json");
            if ($anc[0] != $reg['a'][0] || $anc[1] != $reg['a'][1]) falla("$nom: l'ancoratge no coincideix amb registre.json");
        }
        foreach (ids_de($p) as $id) falla("$nom: una peça no pot tenir ids interns ($id)");
        // La placa es pren del dibuix (el rectangle del registre no coincideix amb el dibuix en molts models)
        if (!$es_llum && $peces[$codi]['placa'] && ($rect = placa_dibuixada($p))) $peces[$codi]['placa'] = $rect;
        $s = $doc->createElementNS(SVG_NS, 'symbol');
        $s->setAttribute('id', 'pc-' . $codi);
        $s->setAttribute('viewBox', $r->getAttribute('viewBox'));
        foreach ($r->childNodes as $fill) {
            $nou = $doc->importNode($fill, true);
            if ($nou instanceof DOMElement) $nou->removeAttribute('class');   // la classe només repeteix el rol
            $s->appendChild($nou);
        }
        $defs->appendChild($s);
        // Cases: finestres que el tutor pot treure. Per a cada una, una «tapa» (pc-CODI-F0, F1…) que hi dibuixa paret.
        if (!$es_llum && $peces[$codi]['cat'] === 'casa') {
            // Rectangle de la part dibuixada (per ampliar la casa a la previsualització dels tutors)
            [$gr] = graella_rols($p, (int)$vb[2], (int)$vb[3]);
            $vx = []; $vy = [];
            foreach ($gr as $y => $fila) foreach ($fila as $x => $rol) if ($rol !== null) { $vx[] = $x; $vy[] = $y; }
            $peces[$codi]['dibuix'] = [min($vx), min($vy), max($vx) - min($vx) + 1, max($vy) - min($vy) + 1];
            $peces[$codi]['finestres'] = [];
            foreach (finestres_casa($p, (int)$vb[2], (int)$vb[3]) as $i => [$rect, $camins]) {
                $peces[$codi]['finestres'][] = $rect;
                $t = $doc->createElementNS(SVG_NS, 'symbol');
                $t->setAttribute('id', 'pc-' . $codi . '-F' . $i);
                $t->setAttribute('viewBox', $r->getAttribute('viewBox'));
                foreach ($camins as $fill => $d) {
                    $cami = $doc->createElementNS(SVG_NS, 'path');
                    $cami->setAttribute('fill', $fill);
                    $cami->setAttribute('d', $d);
                    $t->appendChild($cami);
                }
                $defs->appendChild($t);
            }
        }
    }
    ksort($peces);
    return [$doc, $peces];
}

/** Graella de rols d'una peça (a partir dels camins de rectangles) i color de cada rol. */
function graella_rols(DOMDocument $doc, int $w, int $h): array
{
    $g = array_fill(0, $h, array_fill(0, $w, null));
    $fills = [];
    foreach ($doc->getElementsByTagNameNS(SVG_NS, 'path') as $el) {
        $rol = $el->getAttribute('class');
        if ($rol === '' || $el->hasAttribute('fill-opacity')) continue;          // l'ombra a terra no compta
        $fills[$rol] = $el->getAttribute('fill');
        preg_match_all('/M(\d+) (\d+)h(\d+)v(\d+)/', $el->getAttribute('d'), $m, PREG_SET_ORDER);
        foreach ($m as [, $x, $y, $rw, $rh]) {
            for ($yy = (int)$y; $yy < $y + $rh && $yy < $h; $yy++) for ($xx = (int)$x; $xx < $x + $rw && $xx < $w; $xx++) $g[$yy][$xx] = $rol;
        }
    }
    return [$g, $fills];
}

/**
 * Finestres d'una casa: grups de vidres (rol llum*) separats com a molt per 1 píxel de marc.
 * Per a cada finestra retorna [rectangle [x, y, w, h], camins de la tapa per color]. La tapa cobreix vidres, marc i
 * llinda de fusta i hi continua la paret del costat (pedra o façana, fila a fila).
 */
function finestres_casa(DOMDocument $doc, int $w, int $h): array
{
    [$g, $fills] = graella_rols($doc, $w, $h);
    $vidre = function ($x, $y) use ($g, $w, $h) { return $x >= 0 && $y >= 0 && $x < $w && $y < $h && strncmp((string)$g[$y][$x], 'llum', 4) === 0; };
    // 1. Components de vidre
    $vist = [];
    $caixes = [];
    for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) {
        if (!$vidre($x, $y) || isset($vist["$x,$y"])) continue;
        $pila = [[$x, $y]];
        $vist["$x,$y"] = true;
        $c = [$x, $y, $x, $y];
        while ($pila) {
            [$a, $b] = array_pop($pila);
            $c = [min($c[0], $a), min($c[1], $b), max($c[2], $a), max($c[3], $b)];
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $nx = $a + $dx; $ny = $b + $dy;
                if ($vidre($nx, $ny) && !isset($vist["$nx,$ny"])) { $vist["$nx,$ny"] = true; $pila[] = [$nx, $ny]; }
            }
        }
        $caixes[] = $c;
    }
    // 2. Ajunta els vidres d'una mateixa finestra (separats per un muntant d'1 píxel)
    do {
        $fet = false;
        for ($i = 0; $i < count($caixes) && !$fet; $i++) for ($j = $i + 1; $j < count($caixes) && !$fet; $j++) {
            [$a, $b] = [$caixes[$i], $caixes[$j]];
            if ($b[0] <= $a[2] + 2 && $a[0] <= $b[2] + 2 && $b[1] <= $a[3] + 2 && $a[1] <= $b[3] + 2) {
                $caixes[$i] = [min($a[0], $b[0]), min($a[1], $b[1]), max($a[2], $b[2]), max($a[3], $b[3])];
                array_splice($caixes, $j, 1);
                $fet = true;
            }
        }
    } while ($fet);
    usort($caixes, function ($a, $b) { return [$a[1], $a[0]] <=> [$b[1], $b[0]]; });

    $out = [];
    $paret = function ($rol) { return preg_match('/^(facana|pedra)(-clar|-ombra|-fosc)?$/', (string)$rol) === 1; };
    foreach ($caixes as [$x0, $y0, $x1, $y1]) {
        // 3. Píxels a tapar: vidres, marc (contorn) i fusta dins el rectangle ampliat 1 px, i la llinda de fusta de sobre
        $tapa = [];
        for ($y = $y0 - 1; $y <= $y1 + 1; $y++) for ($x = $x0 - 1; $x <= $x1 + 1; $x++) {
            $r = $g[$y][$x] ?? null;
            if ($r !== null && (strncmp($r, 'llum', 4) === 0 || $r === 'contorn' || strncmp($r, 'fusta', 5) === 0)) $tapa["$x,$y"] = [$x, $y];
        }
        $y = $y0 - 2;
        $fusta = [];
        for ($x = $x0 - 2; $x <= $x1 + 2; $x++) if (strncmp((string)($g[$y][$x] ?? ''), 'fusta', 5) === 0) $fusta[] = [$x, $y];
        if (count($fusta) >= $x1 - $x0 + 1) foreach ($fusta as $p) $tapa["{$p[0]},{$p[1]}"] = $p;
        if (!$tapa) continue;
        // 4. Cada píxel agafa la paret més propera de la mateixa fila (esquerra o dreta)
        $xs = array_column($tapa, 0); $ys = array_column($tapa, 1);
        [$tx0, $tx1] = [min($xs), max($xs)];
        $files = [];
        foreach ($tapa as [$x, $y]) {
            $esq = null; $dre = null;
            for ($d = 1; $d <= 8 && $esq === null; $d++) if ($paret($g[$y][$tx0 - $d] ?? null) && !isset($tapa[($tx0 - $d) . ",$y"])) $esq = $g[$y][$tx0 - $d];
            for ($d = 1; $d <= 8 && $dre === null; $d++) if ($paret($g[$y][$tx1 + $d] ?? null) && !isset($tapa[($tx1 + $d) . ",$y"])) $dre = $g[$y][$tx1 + $d];
            $rol = ($x - $tx0 <= $tx1 - $x) ? ($esq ?? $dre) : ($dre ?? $esq);
            $rol = $rol ?? 'facana';
            $files[$fills[$rol] ?? 'var(--facana, #F5D9A8)'][$y][] = $x;
        }
        $camins = [];
        foreach ($files as $fill => $per_fila) {
            ksort($per_fila);
            $d = '';
            foreach ($per_fila as $y => $cols) {
                sort($cols);
                for ($i = 0; $i < count($cols); $i++) {
                    $ini = $cols[$i];
                    while ($i + 1 < count($cols) && $cols[$i + 1] === $cols[$i] + 1) $i++;
                    $l = $cols[$i] - $ini + 1;
                    $d .= "M$ini {$y}h{$l}v1h-{$l}z";
                }
            }
            $camins[$fill] = $d;
        }
        ksort($camins);
        $out[] = [[$tx0, min($ys), $tx1 - $tx0 + 1, max($ys) - min($ys) + 1], $camins];
    }
    return $out;
}

function normalitza_mapa(array $m, array $registre, array &$avisos): array
{
    if (($m['mida'] ?? null) !== [2400, 800]) falla('mapa.json: la mida no és 2400×800');
    $franges = $m['franges'] ?? null;
    if (!is_array($franges) || array_keys($franges) !== ['Z0', 'Z1', 'Z2', 'Z3']) falla('mapa.json: franges inesperades');
    foreach ($franges as $z => $rs) foreach ($rs as $r) nums($r, 2, "mapa.json franges $z");
    $zona_x = function (int $x) use ($franges): string {
        foreach ($franges as $z => $rs) foreach ($rs as [$a, $b]) if ($x >= $a && $x < $b) return $z;
        return 'Z3';
    };
    $llocs = [];
    $ids = [];
    $comu = function (array $e, string $on) use (&$ids, $franges) {
        if (!is_string($e['id'] ?? null) || !preg_match('/^[CEP][0-9]{3,4}$/', $e['id'])) falla("$on: id no vàlid");
        if (isset($ids[$e['id']])) falla("$on: id repetit {$e['id']}");
        $ids[$e['id']] = true;
        if (!is_int($e['x'] ?? null) || !is_int($e['y'] ?? null) || $e['x'] < 0 || $e['x'] > 2400 || $e['y'] < 0 || $e['y'] > 800) falla("$on: coordenades fora de l'escena");
        if (!isset($franges[$e['zona'] ?? ''])) falla("$on: zona desconeguda");
        return ['id' => $e['id'], 'x' => $e['x'], 'y' => $e['y'], 'zona' => $e['zona']];
    };
    foreach ($m['cases'] ?? [] as $i => $c) {
        $l = $comu($c, "mapa.json cases[$i]");
        if (!in_array($c['tipus'] ?? null, ['classe', 'familia'], true)) falla("mapa.json {$l['id']}: tipus de casa desconegut");
        if (($registre[$c['codi'] ?? '']['cat'] ?? '') !== 'casa') falla("mapa.json {$l['id']}: la peça no és una casa");
        if (!is_bool($c['mirall'] ?? null)) falla("mapa.json {$l['id']}: mirall no vàlid");
        $llocs[] = ['id' => $l['id'], 'tipus' => 'casa', 'x' => $l['x'], 'y' => $l['y'], 'zona' => $l['zona'],
                    'grup' => $c['tipus'] === 'classe' ? 'poble' : 'general', 'codi' => $c['codi'], 'mirall' => $c['mirall'] ? 1 : 0];
    }
    foreach ($m['elements'] ?? [] as $i => $e) {
        $l = $comu($e, "mapa.json elements[$i]");
        if (($registre[$e['codi'] ?? '']['cat'] ?? '') !== 'element') falla("mapa.json {$l['id']}: la peça no és un element");
        if (!is_bool($e['mirall'] ?? null)) falla("mapa.json {$l['id']}: mirall no vàlid");
        $llocs[] = ['id' => $l['id'], 'tipus' => 'element', 'x' => $l['x'], 'y' => $l['y'], 'zona' => $l['zona'],
                    'grup' => 'general', 'codi' => $e['codi'], 'mirall' => $e['mirall'] ? 1 : 0];
    }
    $pastors_fora = 0;
    foreach ($m['punts'] ?? [] as $i => $p) {
        $l = $comu($p, "mapa.json punts[$i]");
        $t = $p['tipus'] ?? null;
        if (!in_array($t, ['persona', 'animal', 'pastor'], true)) falla("mapa.json {$l['id']}: tipus de punt desconegut");
        $nou = ['id' => $l['id'], 'tipus' => $t === 'animal' ? 'animal' : 'persona', 'x' => $l['x'], 'y' => $l['y'], 'zona' => $l['zona'],
                'grup' => $t === 'pastor' ? 'claustre' : 'general'];
        if ($t === 'pastor') {
            $nou['pastor'] = 1;
            if ($zona_x($l['x']) !== $l['zona']) $pastors_fora++;
        }
        $llocs[] = $nou;
    }
    if ($pastors_fora) $avisos[] = "$pastors_fora pastors porten zona Z0 però són a la clariana (x 263–427, franges Z2/Z3). Es manté Z0 (mana el zip).";

    // Llocs propers a la clariana → grup claustre (no cases, no pastors). PROPOSTA.
    [$cx, $cy] = nums($m['clariana'] ?? null, 2, 'mapa.json clariana');
    $cand = [];
    foreach ($llocs as $i => $l) {
        if ($l['tipus'] === 'casa' || $l['grup'] !== 'general') continue;
        $d = (($l['x'] - $cx) / 175) ** 2 + (($l['y'] - $cy + 10) / 100) ** 2;   // mateixa el·lipse que prop_clariana()
        $cand[] = [$d, $l['id'], $i];
    }
    sort($cand);
    foreach (array_slice($cand, 0, CLAUSTRE_PROPERS) as [, , $i]) $llocs[$i]['grup'] = 'claustre';

    // Comprova els recomptes del paquet
    $rec = $m['recompte'] ?? [];
    $compta = function (callable $f) use ($llocs) {
        $n = ['Z0' => 0, 'Z1' => 0, 'Z2' => 0, 'Z3' => 0];
        foreach ($llocs as $l) if ($f($l)) $n[$l['zona']]++;
        $n['total'] = array_sum($n);
        return $n;
    };
    $esperat = [
        'cases_classe' => function ($l) { return $l['grup'] === 'poble'; },
        'cases_familia' => function ($l) { return $l['tipus'] === 'casa' && $l['grup'] !== 'poble'; },
        'elements' => function ($l) { return $l['tipus'] === 'element'; },
        'persones' => function ($l) { return $l['tipus'] === 'persona' && empty($l['pastor']); },
        'animals' => function ($l) { return $l['tipus'] === 'animal'; },
        'pastors' => function ($l) { return !empty($l['pastor']); },
    ];
    foreach ($esperat as $clau => $fn) {
        if (($rec[$clau] ?? null) !== $compta($fn)) falla("mapa.json: el recompte de $clau no quadra amb els llocs");
    }

    usort($llocs, function ($a, $b) { return strcmp($a['id'], $b['id']); });
    $grups = array_count_values(array_column($llocs, 'grup'));
    ksort($grups);
    return [
        'cap' => [
            'nota' => 'Generat per eines/converteix.php a partir de mapa.json del paquet v2. x, y = ancoratge. grup = qui pot reservar el lloc.',
            'versio' => (string)($m['versio'] ?? ''),
            'mida' => [2400, 800],
            'franges' => $franges,
            'naixement' => nums($m['naixement'] ?? null, 2, 'mapa.json naixement'),
            'clariana' => [$cx, $cy],
            'grups' => $grups,
        ],
        'llocs' => $llocs,
    ];
}

function valida_escena_fixa(array $e, array $registre): array
{
    $out = [];
    foreach ($e['peces'] ?? [] as $i => $p) {
        $on = "escena-fixa.json peces[$i]";
        $codi = $p['codi'] ?? '';
        if (!isset($registre[$codi])) falla("$on: peça desconeguda");
        $n = ['codi' => $codi, 'x' => $p['x'] ?? null, 'y' => $p['y'] ?? null];
        if (!es_num($n['x']) || !es_num($n['y'])) falla("$on: coordenades no vàlides");
        if (isset($p['mirall'])) {
            if (!is_bool($p['mirall'])) falla("$on: mirall no vàlid");
            if ($p['mirall']) $n['mirall'] = 1;
        }
        if (isset($p['ordre'])) {
            if (!es_num($p['ordre'])) falla("$on: ordre no vàlid");
            $n['ordre'] = $p['ordre'];
        }
        if (isset($p['colors'])) {
            if (!is_array($p['colors'])) falla("$on: colors no vàlids");
            foreach ($p['colors'] as $rol => $c) {
                if (!in_array($rol, $registre[$codi]['rols'], true) || !es_hex($c)) falla("$on: color $rol no vàlid");
            }
            $n['colors'] = $p['colors'];
        }
        $out[] = $n;
    }
    if (!$out) falla('escena-fixa.json: no hi ha peces');
    return $out;
}

/** Aplica disseny-propi/escena-fixa-canvis.json: troba cada peça per codi i x i n'hi canvia el codi o hi afegeix colors. */
function canvis_escena_fixa(array $fixa, string $font, array $registre): array
{
    $d = json_decode((string)file_get_contents($font), true);
    if (!is_array($d) || !is_array($d['canvis'] ?? null)) falla("$font: format no vàlid");
    foreach ($d['canvis'] as $i => $c) {
        $on = "escena-fixa-canvis.json canvis[$i]";
        $trobades = array_keys(array_filter($fixa, function ($p) use ($c) { return $p['codi'] === ($c['codi'] ?? '') && $p['x'] === ($c['x'] ?? null); }));
        if (count($trobades) !== 1) falla("$on: no es troba una sola peça {$c['codi']} a x={$c['x']}");
        $p = &$fixa[$trobades[0]];
        if (isset($c['nou_codi'])) {
            if (!isset($registre[$c['nou_codi']])) falla("$on: peça desconeguda");
            $p['codi'] = $c['nou_codi'];
        }
        foreach ($c['colors'] ?? [] as $rol => $color) {
            if (!in_array($rol, $registre[$p['codi']]['rols'], true) || !es_hex($color)) falla("$on: color $rol no vàlid");
            $p['colors'][$rol] = $color;
        }
        foreach (array_keys($p['colors'] ?? []) as $rol) {
            if (!in_array($rol, $registre[$p['codi']]['rols'], true)) falla("$on: la peça {$p['codi']} no té el rol $rol");
        }
        unset($p);
    }
    return $fixa;
}

/** Catmull-Rom (mateixa funció que suau() de terreny.py). */
function suau(array $pts, int $n): array
{
    $P = array_merge([$pts[0]], $pts, [end($pts)]);
    $out = [];
    for ($i = 1; $i < count($P) - 2; $i++) {
        [$p0, $p1, $p2, $p3] = [$P[$i - 1], $P[$i], $P[$i + 1], $P[$i + 2]];
        for ($j = 0; $j < $n; $j++) {
            $t = $j / $n;
            $pt = [];
            for ($c = 0; $c < 2; $c++) {
                $pt[] = .5 * ((2 * $p1[$c]) + (-$p0[$c] + $p2[$c]) * $t + (2 * $p0[$c] - 5 * $p1[$c] + 4 * $p2[$c] - $p3[$c]) * $t * $t
                        + (-$p0[$c] + 3 * $p1[$c] - 3 * $p2[$c] + $p3[$c]) * $t ** 3);
            }
            $out[] = $pt;
        }
    }
    $out[] = end($pts);
    return $out;
}

/** Recorregut dels Reis: polilínia del camí + distància (al llarg del camí) de cada rei el primer i el darrer dia. */
function cami_reis(string $font, array $fixa): array
{
    $d = json_decode((string)file_get_contents($font), true);
    if (!is_array($d) || !is_array($d['control'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['primer_dia'] ?? '')) falla("$font: format no vàlid");
    $pts = array_values(array_filter(suau($d['control'], 6), function ($p) { return $p[0] >= 0 && $p[0] <= 2400; }));
    $cami = [];
    $s = [0.0];
    foreach ($pts as $i => $p) {
        $cami[] = [round($p[0], 1), round($p[1], 1)];
        if ($i > 0) {
            if ($cami[$i][0] <= $cami[$i - 1][0]) falla("$font: el camí ha d'anar d'esquerra a dreta");
            $s[] = $s[$i - 1] + hypot($cami[$i][0] - $cami[$i - 1][0], $cami[$i][1] - $cami[$i - 1][1]);
        }
    }
    $s_a_x = function (float $x) use ($cami, $s): float {
        for ($i = 1; $i < count($cami); $i++) {
            if ($x <= $cami[$i][0]) {
                $t = ($x - $cami[$i - 1][0]) / ($cami[$i][0] - $cami[$i - 1][0]);
                return round($s[$i - 1] + $t * ($s[$i] - $s[$i - 1]), 1);
            }
        }
        return round(end($s), 1);
    };
    $inici = [];
    foreach ($fixa as $p) if (strncmp($p['codi'], 'ESC-REI-', 8) === 0) $inici[] = $s_a_x((float)$p['x']);
    if (count($inici) !== 3) falla('escena-fixa.json: s\'esperaven 3 Reis (ESC-REI-*)');
    return ['nota' => 'Generat per eines/converteix.php des de eines/reis-cami-font.json. inici = distància al llarg del camí de cada rei (ordre d\'escena-fixa) el primer dia; final = la del primer rei el darrer pas.',
            'primer_dia' => $d['primer_dia'], 'passos' => (int)$d['passos'], 'inici' => $inici, 'final' => $s_a_x((float)$d['final_x']), 'cami' => $cami];
}

/** @return array{0: string, 1: string[]} */
function moments_css(array $f): array
{
    $avisos = [];
    $json = llegeix_json($f, 'moments/moments.json');
    $out = '';
    foreach (MOMENTS as $mo) {
        $text = preg_replace('#/\*.*?\*/#s', '', cal($f, "moments/$mo.css"));
        if (!preg_match('/^\s*\.moment-' . $mo . '\s*\{([^{}]*)\}\s*$/s', $text, $mm)) falla("moments/$mo.css: format inesperat");
        $vars = [];
        foreach (array_filter(array_map('trim', explode(';', $mm[1]))) as $decl) {
            if (!preg_match('/^--([a-z0-9-]+)\s*:\s*(#[0-9A-Fa-f]{3,8}|-?[0-9.]+(px)?)$/', $decl, $d)) falla("moments/$mo.css: declaració no permesa");
            $vars[$d[1]] = $d[2];
        }
        $ref = array_map('strval', $json[$mo]['variables'] ?? []);
        if ($ref != $vars) $avisos[] = "moments/$mo.css i moments.json no coincideixen (es fa servir el .css).";
        $out .= ".moment-$mo {\n";
        foreach ($vars as $k => $v) $out .= "  --$k: $v;\n";
        $out .= "}\n";
    }
    return [$out, $avisos];
}

function escena_css(array $css, array $registre): string
{
    $o = "/* Generat per eines/converteix.php. No l'editis a mà. */\n/* Estils de les capes (al paquet eren atributs style, que la CSP no permet) */\n";
    ksort($css);
    foreach ($css as $sel => $decls) $o .= "$sel { " . implode('; ', $decls) . "; }\n";
    // Silueta fantasma (compon.estil_fantasma): tots els rols de cases i elements en clar.
    $rols = [];
    foreach ($registre as $p) if (in_array($p['cat'], ['casa', 'element'], true)) foreach ($p['rols'] as $r) $rols[$r] = true;
    ksort($rols);
    $o .= "/* Llocs lliures: silueta fantasma (cases opacitat .62, elements .5) */\n.fantasma {\n";
    foreach (array_keys($rols) as $r) {
        if ($r === 'contorn' || $r === 'ombra-terra') { $c = '#FFFFFF'; }
        else {
            $suf = '';
            foreach (['-clar', '-ombra', '-fosc', '-llum'] as $s) if (substr($r, -strlen($s)) === $s) { $suf = $s; break; }
            $c = FANTASMA[$suf] ?? '#FFFDF4';
        }
        $o .= "  --$r: $c;\n";
    }
    $o .= "}\n";
    foreach (OPACITAT_FANTASMA as $cat => $op) $o .= ".fantasma.$cat { opacity: $op; }\n";
    // Contorn de les cases lliures: només el rol contorn, en gris; la resta transparent.
    $rols_casa = [];
    foreach ($registre as $p) if ($p['cat'] === 'casa') foreach ($p['rols'] as $r) $rols_casa[$r] = true;
    ksort($rols_casa);
    $o .= "/* Cases lliures: contorn gris fi a sobre de la silueta */\n.fantasma-vora {\n";
    foreach (array_keys($rols_casa) as $r) $o .= "  --$r: " . ($r === 'contorn' ? VORA_FANTASMA['color'] : 'transparent') . ";\n";
    $o .= "  opacity: " . VORA_FANTASMA['opacitat'] . ";\n}\n";
    // Paviment del casc antic: el visor en baixa la intensitat mentre falten cases de classe (--casc-op).
    $o .= "#casc-paviment { opacity: var(--casc-op, 1); transition: opacity .8s; }\n";
    return $o;
}

// ───────────────────────────── sortida JSON llegible (una entrada per línia)

function json_llista_per_linies(array $cap, string $clau, array $llista): string
{
    $o = "{\n";
    foreach ($cap as $k => $v) $o .= ' ' . json_encode($k) . ': ' . json_encode($v, JSON_F) . ",\n";
    $linies = array_map(function ($e) { return '  ' . json_encode($e, JSON_F); }, $llista);
    return $o . ' ' . json_encode($clau) . ": [\n" . implode(",\n", $linies) . "\n ]\n}\n";
}

function json_objecte_per_linies(array $cap, string $clau, array $obj): string
{
    $o = "{\n";
    foreach ($cap as $k => $v) $o .= ' ' . json_encode($k) . ': ' . json_encode($v, JSON_F) . ",\n";
    $linies = [];
    foreach ($obj as $k => $v) $linies[] = '  ' . json_encode((string)$k) . ': ' . json_encode($v, JSON_F);
    return $o . ' ' . json_encode($clau) . ": {\n" . implode(",\n", $linies) . "\n }\n}\n";
}
