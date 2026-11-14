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
require_once __DIR__ . '/qr.php';
require_once __DIR__ . '/fites.php';
require_once __DIR__ . '/auto.php';
require_once __DIR__ . '/admin.php';

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

/** Valors inicials de la configuració (els que ja hi són no es toquen). */
const CONFIG_INICIAL = DEFECTES_CALENDARI + ['classes_tanca' => '2026-12-21 22:59:00', 'moment' => 'dia', 'zones' => 'Z0', 'aturada' => '0',
    'usos_codi' => '3', 'llindar_zona' => '75', 'objectiu' => '600', 'minim_dies' => '1',
    'auto_posta' => '35', 'auto_nit' => '50', 'auto_neu' => '60', 'auto_max_zona' => '40'];

/** Crea les taules, carrega els llocs del mapa, les fites i la configuració inicial. Retorna quants llocs hi ha. */
function app_installa(PDO $pdo, array $cfg): int
{
    bd_esquema($pdo);
    $n = llocs_carrega($pdo, disseny($cfg, 'mapa.json'));
    fites_inicialitza($pdo);
    foreach (CONFIG_INICIAL as $k => $v) if (config_llegeix($pdo, $k) === null) config_desa($pdo, $k, $v);
    return $n;
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
            app_progressa($pdo, $cfg, $ara);
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

/** Fites per data o per participació i participació automàtica (a cada escriptura i a cada «tick»). */
function app_progressa(PDO $pdo, array $cfg, DateTimeImmutable $ara): void
{
    if (config_llegeix($pdo, 'tancat', '0') === '1' || bd_hora($ara) >= calendari($pdo, 'families_fins')) return;
    fites_aplica($pdo, $ara);
    auto_aplica($pdo, $cfg, $ara);
}

/** Transacció curta sense bloqueig ni snapshot (per a lectures que només toquen el límit d'intents). */
function app_transaccio(PDO $pdo, callable $feina)
{
    $pdo->beginTransaction();
    try { $res = $feina(); $pdo->commit(); return $res; }
    catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

/** Les etapes del codi d'etapa de la petició (i el límit d'intents). La fallada s'apunta a app_executa, fora de la transacció.
 *  Errors de format o del caràcter de control: no compten com a intents (com als codis de família). */
function app_etapes(PDO $pdo, array $cfg, array $p, string $ip, DateTimeImmutable $ara): array
{
    limit_comprova($pdo, limit_empremta($pdo, $ip, $ara), $ara);
    $c = codi_normalitza($p['c'] ?? null);
    if ($c === null) throw new ErrorPessebre('El codi té 10 caràcters (lletres i xifres), com K7M-QX4-P9TR. Revisa\'l.', 400, 'format');
    if (!codi_control_ok($c)) throw new ErrorPessebre('Sembla que hi ha un caràcter mal copiat. Revisa el codi.', 400, 'control');
    $etapes = etapes_per_codi($pdo, $cfg['pebre'], $c);
    if (!$etapes) throw new ErrorPessebre('Aquest codi no és de cap etapa, o ja s\'ha canviat. Demana\'l a la coordinació.', 403, 'codi');
    return $etapes;
}

/** Codi de família de la petició (comprovant el límit d'intents). */
function app_codi(PDO $pdo, array $cfg, array $p, string $ip, DateTimeImmutable $ara): array
{
    limit_comprova($pdo, limit_empremta($pdo, $ip, $ara), $ara);
    return codi_comprova($pdo, $cfg, $p['c'] ?? null);
}

/** Enllaç del claustre de la petició (comprovant el límit d'intents). */
function app_claustre(PDO $pdo, array $cfg, array $p, string $ip, DateTimeImmutable $ara): void
{
    limit_comprova($pdo, limit_empremta($pdo, $ip, $ara), $ara);
    if (!enllac_valida($pdo, $cfg['pebre'], $p['k'] ?? null, 'claustre')) {
        throw new ErrorPessebre('Aquest enllaç del claustre no és vàlid o ja no està actiu.', 403, 'enllac');
    }
}

/** @return array{0: int, 1: array} */
function app_executa(PDO $pdo, array $cfg, array $p, string $ip, DateTimeImmutable $ara): array
{
    try {
        registre_qui(null, true);           // cada petició diu qui és (comptes d'administració)
        $a = $p['a'] ?? '';
        // Després de «Tancar i esborrar» (Reis) ja no es pot escriure res: el pessebre queda com a pàgina estàtica
        if (config_llegeix($pdo, 'tancat', '0') === '1' && !in_array($a, ['admin.estat', 'admin.lloc', 'admin.registre'], true)) {
            if ($a === 'tick') return [200, ['ok' => true, 'fet' => false]];
            throw new ErrorPessebre('El pessebre d\'aquest any ja s\'ha tancat. Moltes gràcies per participar!', 403, 'tancat');
        }
        switch ($a) {
            case 'tick':
                clearstatcache();
                $edat = is_file($cfg['snapshot']) ? $ara->getTimestamp() - filemtime($cfg['snapshot']) : PHP_INT_MAX;
                if ($edat > SNAPSHOT_EDAT_TICK) {
                    app_escriu($pdo, $cfg, $ara, function () { return null; });
                    return [200, ['ok' => true, 'fet' => true]];
                }
                return [200, ['ok' => true, 'fet' => false]];

            case 'classes.estat':
                $etapes = app_transaccio($pdo, function () use ($pdo, $cfg, $p, $ip, $ara) { return app_etapes($pdo, $cfg, $p, $ip, $ara); });
                return [200, ['ok' => true] + etapa_estat($pdo, $etapes, $ara)];

            case 'classes.retenir':
            case 'classes.confirmar':
            case 'classes.deixar':
                $res = app_escriu($pdo, $cfg, $ara, function () use ($pdo, $cfg, $p, $ip, $ara, $a) {
                    $c = classe_de_etapes($pdo, $p['classe'] ?? null, app_etapes($pdo, $cfg, $p, $ip, $ara));
                    if ($a === 'classes.retenir') return classes_retenir($pdo, $cfg, $c, $p['lloc'] ?? null, $ara);
                    if ($a === 'classes.confirmar') return classes_confirmar($pdo, $cfg, $c, $p['lloc'] ?? null, $p['retencio'] ?? null, $p['colors'] ?? null, $ara, $p['mirall'] ?? null, $p['finestres'] ?? 0);
                    return classes_deixar($pdo, $cfg, $c, $p['lloc'] ?? null, $p['retencio'] ?? null, $ara);
                });
                return [200, ['ok' => true] + $res];

            case 'codi.validar':
                // Un sol botó «Participar» per a tothom: si el codi és d'una etapa, la pàgina va a la dels tutors.
                // Primer el límit d'intents (també per als codis d'etapa), després família o etapa.
                $codi = app_transaccio($pdo, function () use ($pdo, $cfg, $p, $ip, $ara) {
                    limit_comprova($pdo, limit_empremta($pdo, $ip, $ara), $ara);
                    $c = codi_normalitza($p['c'] ?? null);
                    if ($c !== null && codi_control_ok($c) && etapes_per_codi($pdo, $cfg['pebre'], $c)) return null;
                    return codi_comprova($pdo, $cfg, $p['c'] ?? null);
                });
                if ($codi === null) return [200, ['ok' => true, 'tipus' => 'etapa', 'pot' => true]];
                return [200, ['ok' => true, 'tipus' => 'familia'] + codi_estat($pdo, $codi, $ara)];

            case 'codi.retenir':
            case 'codi.confirmar':
            case 'codi.deixar':
                $res = app_escriu($pdo, $cfg, $ara, function () use ($pdo, $cfg, $p, $ip, $ara, $a) {
                    $codi = app_codi($pdo, $cfg, $p, $ip, $ara);
                    aturada_comprova($pdo);
                    if ($a === 'codi.retenir') return codi_retenir($pdo, $cfg, $codi, $p['lloc'] ?? null, $ara);
                    if ($a === 'codi.confirmar') return codi_confirmar($pdo, $cfg, $codi, $p['lloc'] ?? null, $p['retencio'] ?? null, $p['peca'] ?? null, $p['mirall'] ?? 0, $p['colors'] ?? null, $ara, $p['finestres'] ?? 0);
                    return codi_deixar($pdo, $cfg, $codi, $p['lloc'] ?? null, $p['retencio'] ?? null, $ara);
                });
                return [200, ['ok' => true] + $res];

            case 'claustre.estat':
                app_transaccio($pdo, function () use ($pdo, $cfg, $p, $ip, $ara) { return app_claustre($pdo, $cfg, $p, $ip, $ara); });
                return [200, ['ok' => true] + claustre_estat($pdo, $ara)];

            case 'claustre.retenir':
            case 'claustre.confirmar':
            case 'claustre.deixar':
                $res = app_escriu($pdo, $cfg, $ara, function () use ($pdo, $cfg, $p, $ip, $ara, $a) {
                    app_claustre($pdo, $cfg, $p, $ip, $ara);
                    if ($a === 'claustre.retenir') return claustre_retenir($pdo, $cfg, $p['lloc'] ?? null, $ara);
                    if ($a === 'claustre.confirmar') return claustre_confirmar($pdo, $cfg, $p['lloc'] ?? null, $p['retencio'] ?? null, $p['peca'] ?? null, $p['mirall'] ?? 0, $p['colors'] ?? null, $ara, $p['finestres'] ?? 0);
                    return claustre_deixar($pdo, $cfg, $p['lloc'] ?? null, $p['retencio'] ?? null, $ara);
                });
                return [200, ['ok' => true] + $res];

            default:
                if (strncmp($a, 'admin.', 6) === 0) return [200, ['ok' => true] + admin_accio($pdo, $cfg, $a, $p, $ip, $ara)];
                return [400, ['ok' => false, 'error' => 'Acció desconeguda.', 'codi' => 'accio']];
        }
    } catch (ErrorPessebre $e) {
        // Les fallades d'enllaç o de codi s'han de desar encara que la transacció s'hagi desfet.
        // Els errors de format o del caràcter de control (transcripció) no compten com a intents.
        if ($e->codi === 'enllac' || $e->codi === 'codi') app_apunta_fallada($pdo, $ip, $ara);
        if ($e->codi === 'contrasenya') admin_apunta_fallada($pdo, $ip, $ara);
        return [$e->estat, ['ok' => false, 'error' => $e->getMessage(), 'codi' => $e->codi]];
    }
}

function app_apunta_fallada(PDO $pdo, string $ip, DateTimeImmutable $ara): void
{
    $pdo->beginTransaction();
    limit_fallada($pdo, limit_empremta($pdo, $ip, $ara), $ara);
    $pdo->commit();
}
