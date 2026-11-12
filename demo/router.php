<?php
// Encaminador del servidor de la DEMOSTRACIÓ (php -S, l'engega eines/demo.sh). MAI a l'hosting.
//  · Només respon a adreces de la xarxa local (l'ordinador mateix, la wifi o la compartició del mòbil).
//  · Serveix les pàgines de public_html/ amb el panell de la demo afegit, el snapshot de la demo i l'API de la demo.
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$local = preg_match('/^(127\.|10\.|192\.168\.|169\.254\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip)
      || preg_match('/^(::1$|fe80:|f[cd][0-9a-f]{2}:|::ffff:(127|10|192\.168)\.)/i', $ip);
if (!$local) { http_response_code(403); echo 'Només xarxa local.'; exit; }

$cami = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#(^|/)\.|\.tmp$#', $cami)) { http_response_code(403); exit; }
header("Content-Security-Policy: default-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if ($cami === '/api.php') { require __DIR__ . '/api.php'; return true; }

if ($cami === '/snapshot.json') {
    $cfg = require (getenv('PESSEBRE_CONFIG') ?: __DIR__ . '/dades/config.php');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');
    if (!is_file($cfg['snapshot'])) { http_response_code(404); exit; }
    readfile($cfg['snapshot']);
    return true;
}

if (preg_match('#^/demo/(panell\.(js|css))$#', $cami, $m)) {
    header('Content-Type: ' . ($m[2] === 'js' ? 'text/javascript' : 'text/css') . '; charset=utf-8');
    header('Cache-Control: no-cache');
    readfile(__DIR__ . '/' . $m[1]);
    return true;
}

// Pàgines: les de public_html/ amb el panell de la demo
$pagines = ['/' => 'index.html', '/demo' => 'index.html', '/demo.html' => 'index.html', '/index.html' => 'index.html',
            '/classes.html' => 'classes.html', '/admin.html' => 'admin.html', '/privacitat.html' => 'privacitat.html'];
if (isset($pagines[$cami])) {
    $html = (string)file_get_contents(__DIR__ . '/../public_html/' . $pagines[$cami]);
    $panell = '<link rel="stylesheet" href="/demo/panell.css"><script src="/demo/panell.js"></script>';
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache');
    echo str_replace('</body>', $panell . "\n</body>", $html);
    return true;
}

if (substr($cami, -4) === '.svg') header('Content-Type: image/svg+xml');
if (preg_match('#^/(api|snapshot)#', $cami)) { http_response_code(404); exit; }
return false;   // la resta (app/, disseny/…) la serveix el servidor integrat des de public_html/
