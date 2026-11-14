<?php
// Instal·lador web d'UN SOL ÚS (per a hostings sense terminal). Comprova l'hosting, crea config.php amb secrets aleatoris,
// la base de dades i el primer compte d'administració. Demana la clau d'instal·lació que hi ha a pessebre/clau-installacio.txt
// (la genera eines/paquet.php; no és al repositori). En acabar, esborra la clau i ja no torna a funcionar: després,
// esborra també aquest fitxer de l'hosting.
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$PESSEBRE = is_dir(__DIR__ . '/../pessebre/src') ? __DIR__ . '/../pessebre' : __DIR__ . '/pessebre';
$fitxer_clau = "$PESSEBRE/clau-installacio.txt";
$cami_config = "$PESSEBRE/config.php";

function e(string $t): string { return htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); }

function pagina(string $titol, string $cos): void
{
    echo '<!doctype html><html lang="ca"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="referrer" content="no-referrer"><title>' . e($titol) . ' · Pessebre</title><link rel="stylesheet" href="app/estil.css"></head>'
       . '<body class="pagina"><main class="text installa"><h1>' . e($titol) . '</h1>' . $cos . '</main></body></html>';
    exit;
}

// Ja instal·lat (o sense clau): no fa res més
if (!is_file($fitxer_clau)) {
    http_response_code(403);
    pagina('Instal·lació', '<p>' . (is_file($cami_config)
        ? 'El pessebre ja està instal·lat. <strong>Esborra <code>installa.php</code> de l\'hosting.</strong>'
        : 'Falta la clau d\'instal·lació (<code>pessebre/clau-installacio.txt</code>). Torna a pujar el paquet preparat amb <code>eines/paquet.php</code>.') . '</p>');
}
$clau = trim((string)file_get_contents($fitxer_clau));
$p = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
$clau_ok = isset($p['clau']) && is_string($p['clau']) && strlen($clau) >= 20 && hash_equals($clau, trim($p['clau']));

if (!$clau_ok) {
    if (isset($p['clau'])) sleep(2);           // fre per a qui provi claus a l'atzar
    pagina('Instal·lació del pessebre', ($p ? '<p class="error">La clau no és correcta.</p>' : '')
        . '<p>Escriu la clau d\'instal·lació. És a <code>pessebre/clau-installacio.txt</code> del paquet i la va mostrar <code>eines/paquet.php</code> en preparar-lo.</p>'
        . '<form method="post" class="form-installa"><input class="camp" name="clau" autocomplete="off" required>'
        . '<button class="boto primari" type="submit">Continuar</button></form>');
}

// ── Comprovacions de l'hosting
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$dins = !is_dir(__DIR__ . '/../pessebre/src');            // pessebre/ dins de l'arrel web
$comp = [
    ['PHP 7.4 o més nou', version_compare(PHP_VERSION, '7.4.0', '>='), 'PHP ' . PHP_VERSION, true],
    ['PDO amb SQLite', extension_loaded('pdo_sqlite'), extension_loaded('pdo_sqlite') ? 'sí' : 'no (caldrà MySQL)', false],
    ['PDO amb MySQL', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'sí' : 'no', false],
    ['SQLite o MySQL', extension_loaded('pdo_sqlite') || extension_loaded('pdo_mysql'), '', true],
    ['mbstring i json', extension_loaded('mbstring') && extension_loaded('json'), '', true],
    ['Es pot escriure a pessebre/', is_writable($PESSEBRE), $PESSEBRE, true],
    ['Es pot escriure a l\'arrel web (snapshot.json)', is_writable(__DIR__), __DIR__, true],
    ['Disseny del pessebre', is_file(__DIR__ . '/disseny/mapa.json') && is_file(__DIR__ . '/disseny/peces.svg'), '', true],
    ['Logo de l\'escola (disseny/logo.svg)', is_file(__DIR__ . '/disseny/logo.svg'), is_file(__DIR__ . '/disseny/logo.svg') ? 'sí' : 'no hi és: el pessebre funciona sense', false],
    ['HTTPS', $https, $https ? 'sí' : 'no: demana el certificat abans d\'obrir-lo a les famílies', false],
    ['pessebre/ fora de l\'arrel web', !$dins, $dins ? 'és dins: comprova que no es pot obrir pessebre/config.exemple.php des del navegador' : 'sí', false],
];
$pot = !in_array(false, array_map(function ($c) { return !$c[3] || $c[1]; }, $comp), true);
$taula = '<table class="taula"><tbody>';
foreach ($comp as [$nom, $ok, $detall, $cal]) {
    $taula .= '<tr><td>' . ($ok ? '✓' : ($cal ? '✗' : '!')) . '</td><td>' . e($nom) . '</td><td class="suau">' . e($detall) . '</td></tr>';
}
$taula .= '</tbody></table>';

$errors = [];
if (isset($p['installa']) && $pot) {
    // ── Instal·lar
    $bd = ($p['bd'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
    $usuari = mb_strtolower(trim((string)($p['usuari'] ?? '')));
    $c1 = (string)($p['contrasenya'] ?? '');
    $url = trim((string)($p['url_base'] ?? ''));
    if (!preg_match('#^https?://[^\s"\'<>]+/$#', $url)) $errors[] = 'L\'adreça ha de començar per https:// i acabar amb «/».';
    if (!preg_match('/^[a-z0-9][a-z0-9._-]{1,29}$/', $usuari)) $errors[] = 'Usuari: de 2 a 30 lletres sense accents, xifres, punts o guions.';
    if (mb_strlen($c1) < 10) $errors[] = 'La contrasenya ha de tenir com a mínim 10 caràcters.';
    if ($c1 !== (string)($p['contrasenya2'] ?? '')) $errors[] = 'Les dues contrasenyes no coincideixen.';
    if ($bd === 'mysql' && (trim((string)($p['mysql_host'] ?? '')) === '' || trim((string)($p['mysql_bd'] ?? '')) === '')) $errors[] = 'Falten les dades de MySQL.';
    if (!$errors) {
        $cfg = [
            'bd_tipus' => $bd,
            'bd_sqlite' => "$PESSEBRE/dades/pessebre.sqlite",
            'bd_mysql' => ['dsn' => 'mysql:host=' . trim((string)($p['mysql_host'] ?? 'localhost')) . ';dbname=' . trim((string)($p['mysql_bd'] ?? '')) . ';charset=utf8mb4',
                           'usuari' => (string)($p['mysql_usuari'] ?? ''), 'clau' => (string)($p['mysql_clau'] ?? '')],
            'pebre' => bin2hex(random_bytes(32)),
            'llavor' => bin2hex(random_bytes(16)),
            'web' => __DIR__,
            'snapshot' => __DIR__ . '/snapshot.json',
            'bloqueig' => "$PESSEBRE/dades/escriptura.lock",
            'url_base' => $url,
            'data_simulada' => null,
        ];
        try {
            if (!is_dir("$PESSEBRE/dades")) mkdir("$PESSEBRE/dades", 0700, true);
            require "$PESSEBRE/src/app.php";
            $pdo = bd_connecta($cfg);                       // si MySQL falla, ho diu aquí, abans d'escriure res
            $ara = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $n = app_installa($pdo, $cfg);
            usuari_desa($pdo, $usuari, $c1, 'admin', $ara);
            registre_afegeix($pdo, $ara, 'instal·lador', 'installa', null, "$n llocs; compte $usuari");
            snapshot_regenera($pdo, $cfg, $ara);
            $text = "<?php\n// Configuració creada per installa.php el " . gmdate('Y-m-d H:i') . " UTC. SECRETS: no es puja mai al repositori.\nreturn " . var_export($cfg, true) . ";\n";
            if (file_put_contents($cami_config, $text) === false) throw new RuntimeException('No es pot escriure pessebre/config.php');
            @chmod($cami_config, 0600);
            @unlink($fitxer_clau);                           // l'instal·lador ja no torna a funcionar
            pagina('Pessebre instal·lat', '<p>Fet: base de dades amb ' . (int)$n . ' llocs i el compte <strong>' . e($usuari) . '</strong>.</p>'
                . '<ol><li><strong>Esborra <code>installa.php</code></strong> de l\'hosting' . (is_file($fitxer_clau) ? ' i també <code>pessebre/clau-installacio.txt</code>' : '') . '.</li>'
                . '<li>Entra a <a href="admin.html">l\'administració</a>: carrega les classes, crea els comptes de l\'equip, els codis d\'etapa, l\'enllaç de la torronada i els codis de les famílies.</li>'
                . '<li>Comprova el pessebre des d\'un mòbil: <a href="./">' . e($url) . '</a></li></ol>');
        } catch (Throwable $ex) {
            if (is_file($cami_config) && !isset($text)) @unlink($cami_config);
            $errors[] = 'No s\'ha pogut instal·lar: ' . $ex->getMessage();
        }
    }
}

$url_defecte = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';
$v = function (string $k, string $d = '') use ($p) { return e(isset($p[$k]) && is_string($p[$k]) ? $p[$k] : $d); };
pagina('Instal·lació del pessebre', '<h2>1. Comprovacions</h2>' . $taula
    . ($pot ? '' : '<p class="error">Cal resoldre les marcades amb ✗ abans d\'instal·lar (demana-ho a informàtica).</p>')
    . ($errors ? '<p class="error">' . implode('<br>', array_map('e', $errors)) . '</p>' : '')
    . ($pot ? '<h2>2. Dades</h2><form method="post" class="form-installa">'
        . '<input type="hidden" name="clau" value="' . e($clau) . '"><input type="hidden" name="installa" value="1">'
        . '<label>Adreça pública del pessebre (va als QR: ha de ser la definitiva)</label><input class="camp" name="url_base" value="' . $v('url_base', $url_defecte) . '" required>'
        . '<label>Base de dades</label><select class="camp" name="bd"><option value="sqlite"' . ($v('bd') !== 'mysql' ? ' selected' : '') . '>SQLite (recomanat: un fitxer dins de pessebre/dades)</option>'
        . '<option value="mysql"' . ($v('bd') === 'mysql' ? ' selected' : '') . '>MySQL / MariaDB</option></select>'
        . '<fieldset><legend>Només si és MySQL</legend><input class="camp" name="mysql_host" placeholder="servidor (localhost)" value="' . $v('mysql_host', 'localhost') . '">'
        . '<input class="camp" name="mysql_bd" placeholder="nom de la base de dades" value="' . $v('mysql_bd') . '"><input class="camp" name="mysql_usuari" placeholder="usuari" value="' . $v('mysql_usuari') . '" autocomplete="off">'
        . '<input class="camp" name="mysql_clau" type="password" placeholder="contrasenya" autocomplete="off"></fieldset>'
        . '<label>Primer compte d\'administració (el teu)</label><input class="camp" name="usuari" placeholder="usuari (p. ex. eduard)" value="' . $v('usuari') . '" required>'
        . '<input class="camp" name="contrasenya" type="password" placeholder="contrasenya (10 caràcters com a mínim)" autocomplete="new-password" required>'
        . '<input class="camp" name="contrasenya2" type="password" placeholder="torna-la a escriure" autocomplete="new-password" required>'
        . '<button class="boto primari" type="submit">Instal·lar</button></form>' : ''));
