<?php
// Diagnosi de l'hosting per al pessebre (IONOS o qualsevol altre).
// ÚS: puja aquest fitxer a l'arrel web del subdomini, obre https://ADREÇA/diagnosi-hosting.php, fes una captura i ESBORRA'L.
// No canvia res de l'hosting: només crea i esborra fitxers temporals en una carpeta pròpia.
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
ini_set('display_errors', '0');

$files = [];
function fila(string $que, ?bool $ok, string $detall, string $consell = ''): void
{
    global $files;
    $files[] = [$que, $ok, $detall, $consell];
}
$h = function ($t) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); };

// 1. PHP i extensions
fila('Versió de PHP', version_compare(PHP_VERSION, '8.1', '>='), PHP_VERSION, 'Tria PHP 8.2 o 8.3 al panell (PHP 7.4 ja no té actualitzacions de seguretat).');
foreach (['pdo_sqlite' => 'SQLite', 'pdo_mysql' => 'MySQL', 'mbstring' => 'mbstring', 'json' => 'json'] as $ext => $nom) {
    fila("Extensió $nom", extension_loaded($ext), extension_loaded($ext) ? 'sí' : 'no');
}
fila('OPcache', function_exists('opcache_get_status') ? true : null, function_exists('opcache_get_status') ? 'disponible' : 'no', 'Si hi és, els canvis a config.php poden trigar uns segons a aplicar-se.');

// 2. IP del visitant (el límit d'intents en depèn)
$cap = [];
foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_FORWARDED'] as $k) if (!empty($_SERVER[$k])) $cap[] = "$k=" . $_SERVER[$k];
$remota = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$privada = !filter_var($remota, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
fila('IP que veu PHP (REMOTE_ADDR)', !$privada && !$cap, $remota . ($cap ? ' · capçaleres de proxy: ' . implode('; ', $cap) : ' · sense proxy'),
     'Ha de coincidir amb la IP pública del teu mòbil o ordinador (busca «quina és la meva IP»). Si és una IP privada o surten capçaleres de proxy, TOTS els usuaris semblarien el mateix i el límit d\'intents bloquejaria tothom: cal avisar-ho abans d\'obrir.');

// 3. HTTPS
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
fila('HTTPS', $https, $https ? 'sí' : 'no', 'Activa el certificat SSL gratuït del subdomini abans d\'obrir el pessebre.');

// 4. Carpetes i sistema de fitxers
$arrel = __DIR__;
$pare = dirname($arrel);
fila('Es pot escriure a l\'arrel web', is_writable($arrel), $arrel);
fila('Es pot escriure a la carpeta de sobre (per posar pessebre/ fora de l\'arrel web)', is_writable($pare), $pare, 'Si no, pessebre/ haurà d\'anar dins de l\'arrel web (protegida per .htaccess).');
$fs = 'desconegut';
if (is_readable('/proc/mounts')) {
    $millor = '';
    foreach (file('/proc/mounts') as $l) {
        $p = preg_split('/\s+/', trim($l));
        if (isset($p[1], $p[2]) && strpos($arrel . '/', rtrim($p[1], '/') . '/') === 0 && strlen($p[1]) >= strlen($millor)) { $millor = $p[1]; $fs = $p[2] . ' (' . $p[1] . ')'; }
    }
}
$xarxa = (bool)preg_match('/^(nfs|nfs4|cifs|smb|fuse|glusterfs|ceph)/i', $fs);
fila('Tipus de disc', $fs === 'desconegut' ? null : !$xarxa, $fs, 'Si és de xarxa (nfs, cifs, fuse...), fes servir MySQL en lloc de SQLite: SQLite en un disc de xarxa es pot corrompre amb escriptures simultànies.');
$lliure = @disk_free_space($arrel);
fila('Espai lliure', $lliure === false ? null : $lliure > 200e6, $lliure === false ? 'no es pot saber' : round($lliure / 1e9, 1) . ' GB');

// 5. Bloqueig de fitxers i SQLite (en una carpeta temporal que s'esborra)
$tmp = $arrel . '/diag-' . bin2hex(random_bytes(4));
@mkdir($tmp, 0700);
@file_put_contents("$tmp/.htaccess", "Require all denied\n");
$f = @fopen("$tmp/prova.lock", 'c');
$flock = $f && flock($f, LOCK_EX | LOCK_NB);
fila('Bloqueig de fitxers (flock)', $flock, $flock ? 'funciona' : 'NO funciona', 'L\'app serialitza les escriptures amb flock: si no funciona, cal MySQL i revisar-ho.');
if ($f) { flock($f, LOCK_UN); fclose($f); }
if (extension_loaded('pdo_sqlite')) {
    try {
        $pdo = new PDO("sqlite:$tmp/prova.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $t0 = microtime(true);
        for ($i = 0; $i < 100; $i++) { $pdo->beginTransaction(); $pdo->prepare('INSERT INTO t (v) VALUES (?)')->execute([str_repeat('x', 100)]); $pdo->commit(); }
        $ms = (microtime(true) - $t0) * 10;
        fila('SQLite: 100 escriptures', $ms < 30, round($ms, 1) . ' ms per escriptura', 'Més de 30 ms per escriptura indica un disc lent o de xarxa: millor MySQL.');
        $pdo = null;
    } catch (Throwable $e) {
        fila('SQLite', false, $e->getMessage());
    }
}

// 6. El .htaccess es respecta? (petició a la mateixa web cap a la carpeta temporal bloquejada)
@file_put_contents("$tmp/secret.txt", 'secret');
$host = $_SERVER['HTTP_HOST'] ?? '';
$url = ($https ? 'https' : 'http') . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/' . basename($tmp) . '/secret.txt';
$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
$resp = @file_get_contents($url, false, $ctx);
$codi = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
if ($codi === 0) fila('El .htaccess bloqueja carpetes', null, 'no s\'ha pogut comprovar des del servidor', 'Comprova-ho a mà després d\'instal·lar (https://ADREÇA/pessebre/config.exemple.php ha de donar 403).');
else fila('El .htaccess bloqueja carpetes', $codi === 403 || $codi === 404, "resposta $codi" . ($resp === 'secret' ? ': EL FITXER ES POT LLEGIR' : ''), 'Si es pot llegir, pessebre/ ha d\'anar obligatòriament fora de l\'arrel web.');
foreach (['secret.txt', 'prova.lock', 'prova.sqlite', 'prova.sqlite-journal', '.htaccess'] as $x) @unlink("$tmp/$x");
@rmdir($tmp);

// 7. Rellotge i límits
$bcn = new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid'));
fila('Hora del servidor', true, gmdate('Y-m-d H:i:s') . ' UTC · ' . $bcn->format('H:i:s') . ' a Barcelona', 'Compara-la amb el teu rellotge: ha de coincidir (±1 minut).');
fila('Temps màxim per petició', (int)ini_get('max_execution_time') === 0 || (int)ini_get('max_execution_time') >= 30, ini_get('max_execution_time') . ' s', 'Generar 1000 codis triga uns segons; amb menys de 30 s, genera\'ls en lots de 300.');
fila('Memòria per petició', true, (string)ini_get('memory_limit'));
fila('Servidor web', null, (string)($_SERVER['SERVER_SOFTWARE'] ?? 'desconegut'), 'Ha de ser Apache (o LiteSpeed) perquè funcionin els .htaccess. Amb nginx, vegeu el final d\'INSTAL·LACIO.md.');
?><!doctype html>
<html lang="ca"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diagnosi de l'hosting · Pessebre</title>
<style>
body{font:15px/1.45 system-ui,sans-serif;margin:16px;color:#222;background:#fff}h1{font-size:20px}
table{border-collapse:collapse;width:100%;max-width:980px}td{border-bottom:1px solid #ddd;padding:8px;vertical-align:top}
.ok{color:#1d7a35;font-weight:700}.ko{color:#b3261e;font-weight:700}.av{color:#8a5a00;font-weight:700}.c{color:#555;font-size:13px}
.final{margin-top:16px;padding:12px;background:#fff4d6;border-radius:8px;max-width:950px}
</style></head><body>
<h1>Diagnosi de l'hosting per al pessebre</h1>
<table><tbody>
<?php foreach ($files as [$que, $ok, $detall, $consell]): ?>
<tr><td class="<?= $ok === true ? 'ok' : ($ok === false ? 'ko' : 'av') ?>"><?= $ok === true ? '✓' : ($ok === false ? '✗' : '?') ?></td>
<td><strong><?= $h($que) ?></strong><br><?= $h($detall) ?><?php if ($consell && $ok !== true): ?><div class="c"><?= $h($consell) ?></div><?php endif ?></td></tr>
<?php endforeach ?>
</tbody></table>
<p class="final"><strong>Fes una captura d'aquesta pàgina i esborra <code>diagnosi-hosting.php</code> de l'hosting ara mateix.</strong></p>
</body></html>
