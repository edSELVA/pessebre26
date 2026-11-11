<?php
// Fase C: codis de família, QR, etiquetes, participació de famílies i claustre, segona ronda i obertura de zones.
require_once $ARREL . '/pessebre/src/app.php';
require_once $ARREL . '/pessebre/src/etiquetes.php';

// ── Codis
$codis = [];
for ($i = 0; $i < 300; $i++) $codis[] = codi_nou();
comprova(count(array_filter($codis, 'codi_control_ok')) === 300, 'tots els codis generats tenen el control bo');
comprova(count(array_filter($codis, function ($c) { return preg_match('/^[2-9A-HJKMNP-Z]{10}$/', $c) === 1; })) === 300, 'només caràcters de l\'alfabet sense ambigus');
$detectats = 0; $provats = 0;
foreach (array_slice($codis, 0, 60) as $c) {
    for ($p = 0; $p < 10; $p++) {
        foreach (str_split(ALFABET_CODIS) as $x) {
            if ($x === $c[$p]) continue;
            $mal = substr_replace($c, $x, $p, 1);
            $provats++;
            if (!codi_control_ok($mal)) $detectats++;
        }
    }
}
igual($provats, $detectats, 'el control detecta qualsevol caràcter mal copiat');
$transp = 0; $tdet = 0;
foreach (array_slice($codis, 0, 200) as $c) {
    for ($p = 0; $p < 9; $p++) {
        if ($c[$p] === $c[$p + 1]) continue;
        $transp++;
        if (!codi_control_ok(substr($c, 0, $p) . $c[$p + 1] . $c[$p] . substr($c, $p + 2))) $tdet++;
    }
}
igual($transp, $tdet, 'el control detecta qualsevol intercanvi de dos caràcters veïns');
igual('K7MQX4P9TR', codi_normalitza(' k7m-qx4 p9tr '), 'normalitza guions, espais i minúscules');
igual(null, codi_normalitza('K7M-QX4-P9T0'), 'rebutja caràcters ambigus (0, O, 1, I, L)');
igual('K7M-QX4-P9TR', codi_impres('K7MQX4P9TR'), 'format imprès amb guions');

// ── QR i etiquetes
igual(21, count(qr_matriu('HOLA')), 'QR curt: versió 1 (21×21)');
igual(33, count(qr_matriu('https://pessebre.escola.exemple.cat/#c=K7MQX4P9TR')), 'QR d\'una adreça amb codi: versió 4 (33×33)');
$m = qr_matriu('HOLA');
comprova($m[0][0] && $m[0][6] && $m[6][0] && !$m[1][1] && $m[2][2], 'el QR té el patró de posició a la cantonada');
llança(function () { qr_matriu(str_repeat('x', 200)); }, 'el QR refusa textos massa llargs');
$html = etiquetes_html(array_slice($codis, 0, 30), 'https://pessebre.exemple.cat/', 7);
igual(30, substr_count($html, 'class="et"'), 'una etiqueta per codi');
igual(2, substr_count($html, 'class="full"'), '24 per full: 30 codis ocupen 2 fulls');
comprova(strpos($html, codi_impres($codis[0])) !== false, 'l\'etiqueta mostra el codi amb guions');
comprova(strpos($html, '<script') === false, 'el full d\'etiquetes no té scripts');

// ── Participació (API) amb SQLite i, si n'hi ha, MySQL
$prova_families = function (array $cfg, string $nom) use ($ARREL) {
    echo "    ($nom)\n";
    $pdo = bd_connecta($cfg);
    foreach (['intents', 'config', 'registre', 'ocupacions', 'enllacos', 'classes', 'codis', 'llocs'] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
    bd_esquema($pdo);
    $mapa = disseny($cfg, 'mapa.json');
    llocs_carrega($pdo, $mapa);
    $t0 = new DateTimeImmutable('2026-12-15 09:00:00', new DateTimeZone('UTC'));
    $c = codis_genera($pdo, $cfg['pebre'], 6, $t0)['codis'];
    $k = enllac_crea($pdo, $cfg['pebre'], 'claustre', null, $t0);
    $crida = function (array $p, string $hora, string $ip = '10.1.0.1') use ($pdo, $cfg) {
        return app_executa($pdo, $cfg, $p, $ip, new DateTimeImmutable($hora, new DateTimeZone('UTC')));
    };
    $snap = function () use ($cfg) { return json_decode((string)file_get_contents($cfg['snapshot']), true); };
    // Colors d'una figura: els que es donen i 0 per a la resta de parts personalitzables (pell, cabells…)
    $col = function (string $peca, array $c) use ($cfg) {
        return array_pad($c, count(disseny($cfg, 'peces.json')['peces'][$peca]['pers']), 0);
    };
    $un = function (callable $f) use ($mapa) { foreach ($mapa['llocs'] as $l) if ($f($l)) return $l; return null; };
    $persona = $un(function ($l) { return $l['tipus'] === 'persona' && $l['grup'] === 'general' && $l['zona'] === 'Z0'; });
    $persona2 = $un(function ($l) use ($persona) { return $l['tipus'] === 'persona' && $l['grup'] === 'general' && $l['zona'] === 'Z0' && $l['id'] !== $persona['id']; });
    $casa = $un(function ($l) { return $l['tipus'] === 'casa' && $l['grup'] === 'general' && $l['zona'] === 'Z0'; });
    $element = $un(function ($l) { return $l['tipus'] === 'element' && $l['grup'] === 'general' && $l['zona'] === 'Z0'; });
    $animal = $un(function ($l) { return $l['tipus'] === 'animal' && $l['grup'] === 'general' && $l['zona'] === 'Z0'; });
    $z1 = $un(function ($l) { return $l['grup'] === 'general' && $l['zona'] === 'Z1'; });
    $pastor = $un(function ($l) { return !empty($l['pastor']); });
    $pastor2 = $un(function ($l) use ($pastor) { return !empty($l['pastor']) && $l['id'] !== $pastor['id']; });
    $claus_el = $un(function ($l) { return $l['grup'] === 'claustre' && $l['tipus'] === 'element'; });

    // Abans del 21 de desembre
    [$e, $r] = $crida(['a' => 'codi.validar', 'c' => $c[0]], '2026-12-15 10:00:00');
    igual([200, false], [$e, $r['pot'] ?? null], 'abans del 21 des el codi és bo però encara no es pot participar');
    // Errors de transcripció: no compten com a intents
    for ($i = 0; $i < 12; $i++) $crida(['a' => 'codi.validar', 'c' => substr($c[0], 0, 9) . ($c[0][9] === '2' ? '3' : '2')], '2026-12-15 10:00:00', '10.1.0.9');
    [$e, $r] = $crida(['a' => 'codi.validar', 'c' => $c[0]], '2026-12-15 10:00:00', '10.1.0.9');
    igual(200, $e, 'els errors del caràcter de control no compten com a intents');
    [$e, $r] = $crida(['a' => 'codi.validar', 'c' => 'ABC'], '2026-12-15 10:00:00');
    igual([400, 'format'], [$e, $r['codi'] ?? ''], 'codi amb format dolent → error de format');
    $inventat = 'ABCDEFGHJ' . codi_control('ABCDEFGHJ');
    for ($i = 0; $i < 10; $i++) $crida(['a' => 'codi.validar', 'c' => $inventat], '2026-12-15 10:00:00', '10.1.0.8');
    [$e] = $crida(['a' => 'codi.validar', 'c' => $c[0]], '2026-12-15 10:00:00', '10.1.0.8');
    igual(429, $e, '10 codis inexistents (amb control bo) → cal esperar');

    // Claustre: 21 des, abans de les 22:00
    $h = '2026-12-21 17:00:00';
    [$e, $r] = $crida(['a' => 'claustre.estat', 'k' => $k], $h);
    igual([200, true], [$e, $r['pot'] ?? null], 'el claustre pot participar el 21 des');
    [$e, $r] = $crida(['a' => 'claustre.estat', 'k' => 'x'], $h, '10.1.0.7');
    igual(403, $e, 'enllaç del claustre dolent → 403');
    [$e, $r] = $crida(['a' => 'claustre.retenir', 'k' => $k, 'lloc' => $persona['id']], $h);
    igual([400, 'lloc'], [$e, $r['codi'] ?? ''], 'el claustre no pot triar llocs de les famílies');
    [$e, $r] = $crida(['a' => 'claustre.retenir', 'k' => $k, 'lloc' => $pastor['id']], $h);
    igual(200, $e, 'el claustre reté un pastor');
    [$e, $r2] = $crida(['a' => 'claustre.confirmar', 'k' => $k, 'lloc' => $pastor['id'], 'retencio' => $r['retencio'] ?? '', 'peca' => 'PER-PAS-F', 'mirall' => 1, 'colors' => $col('PER-PAS-F', [6, 0])], $h);
    igual([400, 'colors'], [$e, $r2['codi'] ?? ''], 'un pastor no pot portar colors fora de la paleta de terra');
    [$e] = $crida(['a' => 'claustre.confirmar', 'k' => $k, 'lloc' => $pastor['id'], 'retencio' => $r['retencio'] ?? '', 'peca' => 'PER-PAS-F', 'mirall' => 1, 'colors' => $col('PER-PAS-F', [3, 1])], $h);
    igual(200, $e, 'el pastor es confirma amb la paleta de terra');
    igual(['p' => 'PER-PAS-F', 'm' => 1, 'c' => $col('PER-PAS-F', [3, 1])], $snap()['o'][$pastor['id']] ?? null, 'el snapshot porta el pastor');
    igual(true, $snap()['claustre'], 'el snapshot indica que el claustre és obert');
    config_desa($pdo, 'claustre_limit', '1');
    [$e, $r] = $crida(['a' => 'claustre.retenir', 'k' => $k, 'lloc' => $pastor2['id']], $h);
    igual([403, 'claustre_no'], [$e, $r['codi'] ?? ''], 'el claustre no pot passar del límit de participacions');
    config_desa($pdo, 'claustre_limit', '50');
    [$e, $r] = $crida(['a' => 'claustre.retenir', 'k' => $k, 'lloc' => $pastor2['id']], '2026-12-21 21:00:00');
    igual([403, 'claustre_no'], [$e, $r['codi'] ?? ''], 'a les 22:00 del 21 des el claustre es tanca');

    // Famílies: 22 des
    $h = '2026-12-22 10:00:00';
    [$e, $r] = $crida(['a' => 'codi.validar', 'c' => codi_impres($c[0])], $h);
    igual([200, true], [$e, $r['pot'] ?? null], 'el 22 des el codi (amb guions) permet participar');
    [$e, $r] = $crida(['a' => 'codi.retenir', 'c' => $c[0], 'lloc' => 'C001'], $h);
    igual([400, 'lloc'], [$e, $r['codi'] ?? ''], 'una família no pot triar una casa de classe');
    [$e, $r] = $crida(['a' => 'codi.retenir', 'c' => $c[0], 'lloc' => $z1['id']], $h);
    igual([400, 'zona'], [$e, $r['codi'] ?? ''], 'una família no pot triar en una zona tancada');
    [$e, $r] = $crida(['a' => 'codi.retenir', 'c' => $c[0], 'lloc' => $persona['id']], $h);
    igual(200, $e, 'una família reté un lloc de persona');
    $ret = $r['retencio'] ?? '';
    [$e, $r] = $crida(['a' => 'codi.retenir', 'c' => $c[1], 'lloc' => $persona['id']], $h);
    igual(409, $e, 'una altra família no pot retenir el mateix lloc');
    [$e, $r] = $crida(['a' => 'codi.confirmar', 'c' => $c[0], 'lloc' => $persona['id'], 'retencio' => $ret, 'peca' => 'ANI-GOS-D', 'mirall' => 0], $h);
    igual([400, 'peca'], [$e, $r['codi'] ?? ''], 'no es pot posar un animal en un lloc de persona');
    [$e, $r] = $crida(['a' => 'codi.confirmar', 'c' => $c[0], 'lloc' => $persona['id'], 'retencio' => $ret, 'peca' => 'ESC-MARIA', 'mirall' => 0], $h);
    igual([400, 'peca'], [$e, $r['codi'] ?? ''], 'no es poden posar peces de l\'escena fixa');
    [$e, $r] = $crida(['a' => 'codi.confirmar', 'c' => $c[0], 'lloc' => $persona['id'], 'retencio' => $ret, 'peca' => 'PER-PAS-F', 'mirall' => 1, 'colors' => $col('PER-PAS-F', [6, 3])], $h);
    igual(200, $e, 'una família pot posar un pastor (PER-PAS-F) amb la paleta normal');
    [$e, $r] = $crida(['a' => 'codi.retenir', 'c' => $c[0], 'lloc' => $persona2['id']], $h);
    igual([403, 'codi_no'], [$e, $r['codi'] ?? ''], 'un codi només serveix per a una figura');
    [, $r] = $crida(['a' => 'codi.validar', 'c' => $c[0]], $h);
    igual(false, $r['pot'] ?? null, 'el codi gastat ho diu en validar-lo');

    // Segona ronda
    config_desa($pdo, 'ronda', '2');
    [$e, $r] = $crida(['a' => 'codi.retenir', 'c' => $c[0], 'lloc' => $casa['id']], $h);
    igual(200, $e, 'amb la segona ronda el mateix codi pot tornar a participar');
    [$e, $r] = $crida(['a' => 'codi.confirmar', 'c' => $c[0], 'lloc' => $casa['id'], 'retencio' => $r['retencio'] ?? '', 'peca' => $casa['codi'], 'colors' => array_slice([2, 1, 0, 3], 0, count(disseny($cfg, 'peces.json')['peces'][$casa['codi']]['pers']))], $h);
    igual(200, $e, 'una família pinta una casa de la perifèria');
    [$e] = $crida(['a' => 'codi.retenir', 'c' => $c[0], 'lloc' => $persona2['id']], $h);
    igual(403, $e, 'a la segona ronda, com a molt dues figures per codi');
    config_desa($pdo, 'ronda', '1');

    // Element (sense colors) i llocs del claustre alliberats
    [, $r] = $crida(['a' => 'codi.retenir', 'c' => $c[1], 'lloc' => $element['id']], $h);
    [$e] = $crida(['a' => 'codi.confirmar', 'c' => $c[1], 'lloc' => $element['id'], 'retencio' => $r['retencio'] ?? '', 'peca' => $element['codi']], $h);
    igual(200, $e, 'una família planta un element (sense colors)');
    igual(true, $snap()['claustre_alliberat'], 'després de les 22:00 del 21 des els llocs del claustre són de les famílies');
    // Pelatge dels animals i to de pell
    [, $r] = $crida(['a' => 'codi.retenir', 'c' => $c[4], 'lloc' => $animal['id']], $h);
    $ret5 = $r['retencio'] ?? '';
    [$e] = $crida(['a' => 'codi.confirmar', 'c' => $c[4], 'lloc' => $animal['id'], 'retencio' => $ret5, 'peca' => 'ANI-GAT-D', 'mirall' => 0, 'colors' => [5]], $h);
    igual(400, $e, 'un pelatge fora de la paleta → error');
    [$e] = $crida(['a' => 'codi.confirmar', 'c' => $c[4], 'lloc' => $animal['id'], 'retencio' => $ret5, 'peca' => 'ANI-GAT-D', 'mirall' => 0, 'colors' => [2]], $h);
    igual(200, $e, 'una família posa un gat negre');
    igual([2], $snap()['o'][$animal['id']]['c'] ?? null, 'el snapshot porta el pelatge del gat');
    [, $r] = $crida(['a' => 'codi.retenir', 'c' => $c[5], 'lloc' => $persona2['id']], $h);
    $ret6 = $r['retencio'] ?? '';
    [$e] = $crida(['a' => 'codi.confirmar', 'c' => $c[5], 'lloc' => $persona2['id'], 'retencio' => $ret6, 'peca' => 'PER-ADU-F', 'mirall' => 0, 'colors' => [0, 0, 5]], $h);
    igual(400, $e, 'un to de pell fora de la paleta → error');
    [$e] = $crida(['a' => 'codi.deixar', 'c' => $c[5], 'lloc' => $persona2['id'], 'retencio' => $ret6], $h);
    [$e, $r] = $crida(['a' => 'codi.retenir', 'c' => $c[2], 'lloc' => $pastor2['id']], $h);
    igual(200, $e, 'una família pot ocupar un pastor que el claustre ha deixat lliure');
    [$e, $r] = $crida(['a' => 'codi.confirmar', 'c' => $c[2], 'lloc' => $pastor2['id'], 'retencio' => $r['retencio'] ?? '', 'peca' => 'PER-ADU-F', 'mirall' => 0, 'colors' => $col('PER-ADU-F', [2, 1])], $h);
    igual(200, $e, 'als llocs de pastor, també les famílies fan servir la paleta de terra');
    [$e, $r] = $crida(['a' => 'codi.retenir', 'c' => $c[3], 'lloc' => $claus_el['id']], $h);
    igual(in_array($claus_el['zona'], ['Z0'], true) ? 200 : 400, $e, 'un element del claustre alliberat només es pot triar si la seva zona és oberta');

    // Obertura automàtica de zones
    config_desa($pdo, 'llindar_zona', '1');
    [, $r] = $crida(['a' => 'codi.retenir', 'c' => $c[3], 'lloc' => $persona2['id']], $h);
    $ret4 = $r['retencio'] ?? '';
    [$e] = $crida(['a' => 'codi.confirmar', 'c' => $c[3], 'lloc' => $persona2['id'], 'retencio' => $ret4, 'peca' => 'ANI-GOS-D', 'mirall' => 0], $h);
    igual(400, $e, 'en un lloc de persona no hi va un gos');
    [$e, $r] = $crida(['a' => 'codi.confirmar', 'c' => $c[3], 'lloc' => $persona2['id'], 'retencio' => $ret4, 'peca' => 'PER-INF-F', 'mirall' => 0, 'colors' => $col('PER-INF-F', [1])], $h);
    $s = $snap();
    // Esperat: s'obren zones mentre les obertes tinguin com a mínim l'1 % de llocs ocupats (7 participacions reals)
    $esperades = ['Z0'];
    foreach (['Z1', 'Z2', 'Z3'] as $z) {
        $total = count(array_filter($mapa['llocs'], function ($l) use ($esperades) { return $l['grup'] !== 'poble' && in_array($l['zona'], $esperades, true); }));
        $reals = count(array_filter(array_keys($s['o']), function ($id) use ($mapa, $esperades) {
            foreach ($mapa['llocs'] as $l) if ($l['id'] === $id) return $l['grup'] !== 'poble' && in_array($l['zona'], $esperades, true);
            return false;
        }));
        if ($reals * 100 < 1 * $total) break;
        $esperades[] = $z;
    }
    comprova(count($esperades) > 1, 'amb un llindar de l\'1 % s\'obre almenys una zona nova');
    igual($esperades, $s['zones'], 'les zones s\'obren en ordre i s\'aturen quan les obertes no arriben al llindar');
    comprova(!empty($r['zona_nova']), 'la resposta avisa que s\'ha obert una zona');
    $obre = $pdo->query("SELECT COUNT(*) FROM registre WHERE accio = 'zones.obre'")->fetchColumn();
    igual(count($esperades) - 1, (int)$obre, 'el registre apunta cada zona oberta');
    igual([], snapshot_errors($s, $mapa, disseny($cfg, 'peces.json')['peces'], disseny($cfg, 'paletes.json')), 'el snapshot generat és vàlid');
    igual(['tipus' => 'participacions', 'fet' => 7, 'fita' => null], $s['comptador'], 'comptador: 7 participacions reals');

    // Registre sense dades personals
    $qui = $pdo->query("SELECT DISTINCT qui FROM registre")->fetchAll(PDO::FETCH_COLUMN);
    comprova(count(array_filter($qui, function ($q) { return preg_match('/^(codi #\d+ \(lot \d+\)|claustre|automàtic)$/u', $q) !== 1; })) === 0, 'el registre només apunta «codi #n (lot)», «claustre» o «automàtic»');
    $text = json_encode($pdo->query('SELECT * FROM registre')->fetchAll());
    foreach ($c as $codi) comprova(strpos($text, $codi) === false, 'el registre no conté cap codi en clar');
    comprova(strpos(json_encode($pdo->query('SELECT * FROM codis')->fetchAll()), $c[0]) === false, 'la taula de codis no conté cap codi en clar');
};

$tmp = sys_get_temp_dir() . '/pessebre-familia-' . getmypid();
@mkdir($tmp, 0700, true);
$base = ['pebre' => bin2hex(random_bytes(32)), 'web' => "$ARREL/public_html", 'snapshot' => "$tmp/snapshot.json", 'bloqueig' => "$tmp/escriptura.lock", 'data_simulada' => null];
$prova_families($base + ['bd_tipus' => 'sqlite', 'bd_sqlite' => "$tmp/pessebre.sqlite"], 'SQLite');
if (getenv('PESSEBRE_MYSQL_DSN')) {
    $prova_families($base + ['bd_tipus' => 'mysql', 'bd_mysql' => ['dsn' => getenv('PESSEBRE_MYSQL_DSN'), 'usuari' => getenv('PESSEBRE_MYSQL_USUARI'), 'clau' => getenv('PESSEBRE_MYSQL_CLAU')]], 'MySQL');
}
array_map('unlink', glob("$tmp/*"));
rmdir($tmp);
