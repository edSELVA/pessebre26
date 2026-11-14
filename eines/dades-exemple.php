<?php
// Genera snapshots d'EXEMPLE (dades inventades) a public_html/exemples/ i copia el de la nit a public_html/snapshot.json.
// Ús:  php eines/dades-exemple.php
// Els noms de placa són inventats a propòsit: la llista real de classes no va mai al repositori.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }      // eina de terminal: mai des del web
require __DIR__ . '/../pessebre/src/snapshot.php';

$arrel = dirname(__DIR__);
$d = "$arrel/public_html/disseny";
$mapa = json_decode(file_get_contents("$d/mapa.json"), true);
$peces = json_decode(file_get_contents("$d/peces.json"), true)['peces'];
$paletes = json_decode(file_get_contents("$d/paletes.json"), true);

const NOMS_INVENTATS = ['3r ESO B', 'Estels', 'Pins', 'Roure', 'Riu', 'Cabres', 'Mussols', 'Guineus', 'Esquirol', 'Cargols', 'Abelles', 'Grills', 'Pomes', 'Nespres', 'Codonys', 'Figues', 'Glans', 'Bolets', 'Molses', 'Falguera', 'Ginesta', 'Romaní', 'Farigola', 'Espígol', 'Óssos', 'Llops', 'Daines', 'Isards', 'Senglars', 'Teixons', 'Llúdriga', 'Garses', 'Merles', 'Pardals', 'Orenetes', 'Gavines', 'Fars', 'Velers', 'Barques', 'Rems', 'Àncores', 'Petxines', 'Corals', 'Algues', 'Dofins', 'Balenes', 'Tortuga', 'Crancs', 'Núvols', 'Llamps', 'Trons', 'Boira', 'Rosada', 'Gebre', 'Pedra', 'Arc', 'Ventada', 'Brisa', 'Ponentol', 'Garbí', 'Llebeig', 'Xaloc', 'Mestral', 'Gregal', 'Ponent', 'Llevant', 'Migjorn', 'Cometes', 'Planetes', 'Galàxies', 'Llumins', 'Espurnes', 'Fanals', 'Ciris'];

$persones = array_keys(array_filter($peces, function ($p) { return $p['cat'] === 'persona'; }));   // PER-PAS-F també: tothom la pot triar
$animals = array_keys(array_filter($peces, function ($p) { return $p['cat'] === 'animal'; }));

function tria(array $a) { return $a[mt_rand(0, count($a) - 1)]; }

function ocupacio(array $l, array $peces, array $paletes, array $persones, array $animals, string $origen): array
{
    if ($l['tipus'] === 'casa') {
        $p = $origen === 'admin' ? preg_replace('/-P$/', '-SP', $l['codi']) : $l['codi'];
    } elseif ($l['tipus'] === 'element') {
        $alt = $peces[$l['codi']]['alternatives'] ?? [$l['codi']];
        $p = mt_rand(0, 3) === 0 ? tria($alt) : $l['codi'];      // algunes famílies canvien l'espècie de l'arbre
    } elseif ($l['tipus'] === 'animal') {
        $p = tria($animals);
    } else {
        $p = !empty($l['pastor']) ? tria(['PER-PAS-F', 'PER-PAS-F', 'PER-ADU-BARBA', 'PER-ADU-F']) : tria($persones);
    }
    $o = ['p' => $p];
    if (in_array($l['tipus'], ['persona', 'animal'], true)) $o['m'] = mt_rand(0, 1);
    $pers = $peces[$p]['pers'];
    if ($pers) {
        $grup = ['casa' => 'cases', 'animal' => 'animals', 'element' => 'elements'][$l['tipus']] ?? (!empty($l['pastor']) ? 'pastors' : 'persones');
        $o['c'] = array_map(function ($rol) use ($paletes, $grup) { return mt_rand(0, count(($paletes[$grup][$rol] ?? $paletes['persones'][$rol])['opcions']) - 1); }, $pers);
    }
    return $o;
}

$escenaris = [
    // nom => [data, moment, zones, classes obert, claustre obert, cases de classe, cases admin, claustre, fracció de famílies]
    'inici'   => ['2026-12-01', 'dia',   ['Z0'],                   true,  false, 0,  0, 0,  0],
    'classes' => ['2026-12-18', 'dia',   ['Z0'],                   true,  false, 52, 0, 0,  0],
    'posta'   => ['2026-12-23', 'posta', ['Z0', 'Z1'],             false, false, 71, 2, 44, .35],
    'nit'     => ['2026-12-28', 'nit',   ['Z0', 'Z1', 'Z2'],       false, false, 71, 2, 44, .55],
    'neu'     => ['2027-01-03', 'neu',   ['Z0', 'Z1', 'Z2', 'Z3'], false, false, 71, 2, 44, .7],
];

@mkdir("$arrel/public_html/exemples", 0755, true);
foreach ($escenaris as $nom => [$data, $moment, $zones, $classes, $claustre, $n_classes, $n_admin, $n_claustre, $frac]) {
    mt_srand(2026);   // mateixa llavor a cada escenari: les ocupacions creixen d'un escenari al següent
    $o = [];
    $poble = array_values(array_filter($mapa['llocs'], function ($l) { return $l['grup'] === 'poble'; }));
    shuffle($poble);
    foreach (array_slice($poble, 0, $n_classes) as $i => $l) {
        $o[$l['id']] = ocupacio($l, $peces, $paletes, $persones, $animals, 'classe') + ['n' => NOMS_INVENTATS[$i]];
        $nf = count($peces[$l['codi']]['finestres']);
        if ($nf && mt_rand(0, 2) === 0) $o[$l['id']]['f'] = mt_rand(1, (1 << $nf) - 1);   // algunes classes treuen finestres
    }
    foreach (array_slice($poble, $n_classes, $n_admin) as $l) $o[$l['id']] = ocupacio($l, $peces, $paletes, $persones, $animals, 'admin');
    $claus = array_values(array_filter($mapa['llocs'], function ($l) { return $l['grup'] === 'claustre'; }));
    usort($claus, function ($a, $b) { return empty($b['pastor']) <=> empty($a['pastor']) ?: strcmp($a['id'], $b['id']); });
    foreach (array_slice($claus, 0, $n_claustre) as $l) $o[$l['id']] = ocupacio($l, $peces, $paletes, $persones, $animals, 'claustre');
    foreach ($mapa['llocs'] as $l) {
        $r = mt_rand() / mt_getrandmax();   // es crida sempre perquè la seqüència sigui estable
        if ($l['grup'] === 'general' && in_array($l['zona'], $zones, true) && $r < $frac) {
            $o[$l['id']] = ocupacio($l, $peces, $paletes, $persones, $animals, 'familia');
        }
    }
    // Uns quants llocs retinguts (algú està triant)
    $lliures = array_values(array_filter($mapa['llocs'], function ($l) use ($o, $zones, $classes) {
        return !isset($o[$l['id']]) && in_array($l['zona'], $zones, true) && ($l['grup'] === 'general' || ($classes && $l['grup'] === 'poble'));
    }));
    $ret = [];
    for ($i = 0; $i < 3 && $lliures; $i++) $ret[] = $lliures[mt_rand(0, count($lliures) - 1)]['id'];
    $ret = array_values(array_unique($ret));
    sort($ret);
    ksort($o);

    $reals = count($o);
    $comptador = $classes
        ? ['tipus' => 'classes', 'fet' => $n_classes, 'total' => 74]
        : ['tipus' => 'participacions', 'fet' => $reals, 'fita' => $moment === 'neu' ? null : (int)(ceil(($reals + 1) / 100) * 100)];
    $s = ['versio' => 1, 'generat' => $data . 'T17:00:00Z', 'data' => $data, 'moment' => $moment, 'zones' => $zones,
          'classes' => $classes, 'claustre' => $claustre, 'claustre_alliberat' => $data >= '2026-12-22', 'tancat' => false, 'comptador' => $comptador, 'o' => (object)$o, 'r' => $ret];
    $errors = snapshot_errors(json_decode(json_encode($s), true), $mapa, $peces, $paletes);
    if ($errors) { fwrite(STDERR, "$nom: " . implode('; ', $errors) . "\n"); exit(1); }
    snapshot_escriu("$arrel/public_html/exemples/$nom.json", $s);
    printf("  %-8s %s  %-5s  %3d ocupats  %5.1f KB\n", $nom, $data, $moment, $reals, filesize("$arrel/public_html/exemples/$nom.json") / 1024);
}
copy("$arrel/public_html/exemples/nit.json", "$arrel/public_html/snapshot.json");
echo "Fet. public_html/snapshot.json = exemple «nit» (no es puja al repositori).\n";
