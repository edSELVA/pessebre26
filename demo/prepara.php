<?php
// Prepara la DEMOSTRACIÓ (ho crida eines/demo.sh): crea demo/dades/config.php amb valors aleatoris i 'demo' => true
// i, si encara no hi és, la base de dades de la demo a la fase 1. demo/dades/ no es puja mai (és a .gitignore).
// La contrasenya d'administració de la demo és «demo» (només serveix dins la demo, a la xarxa local).
declare(strict_types=1);
require __DIR__ . '/demo.php';

$dades = __DIR__ . '/dades';
if (!is_dir($dades)) mkdir($dades, 0700, true);
$cami = "$dades/config.php";
if (!is_file($cami)) {
    $valors = [
        'demo' => true,
        'bd_tipus' => 'sqlite',
        'bd_sqlite' => "$dades/demo.sqlite",
        'pebre' => bin2hex(random_bytes(32)),
        'llavor' => bin2hex(random_bytes(16)),
        'hash_admin' => password_hash('demo', PASSWORD_DEFAULT),
        'hash_lectura' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        'web' => realpath(__DIR__ . '/../public_html'),
        'snapshot' => "$dades/snapshot.json",
        'bloqueig' => "$dades/escriptura.lock",
        'demo_estat' => "$dades/estat.json",
        'url_base' => 'http://localhost:8027/',
        'data_simulada' => null,
    ];
    file_put_contents($cami, "<?php\n// Configuració de la DEMOSTRACIÓ creada per demo/prepara.php (no es puja mai).\nreturn " . var_export($valors, true) . ";\n");
    chmod($cami, 0600);
    echo "Creada la configuració de la demo.\n";
}
$cfg = app_config($cami);
$pdo = bd_connecta($cfg);
$e = demo_estat($cfg);
if ($e === null || !is_file($cfg['snapshot'])) {
    echo "Muntant la demo (fase 1)…\n";
    $e = demo_munta($pdo, $cfg, 1);
}
$e = demo_rellotge_a_lloc($cfg, $e);
$r = demo_resum($pdo, $cfg, $e);
echo "Fase de la demo: {$r['fase']} · {$r['fases'][$r['fase'] - 1]['nom']} · {$r['hora']}\n";
