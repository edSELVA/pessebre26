<?php
// Llocs del mapa (es carreguen de public_html/disseny/mapa.json) i fitxers de disseny que fa servir el servidor.
declare(strict_types=1);

/** Llegeix un JSON de public_html/disseny/ (es guarda en memòria durant la petició). */
function disseny(array $cfg, string $nom): array
{
    static $memoria = [];
    $cami = rtrim($cfg['web'], '/') . "/disseny/$nom";
    if (!isset($memoria[$cami])) {
        $d = json_decode((string)@file_get_contents($cami), true);
        if (!is_array($d)) throw new RuntimeException("No es pot llegir $cami");
        $memoria[$cami] = $d;
    }
    return $memoria[$cami];
}

/** Carrega els llocs del mapa si la taula és buida. Retorna quants llocs hi ha. */
function llocs_carrega(PDO $pdo, array $mapa): int
{
    $n = (int)$pdo->query('SELECT COUNT(*) FROM llocs')->fetchColumn();
    if ($n > 0) {
        if ($n !== count($mapa['llocs'])) throw new RuntimeException("La taula llocs té $n llocs i el mapa " . count($mapa['llocs']));
        return $n;
    }
    $st = $pdo->prepare('INSERT INTO llocs (id, tipus, zona, grup, x, y, codi, mirall) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($mapa['llocs'] as $l) {
        $st->execute([$l['id'], $l['tipus'], $l['zona'], $l['grup'], $l['x'], $l['y'], $l['codi'] ?? null, $l['mirall'] ?? 0]);
    }
    return count($mapa['llocs']);
}

function lloc(PDO $pdo, string $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM llocs WHERE id = ?');
    $st->execute([$id]);
    $l = $st->fetch();
    return $l ?: null;
}

/** Comprova que els índexs de colors són vàlids per a la peça (en l'ordre de "pers"). */
function colors_valids(array $cfg, string $peca, $colors): bool
{
    $p = disseny($cfg, 'peces.json')['peces'][$peca] ?? null;
    if (!$p || !is_array($colors) || count($colors) !== count($p['pers']) || array_values($colors) !== $colors) return false;
    $grup = $p['cat'] === 'casa' ? 'cases' : 'persones';
    $paletes = disseny($cfg, 'paletes.json');
    foreach ($p['pers'] as $i => $rol) {
        $n = count($paletes[$grup][$rol]['opcions'] ?? []);
        if (!is_int($colors[$i]) || $colors[$i] < 0 || $colors[$i] >= $n) return false;
    }
    return true;
}
