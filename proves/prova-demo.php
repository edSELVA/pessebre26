<?php
// Mode demostració (demo/): les sis fases es munten bé, el rellotge simulat, els rols, el temps i els reinicis.
// I sobretot: res de la demo és dins de public_html/ ni de pessebre/ (el que es puja a l'hosting).
require_once $ARREL . '/demo/demo.php';

$tmp = sys_get_temp_dir() . '/pessebre-demo-' . getmypid();
@mkdir($tmp, 0700, true);
$cfg = ['demo' => true, 'bd_tipus' => 'sqlite', 'bd_sqlite' => "$tmp/demo.sqlite", 'pebre' => bin2hex(random_bytes(32)), 'llavor' => 'llavor-de-prova',
        'web' => "$ARREL/public_html", 'snapshot' => "$tmp/snapshot.json", 'bloqueig' => "$tmp/escriptura.lock", 'demo_estat' => "$tmp/estat.json",
        'data_simulada' => null, 'url_base' => 'http://localhost:8027/'];
$pdo = bd_connecta($cfg);
$snap = function () use ($cfg) { return json_decode((string)file_get_contents($cfg['snapshot']), true); };
$origens = function () use ($pdo) { return $pdo->query('SELECT origen, COUNT(*) FROM ocupacions GROUP BY origen')->fetchAll(PDO::FETCH_KEY_PAIR); };
$rols = function (array $r) { return array_column($r['rols'], 'rol'); };
$mapa = disseny($cfg, 'mapa.json');
$valid = function () use ($cfg, $snap, $mapa) { return snapshot_errors($snap(), $mapa, disseny($cfg, 'peces.json')['peces'], disseny($cfg, 'paletes.json')); };

$esperat = [
    1 => ['dia', 0, ['public', 'tutor', 'admin']],
    2 => ['dia', 34, ['public', 'tutor', 'admin']],
    3 => ['posta', 58 + 28 + 8, ['public', 'tutor', 'claustre', 'familia', 'admin']],
    4 => ['posta', 65 + 44 + 70, ['public', 'familia', 'admin']],
    5 => ['nit', 65 + 44 + 82, ['public', 'familia', 'admin']],
    6 => ['neu', 65 + 44 + 107, ['public', 'admin']],
];
foreach ($esperat as $fase => [$moment, $reals, $r]) {
    $e = demo_munta($pdo, $cfg, $fase);
    $s = $snap();
    $res = demo_resum($pdo, $cfg, $e);
    igual($moment, $s['moment'], "fase $fase: moment «{$moment}»");
    $o = $origens();
    igual($reals, ($o['classe'] ?? 0) + ($o['claustre'] ?? 0) + ($o['familia'] ?? 0), "fase $fase: $reals participacions reals");
    igual($r, $rols($res), "fase $fase: rols " . implode(', ', $r));
    igual([], $valid(), "fase $fase: el snapshot és vàlid");
    comprova(abs(demo_ara($e)->getTimestamp() - demo_utc(DEMO_FASES[$fase]['fins'])->getTimestamp()) < 5, "fase $fase: el rellotge de la demo és a " . DEMO_FASES[$fase]['fins']);
}

// Mateixa fase, mateix resultat (atzar amb llavor fixa)
demo_munta($pdo, $cfg, 3);
$a = md5(json_encode($snap()['o']));
demo_munta($pdo, $cfg, 3);
igual($a, md5(json_encode($snap()['o'])), 'muntar dues vegades la mateixa fase dona el mateix pessebre');

// Fites: posta per participació (classes) i nit i neu per data, amb automàtiques
$e = demo_munta($pdo, $cfg, 5);
igual(['llindar', 'data'], $pdo->query('SELECT per FROM fites WHERE numero IN (1, 2) ORDER BY numero')->fetchAll(PDO::FETCH_COLUMN), 'fase 5: posta per participació i nit per data');
igual(2 * AUTO_PER_HORA, $origens()['auto'] ?? 0, 'fase 5: les automàtiques acaben de començar (dues hores)');
$comptador = $snap()['comptador'];

// Deixar passar el temps
$e = demo_temps($pdo, $cfg, $e, 1);
igual(3 * AUTO_PER_HORA, $origens()['auto'] ?? 0, '+1 hora: ' . AUTO_PER_HORA . ' automàtiques més');
$e = demo_temps($pdo, $cfg, $e, 24);
comprova(($origens()['auto'] ?? 0) > 3 * AUTO_PER_HORA, '+1 dia: continuen les automàtiques');
igual($comptador, $snap()['comptador'], 'les automàtiques no canvien el comptador');
igual('2026-12-28 02:30', demo_ara($e)->setTimezone(new DateTimeZone('Europe/Madrid'))->format('Y-m-d H:i'), 'el rellotge ha avançat 25 hores');
igual(5, demo_estat($cfg)['fase'], 'deixar passar el temps no canvia de fase');
$e['desfasament'] += 86400;                    // la demo s'engega l'endemà…
$e = demo_rellotge_a_lloc($cfg, $e);
igual('2026-12-28 02:30', demo_ara($e)->setTimezone(new DateTimeZone('Europe/Madrid'))->format('Y-m-d H:i'), 'en engegar, el rellotge torna a l\'hora de la fase més les hores dels botons');
llança(function () use ($pdo, $cfg, $e) { demo_temps($pdo, $cfg, $e, 0); }, 'no es pot deixar passar zero hores');

// La família del panell pot participar amb l'hora simulada (l'API real amb el rellotge de la demo)
$res = demo_resum($pdo, $cfg, $e);
$url = array_values(array_filter($res['rols'], function ($x) { return $x['rol'] === 'familia'; }))[0]['url'];
$codi = substr($url, strpos($url, '#c=') + 3);
[$estat, $r] = app_executa($pdo, $cfg, ['a' => 'codi.validar', 'c' => $codi], '127.0.0.1', demo_ara($e));
igual([200, true], [$estat, $r['pot'] ?? null], 'el codi del rol «Família» es pot fer servir');
$f = app_executa($pdo, $cfg, ['a' => 'codi.validar', 'c' => $codi], '127.0.0.1', new DateTimeImmutable('2026-11-12', new DateTimeZone('UTC')))[1];
igual(false, $f['pot'], 'amb l\'hora real (novembre) les famílies encara no podrien participar: la demo fa servir l\'hora simulada');

// Reiniciar famílies: fora les seves figures i els codis tornen a zero; la resta es manté
$abans = $origens();
demo_reinicia_families($pdo, $cfg, $e);
$despres = $origens();
igual(0, $despres['familia'] ?? 0, 'reiniciar famílies treu totes les seves figures');
igual([$abans['classe'], $abans['claustre'], $abans['auto']], [$despres['classe'], $despres['claustre'], $despres['auto']], 'reiniciar famílies no toca classes, claustre ni automàtiques');
igual(0, (int)$pdo->query('SELECT COUNT(*) FROM codis WHERE usos > 0')->fetchColumn(), 'reiniciar famílies torna tots els codis a zero');

// Accions del panell
igual(1, demo_accio($pdo, $cfg, 'demo.fase', ['fase' => 1])['fase'], '«Reiniciar-ho tot» torna a la fase 1');
igual([], $origens(), 'a la fase 1 no hi ha cap figura');
llança(function () use ($pdo, $cfg) { demo_accio($pdo, $cfg, 'demo.fase', ['fase' => 9]); }, 'una fase que no existeix dona error');
llança(function () use ($pdo, $cfg) { demo_accio($pdo, $cfg, 'demo.res', []); }, 'una acció de demo desconeguda dona error');

// Res de la demo a l'hosting: public_html/ i pessebre/ no en saben res
$fitxers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$ARREL/public_html", FilesystemIterator::SKIP_DOTS));
$amb_demo = [];
foreach ($fitxers as $fitxer) {
    if (!preg_match('/\.(php|html|js|css|htaccess)$/', $fitxer->getFilename())) continue;
    if (preg_match('/demo[\/._-]|demo_|demo\.html/i', (string)file_get_contents($fitxer->getPathname()))) $amb_demo[] = $fitxer->getFilename();
}
foreach (glob("$ARREL/pessebre/src/*.php") as $f) if (preg_match('/demo/i', (string)file_get_contents($f))) $amb_demo[] = basename($f);
igual([], $amb_demo, 'cap fitxer de public_html/ ni de pessebre/src/ fa servir la demo');
comprova(strpos((string)file_get_contents("$ARREL/demo/api.php"), "(\$cfg['demo'] ?? false) !== true") !== false, 'l\'API de la demo només funciona amb \'demo\' => true a la configuració');
comprova(strpos((string)file_get_contents("$ARREL/pessebre/config.exemple.php"), "'demo'") === false, 'la configuració d\'exemple (la de l\'hosting) no té l\'opció demo');

array_map('unlink', glob("$tmp/*"));
rmdir($tmp);
