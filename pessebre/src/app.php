<?php
// Nucli de l'API: rep una petició ja descodificada i retorna [estat HTTP, resposta].
// Totes les escriptures: bloqueig flock → transacció → aplicar → confirmar → regenerar snapshot.json → alliberar.
declare(strict_types=1);
ini_set('serialize_precision', '-1');

require_once __DIR__ . '/bd.php';
require_once __DIR__ . '/llocs.php';
require_once __DIR__ . '/codis.php';
require_once __DIR__ . '/limit.php';
require_once __DIR__ . '/reserva.php';
require_once __DIR__ . '/snapshot.php';

const SNAPSHOT_EDAT_TICK = 900;   // el «tick» públic només fa feina si el snapshot té més de 15 minuts

function app_config(string $cami): array
{
    if (!is_file($cami)) throw new RuntimeException('Falta config.php (copia config.exemple.php i omple-la).');
    $cfg = require $cami;
    foreach (['pebre', 'snapshot', 'bloqueig', 'web'] as $k) {
        if (empty($cfg[$k]) || $cfg[$k] === 'CANVIA-AIXO') throw new RuntimeException("config.php: cal definir $k");
    }
    return $cfg;
}

/** Executa $feina amb el bloqueig d'escriptura i dins d'una transacció; després regenera el snapshot. */
function app_escriu(PDO $pdo, array $cfg, DateTimeImmutable $ara, callable $feina)
{
    $f = fopen($cfg['bloqueig'], 'c');
    if (!$f || !flock($f, LOCK_EX)) throw new RuntimeException('No es pot obtenir el bloqueig d\'escriptura');
    try {
        $pdo->beginTransaction();
        try {
            reserva_purga($pdo, $ara);
            $res = $feina();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        snapshot_regenera($pdo, $cfg, $ara);
        return $res;
    } finally {
        flock($f, LOCK_UN);
        fclose($f);
    }
}

/** Transacció curta sense bloqueig ni snapshot (per a lectures que només toquen el límit d'intents). */
function app_transaccio(PDO $pdo, callable $feina)
{
    $pdo->beginTransaction();
    try { $res = $feina(); $pdo->commit(); return $res; }
    catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

/** Comprova l'enllaç d'etapa (i el límit d'intents). La fallada s'apunta a app_executa, fora de la transacció. */
function app_etapa(PDO $pdo, array $cfg, array $p, string $ip, DateTimeImmutable $ara): string
{
    limit_comprova($pdo, limit_empremta($pdo, $ip, $ara), $ara);
    $e = enllac_valida($pdo, $cfg['pebre'], $p['e'] ?? null, 'etapa');
    if (!$e) throw new ErrorPessebre('Aquest enllaç no és vàlid o ja no està actiu. Demana l\'enllaç nou a la coordinació.', 403, 'enllac');
    return $e['etapa'];
}

/** @return array{0: int, 1: array} */
function app_executa(PDO $pdo, array $cfg, array $p, string $ip, DateTimeImmutable $ara): array
{
    try {
        $a = $p['a'] ?? '';
        switch ($a) {
            case 'tick':
                clearstatcache();
                $edat = is_file($cfg['snapshot']) ? $ara->getTimestamp() - filemtime($cfg['snapshot']) : PHP_INT_MAX;
                if ($edat > SNAPSHOT_EDAT_TICK) {
                    app_escriu($pdo, $cfg, $ara, function () { return null; });
                    return [200, ['ok' => true, 'fet' => true]];
                }
                return [200, ['ok' => true, 'fet' => false]];

            case 'classes.llista':
                $etapa = app_transaccio($pdo, function () use ($pdo, $cfg, $p, $ip, $ara) { return app_etapa($pdo, $cfg, $p, $ip, $ara); });
                $obert = bd_hora($ara) < config_llegeix($pdo, 'classes_tanca', '2026-12-21 22:59:00') && config_llegeix($pdo, 'aturada', '0') !== '1';
                return [200, ['ok' => true, 'etapa' => ETAPES[$etapa], 'obert' => $obert, 'classes' => classes_llista($pdo, $etapa)]];

            case 'classes.retenir':
            case 'classes.confirmar':
            case 'classes.deixar':
                $res = app_escriu($pdo, $cfg, $ara, function () use ($pdo, $cfg, $p, $ip, $ara, $a) {
                    $etapa = app_etapa($pdo, $cfg, $p, $ip, $ara);
                    if ($a === 'classes.retenir') return classes_retenir($pdo, $cfg, $etapa, $p['classe'] ?? null, $p['lloc'] ?? null, $ara);
                    if ($a === 'classes.confirmar') return classes_confirmar($pdo, $cfg, $etapa, $p['classe'] ?? null, $p['lloc'] ?? null, $p['retencio'] ?? null, $p['colors'] ?? null, $ara);
                    return classes_deixar($pdo, $cfg, $etapa, $p['classe'] ?? null, $p['lloc'] ?? null, $p['retencio'] ?? null, $ara);
                });
                return [200, ['ok' => true] + $res];

            default:
                return [400, ['ok' => false, 'error' => 'Acció desconeguda.', 'codi' => 'accio']];
        }
    } catch (ErrorPessebre $e) {
        // Les fallades d'enllaç s'han de desar encara que la transacció s'hagi desfet.
        if ($e->codi === 'enllac') app_apunta_fallada($pdo, $ip, $ara);
        return [$e->estat, ['ok' => false, 'error' => $e->getMessage(), 'codi' => $e->codi]];
    }
}

function app_apunta_fallada(PDO $pdo, string $ip, DateTimeImmutable $ara): void
{
    $pdo->beginTransaction();
    limit_fallada($pdo, limit_empremta($pdo, $ip, $ara), $ara);
    $pdo->commit();
}
