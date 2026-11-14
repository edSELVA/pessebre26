<?php
// Fase D: administració (comptes personals, límit d'intents, lectura i escriptura, alliberar, canviar, casa sense placa,
// configuració, enllaç del claustre, codis de classe i de família, classes i registre).
require_once $ARREL . '/pessebre/src/app.php';

$prova_admin = function (array $cfg, string $nom) {
    echo "    ($nom)\n";
    $pdo = bd_connecta($cfg);
    foreach (['fites', 'intents', 'config', 'registre', 'ocupacions', 'enllacos', 'classes', 'codis', 'llocs', 'usuaris'] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
    bd_esquema($pdo);
    // Comptes personals (§31): l'Anna administra, en Lluc només mira
    usuari_desa($pdo, 'Anna', 'admin-de-prova-1234', 'admin', new DateTimeImmutable('2026-12-01', new DateTimeZone('UTC')), 4);
    usuari_desa($pdo, 'lluc', 'lectura-de-prova-1234', 'lectura', new DateTimeImmutable('2026-12-01', new DateTimeZone('UTC')), 4);
    $mapa = disseny($cfg, 'mapa.json');
    llocs_carrega($pdo, $mapa);
    $peces = disseny($cfg, 'peces.json')['peces'];
    $h = '2026-12-22 10:00:00';
    $t0 = new DateTimeImmutable($h, new DateTimeZone('UTC'));
    $crida = function (array $p, string $hora = '2026-12-22 10:00:00', string $ip = '10.2.0.1') use ($pdo, $cfg) {
        return app_executa($pdo, $cfg, $p, $ip, new DateTimeImmutable($hora, new DateTimeZone('UTC')));
    };
    $A = ['usuari' => 'anna', 'contrasenya' => 'admin-de-prova-1234'];
    $L = ['usuari' => 'lluc', 'contrasenya' => 'lectura-de-prova-1234'];
    $snap = function () use ($cfg) { return json_decode((string)file_get_contents($cfg['snapshot']), true); };
    $un = function (callable $f) use ($mapa) { foreach ($mapa['llocs'] as $l) if ($f($l)) return $l; return null; };

    // Contrasenyes i límit d'intents (5)
    [$e, $r] = $crida(['a' => 'admin.estat']);
    igual(403, $e, 'sense contrasenya → 403');
    [$e, $r] = $crida(['a' => 'admin.estat'] + $A);
    igual([200, 'admin', 'anna'], [$e, $r['rol'] ?? null, $r['nom'] ?? null], 'l\'Anna entra amb el seu compte d\'administració (el nom es desa en minúscules)');
    comprova(!empty($r['usuaris']) && !isset($r['usuaris'][0]['hash']), 'l\'administració veu la llista de comptes (sense els hash)');
    [$e, $r] = $crida(['a' => 'admin.estat'] + $L);
    igual([200, 'lectura'], [$e, $r['rol'] ?? null], 'en Lluc entra amb el seu compte de només lectura');
    comprova(!isset($r['usuaris']), 'un compte de lectura no veu la llista de comptes');
    [$e] = $crida(['a' => 'admin.estat', 'usuari' => 'lluc', 'contrasenya' => 'admin-de-prova-1234'], $h, '10.2.0.8');
    igual(403, $e, 'la contrasenya d\'un altre compte no serveix');
    for ($i = 0; $i < 5; $i++) $crida(['a' => 'admin.estat', 'usuari' => 'anna', 'contrasenya' => 'mala'], $h, '10.2.0.9');
    [$e] = $crida(['a' => 'admin.estat'] + $A, $h, '10.2.0.9');
    igual(429, $e, 'després de 5 contrasenyes dolentes cal esperar');
    [$e] = $crida(['a' => 'admin.estat'] + $A, '2026-12-22 10:16:00', '10.2.0.9');
    igual(200, $e, 'passats 15 minuts es pot tornar a entrar');
    [$e, $r] = $crida(['a' => 'admin.config', 'opcio' => 'usos_codi', 'valor' => '5'] + $L);
    igual([403, 'lectura'], [$e, $r['codi'] ?? ''], 'un compte de lectura no pot fer canvis');

    // Classes (enganxant la llista) i casa d'una classe
    [$e, $r] = $crida(['a' => 'admin.classes', 'text' => "etapa;nom\nprimaria;Mussols\nprimaria;Guineus\ncicles;Massa llarg de nom"] + $A);
    igual([400, 'classes'], [$e, $r['codi'] ?? ''], 'una classe amb el nom massa llarg atura la càrrega');
    [$e, $r] = $crida(['a' => 'admin.classes', 'text' => "primaria;Mussols\nprimaria;Guineus\ncicles;Isards"] + $A);
    igual([200, 3], [$e, $r['classes'] ?? null], 'es carreguen 3 classes enganxant la llista');
    $id = (int)$pdo->query("SELECT id FROM classes WHERE nom = 'Mussols'")->fetchColumn();
    $codiMussols = codi_classe_nou($pdo, $cfg['pebre'], $id);
    config_desa($pdo, 'classes_tanca', '2026-12-31 00:00:00');
    [, $r] = $crida(['a' => 'classes.retenir', 'c' => $codiMussols, 'lloc' => 'C001']);
    $pc = $peces[$un(function ($l) { return $l['id'] === 'C001'; })['codi']];
    [$e] = $crida(['a' => 'classes.confirmar', 'c' => $codiMussols, 'lloc' => 'C001', 'retencio' => $r['retencio'] ?? '', 'colors' => array_fill(0, count($pc['pers']), 0)]);
    igual(200, $e, 'una classe confirma la seva casa (preparació)');

    // Estat i lloc
    [$e, $r] = $crida(['a' => 'admin.estat'] + $L);
    igual(1, $r['zones']['Z0']['classes']['ocupats'] ?? null, 'l\'estat compta la casa de classe ocupada a Z0');
    igual(['id' => $id, 'nom' => 'Mussols', 'codi' => true, 'lloc' => 'C001'], $r['classes']['primaria'][0] ?? null, 'l\'estat diu quina casa i si té codi cada classe');
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
    $codiGuineus = codi_classe_nou($pdo, $cfg['pebre'], (int)$pdo->query("SELECT id FROM classes WHERE nom = 'Guineus'")->fetchColumn());
    [$e, $r] = $crida(['a' => 'classes.retenir', 'c' => $codiGuineus, 'lloc' => 'C005']);
    igual([403, 'aturada'], [$e, $r['codi'] ?? ''], 'el botó d\'aturada atura les reserves');
    $crida(['a' => 'admin.config', 'opcio' => 'aturada', 'valor' => '0'] + $A);

    // Enllaç del claustre (torronada, §30): l'únic enllaç, amb el QR per imprimir
    [$e, $r] = $crida(['a' => 'admin.enllac', 'tipus' => 'claustre'] + $A);
    comprova($e === 200 && strpos($r['url'] ?? '', '#k=') !== false && strpos($r['qr'] ?? '', '<svg') === 0, 'l\'administració crea l\'enllaç del claustre amb el seu QR');
    $k1 = substr($r['url'], strpos($r['url'], '#k=') + 3);
    [, $r] = $crida(['a' => 'admin.enllac', 'tipus' => 'claustre'] + $A);
    $k2 = substr($r['url'], strpos($r['url'], '#k=') + 3);
    igual(403, $crida(['a' => 'claustre.estat', 'k' => $k1], $h, '10.2.0.5')[0], 'regenerat, l\'enllaç antic ja no funciona');
    igual(200, $crida(['a' => 'claustre.estat', 'k' => $k2], $h, '10.2.0.5')[0], 'el nou, sí');
    igual(1, count($crida(['a' => 'admin.estat'] + $L)[1]['enllacos']), 'només hi ha un enllaç actiu: el del claustre');

    // Codis de classe (§30): es generen a l'administració, un per classe, i es mostren un sol cop
    [$e, $r] = $crida(['a' => 'admin.codis_classe', 'quines' => 'pendents'] + $L);
    igual(403, $e, 'un compte de lectura no pot generar codis de classe');
    [$e, $r] = $crida(['a' => 'admin.codis_classe', 'quines' => 'pendents'] + $A);
    igual([200, ['Isards']], [$e, array_column($r['codis'] ?? [], 'classe')], 'genera el codi de la classe que encara no en té');
    comprova(strpos($r['codis'][0]['qr'] ?? '', '<svg') === 0 && preg_match('/^\w{3}-\w{3}-\w{4}$/', $r['codis'][0]['codi'] ?? '') === 1, 'cada codi de classe porta el seu QR i es veu amb guions');
    $codiIsards = str_replace('-', '', $r['codis'][0]['codi']);
    [, $r] = $crida(['a' => 'codi.validar', 'c' => $codiIsards], $h, '10.2.0.5');
    igual('classe', $r['tipus'] ?? null, 'el botó «Participar» reconeix un codi de classe (i la pàgina va a la dels tutors)');
    [, $r] = $crida(['a' => 'classes.estat', 'c' => $codiIsards], $h, '10.2.0.5');
    igual(['Isards', 'Cicles', false], [$r['classe'] ?? null, $r['etapa'] ?? null, $r['feta'] ?? null], 'el codi diu quina és la classe (sense haver-la de triar)');
    igual(400, $crida(['a' => 'admin.codis_classe', 'quines' => 'pendents'] + $A)[0], 'si totes en tenen, no en genera cap');
    [$e, $r] = $crida(['a' => 'admin.codis_classe', 'quines' => $id] + $A);
    igual([200, 'Mussols'], [$e, $r['codis'][0]['classe'] ?? null], 'l\'administració regenera el codi d\'una classe');
    igual(403, $crida(['a' => 'classes.estat', 'c' => $codiMussols], $h, '10.2.0.5')[0], 'el codi antic ja no funciona');
    [, $r] = $crida(['a' => 'classes.estat', 'c' => str_replace('-', '', $r['codis'][0]['codi'])], $h, '10.2.0.5');
    igual([true, 'C001'], [$r['feta'] ?? null, $r['lloc'] ?? null], 'amb el codi nou, la classe veu que ja té la casa');

    // Comptes (§31)
    [$e, $r] = $crida(['a' => 'admin.usuari', 'nom' => 'Marta', 'nova' => 'una-clau-de-marta', 'rol' => 'lectura'] + $L);
    igual(403, $e, 'un compte de lectura no pot crear comptes');
    [$e, $r] = $crida(['a' => 'admin.usuari', 'nom' => 'Marta', 'nova' => 'curta', 'rol' => 'lectura'] + $A);
    igual([400, 'contrasenya_curta'], [$e, $r['codi'] ?? ''], 'la contrasenya ha de tenir 10 caràcters com a mínim');
    igual(400, $crida(['a' => 'admin.usuari', 'nom' => 'm', 'nova' => 'una-clau-de-marta', 'rol' => 'lectura'] + $A)[0], 'nom d\'usuari massa curt');
    igual(400, $crida(['a' => 'admin.usuari', 'nom' => 'marta', 'nova' => 'una-clau-de-marta', 'rol' => 'jefa'] + $A)[0], 'el nivell ha de ser admin o lectura');
    [$e, $r] = $crida(['a' => 'admin.usuari', 'nom' => 'Marta', 'nova' => 'una-clau-de-marta', 'rol' => 'lectura'] + $A);
    igual([200, 'marta', true], [$e, $r['nom'] ?? null, $r['nou'] ?? null], 'l\'administració crea el compte de la Marta');
    igual(200, $crida(['a' => 'admin.estat', 'usuari' => 'marta', 'contrasenya' => 'una-clau-de-marta'])[0], 'la Marta ja pot entrar');
    igual(200, $crida(['a' => 'admin.contrasenya_propia', 'nova' => 'la-meva-clau-nova', 'usuari' => 'marta', 'contrasenya' => 'una-clau-de-marta'])[0], 'qualsevol compte pot canviar la seva contrasenya');
    igual(403, $crida(['a' => 'admin.estat', 'usuari' => 'marta', 'contrasenya' => 'una-clau-de-marta'], $h, '10.2.0.7')[0], 'la contrasenya vella ja no serveix');
    igual(200, $crida(['a' => 'admin.estat', 'usuari' => 'marta', 'contrasenya' => 'la-meva-clau-nova'])[0], 'la nova, sí');
    igual([409, 'jo'], [$crida(['a' => 'admin.usuari_treu', 'nom' => 'anna'] + $A)[0], $crida(['a' => 'admin.usuari_treu', 'nom' => 'anna'] + $A)[1]['codi'] ?? ''], 'no et pots treure el teu compte');
    igual([409, 'darrer'], [$crida(['a' => 'admin.usuari', 'nom' => 'anna', 'nova' => 'admin-de-prova-1234', 'rol' => 'lectura'] + $A)[0],
                           $crida(['a' => 'admin.usuari', 'nom' => 'anna', 'nova' => 'admin-de-prova-1234', 'rol' => 'lectura'] + $A)[1]['codi'] ?? ''], 'ha de quedar com a mínim un compte d\'administració');
    [$e, $r] = $crida(['a' => 'admin.usuari_treu', 'nom' => 'marta'] + $A);
    igual([200, ['anna', 'lluc']], [$e, array_column($r['usuaris'] ?? [], 'nom')], 'l\'administració treu el compte de la Marta');
    igual(403, $crida(['a' => 'admin.estat', 'usuari' => 'marta', 'contrasenya' => 'la-meva-clau-nova'], $h, '10.2.0.6')[0], 'la Marta ja no pot entrar');

    // Registre
    [$e, $r] = $crida(['a' => 'admin.registre'] + $L);
    $accions = array_column($r['registre'] ?? [], 'accio');
    foreach (['admin.alliberar', 'admin.canvia', 'admin.casa_sense_placa', 'admin.config', 'admin.enllac', 'admin.generar_codis', 'admin.classes', 'admin.reactivar',
              'admin.codis_classe', 'admin.usuari', 'admin.usuari_treu', 'admin.contrasenya_propia'] as $acc) {
        comprova(in_array($acc, $accions, true), "el registre apunta $acc");
    }
    $quins = array_unique(array_column(array_filter($r['registre'] ?? [], function ($f) { return strpos($f['accio'], 'admin.') === 0; }), 'qui'));
    comprova(in_array('administració · anna', $quins, true) && in_array('administració · marta', $quins, true), 'el registre diu quina persona ha fet cada canvi');
    igual([], snapshot_errors($snap(), $mapa, $peces, disseny($cfg, 'paletes.json')), 'el snapshot continua essent vàlid');
};

$tmp = sys_get_temp_dir() . '/pessebre-admin-' . getmypid();
@mkdir($tmp, 0700, true);
$base = ['pebre' => bin2hex(random_bytes(32)), 'web' => "$ARREL/public_html", 'snapshot' => "$tmp/snapshot.json", 'bloqueig' => "$tmp/escriptura.lock",
         'data_simulada' => null, 'url_base' => 'https://pessebre.exemple.cat/'];
$prova_admin($base + ['bd_tipus' => 'sqlite', 'bd_sqlite' => "$tmp/pessebre.sqlite"], 'SQLite');
if (getenv('PESSEBRE_MYSQL_DSN')) {
    $prova_admin($base + ['bd_tipus' => 'mysql', 'bd_mysql' => ['dsn' => getenv('PESSEBRE_MYSQL_DSN'), 'usuari' => getenv('PESSEBRE_MYSQL_USUARI'), 'clau' => getenv('PESSEBRE_MYSQL_CLAU')]], 'MySQL');
}
array_map('unlink', glob("$tmp/*"));
rmdir($tmp);
