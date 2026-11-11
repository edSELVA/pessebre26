<?php
// Encaminador per al servidor de proves (php -S): afegeix les mateixes capçaleres que .htaccess
// i serveix els fitxers estàtics de public_html/. NOMÉS per desenvolupar.
$cami = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#(^|/)\.|\.tmp$#', $cami)) { http_response_code(403); exit; }
header("Content-Security-Policy: default-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if (basename($cami) === 'snapshot.json') header('Cache-Control: no-cache');
if (substr($cami, -4) === '.svg') header('Content-Type: image/svg+xml');
return false;   // la resta la fa el servidor integrat
