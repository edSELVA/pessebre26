<?php
// Participació automàtica (Fase E): quan una fita salta per data, l'aplicació afegeix figures, cases i elements fins a
// un objectiu d'ocupació de les zones obertes, perquè el pessebre no es vegi pobre. No ho omple tot:
// mai més del 40 % de cada zona en automàtiques, només llocs de les famílies (mai classes, claustre ni Reis) i només
// a zones obertes. Ho fa a poc a poc (AUTO_PER_HORA cada hora). Tria a l'atzar però de forma reproduïble amb la llavor
// de config.php. No compten per a les fites ni obren zones. L'administració les pot retirar una a una o totes.
declare(strict_types=1);

const AUTO_PER_HORA = 12;
const AUTO_OBJECTIU_DEFECTE = ['posta' => 35, 'nit' => 50, 'neu' => 60];

/** Nombre pseudoaleatori reproduïble a partir de la llavor (0 … $n-1). */
function auto_atzar(string $llavor, string $que, int $n): int
{
    return $n <= 1 ? 0 : hexdec(substr(hash('sha256', $llavor . '|' . $que), 0, 8)) % $n;
}

/** Afegeix automàtiques si toca. Retorna quantes n'ha afegit. */
function auto_aplica(PDO $pdo, array $cfg, DateTimeImmutable $ara): int
{
    $fase = config_llegeix($pdo, 'auto_fase');
    if ($fase === null || config_llegeix($pdo, 'tancat', '0') === '1' || bd_hora($ara) >= calendari($pdo, 'families_fins')) return 0;
    $hora = $ara->format('YmdH');
    if (config_llegeix($pdo, 'auto_hora') === $hora) return 0;                // com a molt un pas per hora
    $objectiu = (int)config_llegeix($pdo, "auto_$fase", (string)(AUTO_OBJECTIU_DEFECTE[$fase] ?? 0));
    $max_zona = (int)config_llegeix($pdo, 'auto_max_zona', '40');
    $zones = zones_obertes($pdo);
    $llavor = (string)($cfg['llavor'] ?? 'llavor');

    // Estat de les zones obertes (sense les cases de classe)
    $marques = implode(',', array_fill(0, count($zones), '?'));
    $st = $pdo->prepare("SELECT l.*, o.estat, o.origen FROM llocs l LEFT JOIN ocupacions o ON o.lloc_id = l.id
                         WHERE l.grup <> 'poble' AND l.zona IN ($marques)");
    $st->execute($zones);
    $llocs = $st->fetchAll();
    $total = count($llocs);
    $ocupats = count(array_filter($llocs, function ($l) { return $l['estat'] !== null; }));
    $falten = (int)ceil($total * $objectiu / 100) - $ocupats;
    if ($falten <= 0) { config_desa($pdo, 'auto_hora', $hora); return 0; }

    $per_zona = [];
    foreach ($llocs as $l) {
        $z = &$per_zona[$l['zona']];
        $z['total'] = ($z['total'] ?? 0) + 1;
        if ($l['origen'] === 'auto') $z['auto'] = ($z['auto'] ?? 0) + 1;
        unset($z);
    }
    // Candidats: llocs de les famílies lliures, en un ordre a l'atzar reproduïble
    $cand = array_values(array_filter($llocs, function ($l) { return $l['estat'] === null && $l['grup'] === 'general'; }));
    usort($cand, function ($a, $b) use ($llavor) { return strcmp(hash('sha256', $llavor . $a['id']), hash('sha256', $llavor . $b['id'])); });

    $peces = disseny($cfg, 'peces.json')['peces'];
    $paletes = disseny($cfg, 'paletes.json');
    $per_cat = [];
    foreach ($peces as $codi => $p) $per_cat[$p['cat']][] = $codi;
    $afegides = 0;
    $ins = $pdo->prepare("INSERT INTO ocupacions (lloc_id, estat, origen, peca, mirall, colors, hora) VALUES (?, 'confirmat', 'auto', ?, ?, ?, ?)");
    foreach ($cand as $l) {
        if ($afegides >= min($falten, AUTO_PER_HORA)) break;
        $z = $per_zona[$l['zona']];
        if (($z['auto'] ?? 0) + 1 > floor($z['total'] * $max_zona / 100)) continue;
        // Peça: cases i elements, la del mapa (o del grup intercanviable); persones i animals, qualsevol de la categoria
        if ($l['tipus'] === 'casa') $opcions = [$l['codi']];
        elseif ($l['tipus'] === 'element') $opcions = $peces[$l['codi']]['alternatives'] ?? [$l['codi']];
        else $opcions = $per_cat[$l['tipus']];
        $peca = $opcions[auto_atzar($llavor, $l['id'] . 'peca', count($opcions))];
        $grup = ['casa' => 'cases', 'animal' => 'animals', 'element' => 'elements'][$l['tipus']] ?? 'persones';
        $colors = [];
        foreach ($peces[$peca]['pers'] as $rol) {
            $n = count(($paletes[$grup][$rol] ?? $paletes['persones'][$rol])['opcions']);
            $colors[] = auto_atzar($llavor, $l['id'] . $rol, $n);
        }
        $ins->execute([$l['id'], $peca, auto_atzar($llavor, $l['id'] . 'mirall', 2), $colors ? json_encode($colors) : null, bd_hora($ara)]);
        $per_zona[$l['zona']]['auto'] = ($z['auto'] ?? 0) + 1;
        $afegides++;
    }
    config_desa($pdo, 'auto_hora', $hora);
    if ($afegides) registre_afegeix($pdo, $ara, 'automàtic', 'auto.afegeix', null, "$afegides figures ($fase)");
    return $afegides;
}

/** Retira totes les automàtiques. Retorna quantes. */
function auto_retira(PDO $pdo, DateTimeImmutable $ara): int
{
    $n = $pdo->exec("DELETE FROM ocupacions WHERE origen = 'auto'");
    $pdo->prepare('DELETE FROM config WHERE clau = ?')->execute(['auto_fase']);    // no se n'afegeixen més fins a la fita següent per data
    registre_afegeix($pdo, $ara, 'administració', 'admin.retirar_auto', null, "$n figures");
    return (int)$n;
}
