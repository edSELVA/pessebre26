<?php
// Executa totes les proves de proves/prova-*.php.  Ús: ./prova   (o php proves/executa.php [filtre])
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(function ($n, $msg, $f, $l) { throw new ErrorException($msg, 0, $n, $f, $l); });

$ARREL = dirname(__DIR__);
$GLOBALS['proves'] = ['ok' => 0, 'ko' => 0];

/** Comprova una condició i n'apunta el resultat. */
function comprova(bool $cond, string $que): void
{
    if ($cond) { $GLOBALS['proves']['ok']++; return; }
    $GLOBALS['proves']['ko']++;
    echo "    ✗ $que\n";
}

function igual($esperat, $real, string $que): void
{
    comprova($esperat === $real, $que . ($esperat === $real ? '' : ' (esperat ' . json_encode($esperat, JSON_UNESCAPED_UNICODE) . ', real ' . json_encode($real, JSON_UNESCAPED_UNICODE) . ')'));
}

function llança(callable $f, string $que): void
{
    try { $f(); comprova(false, "$que (no ha fallat)"); }
    catch (Throwable $e) { comprova(true, $que); }
}

function json_de(string $cami): array { return json_decode((string)file_get_contents($cami), true, 512, JSON_THROW_ON_ERROR); }

$filtre = $argv[1] ?? '';
foreach (glob(__DIR__ . '/prova-*.php') as $fitxer) {
    if ($filtre !== '' && strpos(basename($fitxer), $filtre) === false) continue;
    $abans = $GLOBALS['proves'];
    echo basename($fitxer, '.php'), "\n";
    try {
        (function () use ($fitxer, $ARREL) { require $fitxer; })();
    } catch (Throwable $e) {
        comprova(false, 'Excepció: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
    $ok = $GLOBALS['proves']['ok'] - $abans['ok'];
    $ko = $GLOBALS['proves']['ko'] - $abans['ko'];
    echo $ko ? "  ✗ $ko errors, $ok bé\n" : "  ✓ $ok comprovacions\n";
}
$p = $GLOBALS['proves'];
echo "\n", $p['ko'] ? "✗ HI HA ERRORS: {$p['ko']} de " . ($p['ok'] + $p['ko']) : "✓ Tot correcte: {$p['ok']} comprovacions", " (PHP " . PHP_VERSION . ")\n";
exit($p['ko'] ? 1 : 0);
