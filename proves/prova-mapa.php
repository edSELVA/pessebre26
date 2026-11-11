<?php
// El mapa convertit té els llocs, les zones i els grups que diu CLAUDE.md, i el camí dels Reis és vàlid.
$d = "$ARREL/public_html/disseny";
$mapa = json_de("$d/mapa.json");
$peces = json_de("$d/peces.json")['peces'];
$llocs = $mapa['llocs'];

igual(998, count($llocs), '74 + 906 + 18 llocs');
igual(['claustre' => 50, 'general' => 874, 'poble' => 74], $mapa['grups'], 'grups');
$compta = function (callable $f) use ($llocs) { return count(array_filter($llocs, $f)); };
igual(74, $compta(function ($l) { return $l['grup'] === 'poble' && $l['tipus'] === 'casa' && $l['zona'] === 'Z0'; }), '74 cases de classe, totes a Z0');
igual(18, $compta(function ($l) { return !empty($l['pastor']) && $l['grup'] === 'claustre'; }), '18 pastors al grup del claustre');
igual(0, $compta(function ($l) { return $l['grup'] === 'claustre' && $l['tipus'] === 'casa'; }), 'cap casa al grup del claustre');
igual(96, $compta(function ($l) { return $l['tipus'] === 'casa' && $l['grup'] === 'general'; }), '96 cases de família');
igual(270, $compta(function ($l) { return $l['tipus'] === 'element'; }), '270 elements');
igual(499, $compta(function ($l) { return $l['tipus'] === 'persona'; }), '481 persones + 18 pastors');
igual(59, $compta(function ($l) { return $l['tipus'] === 'animal'; }), '59 animals');

$ids = array_column($llocs, 'id');
igual(count($ids), count(array_unique($ids)), 'ids únics');
foreach ($llocs as $l) {
    if (isset($l['codi'])) comprova(($peces[$l['codi']]['cat'] ?? '') === $l['tipus'], "{$l['id']}: la peça és de la categoria del lloc");
    comprova($l['x'] >= 0 && $l['x'] <= 2400 && $l['y'] >= 0 && $l['y'] <= 800, "{$l['id']}: dins l'escena");
}
foreach ($llocs as $l) if ($l['grup'] === 'poble') comprova(substr($l['codi'], -2) === '-P', "{$l['id']}: casa de classe amb placa");

// Reis
$r = json_de("$d/reis-cami.json");
igual(15, $r['passos'], '15 passos');
igual('2026-12-21', $r['primer_dia'], 'comencen el 21 de desembre');
igual(3, count($r['inici']), 'tres Reis');
comprova($r['final'] > max($r['inici']), 'avancen cap a la dreta');
$x = array_column($r['cami'], 0);
$ordenat = $x;
sort($ordenat);
comprova($x === $ordenat && $x[0] >= 0 && end($x) <= 2400, 'el camí va d\'esquerra a dreta dins l\'escena');
comprova(abs($r['final'] - $mapa['naixement'][0]) < 120, 'el darrer dia arriben a prop del naixement');

// Escena fixa
$fixa = json_de("$d/escena-fixa.json")['peces'];
foreach ($fixa as $f) comprova(isset($peces[$f['codi']]), "escena fixa: {$f['codi']} existeix");
