<?php
// Fase E: el calendari sencer amb hores simulades — fites (posta per participació, nit per data, neu per participació),
// participació automàtica (a poc a poc, amb màxim per zona, reproduïble i retirable), comptador i tancament de Reis.
require_once $ARREL . '/pessebre/src/app.php';

$prova_fites = function (array $cfg, string $nom) {
    echo "    ($nom)\n";
    $pdo = bd_connecta($cfg);
    foreach (['fites', 'intents', 'config', 'registre', 'ocupacions', 'enllacos', 'classes', 'codis', 'llocs'] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
    bd_esquema($pdo);
    $mapa = disseny($cfg, 'mapa.json');
    llocs_carrega($pdo, $mapa);
    fites_inicialitza($pdo);
    $crida = function (array $p, string $hora) use ($pdo, $cfg) {
        return app_executa($pdo, $cfg, $p, '10.3.0.1', new DateTimeImmutable($hora, new DateTimeZone('UTC')));
    };
    $tick = function (string $hora) use ($crida) { return $crida(['a' => 'tick'], $hora); };
    $snap = function () use ($cfg) { return json_decode((string)file_get_contents($cfg['snapshot']), true); };
    $moment = function () use ($pdo) { return config_llegeix($pdo, 'moment', 'dia'); };
    $fita = function (int $n) use ($pdo) { $s = $pdo->prepare('SELECT * FROM fites WHERE numero = ?'); $s->execute([$n]); return $s->fetch(); };
    $autos = function () use ($pdo) { return (int)$pdo->query("SELECT COUNT(*) FROM ocupacions WHERE origen = 'auto'")->fetchColumn(); };
    $peces = disseny($cfg, 'peces.json')['peces'];
    $posa = function (array $llocs, string $origen, string $hora) use ($pdo, $peces) {
        $ins = $pdo->prepare("INSERT INTO ocupacions (lloc_id, estat, origen, classe_id, peca, mirall, colors, hora) VALUES (?, 'confirmat', ?, ?, ?, 0, ?, ?)");
        foreach ($llocs as $i => $l) {
            $classe = null;
            if ($origen === 'classe') {
                $pdo->prepare('INSERT INTO classes (etapa, nom, ordre) VALUES (?, ?, ?)')->execute(['primaria', 'C' . $l['id'], $i]);
                $classe = (int)$pdo->lastInsertId();
            }
            $peca = $l['codi'] ?? ($l['tipus'] === 'animal' ? 'ANI-OVELLA-D' : 'PER-ADU-F');
            $ins->execute([$l['id'], $origen, $classe, $peca, json_encode(array_fill(0, count($peces[$peca]['pers']), 0)), $hora]);
        }
    };
    $llocs = function (callable $f, int $n) use ($pdo, $mapa) {
        $ocupats = $pdo->query('SELECT lloc_id FROM ocupacions')->fetchAll(PDO::FETCH_COLUMN);
        return array_slice(array_values(array_filter($mapa['llocs'], function ($l) use ($f, $ocupats) { return $f($l) && !in_array($l['id'], $ocupats, true); })), 0, $n);
    };

    // Inici: de dia, comptador de classes
    $tick('2026-12-01 10:00:00');
    igual('dia', $snap()['moment'], 'el pessebre comença de dia');
    igual(52, (int)$fita(1)['llindar'], 'la posta salta amb 52 de 74 cases de classe (70 %)');

    // 10 des: 51 cases → encara de dia; 52 → posta per participació
    $posa($llocs(function ($l) { return $l['grup'] === 'poble'; }, 51), 'classe', '2026-12-10 09:00:00');
    $tick('2026-12-10 10:00:00');
    igual('dia', $moment(), 'amb 51 cases encara és de dia');
    $posa($llocs(function ($l) { return $l['grup'] === 'poble'; }, 1), 'classe', '2026-12-10 10:30:00');
    $tick('2026-12-10 11:00:00');
    igual(['posta', 'llindar'], [$moment(), $fita(1)['per']], 'la casa 52 fa saltar la posta (per participació)');
    igual(52 + 150, (int)$fita(2)['llindar'], 'el llindar de la nit es fixa en saltar la posta: 52 + 150 (25 % de 600)');
    igual(0, $autos(), 'si la fita salta per participació, no hi ha automàtiques');
    igual(['tipus' => 'classes', 'fet' => 52, 'total' => 74], $snap()['comptador'], 'durant les tutories el comptador mostra les cases de classe');

    // 22 des: famílies; el comptador passa a participacions amb la propera fita (sense data)
    $posa($llocs(function ($l) { return $l['grup'] === 'general' && $l['zona'] === 'Z0'; }, 10), 'familia', '2026-12-22 10:00:00');
    $tick('2026-12-22 11:00:00');
    igual(['tipus' => 'participacions', 'fet' => 62, 'fita' => 202], $snap()['comptador'], 'comptador: 62 participacions · propera fita a 202');
    comprova(strpos(json_encode($snap()), '2026-12-26') === false, 'el snapshot no revela la data límit de cap fita');

    // 27 des (Barcelona): la nit salta per data i comença la participació automàtica
    $tick('2026-12-26 23:30:00');
    igual(['nit', 'data'], [$moment(), $fita(2)['per']], 'el 27 de desembre la nit salta per data');
    igual(62 + 150, (int)$fita(3)['llindar'], 'el llindar de la neu: participació d\'ara + 150');
    igual(AUTO_PER_HORA, $autos(), 'la primera hora s\'afegeixen ' . AUTO_PER_HORA . ' automàtiques');
    $tick('2026-12-26 23:50:00');
    igual(AUTO_PER_HORA, $autos(), 'dins la mateixa hora no se n\'afegeixen més');
    $primeres = $pdo->query("SELECT lloc_id FROM ocupacions WHERE origen = 'auto' ORDER BY lloc_id")->fetchAll(PDO::FETCH_COLUMN);
    for ($h = 0; $h < 20; $h++) $tick((new DateTimeImmutable('2026-12-27 00:10:00', new DateTimeZone('UTC')))->modify("+$h hours")->format('Y-m-d H:i:s'));
    $z0 = count(array_filter($mapa['llocs'], function ($l) { return $l['grup'] !== 'poble' && $l['zona'] === 'Z0'; }));
    igual((int)floor($z0 * 40 / 100), $autos(), 'les automàtiques s\'aturen al 40 % de la zona (' . floor($z0 * 40 / 100) . ' de ' . $z0 . ')');
    $mal = $pdo->query("SELECT COUNT(*) FROM ocupacions o JOIN llocs l ON l.id = o.lloc_id WHERE o.origen = 'auto' AND (l.grup <> 'general' OR l.zona <> 'Z0')")->fetchColumn();
    igual(0, (int)$mal, 'les automàtiques només ocupen llocs de les famílies de zones obertes');
    igual(62, $snap()['comptador']['fet'], 'les automàtiques no compten al comptador');
    igual(['Z0'], $snap()['zones'], 'les automàtiques no obren zones');
    igual([], snapshot_errors($snap(), $mapa, disseny($cfg, 'peces.json')['peces'], disseny($cfg, 'paletes.json')), 'el snapshot amb automàtiques és vàlid');

    // Reproduïble: retirar i tornar a començar dona les mateixes primeres figures
    $hash_abans = md5(json_encode($pdo->query("SELECT lloc_id, peca, mirall, colors FROM ocupacions WHERE origen = 'auto' ORDER BY lloc_id")->fetchAll()));
    $pdo->exec("DELETE FROM ocupacions WHERE origen = 'auto'");
    config_desa($pdo, 'auto_fase', 'nit');
    $pdo->prepare('DELETE FROM config WHERE clau = ?')->execute(['auto_hora']);
    $tick('2026-12-28 10:00:00');
    igual($primeres, $pdo->query("SELECT lloc_id FROM ocupacions WHERE origen = 'auto' ORDER BY lloc_id")->fetchAll(PDO::FETCH_COLUMN), 'amb la mateixa llavor surten les mateixes figures (reproduïble)');

    // L'administració les retira totes i no en tornen a sortir
    $pdo->beginTransaction();
    auto_retira($pdo, new DateTimeImmutable('2026-12-28 11:00:00', new DateTimeZone('UTC')));
    $pdo->commit();
    $tick('2026-12-28 13:00:00');
    igual(0, $autos(), 'retirades les automàtiques, no se n\'afegeixen més');

    // Neu per participació (212), però no abans d'un dia de la nit
    $posa($llocs(function ($l) { return $l['grup'] === 'general' && $l['zona'] === 'Z0'; }, 150), 'familia', '2026-12-28 14:00:00');
    $tick('2026-12-28 15:00:00');
    igual(['neu', 'llindar'], [$moment(), $fita(3)['per']], 'amb 212 participacions neva (per participació)');
    igual(null, $snap()['comptador']['fita'], 'després de la darrera fita, el comptador no té propera fita');

    // Una fita assolida no es pot editar
    $pdo->beginTransaction();
    llança(function () use ($pdo) { admin_fita($pdo, 3, '2027-01-02 00:00', 25, new DateTimeImmutable('2026-12-29', new DateTimeZone('UTC'))); }, 'una fita assolida no es pot canviar');
    $pdo->rollBack();

    // Tancament de Reis
    $codi = codis_genera($pdo, $cfg['pebre'], 1, new DateTimeImmutable('2026-12-29', new DateTimeZone('UTC')))['codis'][0];
    $pdo->beginTransaction();
    llança(function () use ($pdo) { admin_tancar($pdo, new DateTimeImmutable('2027-01-04 10:00:00', new DateTimeZone('UTC'))); }, 'abans de Reis no es pot tancar i esborrar');
    $pdo->rollBack();
    $tick('2027-01-05 23:30:00');
    igual(true, $snap()['tancat'], 'a l\'hora de Reis el snapshot diu que està tancat');
    [$e] = $crida(['a' => 'codi.retenir', 'c' => $codi, 'lloc' => 'P0001'], '2027-01-05 23:30:00');
    igual(403, $e, 'després de Reis les famílies ja no poden participar');
    $pdo->beginTransaction();
    admin_tancar($pdo, new DateTimeImmutable('2027-01-06 09:00:00', new DateTimeZone('UTC')));
    $pdo->commit();
    foreach (['codis', 'enllacos', 'intents', 'registre'] as $t) igual(0, (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(), "en tancar s'esborra la taula $t");
    comprova((int)$pdo->query("SELECT COUNT(*) FROM ocupacions WHERE estat = 'confirmat'")->fetchColumn() > 200, 'les figures es mantenen (el pessebre queda com a record)');
    [$e, $r] = $crida(['a' => 'codi.validar', 'c' => $codi], '2027-01-06 10:00:00');
    igual([403, 'tancat'], [$e, $r['codi'] ?? ''], 'un cop tancat, qualsevol escriptura respon «tancat»');
    [$e, $r] = $tick('2027-01-06 10:00:00');
    igual(false, $r['fet'] ?? null, 'un cop tancat, el tick no fa res');
};

$tmp = sys_get_temp_dir() . '/pessebre-fites-' . getmypid();
@mkdir($tmp, 0700, true);
$base = ['pebre' => bin2hex(random_bytes(32)), 'llavor' => 'llavor-de-prova', 'web' => "$ARREL/public_html", 'snapshot' => "$tmp/snapshot.json",
         'bloqueig' => "$tmp/escriptura.lock", 'data_simulada' => null, 'url_base' => 'https://pessebre.exemple.cat/'];
$prova_fites($base + ['bd_tipus' => 'sqlite', 'bd_sqlite' => "$tmp/pessebre.sqlite"], 'SQLite');
if (getenv('PESSEBRE_MYSQL_DSN')) {
    $prova_fites($base + ['bd_tipus' => 'mysql', 'bd_mysql' => ['dsn' => getenv('PESSEBRE_MYSQL_DSN'), 'usuari' => getenv('PESSEBRE_MYSQL_USUARI'), 'clau' => getenv('PESSEBRE_MYSQL_CLAU')]], 'MySQL');
}
array_map('unlink', glob("$tmp/*"));
rmdir($tmp);
