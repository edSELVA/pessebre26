<?php
// Els snapshots d'exemple són vàlids i el validador detecta els errors típics.
require_once $ARREL . '/pessebre/src/snapshot.php';
$d = "$ARREL/public_html/disseny";
$mapa = json_de("$d/mapa.json");
$peces = json_de("$d/peces.json")['peces'];
$paletes = json_de("$d/paletes.json");

$exemples = glob("$ARREL/public_html/exemples/*.json");
igual(5, count($exemples), 'hi ha 5 snapshots d\'exemple');
foreach ($exemples as $f) {
    $s = json_de($f);
    $e = snapshot_errors($s, $mapa, $peces, $paletes);
    comprova(!$e, basename($f) . ' és vàlid' . ($e ? ': ' . implode('; ', array_slice($e, 0, 3)) : ''));
    comprova(filesize($f) < 100 * 1024, basename($f) . ' fa menys de 100 KB');
    comprova(!preg_match('/#[0-9A-Fa-f]{6}/', (string)file_get_contents($f)), basename($f) . ' no té colors en cru');
}

$base = json_de("$ARREL/public_html/exemples/posta.json");
$casa = null; $persona = null;
foreach ($base['o'] as $id => $o) {
    if (!$casa && isset($o['n'])) $casa = $id;
    if (!$persona && strncmp($o['p'], 'PER-', 4) === 0) $persona = $id;
}
$errada = function (callable $canvi, string $que) use ($base, $mapa, $peces, $paletes) {
    $s = $base;
    $canvi($s);
    comprova((bool)snapshot_errors($s, $mapa, $peces, $paletes), "detecta: $que");
};
$errada(function (&$s) { $s['o']['X999'] = ['p' => 'ANI-GOS-D']; }, 'lloc desconegut');
$errada(function (&$s) use ($persona) { $s['o'][$persona]['p'] = 'ANI-GOS-D'; }, 'peça d\'una altra categoria');
$errada(function (&$s) use ($persona) { $s['o'][$persona]['c'] = [99, 0]; }, 'índex de color fora de la paleta');
$errada(function (&$s) use ($persona) { $s['o'][$persona]['c'] = ['#FF0000', 0]; }, 'color en cru');
$errada(function (&$s) use ($casa) { $s['o'][$casa]['p'] = 'CASA-ALTA-P'; }, 'casa diferent de la del mapa');
$errada(function (&$s) use ($casa) { $s['o'][$casa]['n'] = '<b>x</b>'; }, 'nom de placa amb caràcters no permesos');
$errada(function (&$s) use ($casa) { $s['o'][$casa]['n'] = '3r ESO BC'; }, 'nom de placa de més de 8 caràcters');
$errada(function (&$s) use ($persona) { $s['o'][$persona]['n'] = 'Nom'; }, 'nom en una persona');
$errada(function (&$s) use ($casa) { $s['r'][] = $casa; }, 'lloc retingut i ocupat alhora');
$errada(function (&$s) { $s['moment'] = 'tarda'; }, 'moment desconegut');
$errada(function (&$s) { $s['zones'] = ['Z1']; }, 'Z0 tancada');
$errada(function (&$s) { $s['comptador']['data_fita'] = '2026-12-27'; }, 'dates de fites al comptador');
$errada(function (&$s) { $s['codis'] = []; }, 'camps desconeguts');

// Escriptura atòmica
$tmp = sys_get_temp_dir() . '/pessebre-prova-' . getmypid() . '.json';
snapshot_escriu($tmp, $base);
igual($base, json_de($tmp), 'snapshot_escriu escriu el que es llegeix');
comprova(!file_exists("$tmp.tmp"), 'no queda cap .tmp');
unlink($tmp);
