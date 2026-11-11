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
const ORIGENS_REALS = ['classe', 'familia', 'claustre'];          // les automàtiques i les de l'administració no compten

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

/** Participacions reals (classes, famílies i claustre) i cases de classe confirmades. */
function participacio_real(PDO $pdo): array
{
    $marques = "'" . implode("','", ORIGENS_REALS) . "'";
    $r = $pdo->query("SELECT COUNT(*) AS reals, SUM(CASE WHEN origen = 'classe' THEN 1 ELSE 0 END) AS classes
                      FROM ocupacions WHERE estat = 'confirmat' AND origen IN ($marques)")->fetch();
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
