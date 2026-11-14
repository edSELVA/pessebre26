<?php
// §22: l'administració afegeix figures (compten com a participació real) i veu el ritme de participació cap a la propera fita.
require_once $ARREL . '/pessebre/src/app.php';

$prova_ritme = function (array $cfg, string $nom) {
    echo "    ($nom)\n";
    $pdo = bd_connecta($cfg);
    foreach (['fites', 'intents', 'config', 'registre', 'ocupacions', 'enllacos', 'classes', 'codis', 'llocs', 'usuaris'] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
    app_installa($pdo, $cfg);
    usuari_desa($pdo, 'anna', 'admin-de-prova-1234', 'admin', new DateTimeImmutable('2026-12-01', new DateTimeZone('UTC')), 4);
    usuari_desa($pdo, 'lluc', 'lectura-de-prova-1234', 'lectura', new DateTimeImmutable('2026-12-01', new DateTimeZone('UTC')), 4);
    $mapa = disseny($cfg, 'mapa.json');
    $utc = function (string $h) { return new DateTimeImmutable($h, new DateTimeZone('UTC')); };
    $crida = function (array $p, string $hora, string $clau = 'admin-de-prova-1234') use ($pdo, $cfg, $utc) {
        return app_executa($pdo, $cfg, $p + ['usuari' => $clau === 'lectura-de-prova-1234' ? 'lluc' : 'anna', 'contrasenya' => $clau], '10.4.0.1', $utc($hora));
    };
    $snap = function () use ($cfg) { return json_decode((string)file_get_contents($cfg['snapshot']), true); };
    $lliure = function (callable $f) use ($pdo, $mapa) {
        $ocupats = $pdo->query('SELECT lloc_id FROM ocupacions')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($mapa['llocs'] as $l) if ($f($l) && !in_array($l['id'], $ocupats, true)) return $l;
        return null;
    };
    $posa = function (int $n, string $origen, string $grup, string $hora) use ($pdo, $mapa, $lliure) {
        for ($i = 0; $i < $n; $i++) {
            $l = $lliure(function ($l) use ($grup) { return $l['grup'] === $grup && $l['zona'] === 'Z0' && $l['tipus'] !== 'casa'; });
            $classe = null;
            if ($origen === 'classe') {
                $l = $lliure(function ($l) { return $l['grup'] === 'poble'; });
                $pdo->prepare('INSERT INTO classes (etapa, nom, ordre) VALUES (?, ?, ?)')->execute(['primaria', 'C' . $l['id'], $i]);
                $classe = (int)$pdo->lastInsertId();
            }
            $pdo->prepare("INSERT INTO ocupacions (lloc_id, estat, origen, classe_id, peca, mirall, hora) VALUES (?, 'confirmat', ?, ?, ?, 0, ?)")
                ->execute([$l['id'], $origen, $classe, $l['codi'] ?? ($l['tipus'] === 'animal' ? 'ANI-OVELLA-D' : 'PER-ADU-F'), $hora]);
        }
    };
    $cols = count(disseny($cfg, 'peces.json')['peces']['PER-ADU-F']['pers']);
    $persona = function (string $id) use ($cols) { return ['a' => 'admin.afegir', 'lloc' => $id, 'peca' => 'PER-ADU-F', 'mirall' => 1, 'colors' => array_fill(0, $cols, 1)]; };

    // Posta per participació el 18 des (52 cases de classe): el llindar de la nit queda a 52 + 150
    $posa(52, 'classe', 'poble', '2026-12-18 09:00:00');
    $crida(['a' => 'tick'], '2026-12-18 10:00:00');
    igual(202, (int)$pdo->query('SELECT llindar FROM fites WHERE numero = 2')->fetchColumn(), 'llindar de la nit: 202');

    // Abans que s'obrin les famílies el tram encara no ha començat
    $r = fites_ritme($pdo, $cfg, $utc('2026-12-20 10:00:00'));
    igual([2, true, '2026-12-21 00:00'], [$r['fita'], $r['abans'], $r['inici']], 'el tram de la nit comença quan s\'obren les famílies');

    // Afegir una figura des de l'administració
    $l = $lliure(function ($l) { return $l['grup'] === 'general' && $l['zona'] === 'Z0' && $l['tipus'] === 'persona'; });
    [$e, $res] = $crida($persona($l['id']), '2026-12-22 10:00:00');
    igual([200, $l['id']], [$e, $res['lloc'] ?? null], 'l\'administració afegeix una figura en un lloc lliure de les famílies');
    igual('admin', $pdo->query("SELECT origen FROM ocupacions WHERE lloc_id = '{$l['id']}'")->fetchColumn(), 'es desa com a figura de l\'administració');
    igual(53, participacio_real($pdo)['reals'], 'compta com a participació real');
    igual(53, $snap()['comptador']['fet'], 'i surt al comptador públic');
    igual('admin.afegeix', $pdo->query("SELECT accio FROM registre ORDER BY id DESC LIMIT 1")->fetchColumn(), 'queda al registre');

    // On no es pot
    igual(409, $crida($persona($l['id']), '2026-12-22 10:05:00')[0], 'no en un lloc ocupat');
    $casa = $lliure(function ($l) { return $l['grup'] === 'poble'; });
    igual(400, $crida(['a' => 'admin.afegir', 'lloc' => $casa['id'], 'peca' => $casa['codi'], 'colors' => [0, 0, 0, 0]], '2026-12-22 10:05:00')[0], 'no en una casa de classe (per a això hi ha la casa sense placa)');
    $z1 = $lliure(function ($l) { return $l['grup'] === 'general' && $l['zona'] === 'Z1' && $l['tipus'] === 'persona'; });
    igual([400, 'zona'], [$crida($persona($z1['id']), '2026-12-22 10:05:00')[0], $crida($persona($z1['id']), '2026-12-22 10:05:00')[1]['codi'] ?? ''], 'no en una zona tancada');
    $pastor = $lliure(function ($l) { return !empty($l['pastor']); });
    igual(400, $crida($persona($pastor['id']), '2026-12-21 18:00:00')[0], 'no en un lloc del claustre abans de les 22:00 del 21 des');
    igual(200, $crida($persona($pastor['id']), '2026-12-22 10:10:00')[0], 'sí en un lloc del claustre que ha quedat lliure');
    $l2 = $lliure(function ($l) { return $l['grup'] === 'general' && $l['zona'] === 'Z0' && $l['tipus'] === 'persona'; });
    igual(400, $crida(['a' => 'admin.afegir', 'lloc' => $l2['id'], 'peca' => 'ANI-OVELLA-D'], '2026-12-22 10:10:00')[0], 'la peça ha de ser de la categoria del lloc');
    igual(403, $crida($persona($l2['id']), '2026-12-22 10:10:00', 'lectura-de-prova-1234')[0], 'amb la contrasenya de lectura no es pot');
    igual(403, $crida($persona($l2['id']), '2027-01-06 10:00:00')[0], 'després de Reis ja no es pot');
    igual(54, participacio_real($pdo)['reals'], 'només han entrat les dues figures permeses');

    // La casa sense placa (lloc de classe) no compta
    $sp = preg_replace('/-P$/', '-SP', $casa['codi']);
    igual(200, $crida(['a' => 'admin.casa_sense_placa', 'lloc' => $casa['id'], 'peca' => $sp, 'colors' => array_fill(0, count(disseny($cfg, 'peces.json')['peces'][$sp]['pers']), 0)], '2026-12-22 10:15:00')[0], 'casa sense placa');
    igual(54, participacio_real($pdo)['reals'], 'la casa sense placa no compta com a participació');

    // Ritme a mig tram (24 des 00:00 de Barcelona: la meitat entre el 21 i el 27)
    $posa(8, 'familia', 'general', '2026-12-22 11:00:00');
    $r = fites_ritme($pdo, $cfg, $utc('2026-12-23 23:00:00'));
    igual(['nit', 202, 52, 62], [$r['moment'], $r['llindar'], $r['tram_base'], $r['fet']], 'ritme: llindar 202, el tram comença amb 52 i ara n\'hi ha 62');
    igual(127, $r['esperat'], 'a mig tram en tocarien 127 (52 + la meitat de 150)');
    igual(13, $r['percent'], 'de les 75 previstes en aquest tram n\'hi ha 10 (13 %)');
    igual([65, 140, 47], [$r['falten_ritme'], $r['falten_fita'], $r['necessari_dia']], 'falten 65 per anar al ritme i 140 per a la fita (47 al dia)');
    igual([3.3, 0], [$r['per_dia'], $r['darreres_24h']], 'ritme d\'ara: 3,3 al dia; cap les darreres 24 hores');
    igual(['data', null], [$r['previsio'], $r['data_prevista']], 'previsió: saltarà per data');
    $z0 = count(array_filter($mapa['llocs'], function ($l) { return $l['grup'] !== 'poble' && $l['zona'] === 'Z0'; }));
    igual(min((int)ceil($z0 * 0.5) - 10, (int)floor($z0 * 0.4)), $r['automatiques'], 'i s\'hi afegirien les automàtiques fins al 50 % (màxim 40 % per zona)');

    // Si el ritme és bo, preveu el salt per participació
    $posa(100, 'familia', 'general', '2026-12-23 10:00:00');
    $r = fites_ritme($pdo, $cfg, $utc('2026-12-23 23:00:00'));
    igual('llindar', $r['previsio'], 'amb 110 figures en tres dies, preveu que saltarà per participació');
    comprova($r['data_prevista'] !== null && $r['data_prevista'] < '2026-12-27', 'i abans de la data límit (' . $r['data_prevista'] . ')');

    // Les figures de l'administració fan saltar la fita
    $falten = 202 - participacio_real($pdo)['reals'];
    for ($i = 0; $i < $falten; $i++) {
        $l = $lliure(function ($l) { return $l['grup'] === 'general' && $l['zona'] === 'Z0' && $l['tipus'] === 'persona'; });
        $crida($persona($l['id']), '2026-12-24 10:00:00');
    }
    igual(['nit', 'llindar'], [config_llegeix($pdo, 'moment'), $pdo->query('SELECT per FROM fites WHERE numero = 2')->fetchColumn()], 'amb les figures de l\'administració arriba la nit per participació');
    igual(0, (int)$pdo->query("SELECT COUNT(*) FROM ocupacions WHERE origen = 'auto'")->fetchColumn(), 'i no hi ha automàtiques');
};

$tmp = sys_get_temp_dir() . '/pessebre-ritme-' . getmypid();
@mkdir($tmp, 0700, true);
$base = ['pebre' => bin2hex(random_bytes(32)), 'llavor' => 'llavor-de-prova', 'web' => "$ARREL/public_html", 'snapshot' => "$tmp/snapshot.json",
         'bloqueig' => "$tmp/escriptura.lock", 'data_simulada' => null, 'url_base' => 'https://pessebre.exemple.cat/'];
$prova_ritme($base + ['bd_tipus' => 'sqlite', 'bd_sqlite' => "$tmp/pessebre.sqlite"], 'SQLite');
if (getenv('PESSEBRE_MYSQL_DSN')) {
    $prova_ritme($base + ['bd_tipus' => 'mysql', 'bd_mysql' => ['dsn' => getenv('PESSEBRE_MYSQL_DSN'), 'usuari' => getenv('PESSEBRE_MYSQL_USUARI'), 'clau' => getenv('PESSEBRE_MYSQL_CLAU')]], 'MySQL');
}
array_map('unlink', glob("$tmp/*"));
rmdir($tmp);
