<?php
// Fase D: administració (contrasenyes, límit d'intents, lectura i escriptura, alliberar, canviar, casa sense placa,
// configuració, enllaços, codis, classes i registre).
require_once $ARREL . '/pessebre/src/app.php';

$prova_admin = function (array $cfg, string $nom) {
    echo "    ($nom)\n";
    $pdo = bd_connecta($cfg);
    foreach (['fites', 'intents', 'config', 'registre', 'ocupacions', 'enllacos', 'classes', 'codis', 'llocs'] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
    bd_esquema($pdo);
    $mapa = disseny($cfg, 'mapa.json');
    llocs_carrega($pdo, $mapa);
    $peces = disseny($cfg, 'peces.json')['peces'];
    $h = '2026-12-22 10:00:00';
    $t0 = new DateTimeImmutable($h, new DateTimeZone('UTC'));
    $crida = function (array $p, string $hora = '2026-12-22 10:00:00', string $ip = '10.2.0.1') use ($pdo, $cfg) {
        return app_executa($pdo, $cfg, $p, $ip, new DateTimeImmutable($hora, new DateTimeZone('UTC')));
    };
    $A = ['contrasenya' => 'admin-de-prova-1234'];
    $L = ['contrasenya' => 'lectura-de-prova-1234'];
    $snap = function () use ($cfg) { return json_decode((string)file_get_contents($cfg['snapshot']), true); };
    $un = function (callable $f) use ($mapa) { foreach ($mapa['llocs'] as $l) if ($f($l)) return $l; return null; };

    // Contrasenyes i límit d'intents (5)
    [$e, $r] = $crida(['a' => 'admin.estat']);
    igual(403, $e, 'sense contrasenya → 403');
    [$e, $r] = $crida(['a' => 'admin.estat'] + $A);
    igual([200, 'admin'], [$e, $r['rol'] ?? null], 'la contrasenya d\'administració entra amb rol admin');
    [$e, $r] = $crida(['a' => 'admin.estat'] + $L);
    igual([200, 'lectura'], [$e, $r['rol'] ?? null], 'la de lectura entra amb rol lectura');
    for ($i = 0; $i < 5; $i++) $crida(['a' => 'admin.estat', 'contrasenya' => 'mala'], $h, '10.2.0.9');
    [$e] = $crida(['a' => 'admin.estat'] + $A, $h, '10.2.0.9');
    igual(429, $e, 'després de 5 contrasenyes dolentes cal esperar');
    [$e] = $crida(['a' => 'admin.estat'] + $A, '2026-12-22 10:16:00', '10.2.0.9');
    igual(200, $e, 'passats 15 minuts es pot tornar a entrar');
    [$e, $r] = $crida(['a' => 'admin.config', 'opcio' => 'usos_codi', 'valor' => '5'] + $L);
    igual([403, 'lectura'], [$e, $r['codi'] ?? ''], 'la contrasenya de lectura no pot fer canvis');

    // Classes (enganxant la llista) i casa d'una classe
    [$e, $r] = $crida(['a' => 'admin.classes', 'text' => "etapa;nom\nprimaria;Mussols\nprimaria;Guineus\ncicles;Massa llarg de nom"] + $A);
    igual([400, 'classes'], [$e, $r['codi'] ?? ''], 'una classe amb el nom massa llarg atura la càrrega');
    [$e, $r] = $crida(['a' => 'admin.classes', 'text' => "primaria;Mussols\nprimaria;Guineus\ncicles;Isards"] + $A);
    igual([200, 3], [$e, $r['classes'] ?? null], 'es carreguen 3 classes enganxant la llista');
    $k = enllac_crea($pdo, $cfg['pebre'], 'etapa', 'primaria', $t0);
    $id = (int)$pdo->query("SELECT id FROM classes WHERE nom = 'Mussols'")->fetchColumn();
    config_desa($pdo, 'classes_tanca', '2026-12-31 00:00:00');
    [, $r] = $crida(['a' => 'classes.retenir', 'e' => $k, 'classe' => $id, 'lloc' => 'C001']);
    $pc = $peces[$un(function ($l) { return $l['id'] === 'C001'; })['codi']];
    [$e] = $crida(['a' => 'classes.confirmar', 'e' => $k, 'classe' => $id, 'lloc' => 'C001', 'retencio' => $r['retencio'] ?? '', 'colors' => array_fill(0, count($pc['pers']), 0)]);
    igual(200, $e, 'una classe confirma la seva casa (preparació)');

    // Estat i lloc
    [$e, $r] = $crida(['a' => 'admin.estat'] + $L);
    igual(1, $r['zones']['Z0']['classes']['ocupats'] ?? null, 'l\'estat compta la casa de classe ocupada a Z0');
    igual(['nom' => 'Mussols', 'lloc' => 'C001'], $r['classes']['primaria'][0] ?? null, 'l\'estat diu quina casa té cada classe');
    [$e, $r] = $crida(['a' => 'admin.lloc', 'lloc' => 'C001'] + $L);
    igual(['confirmat', 'Mussols'], [$r['ocupacio']['estat'] ?? null, $r['ocupacio']['classe'] ?? null], 'el lloc diu qui l\'ocupa');

    // Canviar colors d'una ocupació
    $nous = array_fill(0, count($pc['pers']), 1);
    [$e] = $crida(['a' => 'admin.canvia', 'lloc' => 'C001', 'peca' => 'CASA-ALTA-P', 'colors' => $nous] + $A);
    comprova($e === 400 || $pc === $peces['CASA-ALTA-P'], 'no es pot canviar la casa per un altre model');
    [$e] = $crida(['a' => 'admin.canvia', 'lloc' => 'C001', 'peca' => $pc === null ? '' : $un(function ($l) { return $l['id'] === 'C001'; })['codi'], 'colors' => $nous, 'mirall' => 1] + $A);
    igual(200, $e, 'l\'administració canvia els colors d\'una casa de classe');
    igual(['c' => $nous, 'n' => 'Mussols'], array_intersect_key($snap()['o']['C001'] ?? [], ['c' => 1, 'n' => 1]), 'el snapshot porta els colors nous i manté el nom');

    // Casa sense placa
    $lliure = $un(function ($l) { return $l['grup'] === 'poble' && $l['id'] !== 'C001'; });
    $sp = preg_replace('/-P$/', '-SP', $lliure['codi']);
    [$e] = $crida(['a' => 'admin.casa_sense_placa', 'lloc' => $lliure['id'], 'peca' => $lliure['codi'], 'colors' => array_fill(0, count($peces[$sp]['pers']), 0)] + $A);
    igual(400, $e, 'la casa sense placa ha de ser la variant sense placa');
    [$e] = $crida(['a' => 'admin.casa_sense_placa', 'lloc' => $lliure['id'], 'peca' => $sp, 'colors' => array_fill(0, count($peces[$sp]['pers']), 2)] + $A);
    igual(200, $e, 'l\'administració posa una casa sense placa en una casa de classe lliure');
    igual($sp, $snap()['o'][$lliure['id']]['p'] ?? null, 'el snapshot porta la casa sense placa');
    [$e] = $crida(['a' => 'admin.casa_sense_placa', 'lloc' => 'P0001', 'peca' => 'CASA-ALTA-SP', 'colors' => [0, 0, 0, 0]] + $A);
    igual(400, $e, 'no es pot posar una casa sense placa fora del casc antic');

    // Figures per codi: l'administració les canvia (1–10)
    igual('3', $crida(['a' => 'admin.estat'] + $A)[1]['config']['usos_codi'] ?? null, 'per defecte, cada codi serveix per a 3 figures');
    igual(200, $crida(['a' => 'admin.config', 'opcio' => 'usos_codi', 'valor' => '4'] + $A)[0], 'l\'administració canvia les figures per codi');
    igual('4', $crida(['a' => 'admin.estat'] + $A)[1]['config']['usos_codi'] ?? null, 'i queda desat');
    igual(400, $crida(['a' => 'admin.config', 'opcio' => 'usos_codi', 'valor' => '0'] + $A)[0], 'no pot ser 0');
    igual(400, $crida(['a' => 'admin.config', 'opcio' => 'usos_codi', 'valor' => '11'] + $A)[0], 'ni més de 10');
    igual(400, $crida(['a' => 'admin.config', 'opcio' => 'ronda', 'valor' => '2'] + $A)[0], 'la «segona ronda» ja no existeix');
    $crida(['a' => 'admin.config', 'opcio' => 'usos_codi', 'valor' => '3'] + $A);

    // Codis: generar, cercar, alliberar i reactivar
    [$e, $r] = $crida(['a' => 'admin.generar_codis', 'quants' => 2] + $A);
    igual([200, 2], [$e, count($r['codis'] ?? [])], 'l\'administració genera un lot de 2 codis');
    comprova(strpos($r['codis'][0]['qr'] ?? '', '<svg') === 0, 'cada codi porta el seu QR en SVG');
    igual(3, $r['usos'] ?? null, 'la resposta diu quantes figures permet cada codi (per a l\'etiqueta): 3');
    $codi = str_replace('-', '', $r['codis'][0]['codi']);
    $persona = $un(function ($l) { return $l['tipus'] === 'persona' && $l['grup'] === 'general' && $l['zona'] === 'Z0'; });
    [, $r] = $crida(['a' => 'codi.retenir', 'c' => $codi, 'lloc' => $persona['id']]);
    [$e] = $crida(['a' => 'codi.confirmar', 'c' => $codi, 'lloc' => $persona['id'], 'retencio' => $r['retencio'] ?? '', 'peca' => 'PER-ADU-F', 'mirall' => 0,
                   'colors' => array_fill(0, count($peces['PER-ADU-F']['pers']), 0)]);
    igual(200, $e, 'una família participa amb un codi nou');
    [$e, $r] = $crida(['a' => 'admin.cercar', 'codi' => $codi] + $L);
    igual([true, 1, 3], [$r['trobat'] ?? null, $r['usos'] ?? null, $r['permesos'] ?? null], 'la cerca troba el codi, quantes vegades s\'ha fet servir i quantes en permet (3)');
    comprova(!isset($r['llocs']), 'la cerca no diu on és la figura (participació anònima)');
    [, $r] = $crida(['a' => 'admin.lloc', 'lloc' => $persona['id']] + $L);
    igual('familia', $r['ocupacio']['origen'] ?? null, 'el lloc diu que és d\'una família…');
    comprova(strpos(json_encode($r), $codi) === false && !isset($r['ocupacio']['codi']), '…però no quin codi');
    [$e] = $crida(['a' => 'admin.alliberar', 'lloc' => $persona['id']] + $A);
    igual(200, $e, 'l\'administració allibera el lloc');
    [, $r] = $crida(['a' => 'admin.cercar', 'codi' => $codi] + $L);
    igual(1, $r['usos'] ?? null, 'alliberar no toca el codi (no se sap quin era)');
    [$e] = $crida(['a' => 'admin.reactivar', 'codi' => $codi] + $L);
    igual(403, $e, 'la contrasenya de lectura no pot reactivar codis');
    [$e] = $crida(['a' => 'admin.reactivar', 'codi' => $codi] + $A);
    igual(200, $e, 'l\'administració reactiva el codi escrivint-lo');
    [, $r] = $crida(['a' => 'admin.cercar', 'codi' => $codi] + $L);
    igual(0, $r['usos'] ?? null, 'el codi reactivat torna a servir');
    [$e] = $crida(['a' => 'admin.reactivar', 'codi' => $codi] + $A);
    igual(409, $e, 'un codi sense usar no es pot reactivar');
    [$e] = $crida(['a' => 'admin.alliberar', 'lloc' => $persona['id']] + $A);
    igual(409, $e, 'no es pot alliberar un lloc que ja és lliure');

    // Configuració
    [$e] = $crida(['a' => 'admin.config', 'opcio' => 'families_des', 'valor' => '2026-12-21 08:30'] + $A);
    igual('2026-12-21 07:30:00', config_llegeix($pdo, 'families_des'), 'les dates es desen en UTC (hivern: Barcelona −1 h)');
    [$e] = $crida(['a' => 'admin.config', 'opcio' => 'zones', 'valor' => 'Z0,Z2'] + $A);
    igual(400, $e, 'les zones s\'obren en ordre (no es pot obrir Z2 sense Z1)');
    [$e] = $crida(['a' => 'admin.config', 'opcio' => 'zones', 'valor' => 'Z0,Z1'] + $A);
    igual(['Z0', 'Z1'], $snap()['zones'], 'forçar l\'obertura de Z1 es veu al snapshot');
    [$e] = $crida(['a' => 'admin.config', 'opcio' => 'pebre', 'valor' => 'x'] + $A);
    igual(400, $e, 'no es poden canviar opcions que no són a la llista');
    [$e] = $crida(['a' => 'admin.config', 'opcio' => 'aturada', 'valor' => '1'] + $A);
    [$e, $r] = $crida(['a' => 'classes.retenir', 'e' => $k, 'classe' => (int)$pdo->query("SELECT id FROM classes WHERE nom = 'Guineus'")->fetchColumn(), 'lloc' => 'C005']);
    igual([403, 'aturada'], [$e, $r['codi'] ?? ''], 'el botó d\'aturada atura les reserves');
    $crida(['a' => 'admin.config', 'opcio' => 'aturada', 'valor' => '0'] + $A);

    // Enllaços
    [$e, $r] = $crida(['a' => 'admin.enllac', 'tipus' => 'etapa', 'etapa' => 'primaria'] + $A);
    comprova($e === 200 && strpos($r['url'] ?? '', 'classes.html#e=') !== false, 'l\'administració regenera l\'enllaç d\'una etapa');
    [$e] = $crida(['a' => 'classes.llista', 'e' => $k], $h, '10.2.0.5');
    igual(403, $e, 'l\'enllaç antic ja no funciona');
    [$e] = $crida(['a' => 'classes.llista', 'e' => substr($r['url'], strpos($r['url'], '#e=') + 3)], $h, '10.2.0.5');
    igual(200, $e, 'el nou, sí');

    // Registre
    [$e, $r] = $crida(['a' => 'admin.registre'] + $L);
    $accions = array_column($r['registre'] ?? [], 'accio');
    foreach (['admin.alliberar', 'admin.canvia', 'admin.casa_sense_placa', 'admin.config', 'admin.enllac', 'admin.generar_codis', 'admin.classes', 'admin.reactivar'] as $acc) {
        comprova(in_array($acc, $accions, true), "el registre apunta $acc");
    }
    igual([], snapshot_errors($snap(), $mapa, $peces, disseny($cfg, 'paletes.json')), 'el snapshot continua essent vàlid');
};

$tmp = sys_get_temp_dir() . '/pessebre-admin-' . getmypid();
@mkdir($tmp, 0700, true);
$base = ['pebre' => bin2hex(random_bytes(32)), 'web' => "$ARREL/public_html", 'snapshot' => "$tmp/snapshot.json", 'bloqueig' => "$tmp/escriptura.lock",
         'data_simulada' => null, 'url_base' => 'https://pessebre.exemple.cat/',
         'hash_admin' => password_hash('admin-de-prova-1234', PASSWORD_DEFAULT), 'hash_lectura' => password_hash('lectura-de-prova-1234', PASSWORD_DEFAULT)];
$prova_admin($base + ['bd_tipus' => 'sqlite', 'bd_sqlite' => "$tmp/pessebre.sqlite"], 'SQLite');
if (getenv('PESSEBRE_MYSQL_DSN')) {
    $prova_admin($base + ['bd_tipus' => 'mysql', 'bd_mysql' => ['dsn' => getenv('PESSEBRE_MYSQL_DSN'), 'usuari' => getenv('PESSEBRE_MYSQL_USUARI'), 'clau' => getenv('PESSEBRE_MYSQL_CLAU')]], 'MySQL');
}
array_map('unlink', glob("$tmp/*"));
rmdir($tmp);
