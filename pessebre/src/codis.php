<?php
// Enllaços d'etapa i del claustre (i, a la Fase C, codis de família).
// Un enllaç és un testimoni aleatori de 128 bits que va després del # de l'adreça (el navegador no l'envia mai al servidor
// en carregar la pàgina). La base de dades només en desa l'HMAC amb el pebre de config.php.
declare(strict_types=1);

const ETAPES = [
    'llar' => "Llar d'infants",
    'infantil' => 'Infantil',
    'primaria' => 'Primària',
    'secundaria' => 'Secundària i batxillerat',
    'cicles' => 'Cicles',
];

/** Testimoni aleatori de 128 bits en base64 per a URL (22 caràcters). */
function testimoni_nou(): string
{
    return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
}

function testimoni_format_ok($t): bool
{
    return is_string($t) && preg_match('/^[A-Za-z0-9_-]{22}$/', $t) === 1;
}

function testimoni_hash(string $pebre, string $t): string
{
    return hash_hmac('sha256', $t, $pebre);
}

/** Crea (o regenera) l'enllaç d'una etapa o del claustre. L'anterior queda desactivat. Retorna el testimoni (es mostra un sol cop). */
function enllac_crea(PDO $pdo, string $pebre, string $tipus, ?string $etapa, DateTimeImmutable $ara): string
{
    if ($tipus === 'etapa' && !isset(ETAPES[$etapa])) throw new InvalidArgumentException("Etapa desconeguda: $etapa");
    if ($tipus !== 'etapa' && $tipus !== 'claustre') throw new InvalidArgumentException("Tipus d'enllaç desconegut: $tipus");
    $pdo->prepare('UPDATE enllacos SET activa = 0 WHERE tipus = ? AND (etapa = ? OR (etapa IS NULL AND ? IS NULL))')->execute([$tipus, $etapa, $etapa]);
    $t = testimoni_nou();
    $pdo->prepare('INSERT INTO enllacos (tipus, etapa, hash, activa, creat) VALUES (?, ?, ?, 1, ?)')
        ->execute([$tipus, $etapa, testimoni_hash($pebre, $t), bd_hora($ara)]);
    return $t;
}

/** Retorna l'enllaç actiu que correspon al testimoni (o null). */
function enllac_valida(PDO $pdo, string $pebre, $t, string $tipus): ?array
{
    if (!testimoni_format_ok($t)) return null;
    $st = $pdo->prepare('SELECT * FROM enllacos WHERE hash = ? AND tipus = ? AND activa = 1');
    $st->execute([testimoni_hash($pebre, $t), $tipus]);
    $e = $st->fetch();
    return $e ?: null;
}
