<?php
// Fase B: reserva de cases de classe a través de l'API (app_executa), amb hores simulades.
// S'executa amb SQLite sempre i, si hi ha les variables PESSEBRE_MYSQL_DSN/USUARI/CLAU, també amb MySQL.
require_once $ARREL . '/pessebre/src/app.php';

$prova_reserva = function (array $cfg, string $nom) use ($ARREL) {
    echo "    ($nom)\n";
    $pdo = bd_connecta($cfg);
    foreach (['intents', 'config', 'registre', 'ocupacions', 'enllacos', 'classes', 'llocs'] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
    bd_esquema($pdo);
    llocs_carrega($pdo, disseny($cfg, 'mapa.json'));
    config_desa($pdo, 'classes_tanca', '2026-12-21 22:59:00');
    foreach ([['primaria', 'Mussols'], ['primaria', 'Guineus'], ['primaria', '3r ESO B'], ['cicles', 'Isards']] as $i => [$e, $n]) {
        $pdo->prepare('INSERT INTO classes (etapa, nom, ordre) VALUES (?, ?, ?)')->execute([$e, $n, $i]);
    }
    $id = function ($nom) use ($pdo) { $s = $pdo->prepare('SELECT id FROM classes WHERE nom = ?'); $s->execute([$nom]); return (int)$s->fetchColumn(); };
    $t0 = new DateTimeImmutable('2026-12-15 09:00:00', new DateTimeZone('UTC'));
    $prim = enllac_crea($pdo, $cfg['pebre'], 'etapa', 'primaria', $t0);
    $cicl = enllac_crea($pdo, $cfg['pebre'], 'etapa', 'cicles', $t0);
    $crida = function (array $p, DateTimeImmutable $ara, string $ip = '10.0.0.1') use ($pdo, $cfg) { return app_executa($pdo, $cfg, $p, $ip, $ara); };
    $snap = function () use ($cfg) { return json_decode((string)file_get_contents($cfg['snapshot']), true); };
    $min = function (int $m) use ($t0) { return $t0->modify("+$m minutes"); };

    // Enllaços i límit d'intents
    [$e, $r] = $crida(['a' => 'classes.llista', 'e' => $prim], $t0);
    igual(200, $e, 'llista amb enllaç bo');
    igual('Primària', $r['etapa'] ?? null, 'la llista és de l\'etapa de l\'enllaç');
    igual(['Mussols', 'Guineus', '3r ESO B'], array_column($r['classes'] ?? [], 'nom'), 'només surten les classes de l\'etapa');
    [$e, $r] = $crida(['a' => 'classes.llista', 'e' => 'AAAAAAAAAAAAAAAAAAAAAA'], $t0, '10.0.0.9');
    igual(403, $e, 'enllaç inventat → 403');
    for ($i = 0; $i < 9; $i++) $crida(['a' => 'classes.llista', 'e' => 'x'], $t0, '10.0.0.9');
    [$e] = $crida(['a' => 'classes.llista', 'e' => $prim], $t0, '10.0.0.9');
    igual(429, $e, 'després de 10 fallades, cal esperar encara que l\'enllaç sigui bo');
    [$e] = $crida(['a' => 'classes.llista', 'e' => $prim], $t0, '10.0.0.8');
    igual(200, $e, 'una altra adreça no queda afectada');
    [$e] = $crida(['a' => 'classes.llista', 'e' => $prim], $min(16), '10.0.0.9');
    igual(200, $e, 'passats 15 minuts es pot tornar a provar');
    comprova(!(int)$pdo->query("SELECT COUNT(*) FROM intents WHERE empremta LIKE '10.%'")->fetchColumn(), 'no es desa la IP en clar');

    // Retenir
    [$e, $r] = $crida(['a' => 'classes.retenir', 'e' => $prim, 'classe' => $id('Mussols'), 'lloc' => 'C001'], $t0);
    igual(200, $e, 'retenir una casa de classe lliure');
    $ret1 = $r['retencio'] ?? '';
    igual(['C001'], $snap()['r'], 'el snapshot marca la casa com a retinguda');
    [$e, $r] = $crida(['a' => 'classes.retenir', 'e' => $prim, 'classe' => $id('Guineus'), 'lloc' => 'C001'], $min(1));
    igual([409, 'ocupat'], [$e, $r['codi'] ?? ''], 'una altra classe no pot retenir la mateixa casa');
    [$e, $r] = $crida(['a' => 'classes.retenir', 'e' => $prim, 'classe' => $id('Mussols'), 'lloc' => 'C002'], $min(1));
    igual(200, $e, 'la mateixa classe pot canviar de casa');
    $ret2 = $r['retencio'] ?? '';
    igual(['C002'], $snap()['r'], 'canviar de casa allibera l\'anterior');
    [$e, $r] = $crida(['a' => 'classes.retenir', 'e' => $prim, 'classe' => $id('Isards'), 'lloc' => 'C003'], $min(1));
    igual([400, 'classe'], [$e, $r['codi'] ?? ''], 'no es pot triar una classe d\'una altra etapa');
    [$e, $r] = $crida(['a' => 'classes.retenir', 'e' => $prim, 'classe' => $id('Guineus'), 'lloc' => 'E001'], $min(1));
    igual([400, 'lloc'], [$e, $r['codi'] ?? ''], 'no es pot retenir un lloc que no és casa de classe');
    [$e, $r] = $crida(['a' => 'classes.retenir', 'e' => $prim, 'classe' => $id('Guineus'), 'lloc' => 'C150'], $min(1));
    igual([400, 'lloc'], [$e, $r['codi'] ?? ''], 'no es pot retenir una casa de família');

    // Confirmar
    [$e, $r] = $crida(['a' => 'classes.confirmar', 'e' => $prim, 'classe' => $id('Mussols'), 'lloc' => 'C002', 'retencio' => $ret1, 'colors' => [1, 2, 3]], $min(2));
    igual([409, 'retencio'], [$e, $r['codi'] ?? ''], 'no es pot confirmar amb una retenció que no és la bona');
    [$e, $r] = $crida(['a' => 'classes.confirmar', 'e' => $prim, 'classe' => $id('Mussols'), 'lloc' => 'C002', 'retencio' => $ret2, 'colors' => [9, 0, 0]], $min(2));
    igual([400, 'colors'], [$e, $r['codi'] ?? ''], 'colors fora de la paleta → error');
    [$e, $r] = $crida(['a' => 'classes.confirmar', 'e' => $prim, 'classe' => $id('Mussols'), 'lloc' => 'C002', 'retencio' => $ret2, 'colors' => ['#FF0000', 0, 0]], $min(2));
    igual([400, 'colors'], [$e, $r['codi'] ?? ''], 'colors en cru → error');
    [$e, $r] = $crida(['a' => 'classes.confirmar', 'e' => $prim, 'classe' => $id('Mussols'), 'lloc' => 'C002', 'retencio' => $ret2, 'colors' => [1, 2, 3]], $min(3));
    igual(200, $e, 'confirmar amb la retenció bona');
    $s = $snap();
    $l = lloc($pdo, 'C002');
    igual(['p' => $l['codi'], 'c' => [1, 2, 3], 'n' => 'Mussols'], $s['o']['C002'] ?? null, 'el snapshot porta la casa, els índexs i el nom');
    igual(['tipus' => 'classes', 'fet' => 1, 'total' => 74], $s['comptador'], 'comptador: 1 de 74');
    igual([], snapshot_errors($s, disseny($cfg, 'mapa.json'), disseny($cfg, 'peces.json')['peces'], disseny($cfg, 'paletes.json')), 'el snapshot generat és vàlid');
    [$e, $r] = $crida(['a' => 'classes.retenir', 'e' => $prim, 'classe' => $id('Mussols'), 'lloc' => 'C004'], $min(4));
    igual([409, 'classe_feta'], [$e, $r['codi'] ?? ''], 'una classe amb casa no en pot reservar una altra');
    [, $r] = $crida(['a' => 'classes.llista', 'e' => $prim], $min(4));
    igual(true, $r['classes'][0]['feta'] ?? null, 'la llista indica que la classe ja té casa');

    // Caducitat de la retenció (10 min)
    [, $r] = $crida(['a' => 'classes.retenir', 'e' => $prim, 'classe' => $id('Guineus'), 'lloc' => 'C003'], $min(5));
    $ret3 = $r['retencio'] ?? '';
    [$e] = $crida(['a' => 'classes.retenir', 'e' => $prim, 'classe' => $id('3r ESO B'), 'lloc' => 'C003'], $min(14));
    igual(409, $e, 'abans de 10 minuts la casa continua retinguda');
    [$e] = $crida(['a' => 'classes.retenir', 'e' => $prim, 'classe' => $id('3r ESO B'), 'lloc' => 'C003'], $min(16));
    igual(200, $e, 'passats 10 minuts, la casa torna a estar lliure');
    [$e, $r] = $crida(['a' => 'classes.confirmar', 'e' => $prim, 'classe' => $id('Guineus'), 'lloc' => 'C003', 'retencio' => $ret3, 'colors' => [0, 0, 0]], $min(16));
    igual([409, 'retencio'], [$e, $r['codi'] ?? ''], 'no es pot confirmar una retenció caducada');

    // Deixar
    [, $r] = $crida(['a' => 'classes.retenir', 'e' => $prim, 'classe' => $id('Guineus'), 'lloc' => 'C005'], $min(17));
    [$e] = $crida(['a' => 'classes.deixar', 'e' => $prim, 'classe' => $id('Guineus'), 'lloc' => 'C005', 'retencio' => $r['retencio'] ?? ''], $min(18));
    igual(200, $e, 'deixar una retenció');
    comprova(!in_array('C005', $snap()['r'], true), 'la casa deixada torna a estar lliure');

    // Aturada i tancament
    config_desa($pdo, 'aturada', '1');
    [$e, $r] = $crida(['a' => 'classes.retenir', 'e' => $prim, 'classe' => $id('Guineus'), 'lloc' => 'C006'], $min(19));
    igual([403, 'aturada'], [$e, $r['codi'] ?? ''], 'amb l\'aturada activa no es pot reservar');
    config_desa($pdo, 'aturada', '0');
    $tancat = new DateTimeImmutable('2026-12-21 23:00:00', new DateTimeZone('UTC'));
    [$e, $r] = $crida(['a' => 'classes.retenir', 'e' => $prim, 'classe' => $id('Guineus'), 'lloc' => 'C006'], $tancat);
    igual([403, 'tancat'], [$e, $r['codi'] ?? ''], 'després del 21 de desembre a les 23:59 ja no es pot reservar');
    $s = snapshot_genera($pdo, $cfg, $tancat);
    igual(false, $s['classes'], 'en tancar, el snapshot deixa de mostrar les cases de classe lliures');
    igual('participacions', $s['comptador']['tipus'], 'en tancar, el comptador passa a participacions');

    // Enllaç regenerat
    $nou = enllac_crea($pdo, $cfg['pebre'], 'etapa', 'primaria', $min(20));
    [$e] = $crida(['a' => 'classes.llista', 'e' => $prim], $min(20), '10.0.0.7');
    igual(403, $e, 'l\'enllaç antic deixa de funcionar en regenerar-lo');
    [$e] = $crida(['a' => 'classes.llista', 'e' => $nou], $min(20), '10.0.0.7');
    igual(200, $e, 'l\'enllaç nou funciona');

    // Alliberar (administració des del terminal) i registre
    $fet = app_escriu($pdo, $cfg, $min(21), function () use ($pdo, $min) { return reserva_allibera($pdo, 'C002', 'prova', $min(21)); });
    comprova($fet && !isset($snap()['o']['C002']), 'alliberar una casa confirmada');
    $reg = $pdo->query("SELECT accio FROM registre ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    igual(['classes.confirmar', 'admin.alliberar'], $reg, 'el registre apunta confirmacions i alliberaments');

    // La base de dades mateixa impedeix dobles ocupacions
    $ins = $pdo->prepare("INSERT INTO ocupacions (lloc_id, estat, origen, classe_id, peca, colors, hora) VALUES (?, 'confirmat', 'classe', ?, 'CASA-ALTA-P', '[0,0,0]', '2026-12-15 10:00:00')");
    $ins->execute(['C010', $id('Isards')]);
    llança(function () use ($ins, $id) { $ins->execute(['C010', null]); }, 'la BD impedeix dues ocupacions del mateix lloc');
    llança(function () use ($ins, $id) { $ins->execute(['C011', $id('Isards')]); }, 'la BD impedeix dues cases per a la mateixa classe');

    // Tick
    touch($cfg['snapshot'], $min(21)->getTimestamp());
    [$e, $r] = $crida(['a' => 'tick'], $min(30));
    igual(false, $r['fet'] ?? null, 'tick: no fa res si el snapshot és recent');
    [$e, $r] = $crida(['a' => 'tick'], $min(40));
    igual(true, $r['fet'] ?? null, 'tick: regenera si el snapshot té més de 15 minuts');
    [$e] = $crida(['a' => 'res'], $min(40));
    igual(400, $e, 'acció desconeguda → 400');
};

$tmp = sys_get_temp_dir() . '/pessebre-prova-' . getmypid();
@mkdir($tmp, 0700, true);
$base = ['pebre' => bin2hex(random_bytes(32)), 'web' => "$ARREL/public_html", 'snapshot' => "$tmp/snapshot.json", 'bloqueig' => "$tmp/escriptura.lock", 'data_simulada' => null];
$prova_reserva($base + ['bd_tipus' => 'sqlite', 'bd_sqlite' => "$tmp/pessebre.sqlite"], 'SQLite');
if (getenv('PESSEBRE_MYSQL_DSN')) {
    $prova_reserva($base + ['bd_tipus' => 'mysql', 'bd_mysql' => ['dsn' => getenv('PESSEBRE_MYSQL_DSN'), 'usuari' => getenv('PESSEBRE_MYSQL_USUARI'), 'clau' => getenv('PESSEBRE_MYSQL_CLAU')]], 'MySQL');
} else {
    echo "    (MySQL: no provat; defineix PESSEBRE_MYSQL_DSN, PESSEBRE_MYSQL_USUARI i PESSEBRE_MYSQL_CLAU per provar-lo)\n";
}
array_map('unlink', glob("$tmp/*"));
rmdir($tmp);
