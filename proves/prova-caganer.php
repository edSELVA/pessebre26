<?php
// Joc del caganer: els amagatalls (disseny/caganer.json) han de ser vàlids per a cada dia del 21 de desembre al 6 de gener:
// a terra, sense trepitjar cap lloc on es pugui posar una figura ni cap peça fixa, lluny del camí dels Reis, i repartits.
$d = function (string $nom) use ($ARREL) { return json_decode((string)file_get_contents("$ARREL/public_html/disseny/$nom"), true); };
$cg = $d('caganer.json');
$peces = $d('peces.json')['peces'];
$mapa = $d('mapa.json')['llocs'];
$fixa = $d('escena-fixa.json')['peces'];
$reis = $d('reis-cami.json');

igual('ESC-CAGANER', $cg['peca'], 'la peça del caganer');
comprova(isset($peces['ESC-CAGANER']), 'el caganer és al sprite de peces');
igual('2026-12-21', $cg['primer_dia'], 'el joc comença el 21 de desembre');
$dies = (int)((strtotime('2027-01-06') - strtotime('2026-12-21')) / 86400) + 1;
igual($dies, count($cg['llocs']), "un amagatall per a cadascun dels $dies dies (21 des – 6 gen)");
igual(count($cg['llocs']), count(array_unique(array_map('json_encode', $cg['llocs']))), 'cap amagatall repetit');

$caixa = function (array $p, int $x, int $y): array {
    return [$x - $p['a'][0] + $p['caixa'][0], $y - $p['a'][1] + $p['caixa'][1], $x - $p['a'][0] + $p['caixa'][2], $y - $p['a'][1] + $p['caixa'][3]];
};
$xoca = function (array $a, array $b, int $m = 0): bool { return $a[0] < $b[2] + $m && $a[2] > $b[0] - $m && $a[1] < $b[3] + $m && $a[3] > $b[1] - $m; };
$substitut = ['persona' => 'PER-ADU-F', 'animal' => 'ANI-OVELLA-D'];
$problemes = [];
foreach ($cg['llocs'] as $i => [$x, $y]) {
    $c = $caixa($peces['ESC-CAGANER'], $x, $y);
    if ($y < 280 || $y > 790 || $x < 10 || $x > 2390) $problemes[] = "$i: fora del terra";
    foreach ($mapa as $l) {
        $b = $caixa($peces[$l['codi'] ?? $substitut[$l['tipus']]], $l['x'], $l['y']);
        if (!$xoca($c, $b)) continue;
        // Només es pot tocar amb un arbre que li quedi al davant (així l'amaga una mica)
        $davant = $l['tipus'] === 'element' && abs($l['x'] - $x) < 10 && $l['y'] - $y >= 3 && $l['y'] - $y <= 14;
        if (!$davant) $problemes[] = "$i: trepitja el lloc {$l['id']}";
    }
    foreach ($fixa as $f) if (strpos($f['codi'], 'ESC-REI-') !== 0 && $xoca($c, $caixa($peces[$f['codi']], $f['x'], $f['y']))) $problemes[] = "$i: trepitja {$f['codi']}";
    foreach ($reis['cami'] as $p) if (hypot($p[0] - $x, $p[1] - $y) < 30) { $problemes[] = "$i: massa a prop del camí dels Reis"; break; }
}
igual([], $problemes, 'cap amagatall trepitja llocs, peces fixes ni el camí dels Reis');
$a_prop = 0;
for ($i = 0; $i < count($cg['llocs']); $i++) {
    [$x1, $y1] = $cg['llocs'][$i];
    [$x2, $y2] = $cg['llocs'][($i + 1) % count($cg['llocs'])];
    if (hypot($x2 - $x1, $y2 - $y1) < 300) $a_prop++;
}
igual(0, $a_prop, 'cada dia s\'amaga lluny d\'on era el dia abans');
$costats = array_map(function ($l) { return $l[0] < 1200 ? 'e' : 'd'; }, $cg['llocs']);
comprova(count(array_filter($costats, function ($c) { return $c === 'e'; })) >= 6 && count(array_filter($costats, function ($c) { return $c === 'd'; })) >= 6, 'repartits a banda i banda del pessebre');
