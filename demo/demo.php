<?php
// DEMOSTRACIÓ del pessebre (per ensenyar-lo des de l'ordinador i el mòbil a la xarxa local). MAI a l'hosting.
// Només funciona amb un config.php que porti 'demo' => true, i aquest config.php el crea demo/prepara.php dins de
// demo/dades/ (que no es puja mai). La base de dades de la demo és pròpia: no toca la de proves.
//
// Com funciona:
//  · Rellotge simulat: l'hora de la demo = l'hora real + un desfasament (desat a demo/dades/estat.json). El temps
//    continua passant (les retencions caduquen), i «Deixar passar el temps» avança el desfasament.
//  · Cada fase es munta des de zero repetint la mateixa història amb les funcions reals del servidor (classes,
//    claustre, famílies, fites, participació automàtica). Fa servir un atzar amb llavor fixa: sempre surt igual.
//  · Els codis de família i els enllaços d'etapa i del claustre són inventats i es desen a estat.json per poder
//    entrar «com a» tutor, claustre o família des del panell.
declare(strict_types=1);
require_once __DIR__ . '/../pessebre/src/app.php';

const DEMO_FASES = [
    1 => ['nom' => 'Inici', 'fins' => '2026-12-01 10:00'],
    2 => ['nom' => 'Tutories en marxa', 'fins' => '2026-12-16 12:30'],
    3 => ['nom' => 'Torronada del claustre', 'fins' => '2026-12-21 18:30'],
    4 => ['nom' => 'Famílies', 'fins' => '2026-12-24 13:00'],
    5 => ['nom' => 'Fites per data i participació automàtica', 'fins' => '2026-12-27 01:30'],
    6 => ['nom' => 'Tancat (Reis)', 'fins' => '2027-01-06 11:00'],
];

// Què passa a cada fase (hora de Barcelona): [inici, qui, quants, minuts entre una i l'altra]
const DEMO_FETS = [
    2 => [['2026-12-14 09:00', 'classe', 34, 90]],
    3 => [['2026-12-17 09:00', 'classe', 24, 120], ['2026-12-21 08:00', 'familia', 8, 30], ['2026-12-21 16:00', 'claustre', 28, 4]],
    4 => [['2026-12-21 18:40', 'claustre', 16, 6], ['2026-12-21 19:00', 'classe', 8, 20], ['2026-12-22 09:00', 'familia', 62, 50]],
    5 => [['2026-12-25 10:00', 'familia', 12, 180]],
    6 => [['2026-12-27 12:00', 'familia', 25, 480]],
];

// Classes de la demo: les reals del fitxer local classes.csv (ignorat pel git) si hi és i la configuració ho indica
// ('demo_classes'); si no, unes d'inventades (66, noms de 8 caràcters com a molt), que són les que fan servir les proves.
function demo_classes(array $cfg = []): string
{
    $fitxer = $cfg['demo_classes'] ?? null;
    if (is_string($fitxer) && is_file($fitxer)) return (string)file_get_contents($fitxer);
    $l = ['llar;P0 A', 'llar;P0 B', 'llar;P1 A', 'llar;P1 B', 'llar;P2 A', 'llar;P2 B'];
    foreach (['P3', 'P4', 'P5'] as $c) foreach (['A', 'B', 'C'] as $g) $l[] = "infantil;$c $g";
    foreach (['1r', '2n', '3r', '4t', '5è', '6è'] as $c) foreach (['A', 'B', 'C'] as $g) $l[] = "primaria;$c $g";
    foreach (['1r', '2n', '3r', '4t'] as $c) foreach (['A', 'B', 'C', 'D'] as $g) $l[] = "secundaria;$c ESO $g";
    foreach (['1r', '2n'] as $c) foreach (['A', 'B', 'C'] as $g) $l[] = "secundaria;$c BAT $g";
    foreach (['SMX', 'DAM', 'GA', 'AF', 'CAI'] as $c) foreach (['1r', '2n'] as $n) $l[] = "cicles;$n $c";
    return implode("\n", $l);
}

function demo_utc(string $local): DateTimeImmutable
{
    return (new DateTimeImmutable($local, new DateTimeZone('Europe/Madrid')))->setTimezone(new DateTimeZone('UTC'));
}

// ───────────────────────────── estat i rellotge

function demo_cami_estat(array $cfg): string { return $cfg['demo_estat']; }

function demo_estat(array $cfg): ?array
{
    $e = is_file(demo_cami_estat($cfg)) ? json_decode((string)file_get_contents(demo_cami_estat($cfg)), true) : null;
    return is_array($e) ? $e : null;
}

function demo_desa(array $cfg, array $e): void
{
    file_put_contents(demo_cami_estat($cfg) . '.tmp', json_encode($e, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    rename(demo_cami_estat($cfg) . '.tmp', demo_cami_estat($cfg));
}

/** L'hora de la demo (UTC): l'hora real + el desfasament. */
function demo_ara(array $e): DateTimeImmutable
{
    return (new DateTimeImmutable('@' . (time() + (int)$e['desfasament'])))->setTimezone(new DateTimeZone('UTC'));
}

/** En engegar la demo, el rellotge torna a l'hora de la fase més les hores que s'hi han fet passar amb els botons
 *  (si no, una fase preparada el dia abans ja no seria la mateixa: la torronada s'hauria tancat). */
function demo_rellotge_a_lloc(array $cfg, array $e): array
{
    $e['desfasament'] = demo_utc(DEMO_FASES[$e['fase']]['fins'])->getTimestamp() + (int)($e['hores'] ?? 0) * 3600 - time();
    demo_desa($cfg, $e);
    return $e;
}

// ───────────────────────────── figures a l'atzar (llavor fixa)

function demo_tria(array $llista)
{
    return $llista[mt_rand(0, count($llista) - 1)];
}

/** Peça, mirall, colors i finestres a l'atzar per a un lloc: [peça, mirall, colors|null, finestres]. */
function demo_figura(array $cfg, array $l): array
{
    $peces = disseny($cfg, 'peces.json')['peces'];
    $paletes = disseny($cfg, 'paletes.json');
    if ($l['tipus'] === 'casa') $opcions = [$l['codi']];
    elseif ($l['tipus'] === 'element') $opcions = $peces[$l['codi']]['alternatives'] ?? [$l['codi']];
    else $opcions = array_keys(array_filter($peces, function ($p) use ($l) { return $p['cat'] === $l['tipus']; }));
    $peca = demo_tria($opcions);
    $grup = grup_paleta($peces[$peca]['cat'], lloc_es_pastor($cfg, $l['id']));
    $colors = [];
    foreach ($peces[$peca]['pers'] as $rol) $colors[] = mt_rand(0, count(($paletes[$grup][$rol] ?? $paletes['persones'][$rol])['opcions']) - 1);
    $m = in_array($l['tipus'], ['persona', 'animal'], true) ? mt_rand(0, 1) : (int)$l['mirall'];
    $n = count($peces[$peca]['finestres'] ?? []);
    $f = $l['tipus'] === 'casa' && $n > 0 && mt_rand(1, 5) === 1 ? 1 << mt_rand(0, $n - 1) : 0;
    return [$peca, $m, $colors ?: null, $f];
}

/** Llocs lliures que compleixen la condició SQL (sobre la taula llocs «l»). */
function demo_lliures(PDO $pdo, string $on, array $params = []): array
{
    $st = $pdo->prepare("SELECT l.* FROM llocs l LEFT JOIN ocupacions o ON o.lloc_id = l.id WHERE o.lloc_id IS NULL AND $on ORDER BY l.id");
    $st->execute($params);
    return $st->fetchAll();
}

/** Una participació d'una classe, del claustre o d'una família, feta amb les funcions reals del servidor. */
function demo_participa(PDO $pdo, array $cfg, array &$e, string $qui, DateTimeImmutable $ara): void
{
    if ($qui === 'classe') {
        $classes = $pdo->query('SELECT c.* FROM classes c LEFT JOIN ocupacions o ON o.classe_id = c.id WHERE o.lloc_id IS NULL ORDER BY c.id')->fetchAll();
        $llocs = demo_lliures($pdo, "l.grup = 'poble'");
        if (!$classes || !$llocs) return;
        $c = demo_tria($classes);
        $l = demo_tria($llocs);
        [$peca, , $colors, $f] = demo_figura($cfg, $l);
        $r = classes_retenir($pdo, $cfg, $c['etapa'], (int)$c['id'], $l['id'], $ara);
        classes_confirmar($pdo, $cfg, $c['etapa'], (int)$c['id'], $l['id'], $r['retencio'], $colors, $ara, null, $f);
    } elseif ($qui === 'claustre') {
        $llocs = demo_lliures($pdo, "l.grup = 'claustre'");
        $pastors = array_values(array_filter($llocs, function ($l) use ($cfg) { return lloc_es_pastor($cfg, $l['id']); }));
        if (!$llocs) return;
        $l = demo_tria($pastors ?: $llocs);                 // primer els pastors
        [$peca, $m, $colors, $f] = demo_figura($cfg, $l);
        $r = claustre_retenir($pdo, $cfg, $l['id'], $ara);
        claustre_confirmar($pdo, $cfg, $l['id'], $r['retencio'], $peca, $m, $colors, $ara, $f);
    } else {
        $zones = zones_obertes($pdo);
        $marques = implode(',', array_fill(0, count($zones), '?'));
        $grups = claustre_alliberat($pdo, $ara) ? "('general', 'claustre')" : "('general')";
        $tipus = demo_tria(['persona', 'persona', 'persona', 'persona', 'persona', 'animal', 'animal', 'element', 'element', 'casa']);
        $llocs = demo_lliures($pdo, "l.grup IN $grups AND l.zona IN ($marques) AND l.tipus = ?", array_merge($zones, [$tipus]))
            ?: demo_lliures($pdo, "l.grup IN $grups AND l.zona IN ($marques)", $zones);
        if (!$llocs) return;
        $l = demo_tria($llocs);
        $codi = demo_codi_lliure($pdo, $cfg, $e);
        if (!$codi) return;
        [$peca, $m, $colors, $f] = demo_figura($cfg, $l);
        $fila = codi_busca($pdo, $cfg['pebre'], $codi);
        $r = codi_retenir($pdo, $cfg, $fila, $l['id'], $ara);
        codi_confirmar($pdo, $cfg, codi_busca($pdo, $cfg['pebre'], $codi), $l['id'], $r['retencio'], $peca, $m, $colors, $ara, $f);
    }
}

/** El primer codi de la demo que encara es pot fer servir (o null). */
function demo_codi_lliure(PDO $pdo, array $cfg, array $e): ?string
{
    $permesos = codi_usos_permesos($pdo);
    foreach ($e['codis'] as $c) {
        $f = codi_busca($pdo, $cfg['pebre'], $c);
        if ($f && (int)$f['usos'] < $permesos) return $c;
    }
    return null;
}

// ───────────────────────────── muntar una fase

/** Esborra la base de dades de la demo i hi torna a fer tot el que passa fins al final de la fase. */
function demo_munta(PDO $pdo, array $cfg, int $fase): array
{
    if (!isset(DEMO_FASES[$fase])) throw new ErrorPessebre('Aquesta fase no existeix.', 400, 'fase');
    $bloqueig = fopen($cfg['bloqueig'], 'c');
    flock($bloqueig, LOCK_EX);
    try { return demo_munta_fase($pdo, $cfg, $fase); } finally { flock($bloqueig, LOCK_UN); fclose($bloqueig); }
}

function demo_munta_fase(PDO $pdo, array $cfg, int $fase): array
{
    foreach (['fites', 'intents', 'config', 'registre', 'ocupacions', 'enllacos', 'classes', 'codis', 'llocs'] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
    app_installa($pdo, $cfg);
    mt_srand(2026);
    $inici = demo_utc('2026-12-01 09:00');
    $fi = demo_utc(DEMO_FASES[$fase]['fins']);
    $e = ['fase' => $fase, 'hores' => 0, 'desfasament' => 0, 'enllacos' => [], 'codis' => []];

    $pdo->beginTransaction();
    classes_carrega($pdo, demo_classes($cfg));
    foreach (array_keys(ETAPES) as $etapa) $e['enllacos'][$etapa] = enllac_crea($pdo, $cfg['pebre'], 'etapa', $etapa, $inici);
    $e['enllacos']['claustre'] = enllac_crea($pdo, $cfg['pebre'], 'claustre', null, $inici);
    $e['codis'] = codis_genera($pdo, $cfg['pebre'], 150, $inici)['codis'];

    // La història fins a aquesta fase: participacions i, cada hora, el que faria el «tick» (fites i automàtiques)
    $fets = [];
    for ($n = 2; $n <= $fase; $n++) {
        foreach (DEMO_FETS[$n] as [$des, $qui, $quants, $minuts]) {
            for ($i = 0; $i < $quants; $i++) $fets[] = [demo_utc($des)->modify('+' . ($i * $minuts) . ' minutes'), $qui];
        }
    }
    for ($t = $inici; $t <= $fi; $t = $t->modify('+1 hour')) $fets[] = [$t, 'tick'];
    usort($fets, function ($a, $b) { return $a[0] <=> $b[0] ?: strcmp($a[1], $b[1]); });
    foreach ($fets as [$t, $qui]) {
        if ($t > $fi) break;
        reserva_purga($pdo, $t);
        if ($qui !== 'tick') demo_participa($pdo, $cfg, $e, $qui, $t);
        app_progressa($pdo, $cfg, $t);
    }
    app_progressa($pdo, $cfg, $fi);
    $pdo->commit();
    snapshot_regenera($pdo, $cfg, $fi);
    $e['desfasament'] = $fi->getTimestamp() - time();
    demo_desa($cfg, $e);
    return $e;
}

// ───────────────────────────── accions del panell

/** Treu totes les figures de les famílies i torna els codis a zero (la resta de la fase es manté). */
function demo_reinicia_families(PDO $pdo, array $cfg, array $e): void
{
    app_escriu($pdo, $cfg, demo_ara($e), function () use ($pdo, $e) {
        $n = $pdo->exec("DELETE FROM ocupacions WHERE origen = 'familia'");
        $pdo->exec('UPDATE codis SET usos = 0');
        registre_afegeix($pdo, demo_ara($e), 'demostració', 'demo.reinicia_families', null, "$n figures");
    });
}

/** Avança el rellotge de la demo i fa, hora per hora, el que faria el «tick» (fites per data i automàtiques). */
function demo_temps(PDO $pdo, array $cfg, array $e, int $hores): array
{
    if ($hores < 1 || $hores > 240) throw new ErrorPessebre('Massa temps de cop.', 400, 'temps');
    $abans = demo_ara($e);
    $e['desfasament'] += $hores * 3600;
    $e['hores'] = ($e['hores'] ?? 0) + $hores;
    $despres = demo_ara($e);
    demo_desa($cfg, $e);
    app_escriu($pdo, $cfg, $despres, function () use ($pdo, $cfg, $abans, $despres) {
        $t = $abans->setTime((int)$abans->format('H'), 0)->modify('+1 hour');
        for (; $t <= $despres; $t = $t->modify('+1 hour')) {
            reserva_purga($pdo, $t);
            app_progressa($pdo, $cfg, $t);
        }
    });
    return $e;
}

const DEMO_DIES = ['diumenge', 'dilluns', 'dimarts', 'dimecres', 'dijous', 'divendres', 'dissabte'];
const DEMO_MESOS = [1 => 'de gener', 'de febrer', 'de març', 'd\'abril', 'de maig', 'de juny', 'de juliol', 'd\'agost', 'de setembre', 'd\'octubre', 'de novembre', 'de desembre'];
const DEMO_MOMENTS = ['dia' => 'De dia', 'posta' => 'Posta de sol', 'nit' => 'Nit', 'neu' => 'Neu'];

/** El que mostra el panell: fase, hora simulada i a quines pàgines es pot entrar «com a». */
function demo_resum(PDO $pdo, array $cfg, array $e): array
{
    $ara = demo_ara($e);
    $local = $ara->setTimezone(new DateTimeZone('Europe/Madrid'));
    $hora = DEMO_DIES[(int)$local->format('w')] . ', ' . $local->format('j') . ' ' . DEMO_MESOS[(int)$local->format('n')] . ' de ' . $local->format('Y') . ' · ' . $local->format('H:i');
    $tancat = config_llegeix($pdo, 'tancat', '0') === '1' || bd_hora($ara) >= calendari($pdo, 'families_fins');

    $rols = [['rol' => 'public', 'nom' => 'Públic', 'url' => 'index.html']];
    if (!$tancat && bd_hora($ara) < config_llegeix($pdo, 'classes_tanca', '2026-12-21 22:59:00')) {
        $etapa = $pdo->query("SELECT c.etapa FROM classes c LEFT JOIN ocupacions o ON o.classe_id = c.id AND o.estat = 'confirmat'
                              WHERE o.lloc_id IS NULL ORDER BY CASE c.etapa WHEN 'primaria' THEN 0 ELSE 1 END, c.ordre LIMIT 1")->fetchColumn();
        if ($etapa) $rols[] = ['rol' => 'tutor', 'nom' => 'Tutor (' . ETAPES[$etapa] . ')', 'url' => 'classes.html#e=' . $e['enllacos'][$etapa]];
    }
    if (!$tancat && finestra($pdo, 'claustre_des', 'claustre_tanca', $ara) === 'obert') {
        $rols[] = ['rol' => 'claustre', 'nom' => 'Claustre', 'url' => 'index.html#k=' . $e['enllacos']['claustre']];
    }
    if (!$tancat && finestra($pdo, 'families_des', 'families_fins', $ara) === 'obert') {
        $codi = demo_codi_lliure($pdo, $cfg, $e);
        if ($codi) $rols[] = ['rol' => 'familia', 'nom' => 'Família', 'url' => 'index.html#c=' . $codi];
    }
    $rols[] = ['rol' => 'admin', 'nom' => 'Administració', 'url' => 'admin.html'];

    $fases = [];
    foreach (DEMO_FASES as $n => $f) $fases[] = ['n' => $n, 'nom' => $f['nom']];
    return [
        'fase' => (int)$e['fase'],
        'fases' => $fases,
        'hora' => $hora,
        'moment' => DEMO_MOMENTS[config_llegeix($pdo, 'moment', 'dia')] ?? '',
        'tancat' => $tancat,
        'rols' => $rols,
    ];
}

/** Accions «demo.*» del panell. */
function demo_accio(PDO $pdo, array $cfg, string $a, array $p): array
{
    $e = demo_estat($cfg) ?? demo_munta($pdo, $cfg, 1);
    switch ($a) {
        case 'demo.estat': break;
        case 'demo.fase': $e = demo_munta($pdo, $cfg, is_int($p['fase'] ?? null) ? $p['fase'] : 0); break;
        case 'demo.reinicia_families': demo_reinicia_families($pdo, $cfg, $e); break;
        case 'demo.temps': $e = demo_temps($pdo, $cfg, $e, is_int($p['hores'] ?? null) ? $p['hores'] : 0); break;
        default: throw new ErrorPessebre('Acció de demostració desconeguda.', 400, 'accio');
    }
    return demo_resum($pdo, $cfg, $e);
}
