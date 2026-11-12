<?php
// Administració mínima (Fase D): dues contrasenyes (administració i només lectura) amb password_hash a config.php.
// Sense cookies no hi ha sessió: la pàgina demana la contrasenya a cada càrrega, la guarda només a memòria i l'envia
// a cada acció. 5 fallades en 15 minuts → cal esperar. Cada acció queda al registre.
declare(strict_types=1);

const ADMIN_FALLADES = 5;
const CLAUS_CALENDARI = ['classes_tanca', 'claustre_des', 'claustre_tanca', 'families_des', 'families_fins'];
const NOM_CLASSE = '/^[\p{L}\p{N} .·\'-]{1,8}$/u';
// Opcions numèriques editables: [mínim, màxim]
const CONFIG_NUMERICA = ['claustre_limit' => [1, 200], 'llindar_zona' => [1, 100], 'objectiu' => [10, 5000], 'minim_dies' => [0, 10],
                         'auto_posta' => [0, 100], 'auto_nit' => [0, 100], 'auto_neu' => [0, 100], 'auto_max_zona' => [0, 100]];

/** Comprova la contrasenya i retorna el rol ('admin' o 'lectura'). */
function admin_autentica(PDO $pdo, array $cfg, $contrasenya, string $ip, DateTimeImmutable $ara): string
{
    $empremta = hash('sha256', 'admin:' . limit_empremta($pdo, $ip, $ara));
    limit_comprova($pdo, $empremta, $ara, ADMIN_FALLADES);
    if (is_string($contrasenya) && $contrasenya !== '') {
        foreach (['admin' => 'hash_admin', 'lectura' => 'hash_lectura'] as $rol => $clau) {
            $hash = $cfg[$clau] ?? '';
            if ($hash !== '' && $hash !== 'CANVIA-AIXO' && password_verify($contrasenya, $hash)) return $rol;
        }
    }
    throw new ErrorPessebre('Contrasenya incorrecta.', 403, 'contrasenya');
}

function admin_apunta_fallada(PDO $pdo, string $ip, DateTimeImmutable $ara): void
{
    $pdo->beginTransaction();
    limit_fallada($pdo, hash('sha256', 'admin:' . limit_empremta($pdo, $ip, $ara)), $ara);
    $pdo->commit();
}

function hora_local(?string $utc): ?string
{
    if ($utc === null) return null;
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Madrid'))->format('Y-m-d H:i');
}

/** Resum per a la pestanya «Estat». */
function admin_estat(PDO $pdo, array $cfg, string $rol, DateTimeImmutable $ara): array
{
    $zones = [];
    $st = $pdo->query("SELECT l.zona, l.tipus, l.grup, COUNT(*) AS total,
                              SUM(CASE WHEN o.estat = 'confirmat' THEN 1 ELSE 0 END) AS ocupats
                       FROM llocs l LEFT JOIN ocupacions o ON o.lloc_id = l.id GROUP BY l.zona, l.tipus, l.grup ORDER BY l.zona");
    foreach ($st->fetchAll() as $r) {
        $clau = $r['grup'] === 'poble' ? 'classes' : $r['tipus'];
        $z = &$zones[$r['zona']][$clau];
        $z['total'] = ($z['total'] ?? 0) + (int)$r['total'];
        $z['ocupats'] = ($z['ocupats'] ?? 0) + (int)$r['ocupats'];
        unset($z);
    }
    $origens = $pdo->query("SELECT origen, COUNT(*) FROM ocupacions WHERE estat = 'confirmat' GROUP BY origen")->fetchAll(PDO::FETCH_KEY_PAIR);
    $ret = $pdo->prepare("SELECT lloc_id, origen, caduca FROM ocupacions WHERE estat = 'retingut' AND caduca > ? ORDER BY caduca");
    $ret->execute([bd_hora($ara)]);
    $classes = [];
    foreach ($pdo->query("SELECT c.etapa, c.nom, o.lloc_id, o.estat FROM classes c LEFT JOIN ocupacions o ON o.classe_id = c.id ORDER BY c.etapa, c.ordre") as $c) {
        $classes[$c['etapa']][] = ['nom' => $c['nom'], 'lloc' => $c['estat'] === 'confirmat' ? $c['lloc_id'] : null];
    }
    $enllacos = $pdo->query("SELECT tipus, etapa, creat FROM enllacos WHERE activa = 1")->fetchAll();
    $lots = $pdo->query("SELECT lot, COUNT(*) AS codis, SUM(CASE WHEN usos > 0 THEN 1 ELSE 0 END) AS usats, MIN(creat) AS creat FROM codis GROUP BY lot ORDER BY lot")->fetchAll();
    $config = [];
    foreach (CLAUS_CALENDARI as $k) $config[$k] = hora_local(config_llegeix($pdo, $k, DEFECTES_CALENDARI[$k] ?? '2026-12-21 22:59:00'));
    foreach (['aturada' => '0', 'ronda' => '1', 'zones' => 'Z0', 'claustre_limit' => '50', 'llindar_zona' => '75', 'objectiu' => '600',
              'minim_dies' => '1', 'auto_posta' => '35', 'auto_nit' => '50', 'auto_neu' => '60', 'auto_max_zona' => '40', 'tancat' => '0',
              'moment' => 'dia', 'auto_fase' => ''] as $k => $d) $config[$k] = config_llegeix($pdo, $k, $d);
    return [
        'rol' => $rol,
        'zones' => $zones,
        'origens' => $origens,
        'retencions' => array_map(function ($r) { return ['lloc' => $r['lloc_id'], 'origen' => $r['origen'], 'caduca' => hora_local($r['caduca'])]; }, $ret->fetchAll()),
        'classes' => $classes,
        'etapes' => ETAPES,
        'enllacos' => array_map(function ($e) { return ['tipus' => $e['tipus'], 'etapa' => $e['etapa'], 'creat' => hora_local($e['creat'])]; }, $enllacos),
        'lots' => $lots,
        'claustre' => claustre_estat($pdo, $ara),
        'fites' => array_map(function ($f) {
            return ['numero' => (int)$f['numero'], 'moment' => $f['moment'], 'base' => $f['base'], 'increment' => (int)$f['increment'],
                    'data_limit' => hora_local($f['data_limit']), 'llindar' => $f['llindar'] === null ? null : (int)$f['llindar'],
                    'assolida' => hora_local($f['assolida']), 'per' => $f['per']];
        }, fites($pdo)),
        'participacio' => participacio_real($pdo),
        'ritme' => fites_ritme($pdo, $cfg, $ara),
        'automatiques' => (int)$pdo->query("SELECT COUNT(*) FROM ocupacions WHERE origen = 'auto'")->fetchColumn(),
        'pot_tancar' => bd_hora($ara) >= calendari($pdo, 'families_fins'),
        'config' => $config,
        'url_base' => $cfg['url_base'] ?? '',
    ];
}

/** Tot el que se sap d'un lloc (pestanya «Lloc»). */
function admin_lloc(PDO $pdo, $lloc_id): array
{
    $l = is_string($lloc_id) ? lloc($pdo, $lloc_id) : null;
    if (!$l) throw new ErrorPessebre('Aquest lloc no existeix.', 400, 'lloc');
    $st = $pdo->prepare("SELECT o.*, c.nom AS classe FROM ocupacions o LEFT JOIN classes c ON c.id = o.classe_id WHERE o.lloc_id = ?");
    $st->execute([$l['id']]);
    $o = $st->fetch() ?: null;
    $reg = $pdo->prepare('SELECT hora, qui, accio, detall FROM registre WHERE lloc_id = ? ORDER BY id DESC LIMIT 20');
    $reg->execute([$l['id']]);
    $ocupacio = $o === null ? null : [
        'estat' => $o['estat'], 'origen' => $o['origen'], 'classe' => $o['classe'],
        'peca' => $o['peca'], 'mirall' => (int)$o['mirall'], 'colors' => json_decode((string)$o['colors'], true),
        'finestres' => (int)$o['finestres'], 'hora' => hora_local($o['hora']), 'caduca' => hora_local($o['caduca']),
    ];
    return ['lloc' => $l, 'ocupacio' => $ocupacio,
            'registre' => array_map(function ($r) { $r['hora'] = hora_local($r['hora']); return $r; }, $reg->fetchAll())];
}

/** Cerca un codi de família escrit (amb guions o sense). No diu on és la figura: no es desa (participació anònima). */
function admin_cerca_codi(PDO $pdo, array $cfg, $text): array
{
    $c = codi_normalitza($text);
    if ($c === null || !codi_control_ok($c)) throw new ErrorPessebre('El codi no té el format bo (o hi ha un caràcter mal copiat).', 400, 'format');
    $r = codi_busca($pdo, $cfg['pebre'], $c);
    if (!$r) return ['trobat' => false];
    return ['trobat' => true, 'lot' => (int)$r['lot'], 'usos' => (int)$r['usos'], 'permesos' => codi_usos_permesos($pdo)];
}

/** Torna a deixar fer servir un codi (quan l'administració ha retirat la figura d'una família que s'havia equivocat). */
function admin_reactiva_codi(PDO $pdo, array $cfg, $text, DateTimeImmutable $ara): array
{
    $r = admin_cerca_codi($pdo, $cfg, $text);
    if (!$r['trobat']) throw new ErrorPessebre('Aquest codi no existeix.', 400, 'codi_no');
    if ($r['usos'] === 0) throw new ErrorPessebre('Aquest codi encara no s\'ha fet servir.', 409, 'sense_usos');
    $pdo->prepare('UPDATE codis SET usos = usos - 1 WHERE hash = ? AND usos > 0')->execute([codi_hash($cfg['pebre'], codi_normalitza($text))]);
    registre_afegeix($pdo, $ara, 'administració', 'admin.reactivar', null, 'lot ' . $r['lot']);
    return ['usos' => $r['usos'] - 1];
}

function admin_registre(PDO $pdo, int $quants): array
{
    $st = $pdo->prepare('SELECT hora, qui, accio, lloc_id, detall FROM registre ORDER BY id DESC LIMIT ' . max(1, min(500, $quants)));
    $st->execute();
    return array_map(function ($r) { $r['hora'] = hora_local($r['hora']); return $r; }, $st->fetchAll());
}

/** Allibera un lloc. (El codi d'una família no se sap; si cal, es reactiva escrivint-lo a «Codis».) */
function admin_alliberar(PDO $pdo, $lloc_id, DateTimeImmutable $ara): array
{
    $info = admin_lloc($pdo, $lloc_id);
    $o = $info['ocupacio'];
    if (!$o) throw new ErrorPessebre('Aquest lloc ja és lliure.', 409, 'lliure');
    $pdo->prepare('DELETE FROM ocupacions WHERE lloc_id = ?')->execute([$info['lloc']['id']]);
    registre_afegeix($pdo, $ara, 'administració', 'admin.alliberar', $info['lloc']['id'], $o['classe'] ? 'classe ' . $o['classe'] : $o['origen']);
    return ['lloc' => $info['lloc']['id']];
}

/** Canvia la peça, els colors, el mirall o les finestres d'una ocupació confirmada (o hi posa una casa sense placa). */
function admin_ocupacio(PDO $pdo, array $cfg, array $p, DateTimeImmutable $ara, bool $sense_placa): array
{
    $l = is_string($p['lloc'] ?? null) ? lloc($pdo, $p['lloc']) : null;
    if (!$l) throw new ErrorPessebre('Aquest lloc no existeix.', 400, 'lloc');
    $st = $pdo->prepare('SELECT * FROM ocupacions WHERE lloc_id = ?');
    $st->execute([$l['id']]);
    $o = $st->fetch();
    $lloc = $l;
    if ($sense_placa) {
        // Casa sense placa en una casa de classe lliure (variant -SP del model del mapa)
        if ($l['grup'] !== 'poble') throw new ErrorPessebre('Només es poden posar cases sense placa als llocs de les cases de classe.', 400, 'lloc');
        if ($o) throw new ErrorPessebre('Aquest lloc no és lliure.', 409, 'ocupat');
        $lloc['codi'] = preg_replace('/-P$/', '-SP', $l['codi']);
        if (($p['peca'] ?? '') !== $lloc['codi']) throw new ErrorPessebre('La casa sense placa ha de ser la del mapa sense placa (' . $lloc['codi'] . ').', 400, 'peca');
    } else {
        if (!$o || $o['estat'] !== 'confirmat') throw new ErrorPessebre('Aquest lloc no té cap ocupació confirmada.', 409, 'lliure');
        if ($l['tipus'] === 'casa') $lloc['codi'] = $o['peca'];       // la casa continua essent la mateixa (amb placa o sense)
    }
    $peca = participacio_peca($cfg, $lloc, $p['peca'] ?? null, $p['mirall'] ?? 0, $p['colors'] ?? null, $p['finestres'] ?? 0);
    if ($sense_placa) {
        $pdo->prepare("INSERT INTO ocupacions (lloc_id, estat, origen, peca, mirall, colors, finestres, hora) VALUES (?, 'confirmat', 'admin', ?, ?, ?, ?, ?)")
            ->execute([$l['id'], $peca[0], $peca[1], $peca[2], $peca[3], bd_hora($ara)]);
        registre_afegeix($pdo, $ara, 'administració', 'admin.casa_sense_placa', $l['id'], $peca[0]);
    } else {
        $pdo->prepare('UPDATE ocupacions SET peca = ?, mirall = ?, colors = ?, finestres = ? WHERE lloc_id = ?')
            ->execute([$peca[0], $peca[1], $peca[2], $peca[3], $l['id']]);
        registre_afegeix($pdo, $ara, 'administració', 'admin.canvia', $l['id'], $peca[0] . ' ' . $peca[2]);
    }
    return ['lloc' => $l['id']];
}

/** Desa una opció de configuració de la llista permesa. Les dates arriben en hora de Barcelona («AAAA-MM-DD hh:mm»). */
function admin_config(PDO $pdo, $clau, $valor, DateTimeImmutable $ara): array
{
    $v = is_scalar($valor) ? trim((string)$valor) : '';
    if (in_array($clau, CLAUS_CALENDARI, true)) {
        $t = DateTimeImmutable::createFromFormat('!Y-m-d H:i', str_replace('T', ' ', $v), new DateTimeZone('Europe/Madrid'));
        if (!$t) throw new ErrorPessebre('La data ha de tenir el format AAAA-MM-DD hh:mm.', 400, 'valor');
        $desat = bd_hora($t);
    } elseif ($clau === 'aturada') {
        if ($v !== '0' && $v !== '1') throw new ErrorPessebre('Valor no vàlid.', 400, 'valor');
        $desat = $v;
    } elseif ($clau === 'ronda') {
        if ($v !== '1' && $v !== '2') throw new ErrorPessebre('Valor no vàlid.', 400, 'valor');
        $desat = $v;
    } elseif (isset(CONFIG_NUMERICA[$clau])) {
        [$min, $max] = CONFIG_NUMERICA[$clau];
        if (!preg_match('/^\d{1,4}$/', $v) || (int)$v < $min || (int)$v > $max) throw new ErrorPessebre("Ha de ser un nombre entre $min i $max.", 400, 'valor');
        $desat = (string)(int)$v;
    } elseif ($clau === 'zones') {
        // Forçar o endarrerir zones: sempre Z0 i en ordre (Z0, Z1, Z2, Z3)
        $triades = array_values(array_intersect(ORDRE_ZONES, explode(',', $v)));
        if (!$triades || $triades[0] !== 'Z0' || $triades !== array_slice(ORDRE_ZONES, 0, count($triades))) {
            throw new ErrorPessebre('Les zones s\'obren en ordre: Z0, després Z1, Z2 i Z3.', 400, 'valor');
        }
        $desat = implode(',', $triades);
    } else {
        throw new ErrorPessebre('Aquesta opció no es pot canviar.', 400, 'clau');
    }
    config_desa($pdo, $clau, $desat);
    registre_afegeix($pdo, $ara, 'administració', 'admin.config', null, "$clau = $v");
    return ['opcio' => $clau, 'valor' => $v];
}

/** Canvia la data límit o l'increment d'una fita que encara no s'ha assolit. */
function admin_fita(PDO $pdo, $numero, $data, $increment, DateTimeImmutable $ara): array
{
    $st = $pdo->prepare('SELECT * FROM fites WHERE numero = ?');
    $st->execute([is_int($numero) ? $numero : -1]);
    $f = $st->fetch();
    if (!$f) throw new ErrorPessebre('Aquesta fita no existeix.', 400, 'fita');
    if ($f['assolida'] !== null) throw new ErrorPessebre('Aquesta fita ja s\'ha assolit i no es desfà.', 409, 'assolida');
    $t = DateTimeImmutable::createFromFormat('!Y-m-d H:i', str_replace('T', ' ', (string)$data), new DateTimeZone('Europe/Madrid'));
    if (!$t) throw new ErrorPessebre('La data ha de tenir el format AAAA-MM-DD hh:mm.', 400, 'valor');
    if (!is_int($increment) || $increment < 1 || $increment > 100) throw new ErrorPessebre('L\'increment és un percentatge entre 1 i 100.', 400, 'valor');
    $pdo->prepare('UPDATE fites SET data_limit = ?, increment = ? WHERE numero = ?')->execute([bd_hora($t), $increment, $f['numero']]);
    // La posta es mesura en cases de classe: el seu llindar es pot recalcular; les altres, quan salti l'anterior
    if ($f['base'] === 'classes') {
        $poble = (int)$pdo->query("SELECT COUNT(*) FROM llocs WHERE grup = 'poble'")->fetchColumn();
        $pdo->prepare('UPDATE fites SET llindar = ? WHERE numero = ?')->execute([(int)ceil($poble * $increment / 100), $f['numero']]);
    }
    registre_afegeix($pdo, $ara, 'administració', 'admin.fita', null, "{$f['moment']}: $data, $increment %");
    return ['numero' => (int)$f['numero']];
}

/** L'administració afegeix una figura en un lloc lliure de les famílies d'una zona oberta (§22). Compta com a participació real. */
function admin_afegeix(PDO $pdo, array $cfg, array $p, DateTimeImmutable $ara): array
{
    if (bd_hora($ara) >= calendari($pdo, 'families_fins')) throw new ErrorPessebre('La participació ja s\'ha tancat.', 403, 'tancat');
    $l = lloc_lliure($pdo, $p['lloc'] ?? null);
    $permes = $l['grup'] === 'general' || ($l['grup'] === 'claustre' && claustre_alliberat($pdo, $ara));
    if (!$permes) throw new ErrorPessebre($l['grup'] === 'poble'
        ? 'És una casa de classe: s\'hi pot posar una casa sense placa.'
        : 'Aquest lloc no és de les famílies (encara és del claustre o és dels Reis).', 400, 'lloc');
    if (!in_array($l['zona'], zones_obertes($pdo), true)) throw new ErrorPessebre('Aquesta zona encara no és oberta.', 400, 'zona');
    $peca = participacio_peca($cfg, $l, $p['peca'] ?? null, $p['mirall'] ?? 0, $p['colors'] ?? null, $p['finestres'] ?? 0);
    $pdo->prepare("INSERT INTO ocupacions (lloc_id, estat, origen, peca, mirall, colors, finestres, hora) VALUES (?, 'confirmat', 'admin', ?, ?, ?, ?, ?)")
        ->execute([$l['id'], $peca[0], $peca[1], $peca[2], $peca[3], bd_hora($ara)]);
    registre_afegeix($pdo, $ara, 'administració', 'admin.afegeix', $l['id'], $peca[0]);
    return ['lloc' => $l['id'], 'zona_nova' => zones_actualitza($pdo, $ara)];
}

/** Tancament de Reis: s'esborren codis, enllaços, intents i registre; el pessebre queda com a pàgina estàtica. */
function admin_tancar(PDO $pdo, DateTimeImmutable $ara): array
{
    if (bd_hora($ara) < calendari($pdo, 'families_fins')) {
        throw new ErrorPessebre('Encara no s\'ha arribat a la data de tancament (' . hora_local(calendari($pdo, 'families_fins')) . ').', 409, 'aviat');
    }
    $pdo->exec("DELETE FROM ocupacions WHERE estat = 'retingut'");
    foreach (['codis', 'enllacos', 'intents', 'registre'] as $t) $pdo->exec("DELETE FROM $t");
    $pdo->exec("UPDATE ocupacions SET codi_id = NULL, hash_retencio = NULL");
    foreach (['sal', 'sal_hora', 'global_minut', 'global_fallades', 'auto_fase', 'auto_hora'] as $k) $pdo->prepare('DELETE FROM config WHERE clau = ?')->execute([$k]);
    config_desa($pdo, 'tancat', '1');
    return ['tancat' => true];
}

/** Carrega la llista de classes (línies «etapa;nom»). Retorna [quantes, avisos]. */
function classes_carrega(PDO $pdo, string $text): array
{
    $noves = [];
    foreach (preg_split('/\R/', $text) as $i => $linia) {
        $linia = trim(preg_replace('/^\xEF\xBB\xBF/', '', $linia));
        if ($linia === '' || $linia[0] === '#' || $linia === 'etapa;nom') continue;
        $parts = array_map('trim', explode(';', $linia));
        if (count($parts) !== 2 || !isset(ETAPES[$parts[0]])) throw new ErrorPessebre('Línia ' . ($i + 1) . ': cal «etapa;nom» amb etapa ' . implode(', ', array_keys(ETAPES)) . '.', 400, 'classes');
        if (!preg_match(NOM_CLASSE, $parts[1])) throw new ErrorPessebre('Línia ' . ($i + 1) . ": el nom «{$parts[1]}» ha de tenir com a molt 8 caràcters (lletres, xifres, espais, punts o guions).", 400, 'classes');
        if (isset($noves[$parts[1]])) throw new ErrorPessebre('Línia ' . ($i + 1) . ": el nom «{$parts[1]}» està repetit.", 400, 'classes');
        $noves[$parts[1]] = ['etapa' => $parts[0], 'ordre' => count($noves)];
    }
    $poble = (int)$pdo->query("SELECT COUNT(*) FROM llocs WHERE grup = 'poble'")->fetchColumn();
    if (count($noves) > $poble) throw new ErrorPessebre('Hi ha ' . count($noves) . " classes i només $poble cases de classe.", 400, 'classes');
    $avisos = [];
    foreach ($noves as $nom => $c) {
        $pdo->prepare('UPDATE classes SET etapa = ?, ordre = ? WHERE nom = ?')->execute([$c['etapa'], $c['ordre'], $nom]);
        $existeix = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE nom = ?');
        $existeix->execute([$nom]);
        if (!(int)$existeix->fetchColumn()) $pdo->prepare('INSERT INTO classes (etapa, nom, ordre) VALUES (?, ?, ?)')->execute([$c['etapa'], $nom, $c['ordre']]);
    }
    foreach ($pdo->query('SELECT c.id, c.nom, o.lloc_id FROM classes c LEFT JOIN ocupacions o ON o.classe_id = c.id')->fetchAll() as $c) {
        if (isset($noves[$c['nom']])) continue;
        if ($c['lloc_id']) { $avisos[] = "«{$c['nom']}» no és a la llista però ja té casa ({$c['lloc_id']}); es manté."; continue; }
        $pdo->prepare('DELETE FROM classes WHERE id = ?')->execute([$c['id']]);
    }
    return [count($noves), $avisos];
}

/** Executa una acció d'administració. Les de lectura les pot fer qualsevol de les dues contrasenyes. */
function admin_accio(PDO $pdo, array $cfg, string $a, array $p, string $ip, DateTimeImmutable $ara): array
{
    $lectura = ['admin.estat', 'admin.lloc', 'admin.cercar', 'admin.registre'];
    if (in_array($a, $lectura, true)) {
        $rol = app_transaccio($pdo, function () use ($pdo, $cfg, $p, $ip, $ara) { return admin_autentica($pdo, $cfg, $p['contrasenya'] ?? null, $ip, $ara); });
        switch ($a) {
            case 'admin.estat': return admin_estat($pdo, $cfg, $rol, $ara);
            case 'admin.lloc': return admin_lloc($pdo, $p['lloc'] ?? null);
            case 'admin.cercar': return admin_cerca_codi($pdo, $cfg, $p['codi'] ?? null);
            default: return ['registre' => admin_registre($pdo, (int)($p['quants'] ?? 100))];
        }
    }
    return app_escriu($pdo, $cfg, $ara, function () use ($pdo, $cfg, $a, $p, $ip, $ara) {
        if (admin_autentica($pdo, $cfg, $p['contrasenya'] ?? null, $ip, $ara) !== 'admin') {
            throw new ErrorPessebre('Amb la contrasenya de lectura no es poden fer canvis.', 403, 'lectura');
        }
        switch ($a) {
            case 'admin.alliberar': return admin_alliberar($pdo, $p['lloc'] ?? null, $ara);
            case 'admin.reactivar': return admin_reactiva_codi($pdo, $cfg, $p['codi'] ?? null, $ara);
            case 'admin.fita': return admin_fita($pdo, $p['numero'] ?? null, $p['data'] ?? null, $p['increment'] ?? null, $ara);
            case 'admin.retirar_auto': return ['retirades' => auto_retira($pdo, $ara)];
            case 'admin.tancar': return admin_tancar($pdo, $ara);
            case 'admin.canvia': return admin_ocupacio($pdo, $cfg, $p, $ara, false);
            case 'admin.casa_sense_placa': return admin_ocupacio($pdo, $cfg, $p, $ara, true);
            case 'admin.afegir': return admin_afegeix($pdo, $cfg, $p, $ara);
            case 'admin.config': return admin_config($pdo, $p['opcio'] ?? null, $p['valor'] ?? null, $ara);
            case 'admin.enllac':
                $tipus = ($p['tipus'] ?? '') === 'claustre' ? 'claustre' : 'etapa';
                $etapa = $tipus === 'etapa' ? ($p['etapa'] ?? '') : null;
                if ($tipus === 'etapa' && !isset(ETAPES[$etapa])) throw new ErrorPessebre('Etapa desconeguda.', 400, 'etapa');
                $t = enllac_crea($pdo, $cfg['pebre'], $tipus, $etapa, $ara);
                registre_afegeix($pdo, $ara, 'administració', 'admin.enllac', null, $tipus . ($etapa ? " $etapa" : ''));
                return ['url' => $cfg['url_base'] . ($tipus === 'claustre' ? '#k=' : 'classes.html#e=') . $t];
            case 'admin.generar_codis':
                $n = (int)($p['quants'] ?? 0);
                if ($n < 1 || $n > 1000) throw new ErrorPessebre('Es poden generar entre 1 i 1000 codis de cop.', 400, 'quants');
                $lot = codis_genera($pdo, $cfg['pebre'], $n, $ara);
                registre_afegeix($pdo, $ara, 'administració', 'admin.generar_codis', null, "lot {$lot['lot']}: $n codis");
                return ['lot' => $lot['lot'], 'url_base' => $cfg['url_base'],
                        'codis' => array_map(function ($c) use ($cfg) { return ['codi' => codi_impres($c), 'qr' => qr_svg($cfg['url_base'] . '#c=' . $c)]; }, $lot['codis'])];
            case 'admin.classes':
                [$n, $avisos] = classes_carrega($pdo, (string)($p['text'] ?? ''));
                registre_afegeix($pdo, $ara, 'administració', 'admin.classes', null, "$n classes");
                return ['classes' => $n, 'avisos' => $avisos];
            default:
                throw new ErrorPessebre('Acció desconeguda.', 400, 'accio');
        }
    });
}
