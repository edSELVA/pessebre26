<?php
// Regles de CLAUDE.md §1 i §9: res extern, res de cookies ni d'emmagatzematge, CSP, i cap secret al repositori.
$pub = "$ARREL/public_html";
$web = array_merge(glob("$pub/*.html"), glob("$pub/app/*"), glob("$pub/disseny/*"));
foreach ($web as $f) {
    $t = (string)file_get_contents($f);
    $nom = substr($f, strlen($ARREL) + 1);
    $sense_ns = str_replace(['http://www.w3.org/2000/svg', 'http://www.w3.org/1999/xlink'], '', $t);
    comprova(!preg_match('#(https?:)?//[a-z0-9.-]+\.[a-z]{2,}#i', $sense_ns), "$nom no carrega res de fora");
    comprova(!preg_match('/document\.cookie|localStorage|sessionStorage|indexedDB|navigator\.sendBeacon/', $t), "$nom no fa servir cookies ni emmagatzematge");
}
foreach (glob("$pub/*.html") as $f) {
    $t = (string)file_get_contents($f);
    $nom = basename($f);
    comprova(strpos($t, "content=\"default-src 'self'\"") !== false, "$nom porta la CSP");
    comprova(!preg_match('/<script(?![^>]*\ssrc=)/i', $t), "$nom no té scripts en línia");
    comprova(!preg_match('/\sstyle=|<style|\son[a-z]+=/i', $t), "$nom no té estils ni esdeveniments en línia");
}
foreach (glob("$pub/app/*.js") as $f) {
    $t = (string)file_get_contents($f);
    comprova(!preg_match('/\beval\(|new Function|innerHTML|setAttribute\(\s*[\'"]style/', $t), basename($f) . ' no fa servir eval, innerHTML ni style en línia');
}
$ht = (string)file_get_contents("$pub/.htaccess");
comprova(strpos($ht, "Content-Security-Policy \"default-src 'self'") !== false, '.htaccess envia la CSP');
comprova(strpos($ht, 'Cache-Control "no-cache"') !== false, '.htaccess: snapshot.json sense memòria cau');

// .gitignore i comprovador de secrets
$gi = (string)file_get_contents("$ARREL/.gitignore");
foreach (['pessebre/config.php', 'dades/', '*.sqlite', '*.csv', 'public_html/snapshot.json'] as $p) {
    comprova(strpos($gi, $p) !== false, ".gitignore inclou $p");
}
comprova(is_file("$ARREL/eines/comprova-secrets.sh"), 'hi ha eines/comprova-secrets.sh');
comprova(strpos((string)@file_get_contents("$ARREL/.githooks/pre-commit"), 'comprova-secrets.sh') !== false, 'el hook pre-commit crida el comprovador');
$cfg = include "$ARREL/pessebre/config.exemple.php";
foreach (['pebre', 'llavor', 'hash_admin', 'hash_lectura'] as $k) igual('CANVIA-AIXO', $cfg[$k], "config.exemple.php: $k és un valor fals");

if (is_dir("$ARREL/.git")) {
    exec('cd ' . escapeshellarg($ARREL) . ' && sh eines/comprova-secrets.sh --tot 2>&1', $sortida, $codi);
    comprova($codi === 0, 'el repositori no conté secrets' . ($codi ? ":\n      " . implode("\n      ", $sortida) : ''));
    // El comprovador detecta un codi amb format real
    $tmp = sys_get_temp_dir() . '/pessebre-secret-' . getmypid();
    file_put_contents($tmp, 'codi: H4K-' . "PQ7-M2XZ\n");   // trossejat perquè aquest fitxer no dispari el hook
    exec('cd ' . escapeshellarg($ARREL) . ' && sh eines/comprova-secrets.sh --fitxer ' . escapeshellarg($tmp) . ' 2>&1', $s2, $codi2);
    comprova($codi2 !== 0, 'el comprovador detecta un codi de família');
    unlink($tmp);
}
