<?php
// Format i validació de snapshot.json (el mapa públic estàtic).
// A la Fase A només el fan servir les dades d'exemple i les proves; a la Fase B el generarà el servidor.
//
// Format:
// {
//   "versio": 1,                     número que augmenta a cada canvi
//   "generat": "2026-12-23T18:00:00Z",  hora UTC
//   "data": "2026-12-23",            dia del servidor a Europe/Madrid (posició dels Reis)
//   "moment": "dia|posta|nit|neu",
//   "zones": ["Z0", "Z1"],           zones obertes
//   "classes": true,                 període de tutories obert (les cases de classe lliures es veuen en transparent)
//   "claustre": false,               enllaç del claustre obert (21 des fins a les 22:00)
//   "claustre_alliberat": false,     els llocs del claustre que han quedat lliures ja són de les famílies
//   "comptador": {"tipus": "classes", "fet": 52, "total": 74}
//             o {"tipus": "participacions", "fet": 312, "fita": 400}  (fita null després de l'última)
//   "o": {"C001": {"p": "CASA-BAIXA-P", "c": [2, 0, 1], "n": "Nom"}, "P0001": {"p": "ANI-GOS-D", "m": 1}, …}
//        p = peça, m = mirall (0/1), c = índexs de paleta en l'ordre de "pers" de la peça, n = nom de placa (fins a 8 caràcters)
//   "r": ["P0012", …]                llocs retinguts (només l'id)
// }
// Mai s'hi posen colors en cru ni dates de les fites.
declare(strict_types=1);
ini_set('serialize_precision', '-1');   // decimals curts a json_encode, sigui quin sigui el php.ini

const SNAPSHOT_MOMENTS = ['dia', 'posta', 'nit', 'neu'];

/** Retorna una llista d'errors (buida si el snapshot és vàlid). */
function snapshot_errors(array $s, array $mapa, array $peces, array $paletes): array
{
    $e = [];
    $llocs = [];
    foreach ($mapa['llocs'] as $l) $llocs[$l['id']] = $l;

    if (!is_int($s['versio'] ?? null) || $s['versio'] < 1) $e[] = 'versio ha de ser un enter positiu';
    if (!is_string($s['generat'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $s['generat'])) $e[] = 'generat ha de ser una hora UTC';
    if (!is_string($s['data'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s['data'])) $e[] = 'data ha de ser AAAA-MM-DD';
    if (!in_array($s['moment'] ?? null, SNAPSHOT_MOMENTS, true)) $e[] = 'moment desconegut';
    $zones = $s['zones'] ?? null;
    if (!is_array($zones) || array_diff($zones, array_keys($mapa['franges'])) || !in_array('Z0', $zones, true)) $e[] = 'zones no vàlides (Z0 sempre oberta)';
    foreach (['classes', 'claustre', 'claustre_alliberat'] as $k) if (!is_bool($s[$k] ?? null)) $e[] = "$k ha de ser true o false";

    $c = $s['comptador'] ?? null;
    if (!is_array($c) || !is_int($c['fet'] ?? null)) {
        $e[] = 'comptador no vàlid';
    } elseif (($c['tipus'] ?? '') === 'classes') {
        if (!is_int($c['total'] ?? null)) $e[] = 'comptador de classes sense total';
    } elseif (($c['tipus'] ?? '') === 'participacions') {
        if (!array_key_exists('fita', $c) || !($c['fita'] === null || is_int($c['fita']))) $e[] = 'comptador sense fita';
    } else {
        $e[] = 'tipus de comptador desconegut';
    }
    if ($c && array_diff(array_keys($c), ['tipus', 'fet', 'total', 'fita'])) $e[] = 'el comptador només pot tenir tipus, fet, total i fita';

    $o = $s['o'] ?? null;
    if (!is_array($o)) { $e[] = 'falta o'; $o = []; }
    $noms = [];
    foreach ($o as $id => $oc) {
        $l = $llocs[$id] ?? null;
        if (!$l) { $e[] = "$id: lloc desconegut"; continue; }
        if (!is_array($oc) || array_diff(array_keys($oc), ['p', 'm', 'c', 'n'])) { $e[] = "$id: camps no permesos"; continue; }
        $p = $peces[$oc['p'] ?? ''] ?? null;
        if (!$p || $p['cat'] !== $l['tipus']) { $e[] = "$id: la peça no és de la categoria del lloc"; continue; }
        if ($l['tipus'] === 'casa' && preg_replace('/-S?P$/', '', $oc['p']) !== preg_replace('/-S?P$/', '', $l['codi'])) $e[] = "$id: la casa ha de ser la del mapa (amb placa o sense)";
        if ($l['tipus'] === 'element' && $oc['p'] !== $l['codi']) $e[] = "$id: l'element ha de ser el del mapa";
        if (isset($oc['m']) && $oc['m'] !== 0 && $oc['m'] !== 1) $e[] = "$id: m ha de ser 0 o 1";
        $pers = $p['pers'];
        $idx = $oc['c'] ?? [];
        if (!is_array($idx) || count($idx) !== count($pers)) {
            $e[] = "$id: calen " . count($pers) . ' índexs de color';
        } else {
            $grup = $l['tipus'] === 'casa' ? 'cases' : (!empty($l['pastor']) ? 'pastors' : 'persones');
            foreach ($pers as $i => $rol) {
                $n = count($paletes[$grup][$rol]['opcions'] ?? []);
                if (!is_int($idx[$i]) || $idx[$i] < 0 || $idx[$i] >= $n) $e[] = "$id: índex de color no vàlid per a $rol";
            }
        }
        if (isset($oc['n'])) {
            if ($l['grup'] !== 'poble' || !$p['placa']) $e[] = "$id: només les cases de classe amb placa porten nom";
            elseif (!is_string($oc['n']) || !preg_match('/^[\p{L}\p{N} .·\'-]{1,8}$/u', $oc['n'])) $e[] = "$id: nom de placa no vàlid";
            elseif (isset($noms[$oc['n']])) $e[] = "$id: nom de placa repetit";
            else $noms[$oc['n']] = true;
        }
    }
    $r = $s['r'] ?? null;
    if (!is_array($r)) { $e[] = 'falta r'; $r = []; }
    foreach ($r as $id) {
        if (!is_string($id) || !isset($llocs[$id])) $e[] = 'r: lloc desconegut';
        elseif (isset($o[$id])) $e[] = "r: $id ja és ocupat";
    }
    if (array_diff(array_keys($s), ['versio', 'generat', 'data', 'moment', 'zones', 'classes', 'claustre', 'claustre_alliberat', 'comptador', 'o', 'r'])) $e[] = 'camps de primer nivell no permesos';
    return $e;
}

/** Escriu el snapshot de forma atòmica (fitxer .tmp i rename). */
function snapshot_escriu(string $cami, array $s): void
{
    $text = json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents("$cami.tmp", $text . "\n") === false || !rename("$cami.tmp", $cami)) {
        throw new RuntimeException("No es pot escriure $cami");
    }
}

/** Data del servidor (dia d'Europe/Madrid), o la data simulada de config.php a l'entorn de proves. */
function snapshot_data(array $cfg, DateTimeImmutable $ara): string
{
    $sim = $cfg['data_simulada'] ?? null;
    if (is_string($sim) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $sim)) return $sim;
    return $ara->setTimezone(new DateTimeZone('Europe/Madrid'))->format('Y-m-d');
}

/** Construeix el snapshot a partir de la base de dades. */
function snapshot_genera(PDO $pdo, array $cfg, DateTimeImmutable $ara): array
{
    $o = [];
    $ret = [];
    $st = $pdo->prepare("SELECT o.*, l.tipus, l.grup, l.mirall AS mirall_mapa, c.nom AS classe
                         FROM ocupacions o JOIN llocs l ON l.id = o.lloc_id LEFT JOIN classes c ON c.id = o.classe_id
                         WHERE o.estat = 'confirmat' OR o.caduca > ? ORDER BY o.lloc_id");
    $st->execute([bd_hora($ara)]);
    $classes_fetes = 0;
    $reals = 0;
    foreach ($st->fetchAll() as $r) {
        if ($r['estat'] !== 'confirmat') { $ret[] = $r['lloc_id']; continue; }
        $e = ['p' => $r['peca']];
        if (in_array($r['tipus'], ['persona', 'animal'], true)) $e['m'] = (int)$r['mirall'];
        $c = json_decode((string)$r['colors'], true);
        if (is_array($c) && $c) $e['c'] = $c;
        if ($r['classe'] !== null && $r['grup'] === 'poble' && substr((string)$r['peca'], -2) === '-P') $e['n'] = $r['classe'];
        $o[$r['lloc_id']] = $e;
        if ($r['grup'] === 'poble' && $r['origen'] === 'classe') $classes_fetes++;
        if ($r['origen'] !== 'auto') $reals++;
    }
    $classes = bd_hora($ara) < config_llegeix($pdo, 'classes_tanca', '2026-12-21 22:59:00');
    $poble = (int)$pdo->query("SELECT COUNT(*) FROM llocs WHERE grup = 'poble'")->fetchColumn();
    $zones = array_values(array_filter(explode(',', config_llegeix($pdo, 'zones', 'Z0'))));
    $fita = config_llegeix($pdo, 'fita_seguent');
    return [
        'versio' => (int)config_llegeix($pdo, 'versio', '0') + 1,
        'generat' => $ara->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        'data' => snapshot_data($cfg, $ara),
        'moment' => config_llegeix($pdo, 'moment', 'dia'),
        'zones' => $zones,
        'classes' => $classes,
        'claustre' => finestra($pdo, 'claustre_des', 'claustre_tanca', $ara) === 'obert',
        'claustre_alliberat' => claustre_alliberat($pdo, $ara),
        'comptador' => $classes
            ? ['tipus' => 'classes', 'fet' => $classes_fetes, 'total' => $poble]
            : ['tipus' => 'participacions', 'fet' => $reals, 'fita' => $fita === null ? null : (int)$fita],
        'o' => (object)$o,
        'r' => $ret,
    ];
}

/** Regenera snapshot.json (augmenta la versió). */
function snapshot_regenera(PDO $pdo, array $cfg, DateTimeImmutable $ara): array
{
    $s = snapshot_genera($pdo, $cfg, $ara);
    config_desa($pdo, 'versio', (string)$s['versio']);
    snapshot_escriu($cfg['snapshot'], $s);
    return $s;
}
