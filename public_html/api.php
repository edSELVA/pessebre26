<?php
// Punt d'entrada únic de totes les escriptures: POST amb JSON {"a": "acció", …}. Les lectures són estàtiques (snapshot.json).
declare(strict_types=1);

// On és la carpeta pessebre/ (millor fora de l'arrel web). Si a l'hosting és en un altre lloc, canvia només aquesta línia.
$PESSEBRE = __DIR__ . '/../pessebre';

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
