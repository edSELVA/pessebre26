<?php
// Fites i moments del dia (Fase E): dia → posta → nit → neu, sense tornar mai enrere.
// Cada canvi salta amb el primer que arribi: el llindar de participació real o la data límit (si el llindar ja s'ha
// assolit, la data no fa res). Mínim d'un dia entre fites. El llindar d'una fita es fixa quan salta l'anterior:
// participació real d'aquell moment + increment (% de l'objectiu). La posta té el seu propi llindar: cases de classe.
declare(strict_types=1);

const FITES_DEFECTE = [
    // numero => [moment, base, increment (%), data límit UTC]
    1 => ['posta', 'classes', 70, '2026-12-20 23:00:00'],          // 52 de 74 cases, o el 21 des (00:00 Barcelona)
    2 => ['nit', 'participacions', 25, '2026-12-26 23:00:00'],     // +150 (25 % de 600), o el 27 des
    3 => ['neu', 'participacions', 25, '2026-12-31 23:00:00'],     // +150, o l'1 de gener
];
// Participació real: classes, famílies, claustre i les figures que afegeix l'administració (§22). No compten les
// automàtiques ni les cases sense placa que l'administració posa als llocs de classe.
const CONDICIO_REAL = "(o.origen IN ('classe', 'familia', 'claustre') OR (o.origen = 'admin' AND l.grup <> 'poble'))";

/** Crea les fites si no n'hi ha (i fixa el llindar de la posta, que no depèn de cap fita anterior). */
function fites_inicialitza(PDO $pdo): void
{
    if ((int)$pdo->query('SELECT COUNT(*) FROM fites')->fetchColumn() > 0) return;
    $poble = (int)$pdo->query("SELECT COUNT(*) FROM llocs WHERE grup = 'poble'")->fetchColumn();
    $st = $pdo->prepare('INSERT INTO fites (numero, moment, base, increment, data_limit, llindar) VALUES (?, ?, ?, ?, ?, ?)');
    foreach (FITES_DEFECTE as $n => [$moment, $base, $inc, $data]) {
        $st->execute([$n, $moment, $base, $inc, $data, $n === 1 ? (int)ceil($poble * $inc / 100) : null]);
    }
}

function fites(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM fites ORDER BY numero')->fetchAll();
}

/** Participacions reals i cases de classe confirmades. Amb $fins, només les confirmades fins a aquella hora. */
function participacio_real(PDO $pdo, ?string $fins = null): array
{
    $st = $pdo->prepare("SELECT COUNT(*) AS reals, SUM(CASE WHEN o.origen = 'classe' THEN 1 ELSE 0 END) AS classes
                         FROM ocupacions o JOIN llocs l ON l.id = o.lloc_id
                         WHERE o.estat = 'confirmat' AND " . CONDICIO_REAL . ($fins === null ? '' : ' AND o.hora <= ?'));
    $st->execute($fins === null ? [] : [$fins]);
    $r = $st->fetch();
    return ['reals' => (int)$r['reals'], 'classes' => (int)$r['classes']];
}

/** Aplica les fites que toquen ara. Retorna la fita que ha saltat (o null). Com a molt en salta una per crida. */
function fites_aplica(PDO $pdo, DateTimeImmutable $ara): ?array
{
    $t = bd_hora($ara);
    $anterior = null;
    foreach (fites($pdo) as $f) {
        if ($f['assolida'] !== null) { $anterior = $f; continue; }
        // Mínim de dies entre fites
        $minim = (int)config_llegeix($pdo, 'minim_dies', '1');
        if ($anterior && bd_hora((new DateTimeImmutable($anterior['assolida'], new DateTimeZone('UTC')))->modify("+$minim days")) > $t) return null;
        $p = participacio_real($pdo);
        $valor = $f['base'] === 'classes' ? $p['classes'] : $p['reals'];
        $per = ($f['llindar'] !== null && $valor >= (int)$f['llindar']) ? 'llindar' : ($t >= $f['data_limit'] ? 'data' : null);
        if ($per === null) return null;
        $pdo->prepare('UPDATE fites SET assolida = ?, per = ? WHERE numero = ?')->execute([$t, $per, $f['numero']]);
        config_desa($pdo, 'moment', $f['moment']);
        // Llindar de la fita següent: participació real d'ara + increment (% de l'objectiu)
        $objectiu = (int)config_llegeix($pdo, 'objectiu', '600');
        $pdo->prepare('UPDATE fites SET llindar = ? WHERE numero = ? AND assolida IS NULL')
            ->execute([$p['reals'] + (int)round($objectiu * (int)fita_increment($pdo, (int)$f['numero'] + 1) / 100), (int)$f['numero'] + 1]);
        registre_afegeix($pdo, $ara, 'automàtic', 'fita', null, $f['moment'] . ($per === 'data' ? ' (per data)' : ' (per participació)'));
        if ($per === 'data') config_desa($pdo, 'auto_fase', $f['moment']);     // la participació automàtica omplirà aquesta fase
        return $f + ['per' => $per];
    }
    return null;
}

function fita_increment(PDO $pdo, int $numero): int
{
    $st = $pdo->prepare('SELECT increment FROM fites WHERE numero = ?');
    $st->execute([$numero]);
    return (int)$st->fetchColumn();
}

/** Llindar de la propera fita (per al comptador públic; mai la data). null si ja s'han assolit totes. */
function fita_seguent(PDO $pdo): ?array
{
    foreach (fites($pdo) as $f) {
        if ($f['assolida'] === null) return ['base' => $f['base'], 'llindar' => $f['llindar'] === null ? null : (int)$f['llindar']];
    }
    return null;
}

/**
 * Ritme de participació cap a la propera fita (només per a l'administració).
 * Esperat: repartint de manera uniforme (lineal) la participació que falta entre l'inici del tram i la data límit.
 * L'inici del tram és quan va saltar la fita anterior o, si és més tard, quan s'obre la participació de les famílies.
 */
function fites_ritme(PDO $pdo, array $cfg, DateTimeImmutable $ara): ?array
{
    $anterior = null;
    $f = null;
    foreach (fites($pdo) as $x) {
        if ($x['assolida'] === null) { $f = $x; break; }
        $anterior = $x;
    }
    if ($f === null || $f['llindar'] === null) return null;
    $p = participacio_real($pdo);
    $classes = $f['base'] === 'classes';
    $fet = $classes ? $p['classes'] : $p['reals'];
    $llindar = (int)$f['llindar'];
    $t_ara = $ara->getTimestamp();
    $t_fi = (new DateTimeImmutable($f['data_limit'], new DateTimeZone('UTC')))->getTimestamp();
    // Inici del tram. Posta (cases de classe): la primera casa confirmada. Les altres: la fita anterior o l'obertura de les famílies.
    if ($classes) {
        $primera = $pdo->query("SELECT MIN(hora) FROM ocupacions WHERE origen = 'classe' AND estat = 'confirmat'")->fetchColumn();
        $inici = $primera ? (string)$primera : bd_hora($ara);
    } else {
        $inici = max($anterior ? (string)$anterior['assolida'] : '', calendari($pdo, 'families_des'));
    }
    $t_inici = (new DateTimeImmutable($inici, new DateTimeZone('UTC')))->getTimestamp();
    $base = $classes ? 0 : participacio_real($pdo, $inici)['reals'];
    $fraccio = $t_fi > $t_inici ? max(0.0, min(1.0, ($t_ara - $t_inici) / ($t_fi - $t_inici))) : 1.0;
    $esperat = (int)round($base + ($llindar - $base) * $fraccio);
    $dies_queden = max(0.0, ($t_fi - $t_ara) / 86400);
    $dies_passats = max(0.0, ($t_ara - $t_inici) / 86400);
    $noves = $fet - $base;
    $per_dia = $dies_passats >= 0.25 ? $noves / $dies_passats : null;          // amb menys de 6 hores no es pot dir
    $st = $pdo->prepare('SELECT COUNT(*) FROM ocupacions o JOIN llocs l ON l.id = o.lloc_id WHERE o.estat = \'confirmat\' AND o.hora > ? AND '
        . ($classes ? "o.origen = 'classe'" : CONDICIO_REAL));
    $st->execute([bd_hora($ara->modify('-1 day'))]);
    $falten = max(0, $llindar - $fet);
    // Previsió: si al ritme d'ara s'arriba al llindar abans de la data límit, salta per participació; si no, per data
    $previsio = 'data';
    $data_prevista = null;
    if ($falten === 0) $previsio = 'llindar';
    elseif ($per_dia !== null && $per_dia > 0 && $t_ara + $falten / $per_dia * 86400 < $t_fi) {
        $previsio = 'llindar';
        $data_prevista = hora_local(bd_hora((new DateTimeImmutable('@' . (int)($t_ara + $falten / $per_dia * 86400)))));
    }
    return [
        'fita' => (int)$f['numero'], 'moment' => $f['moment'], 'base' => $f['base'], 'llindar' => $llindar,
        'data_limit' => hora_local($f['data_limit']), 'inici' => hora_local($inici), 'abans' => $t_ara < $t_inici,
        'fet' => $fet, 'esperat' => $esperat, 'tram_base' => $base,
        'percent' => $esperat > $base ? (int)round(100 * max(0, $noves) / ($esperat - $base)) : null,   // del que tocaria haver-se fet en aquest tram
        'falten_ritme' => max(0, $esperat - $fet),
        'falten_fita' => $falten,
        'dies_queden' => round($dies_queden, 1),
        'necessari_dia' => $dies_queden > 0 ? (int)ceil($falten / max($dies_queden, 1 / 24)) : null,
        'per_dia' => $per_dia === null ? null : round($per_dia, 1),
        'darreres_24h' => (int)$st->fetchColumn(),
        'previsio' => $previsio,
        'data_prevista' => $data_prevista,
        'automatiques' => $previsio === 'data' ? auto_previsio($pdo, $f['moment']) : 0,   // si salta per data
    ];
}
