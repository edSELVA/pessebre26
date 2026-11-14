<?php
// Punt d'entrada únic de totes les escriptures: POST amb JSON {"a": "acció", …}. Les lectures són estàtiques (snapshot.json).
declare(strict_types=1);

// On és la carpeta pessebre/: fora de l'arrel web (recomanat, al costat de public_html/) o, si l'hosting no ho permet,
// dins de public_html/ (protegida amb el seu .htaccess). Si fos en un altre lloc, canvia només aquesta línia.
$PESSEBRE = is_dir(__DIR__ . '/../pessebre/src') ? __DIR__ . '/../pessebre' : __DIR__ . '/pessebre';
ini_set('display_errors', '0');          // mai rutes ni errors interns a la resposta (van al registre del servidor)

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
// Exigir JSON evita que una altra web pugui enviar formularis aquí (caldria una petició prèvia que no permetem).
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) respon(415, ['ok' => false, 'error' => 'Cal JSON.', 'codi' => 'tipus']);
$cos = (string)file_get_contents('php://input', false, null, 0, 4097);
if (strlen($cos) > 4096) respon(413, ['ok' => false, 'error' => 'Petició massa gran.', 'codi' => 'mida']);
$p = json_decode($cos, true);
if (!is_array($p)) respon(400, ['ok' => false, 'error' => 'JSON no vàlid.', 'codi' => 'json']);

require $PESSEBRE . '/src/app.php';
try {
    $cfg = app_config(getenv('PESSEBRE_CONFIG') ?: $PESSEBRE . '/config.php');
    $pdo = bd_connecta($cfg);
    [$estat, $resposta] = app_executa($pdo, $cfg, $p, (string)($_SERVER['REMOTE_ADDR'] ?? ''), new DateTimeImmutable('now', new DateTimeZone('UTC')));
    respon($estat, $resposta);
} catch (Throwable $e) {
    error_log('pessebre: ' . $e->getMessage());
    respon(500, ['ok' => false, 'error' => 'Error intern. Torna-ho a provar d\'aquí a una estona.', 'codi' => 'intern']);
}
