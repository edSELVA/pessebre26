<?php
// API de la DEMOSTRACIÓ. És la mateixa que public_html/api.php, però amb l'hora simulada de la demo i les accions
// «demo.*» del panell. Només la fa servir demo/router.php (servidor local); no es puja mai a l'hosting.
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function respon(int $estat, array $cos): void
{
    http_response_code($estat);
    echo json_encode($cos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respon(405, ['ok' => false, 'error' => 'Només POST.', 'codi' => 'metode']);
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) respon(415, ['ok' => false, 'error' => 'Cal JSON.', 'codi' => 'tipus']);
$cos = (string)file_get_contents('php://input', false, null, 0, 4097);
if (strlen($cos) > 4096) respon(413, ['ok' => false, 'error' => 'Petició massa gran.', 'codi' => 'mida']);
$p = json_decode($cos, true);
if (!is_array($p)) respon(400, ['ok' => false, 'error' => 'JSON no vàlid.', 'codi' => 'json']);

require __DIR__ . '/demo.php';
try {
    $cfg = app_config(getenv('PESSEBRE_CONFIG') ?: __DIR__ . '/dades/config.php');
    $cfg['demo_classes'] = __DIR__ . '/../classes.csv';          // les classes reals, si hi són (fitxer local, mai al repositori)
    if (($cfg['demo'] ?? false) !== true) respon(403, ['ok' => false, 'error' => 'Aquesta configuració no és de demostració.', 'codi' => 'demo']);
    $pdo = bd_connecta($cfg);
    $a = is_string($p['a'] ?? null) ? $p['a'] : '';
    if (strncmp($a, 'demo.', 5) === 0) {
        try {
            respon(200, ['ok' => true] + demo_accio($pdo, $cfg, $a, $p));
        } catch (ErrorPessebre $e) {
            respon($e->estat, ['ok' => false, 'error' => $e->getMessage(), 'codi' => $e->codi]);
        }
    }
    $e = demo_estat($cfg) ?? demo_munta($pdo, $cfg, 1);
    [$estat, $resposta] = app_executa($pdo, $cfg, $p, (string)($_SERVER['REMOTE_ADDR'] ?? ''), demo_ara($e));
    respon($estat, $resposta);
} catch (Throwable $e) {
    error_log('pessebre (demo): ' . $e->getMessage() . ' ' . $e->getFile() . ':' . $e->getLine());
    respon(500, ['ok' => false, 'error' => 'Error intern de la demo: ' . $e->getMessage(), 'codi' => 'intern']);
}
