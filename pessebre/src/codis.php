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

/** Crea (o regenera) l'enllaç del claustre (l'únic enllaç que queda: §30). L'anterior queda desactivat. Retorna el testimoni. */
function enllac_crea(PDO $pdo, string $pebre, string $tipus, ?string $etapa, DateTimeImmutable $ara): string
{
    if ($tipus !== 'claustre') throw new InvalidArgumentException("Tipus d'enllaç desconegut: $tipus");
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

// ── Codis de família: 10 caràcters d'un alfabet de 31 sense ambigus (sense 0, 1, I, L, O):
//    9 aleatoris (generador criptogràfic) + 1 de control (suma ponderada mòdul 31). S'imprimeixen com K7M-QX4-P9TR.
//    La base de dades només en desa l'HMAC amb el pebre.

const ALFABET_CODIS = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

/**
 * Caràcter de control dels 9 primers: tria c perquè Σ (posició × valor) ≡ 0 (mòdul 31), amb posicions 1..10.
 * Com que 31 és primer, detecta qualsevol caràcter mal copiat i qualsevol intercanvi de dos caràcters.
 */
function codi_control(string $nou): string
{
    $suma = 0;
    for ($i = 0; $i < 9; $i++) $suma += ($i + 1) * strpos(ALFABET_CODIS, $nou[$i]);
    $invers_de_10 = 28;                       // 10 × 28 = 280 ≡ 1 (mòdul 31)
    return ALFABET_CODIS[((31 - $suma % 31) * $invers_de_10) % 31];
}

function codi_nou(): string
{
    $c = '';
    for ($i = 0; $i < 9; $i++) $c .= ALFABET_CODIS[random_int(0, strlen(ALFABET_CODIS) - 1)];
    return $c . codi_control($c);
}

/** Treu guions i espais i ho passa a majúscules. Retorna null si no té el format (10 caràcters de l'alfabet). */
function codi_normalitza($text): ?string
{
    if (!is_string($text)) return null;
    $c = strtoupper(preg_replace('/[\s\-.]/', '', $text));
    return preg_match('/^[' . ALFABET_CODIS . ']{10}$/', $c) ? $c : null;
}

/** El caràcter de control quadra? (detecta un caràcter mal copiat i la majoria d'intercanvis de veïns) */
function codi_control_ok(string $c): bool
{
    return codi_control(substr($c, 0, 9)) === $c[9];
}

function codi_impres(string $c): string
{
    return substr($c, 0, 3) . '-' . substr($c, 3, 3) . '-' . substr($c, 6, 4);
}

function codi_hash(string $pebre, string $c): string
{
    return hash_hmac('sha256', 'codi:' . $c, $pebre);
}

/** Genera un lot de codis nous. Retorna els codis en clar (es mostren UN SOL COP; la BD només en desa l'HMAC). */
function codis_genera(PDO $pdo, string $pebre, int $quants, DateTimeImmutable $ara): array
{
    $lot = (int)$pdo->query('SELECT COALESCE(MAX(lot), 0) + 1 FROM codis')->fetchColumn();
    $st = $pdo->prepare('INSERT INTO codis (hash, lot, usos, creat) VALUES (?, ?, 0, ?)');
    $existeix = $pdo->prepare('SELECT COUNT(*) FROM codis WHERE hash = ?');
    $out = [];
    while (count($out) < $quants) {
        $c = codi_nou();
        $h = codi_hash($pebre, $c);
        $existeix->execute([$h]);
        if ((int)$existeix->fetchColumn() || isset($out[$c])) continue;
        $st->execute([$h, $lot, bd_hora($ara)]);
        $out[$c] = true;
    }
    return ['lot' => $lot, 'codis' => array_keys($out)];
}

function codi_busca(PDO $pdo, string $pebre, string $c): ?array
{
    $st = $pdo->prepare('SELECT * FROM codis WHERE hash = ?');
    $st->execute([codi_hash($pebre, $c)]);
    $r = $st->fetch();
    return $r ?: null;
}

// ── Codis de classe (§30): un per classe, amb el mateix format que els de família. La classe en desa l'HMAC (codi_hash).
//    Identifiquen la classe (el tutor no tria res de cap llista). Regenerar-ne un fa que l'anterior deixi de funcionar.

/** Codi nou per a una classe (l'anterior queda anul·lat). Retorna el codi: es mostra un sol cop. */
function codi_classe_nou(PDO $pdo, string $pebre, int $classe_id): string
{
    $familia = $pdo->prepare('SELECT COUNT(*) FROM codis WHERE hash = ?');
    $classe = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE codi_hash = ?');
    do {
        $c = codi_nou();
        $h = codi_hash($pebre, $c);
        $familia->execute([$h]);
        $classe->execute([$h]);
    } while ((int)$familia->fetchColumn() || (int)$classe->fetchColumn());
    $pdo->prepare('UPDATE classes SET codi_hash = ? WHERE id = ?')->execute([$h, $classe_id]);
    return $c;
}

/** La classe d'un codi (ja normalitzat), o null. */
function classe_per_codi(PDO $pdo, string $pebre, string $c): ?array
{
    $st = $pdo->prepare('SELECT * FROM classes WHERE codi_hash = ?');
    $st->execute([codi_hash($pebre, $c)]);
    $r = $st->fetch();
    return $r ?: null;
}
