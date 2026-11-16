<?php
// Límit d'intents fallits sense desar la IP: empremta = HMAC de la IP amb una sal que canvia cada hora
// (quan canvia, s'esborren les empremtes velles). 10 fallades en 15 minuts → cal esperar.
// Fre global: si hi ha massa fallades en un minut, s'aturen totes les comprovacions un moment.
declare(strict_types=1);

const LIMIT_FALLADES = 10;
const LIMIT_FINESTRA = 900;        // 15 min
// Fre global: 300/min el podia activar una sola persona amb IPv6 (cada adreça d'un /64 compta com una IP diferent) i
// aleshores es bloquejava la participació de TOTHOM. Amb codis de 10 caràcters (31^9 ≈ 2,6·10^13 combinacions) el fre global
// no cal per evitar endevinar-ne: només és una xarxa de seguretat contra un atac massiu.
const LIMIT_GLOBAL_MINUT = 3000;

function limit_empremta(PDO $pdo, string $ip, DateTimeImmutable $ara): string
{
    $hora = $ara->setTimezone(new DateTimeZone('UTC'))->format('YmdH');
    $sal = config_llegeix($pdo, 'sal');
    if ($sal === null || config_llegeix($pdo, 'sal_hora') !== $hora) {
        $sal = bin2hex(random_bytes(32));
        config_desa($pdo, 'sal', $sal);
        config_desa($pdo, 'sal_hora', $hora);
        $pdo->exec('DELETE FROM intents');     // les empremtes amb la sal vella ja no serveixen
    }
    return hash_hmac('sha256', ip_xarxa($ip), $sal);
}

/** IPv4 tal qual; IPv6, el prefix /64 (una sola connexió domèstica o mòbil en té milers d'adreces). */
function ip_xarxa(string $ip): string
{
    if (strpos($ip, ':') === false) return $ip;
    $bin = @inet_pton($ip);
    if ($bin === false || strlen($bin) !== 16) return $ip;
    return bin2hex(substr($bin, 0, 8)) . '::/64';
}

/** Llança una excepció si aquesta empremta (o el servidor sencer) ha fallat massa. */
function limit_comprova(PDO $pdo, string $empremta, DateTimeImmutable $ara, int $maxim = LIMIT_FALLADES): void
{
    $minut = $ara->format('YmdHi');
    if (config_llegeix($pdo, 'global_minut') === $minut && (int)config_llegeix($pdo, 'global_fallades', '0') >= LIMIT_GLOBAL_MINUT) {
        throw new ErrorPessebre('Ara hi ha massa intents. Torna-ho a provar d\'aquí a un minut.', 429, 'massa_intents');
    }
    $st = $pdo->prepare('SELECT finestra, fallades FROM intents WHERE empremta = ?');
    $st->execute([$empremta]);
    $r = $st->fetch();
    if ($r && $r['finestra'] > bd_hora($ara->modify('-' . LIMIT_FINESTRA . ' seconds')) && (int)$r['fallades'] >= $maxim) {
        throw new ErrorPessebre('Massa intents fallits. Torna-ho a provar d\'aquí a 15 minuts.', 429, 'massa_intents');
    }
}

function limit_fallada(PDO $pdo, string $empremta, DateTimeImmutable $ara): void
{
    $st = $pdo->prepare('SELECT finestra, fallades FROM intents WHERE empremta = ?');
    $st->execute([$empremta]);
    $r = $st->fetch();
    if ($r && $r['finestra'] > bd_hora($ara->modify('-' . LIMIT_FINESTRA . ' seconds'))) {
        $pdo->prepare('UPDATE intents SET fallades = fallades + 1 WHERE empremta = ?')->execute([$empremta]);
    } else {
        $pdo->prepare('DELETE FROM intents WHERE empremta = ?')->execute([$empremta]);
        $pdo->prepare('INSERT INTO intents (empremta, finestra, fallades) VALUES (?, ?, 1)')->execute([$empremta, bd_hora($ara)]);
    }
    $minut = $ara->format('YmdHi');
    $n = config_llegeix($pdo, 'global_minut') === $minut ? (int)config_llegeix($pdo, 'global_fallades', '0') : 0;
    config_desa($pdo, 'global_minut', $minut);
    config_desa($pdo, 'global_fallades', (string)($n + 1));
}
