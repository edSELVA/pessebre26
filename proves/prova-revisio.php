<?php
// Correccions de la revisió del 16 nov 2026 que les altres proves no cobreixen: límit d'intents per xarxa (IPv4 en format
// IPv6 i IPv6 per /64), contrasenyes no trivials, confirmacions idempotents, llista de classes amb versió, llindar de la
// posta segons les classes de la llista i automàtiques que esperen 48 h després d'obrir les famílies.
require_once $ARREL . '/pessebre/src/app.php';

// ── Límit d'intents: quina «xarxa» és cada adreça
igual('83.45.1.2', ip_xarxa('::ffff:83.45.1.2'), 'una IPv4 en format IPv6 (::ffff:) es tracta com la IPv4');
igual('83.45.1.2', ip_xarxa('83.45.1.2'), 'una IPv4 es tracta sencera');
igual(ip_xarxa('2a01:4f8:1:2::10'), ip_xarxa('2a01:4f8:1:2:aaaa::99'), 'dues IPv6 del mateix /64 són la mateixa xarxa');
comprova(ip_xarxa('2a01:4f8:1:2::10') !== ip_xarxa('2a01:4f8:1:3::10'), 'dues IPv6 de /64 diferents no són la mateixa xarxa');
comprova(ip_xarxa('::ffff:83.45.1.2') !== ip_xarxa('::ffff:83.45.1.3'), 'dues IPv4 en format IPv6 no comparteixen límit');

// ── Contrasenyes
llança(function () { contrasenya_valida('curta-123'); }, 'una contrasenya de menys de 12 caràcters no val');
llança(function () { contrasenya_valida('pessebre2026'); }, '«pessebre2026» és massa fàcil');
llança(function () { contrasenya_valida('NadalEscola2026!'); }, 'paraules del context i xifres no fan una contrasenya bona');
llança(function () { contrasenya_valida('marta-marta-1234', 'marta'); }, 'el nom d\'usuari repetit no fa una contrasenya bona');
igual('tres paraules qualssevol', contrasenya_valida('tres paraules qualssevol', 'marta'), 'tres paraules qualssevol són una contrasenya bona');

$prova_revisio = function (array $cfg, string $nom) {
    echo "    ($nom)\n";
    $pdo = bd_connecta($cfg);
    foreach (['fites', 'intents', 'config', 'registre', 'ocupacions', 'enllacos', 'classes', 'codis', 'codis_etapa', 'llocs', 'usuaris'] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
    app_installa($pdo, $cfg);
    $utc = function (string $h) { return new DateTimeImmutable($h, new DateTimeZone('UTC')); };
    usuari_desa($pdo, 'anna', 'admin-de-prova-1234', 'admin', $utc('2026-12-01 09:00:00'), 4);
    $crida = function (array $p, string $hora, string $ip = '10.6.0.1') use ($pdo, $cfg, $utc) { return app_executa($pdo, $cfg, $p, $ip, $utc($hora)); };
    $admin = function (string $a, array $p, string $hora) use ($crida) { return $crida(['a' => $a, 'usuari' => 'anna', 'contrasenya' => 'admin-de-prova-1234'] + $p, $hora, '10.6.9.9'); };
    $peces = disseny($cfg, 'peces.json')['peces'];

    // Valors per defecte decidits el 17 nov: 3 figures per codi, 2 dies entre fites, torronada el 21 des de 17:00 a 22:00
    igual(['3', '600', '2'], [config_llegeix($pdo, 'usos_codi'), config_llegeix($pdo, 'objectiu'), config_llegeix($pdo, 'minim_dies')], 'per defecte: 3 figures per codi, objectiu 600 i 2 dies entre fites');
    igual(['2026-12-21 17:00', '2026-12-21 22:00'], [hora_local(calendari($pdo, 'claustre_des')), hora_local(calendari($pdo, 'claustre_tanca'))], 'per defecte la torronada és el 21 des de 17:00 a 22:00');

    // Llista de classes amb versió: si algú l'ha canviat mentrestant, no se substitueix
    $text = '';
    for ($i = 1; $i <= 50; $i++) $text .= 'primaria;C ' . $i . "\n";
    [$e, $r] = $admin('admin.classes', ['text' => $text], '2026-12-01 10:00:00');
    igual([200, 50], [$e, $r['classes'] ?? null], 'es carreguen 50 classes');
    [$e, $r] = $admin('admin.classes', ['text' => "primaria;Una\n", 'versio' => 'una-versio-antiga'], '2026-12-01 10:05:00');
    igual(409, $e, 'amb una versió antiga de la llista, no se substitueix (409)');
    igual(50, (int)$pdo->query('SELECT COUNT(*) FROM classes')->fetchColumn(), 'i la llista continua sent la de 50 classes');

    // Llindar de la posta: el 70 % de les classes de la llista (no de les 74 cases)
    fites_recalcula_posta($pdo);
    igual(35, (int)fites($pdo)[0]['llindar'], 'amb 50 classes, la posta salta amb 35 cases (70 %)');

    // Confirmació idempotent: si es perd la resposta i el mòbil torna a enviar la confirmació, es dona per bona
    $codi = codis_genera($pdo, $cfg['pebre'], 1, $utc('2026-12-01 10:00:00'))['codis'][0];
    $lloc = null;
    foreach (disseny($cfg, 'mapa.json')['llocs'] as $l) if ($l['grup'] === 'general' && $l['zona'] === 'Z0' && $l['tipus'] === 'persona') { $lloc = $l; break; }
    $h = '2026-12-22 10:00:00';
    [$e, $r] = $crida(['a' => 'codi.retenir', 'c' => $codi, 'lloc' => $lloc['id']], $h);
    igual(200, $e, 'la família reté un lloc');
    $conf = ['a' => 'codi.confirmar', 'c' => $codi, 'lloc' => $lloc['id'], 'retencio' => $r['retencio'] ?? '', 'peca' => 'PER-ADU-F', 'mirall' => 0,
             'colors' => array_fill(0, count($peces['PER-ADU-F']['pers']), 0)];
    [$e, $r1] = $crida($conf, $h);
    igual([200, 2], [$e, $r1['queden'] ?? null], 'confirma la primera figura: en queden 2');
    [$e, $r2] = $crida($conf, '2026-12-22 10:01:00');
    igual([200, true, 2], [$e, $r2['repetida'] ?? null, $r2['queden'] ?? null], 'la mateixa confirmació repetida es dona per bona i no gasta cap figura');
    igual(1, (int)$pdo->query('SELECT usos FROM codis')->fetchColumn(), 'el codi només s\'ha fet servir una vegada');

    // Automàtiques: no comencen fins 48 h després d'obrir les famílies (encara que la posta salti per data el 21 des)
    $autos = function () use ($pdo) { return (int)$pdo->query("SELECT COUNT(*) FROM ocupacions WHERE origen = 'auto'")->fetchColumn(); };
    $tick = function (string $hora) use ($crida, $cfg) { @touch($cfg['snapshot'], 0); return $crida(['a' => 'tick'], $hora); };
    $t = $utc('2026-12-20 23:30:00');
    for ($i = 0; $i < 46; $i++) $tick($t->modify("+$i hours")->format('Y-m-d H:i:s'));
    igual('posta', config_llegeix($pdo, 'moment'), 'la posta ha saltat per data el 21 des');
    igual(0, $autos(), 'durant les primeres 48 h de les famílies no hi ha automàtiques');
    for ($i = 46; $i < 56; $i++) $tick($t->modify("+$i hours")->format('Y-m-d H:i:s'));
    comprova($autos() > 0, 'passades les 48 h, comencen les automàtiques');
};

$tmp = sys_get_temp_dir() . '/pessebre-revisio-' . getmypid();
@mkdir($tmp, 0700, true);
$base = ['pebre' => bin2hex(random_bytes(32)), 'llavor' => 'llavor-de-prova', 'web' => "$ARREL/public_html", 'snapshot' => "$tmp/snapshot.json", 'bloqueig' => "$tmp/escriptura.lock", 'data_simulada' => null];
$prova_revisio($base + ['bd_tipus' => 'sqlite', 'bd_sqlite' => "$tmp/pessebre.sqlite"], 'SQLite');
if (getenv('PESSEBRE_MYSQL_DSN')) {
    $prova_revisio($base + ['bd_tipus' => 'mysql', 'bd_mysql' => ['dsn' => getenv('PESSEBRE_MYSQL_DSN'), 'usuari' => getenv('PESSEBRE_MYSQL_USUARI'), 'clau' => getenv('PESSEBRE_MYSQL_CLAU')]], 'MySQL');
}
array_map('unlink', glob("$tmp/*"));
rmdir($tmp);
