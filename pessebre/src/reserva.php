<?php
// Reserva de cases de classe (tutors). Flux: triar la classe → retenir una casa (10 min) → triar colors → confirmar.
// Una retenció caducada compta com a lloc lliure. Un cop confirmada, només l'administració la pot alliberar.
declare(strict_types=1);

const RETENCIO_CLASSES = 600;     // segons

/** Esborra les retencions caducades. */
function reserva_purga(PDO $pdo, DateTimeImmutable $ara): int
{
    $st = $pdo->prepare("DELETE FROM ocupacions WHERE estat = 'retingut' AND caduca <= ?");
    $st->execute([bd_hora($ara)]);
    return $st->rowCount();
}

/** Comprova que el període de les classes és obert i que no hi ha l'aturada activa. */
function classes_obertes(PDO $pdo, DateTimeImmutable $ara): void
{
    if (config_llegeix($pdo, 'aturada', '0') === '1') {
        throw new ErrorPessebre('Ara mateix les reserves estan aturades. Torna-ho a provar més tard.', 403, 'aturada');
    }
    if (bd_hora($ara) >= config_llegeix($pdo, 'classes_tanca', '2026-12-21 22:59:00')) {
        throw new ErrorPessebre('El període per reservar les cases de classe ja s\'ha tancat.', 403, 'tancat');
    }
}

/** Classes de l'etapa, indicant si ja tenen casa confirmada. */
function classes_llista(PDO $pdo, string $etapa): array
{
    $st = $pdo->prepare("SELECT c.id, c.nom, o.lloc_id, o.estat FROM classes c
                         LEFT JOIN ocupacions o ON o.classe_id = c.id
                         WHERE c.etapa = ? ORDER BY c.ordre, c.nom");
    $st->execute([$etapa]);
    $out = [];
    foreach ($st->fetchAll() as $c) {
        $out[] = ['id' => (int)$c['id'], 'nom' => $c['nom'], 'feta' => $c['estat'] === 'confirmat'];
    }
    return $out;
}

function classe_de_etapa(PDO $pdo, $classe_id, string $etapa): array
{
    $st = $pdo->prepare('SELECT * FROM classes WHERE id = ? AND etapa = ?');
    $st->execute([is_int($classe_id) ? $classe_id : -1, $etapa]);
    $c = $st->fetch();
    if (!$c) throw new ErrorPessebre('Aquesta classe no és de la llista d\'aquest enllaç.', 400, 'classe');
    return $c;
}

function ocupacio_de_classe(PDO $pdo, int $classe_id): ?array
{
    $st = $pdo->prepare('SELECT * FROM ocupacions WHERE classe_id = ?');
    $st->execute([$classe_id]);
    $o = $st->fetch();
    return $o ?: null;
}

/** Reté una casa de classe durant 10 minuts. Retorna el testimoni de la retenció (només el té qui l'ha demanada). */
function classes_retenir(PDO $pdo, array $cfg, string $etapa, $classe_id, $lloc_id, DateTimeImmutable $ara): array
{
    classes_obertes($pdo, $ara);
    $c = classe_de_etapa($pdo, $classe_id, $etapa);
    $l = is_string($lloc_id) ? lloc($pdo, $lloc_id) : null;
    if (!$l || $l['grup'] !== 'poble') throw new ErrorPessebre('Aquesta casa no es pot triar.', 400, 'lloc');

    $propia = ocupacio_de_classe($pdo, (int)$c['id']);
    if ($propia && $propia['estat'] === 'confirmat') throw new ErrorPessebre('Aquesta classe ja té la seva casa al pessebre.', 409, 'classe_feta');
    $st = $pdo->prepare('SELECT * FROM ocupacions WHERE lloc_id = ?');
    $st->execute([$l['id']]);
    $altra = $st->fetch();
    if ($altra && (int)$altra['classe_id'] !== (int)$c['id']) {
        throw new ErrorPessebre('Aquesta casa ja l\'ha triada una altra classe. Tria\'n una altra.', 409, 'ocupat');
    }
    // Una classe només té una retenció: si en tenia una altra, s'allibera.
    $pdo->prepare("DELETE FROM ocupacions WHERE classe_id = ? AND estat = 'retingut'")->execute([$c['id']]);

    $t = testimoni_nou();
    $caduca = $ara->modify('+' . RETENCIO_CLASSES . ' seconds');
    $pdo->prepare("INSERT INTO ocupacions (lloc_id, estat, origen, classe_id, peca, mirall, hash_retencio, caduca, hora)
                   VALUES (?, 'retingut', 'classe', ?, ?, ?, ?, ?, ?)")
        ->execute([$l['id'], $c['id'], $l['codi'], $l['mirall'], testimoni_hash($cfg['pebre'], $t), bd_hora($caduca), bd_hora($ara)]);
    return ['retencio' => $t, 'caduca' => $caduca->format('Y-m-d\TH:i:s\Z'), 'segons' => RETENCIO_CLASSES, 'lloc' => $l['id']];
}

/** Retorna la retenció d'aquesta classe si el testimoni és correcte i no ha caducat. */
function retencio_valida(PDO $pdo, array $cfg, array $classe, $lloc_id, $t, DateTimeImmutable $ara): array
{
    $o = ocupacio_de_classe($pdo, (int)$classe['id']);
    if (!$o || $o['estat'] !== 'retingut' || $o['lloc_id'] !== $lloc_id || !testimoni_format_ok($t)
        || !hash_equals($o['hash_retencio'], testimoni_hash($cfg['pebre'], $t)) || $o['caduca'] <= bd_hora($ara)) {
        throw new ErrorPessebre('La reserva temporal ha caducat o no és vàlida. Torna a triar la casa.', 409, 'retencio');
    }
    return $o;
}

/** Finestres tapades: màscara de bits vàlida per a la peça (0 = totes les finestres hi són). */
function finestres_valides(array $cfg, string $peca, $finestres): bool
{
    $n = count(disseny($cfg, 'peces.json')['peces'][$peca]['finestres'] ?? []);
    return is_int($finestres) && $finestres >= 0 && $finestres < (1 << $n);
}

/** Confirma la casa amb els colors triats (façana, teulada, porta i flors), les finestres tapades i si va girada. */
function classes_confirmar(PDO $pdo, array $cfg, string $etapa, $classe_id, $lloc_id, $t, $colors, DateTimeImmutable $ara, $mirall = null, $finestres = 0): array
{
    classes_obertes($pdo, $ara);
    $c = classe_de_etapa($pdo, $classe_id, $etapa);
    $o = retencio_valida($pdo, $cfg, $c, $lloc_id, $t, $ara);
    if (!colors_valids($cfg, $o['peca'], $colors)) throw new ErrorPessebre('Els colors triats no són vàlids.', 400, 'colors');
    if (!finestres_valides($cfg, $o['peca'], $finestres)) throw new ErrorPessebre('Les finestres triades no són vàlides.', 400, 'finestres');
    $m = $mirall === null ? (int)$o['mirall'] : ($mirall === 1 || $mirall === true ? 1 : 0);
    $pdo->prepare("UPDATE ocupacions SET estat = 'confirmat', colors = ?, mirall = ?, finestres = ?, hash_retencio = NULL, caduca = NULL, hora = ? WHERE lloc_id = ?")
        ->execute([json_encode($colors), $m, $finestres ?: null, bd_hora($ara), $o['lloc_id']]);
    registre_afegeix($pdo, $ara, 'classe: ' . $c['nom'], 'classes.confirmar', $o['lloc_id'], json_encode($colors));
    return ['lloc' => $o['lloc_id'], 'classe' => $c['nom']];
}

/** Deixa anar la retenció (el tutor tria una altra casa o surt). */
function classes_deixar(PDO $pdo, array $cfg, string $etapa, $classe_id, $lloc_id, $t, DateTimeImmutable $ara): array
{
    $c = classe_de_etapa($pdo, $classe_id, $etapa);
    $o = retencio_valida($pdo, $cfg, $c, $lloc_id, $t, $ara);
    $pdo->prepare("DELETE FROM ocupacions WHERE lloc_id = ? AND estat = 'retingut'")->execute([$o['lloc_id']]);
    return ['lloc' => $o['lloc_id']];
}

/** Administració (de moment, des del terminal): allibera un lloc, sigui retingut o confirmat. */
function reserva_allibera(PDO $pdo, string $lloc_id, string $qui, DateTimeImmutable $ara): bool
{
    $st = $pdo->prepare('DELETE FROM ocupacions WHERE lloc_id = ?');
    $st->execute([$lloc_id]);
    if ($st->rowCount() === 0) return false;
    registre_afegeix($pdo, $ara, $qui, 'admin.alliberar', $lloc_id);
    return true;
}

// ───────────────────────────── Famílies (codi) i claustre (enllaç comú)
// Retenció de 5 minuts. Famílies: cada codi serveix per a diverses figures (USOS_CODI, 3 per defecte; l'administració ho canvia).
// Claustre: fins a «claustre_limit» participacions (50) i fins a «claustre_tanca» (21 des a les 22:00).

const RETENCIO_FAMILIES = 300;
const ORDRE_ZONES = ['Z0', 'Z1', 'Z2', 'Z3'];
const DEFECTES_CALENDARI = [
    'families_des'  => '2026-12-20 23:00:00',   // 21 des 00:00 (Barcelona)
    'families_fins' => '2027-01-05 23:00:00',   // 6 gen 00:00 (Barcelona)
    'claustre_des'  => '2026-12-20 23:00:00',   // 21 des 00:00
    'claustre_tanca' => '2026-12-21 21:00:00',  // 21 des 22:00
];

function calendari(PDO $pdo, string $clau): string
{
    return (string)config_llegeix($pdo, $clau, DEFECTES_CALENDARI[$clau]);
}

/** 'abans', 'obert' o 'tancat' segons les dues claus de calendari. */
function finestra(PDO $pdo, string $des, string $fins, DateTimeImmutable $ara): string
{
    $t = bd_hora($ara);
    if ($t < calendari($pdo, $des)) return 'abans';
    return $t < calendari($pdo, $fins) ? 'obert' : 'tancat';
}

function claustre_alliberat(PDO $pdo, DateTimeImmutable $ara): bool
{
    return bd_hora($ara) >= calendari($pdo, 'claustre_tanca');
}

function aturada_comprova(PDO $pdo): void
{
    if (config_llegeix($pdo, 'aturada', '0') === '1') {
        throw new ErrorPessebre('Ara mateix la participació està aturada. Torna-ho a provar més tard.', 403, 'aturada');
    }
}

function zones_obertes(PDO $pdo): array
{
    return array_values(array_filter(explode(',', config_llegeix($pdo, 'zones', 'Z0'))));
}

/** Valida el codi escrit. Els errors de format o de control no compten com a intents; un codi inexistent, sí. */
function codi_comprova(PDO $pdo, array $cfg, $text): array
{
    $c = codi_normalitza($text);
    if ($c === null) throw new ErrorPessebre('El codi té 10 caràcters (lletres i xifres), com K7M-QX4-P9TR. Revisa\'l.', 400, 'format');
    if (!codi_control_ok($c)) throw new ErrorPessebre('Sembla que hi ha un caràcter mal copiat. Revisa el codi.', 400, 'control');
    $r = codi_busca($pdo, $cfg['pebre'], $c);
    if (!$r) throw new ErrorPessebre('Aquest codi no existeix. Revisa\'l.', 403, 'codi');
    return $r;
}

const USOS_CODI = 3;

/** Quantes figures permet cada codi de família (opció «usos_codi», entre 1 i 10). */
function codi_usos_permesos(PDO $pdo): int
{
    return max(1, min(10, (int)config_llegeix($pdo, 'usos_codi', (string)USOS_CODI)));
}

/** Estat del codi per a la pàgina (no canvia res). */
function codi_estat(PDO $pdo, array $codi, DateTimeImmutable $ara): array
{
    $f = finestra($pdo, 'families_des', 'families_fins', $ara);
    $permesos = codi_usos_permesos($pdo);
    $missatge = null;
    if ($f === 'abans') $missatge = 'Les famílies podran participar a partir del 21 de desembre. Guarda el codi!';
    elseif ($f === 'tancat') $missatge = 'La participació ja s\'ha tancat. Moltes gràcies!';
    elseif ((int)$codi['usos'] >= $permesos) $missatge = 'Aquest codi ja s\'ha fet servir. Moltes gràcies per participar!';
    elseif (config_llegeix($pdo, 'aturada', '0') === '1') $missatge = 'Ara mateix la participació està aturada. Torna-ho a provar més tard.';
    return ['pot' => $missatge === null, 'missatge' => $missatge, 'usos' => (int)$codi['usos'], 'permesos' => $permesos];
}

/** Comprova que la peça, el mirall, els colors i les finestres tretes encaixen amb el lloc. Retorna [peça, mirall, colors JSON, finestres]. */
function participacio_peca(array $cfg, array $lloc, $peca, $mirall, $colors, $finestres = 0): array
{
    $peces = disseny($cfg, 'peces.json')['peces'];
    $p = is_string($peca) ? ($peces[$peca] ?? null) : null;
    if (!$p || $p['cat'] !== $lloc['tipus']) throw new ErrorPessebre('Aquesta peça no es pot posar en aquest lloc.', 400, 'peca');
    // Cases: la del mapa. Elements: el del mapa o un del seu grup d'intercanviables (arbres, mates).
    $permeses = $lloc['tipus'] === 'element' ? ($peces[$lloc['codi']]['alternatives'] ?? [$lloc['codi']]) : [$lloc['codi']];
    if (in_array($lloc['tipus'], ['casa', 'element'], true) && !in_array($peca, $permeses, true)) throw new ErrorPessebre('Aquesta peça no es pot posar en aquest lloc.', 400, 'peca');
    $m = $mirall === 1 || $mirall === true ? 1 : 0;
    if (!$p['pers']) $colors = null;
    if (!colors_valids($cfg, $peca, $colors, lloc_es_pastor($cfg, $lloc['id']))) throw new ErrorPessebre('Els colors triats no són vàlids.', 400, 'colors');
    $finestres = $finestres ?? 0;
    if ($finestres !== 0 && ($lloc['tipus'] !== 'casa' || !finestres_valides($cfg, $peca, $finestres))) throw new ErrorPessebre('Les finestres triades no són vàlides.', 400, 'finestres');
    return [$peca, $m, $colors === null ? null : json_encode($colors), $finestres ?: null];
}

function lloc_es_pastor(array $cfg, string $id): bool
{
    static $pastors = null;
    if ($pastors === null) {
        $pastors = [];
        foreach (disseny($cfg, 'mapa.json')['llocs'] as $l) if (!empty($l['pastor'])) $pastors[$l['id']] = true;
    }
    return isset($pastors[$id]);
}

/** El lloc existeix i està lliure (ni ocupat ni retingut per algú altre). */
function lloc_lliure(PDO $pdo, $lloc_id): array
{
    $l = is_string($lloc_id) ? lloc($pdo, $lloc_id) : null;
    if (!$l) throw new ErrorPessebre('Aquest lloc no existeix.', 400, 'lloc');
    $st = $pdo->prepare('SELECT estat FROM ocupacions WHERE lloc_id = ?');
    $st->execute([$l['id']]);
    $o = $st->fetchColumn();
    if ($o === 'confirmat') throw new ErrorPessebre('Aquest lloc ja és ocupat. Tria\'n un altre.', 409, 'ocupat');
    if ($o === 'retingut') throw new ErrorPessebre('Algú altre està triant aquest lloc ara mateix. Tria\'n un altre.', 409, 'ocupat');
    return $l;
}

function retencio_nova(PDO $pdo, array $cfg, array $l, string $origen, ?int $codi_id, DateTimeImmutable $ara): array
{
    $t = testimoni_nou();
    $caduca = $ara->modify('+' . RETENCIO_FAMILIES . ' seconds');
    $pdo->prepare("INSERT INTO ocupacions (lloc_id, estat, origen, codi_id, peca, mirall, hash_retencio, caduca, hora)
                   VALUES (?, 'retingut', ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$l['id'], $origen, $codi_id, $l['codi'], $l['mirall'], testimoni_hash($cfg['pebre'], $t), bd_hora($caduca), bd_hora($ara)]);
    return ['retencio' => $t, 'segons' => RETENCIO_FAMILIES, 'lloc' => $l['id']];
}

/** Retenció d'un lloc per testimoni (famílies i claustre). */
function retencio_de_lloc(PDO $pdo, array $cfg, $lloc_id, $t, string $origen, DateTimeImmutable $ara): array
{
    $st = $pdo->prepare("SELECT * FROM ocupacions WHERE lloc_id = ? AND estat = 'retingut' AND origen = ?");
    $st->execute([is_string($lloc_id) ? $lloc_id : '', $origen]);
    $o = $st->fetch();
    if (!$o || !testimoni_format_ok($t) || !hash_equals($o['hash_retencio'], testimoni_hash($cfg['pebre'], $t)) || $o['caduca'] <= bd_hora($ara)) {
        throw new ErrorPessebre('La reserva temporal ha caducat o no és vàlida. Torna a triar el lloc.', 409, 'retencio');
    }
    return $o;
}

/** Confirma una figura de família o del claustre. Participació anònima: el vincle amb el codi (codi_id) s'esborra aquí. */
function confirma_ocupacio(PDO $pdo, string $lloc_id, array $peca, DateTimeImmutable $ara): void
{
    [$p, $m, $c, $f] = $peca;
    $pdo->prepare("UPDATE ocupacions SET estat = 'confirmat', peca = ?, mirall = ?, colors = ?, finestres = ?, codi_id = NULL, hash_retencio = NULL, caduca = NULL, hora = ? WHERE lloc_id = ?")
        ->execute([$p, $m, $c, $f, bd_hora($ara), $lloc_id]);
}

// Famílies

function codi_retenir(PDO $pdo, array $cfg, array $codi, $lloc_id, DateTimeImmutable $ara): array
{
    $e = codi_estat($pdo, $codi, $ara);
    if (!$e['pot']) throw new ErrorPessebre($e['missatge'], 403, 'codi_no');
    $pdo->prepare("DELETE FROM ocupacions WHERE codi_id = ? AND estat = 'retingut'")->execute([$codi['id']]);
    $l = lloc_lliure($pdo, $lloc_id);
    $permes = $l['grup'] === 'general' || ($l['grup'] === 'claustre' && claustre_alliberat($pdo, $ara));
    if (!$permes) throw new ErrorPessebre('Aquest lloc està reservat. Tria un lloc amb marca a terra o una peça transparent.', 400, 'lloc');
    if (!in_array($l['zona'], zones_obertes($pdo), true)) throw new ErrorPessebre('Aquesta zona encara no és oberta.', 400, 'zona');
    return retencio_nova($pdo, $cfg, $l, 'familia', (int)$codi['id'], $ara);
}

function codi_confirmar(PDO $pdo, array $cfg, array $codi, $lloc_id, $t, $peca, $mirall, $colors, DateTimeImmutable $ara, $finestres = 0): array
{
    $e = codi_estat($pdo, $codi, $ara);
    if (!$e['pot']) throw new ErrorPessebre($e['missatge'], 403, 'codi_no');
    $o = retencio_de_lloc($pdo, $cfg, $lloc_id, $t, 'familia', $ara);
    if ((int)$o['codi_id'] !== (int)$codi['id']) throw new ErrorPessebre('La reserva temporal no és d\'aquest codi.', 409, 'retencio');
    $p = participacio_peca($cfg, lloc($pdo, $o['lloc_id']), $peca, $mirall, $colors, $finestres);
    $st = $pdo->prepare('UPDATE codis SET usos = usos + 1 WHERE id = ? AND usos < ?');
    $st->execute([$codi['id'], $e['permesos']]);
    if ($st->rowCount() !== 1) throw new ErrorPessebre('Aquest codi ja s\'ha fet servir.', 409, 'codi_no');
    confirma_ocupacio($pdo, $o['lloc_id'], $p, $ara);
    registre_afegeix($pdo, $ara, 'família', 'codi.confirmar', $o['lloc_id'], $p[0]);     // sense el codi: participació anònima
    $obertes = zones_actualitza($pdo, $ara);
    return ['lloc' => $o['lloc_id'], 'zona_nova' => $obertes, 'queden' => $e['permesos'] - (int)$codi['usos'] - 1];
}

function codi_deixar(PDO $pdo, array $cfg, array $codi, $lloc_id, $t, DateTimeImmutable $ara): array
{
    $o = retencio_de_lloc($pdo, $cfg, $lloc_id, $t, 'familia', $ara);
    if ((int)$o['codi_id'] !== (int)$codi['id']) throw new ErrorPessebre('La reserva temporal no és d\'aquest codi.', 409, 'retencio');
    $pdo->prepare('DELETE FROM ocupacions WHERE lloc_id = ?')->execute([$o['lloc_id']]);
    return ['lloc' => $o['lloc_id']];
}

// Claustre

function claustre_estat(PDO $pdo, DateTimeImmutable $ara): array
{
    $f = finestra($pdo, 'claustre_des', 'claustre_tanca', $ara);
    $limit = (int)config_llegeix($pdo, 'claustre_limit', '50');
    $fetes = (int)$pdo->query("SELECT COUNT(*) FROM ocupacions WHERE origen = 'claustre' AND estat = 'confirmat'")->fetchColumn();
    $missatge = null;
    if ($f === 'abans') $missatge = 'L\'enllaç del claustre s\'obre el 21 de desembre.';
    elseif ($f === 'tancat') $missatge = 'El claustre ja ha tancat la seva part del pessebre. Gràcies!';
    elseif ($fetes >= $limit) $missatge = 'El claustre ja ha arribat a les ' . $limit . ' participacions. Gràcies!';
    elseif (config_llegeix($pdo, 'aturada', '0') === '1') $missatge = 'Ara mateix la participació està aturada. Torna-ho a provar més tard.';
    return ['pot' => $missatge === null, 'missatge' => $missatge, 'fetes' => $fetes, 'limit' => $limit];
}

function claustre_retenir(PDO $pdo, array $cfg, $lloc_id, DateTimeImmutable $ara): array
{
    $e = claustre_estat($pdo, $ara);
    if (!$e['pot']) throw new ErrorPessebre($e['missatge'], 403, 'claustre_no');
    $st = $pdo->prepare("SELECT COUNT(*) FROM ocupacions WHERE origen = 'claustre' AND estat = 'retingut' AND caduca > ?");
    $st->execute([bd_hora($ara)]);
    if ($e['fetes'] + (int)$st->fetchColumn() >= $e['limit']) throw new ErrorPessebre('Ara mateix tots els llocs que queden s\'estan triant. Torna-ho a provar d\'aquí a uns minuts.', 409, 'ple');
    $l = lloc_lliure($pdo, $lloc_id);
    if ($l['grup'] !== 'claustre') throw new ErrorPessebre('Aquest lloc no és del claustre. Tria un pastor o un lloc prop de la clariana.', 400, 'lloc');
    return retencio_nova($pdo, $cfg, $l, 'claustre', null, $ara);
}

function claustre_confirmar(PDO $pdo, array $cfg, $lloc_id, $t, $peca, $mirall, $colors, DateTimeImmutable $ara, $finestres = 0): array
{
    $e = claustre_estat($pdo, $ara);
    if (!$e['pot']) throw new ErrorPessebre($e['missatge'], 403, 'claustre_no');
    $o = retencio_de_lloc($pdo, $cfg, $lloc_id, $t, 'claustre', $ara);
    $p = participacio_peca($cfg, lloc($pdo, $o['lloc_id']), $peca, $mirall, $colors, $finestres);
    confirma_ocupacio($pdo, $o['lloc_id'], $p, $ara);
    registre_afegeix($pdo, $ara, 'claustre', 'claustre.confirmar', $o['lloc_id'], $p[0]);
    return ['lloc' => $o['lloc_id'], 'zona_nova' => zones_actualitza($pdo, $ara)];
}

function claustre_deixar(PDO $pdo, array $cfg, $lloc_id, $t, DateTimeImmutable $ara): array
{
    $o = retencio_de_lloc($pdo, $cfg, $lloc_id, $t, 'claustre', $ara);
    $pdo->prepare('DELETE FROM ocupacions WHERE lloc_id = ?')->execute([$o['lloc_id']]);
    return ['lloc' => $o['lloc_id']];
}

// Zones

/** Obre la zona següent quan les obertes arriben al llindar d'ocupació real (75 %). Retorna les zones obertes ara (o null si no canvia). */
function zones_actualitza(PDO $pdo, DateTimeImmutable $ara): ?array
{
    $obertes = zones_obertes($pdo);
    $llindar = (int)config_llegeix($pdo, 'llindar_zona', '75');
    $canvi = false;
    while (count($obertes) < count(ORDRE_ZONES)) {
        $marques = implode(',', array_fill(0, count($obertes), '?'));
        $st = $pdo->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN o.estat = 'confirmat' AND o.origen <> 'auto' THEN 1 ELSE 0 END) AS reals
                             FROM llocs l LEFT JOIN ocupacions o ON o.lloc_id = l.id
                             WHERE l.grup <> 'poble' AND l.zona IN ($marques)");
        $st->execute($obertes);
        $r = $st->fetch();
        if (!$r['total'] || (int)$r['reals'] * 100 < $llindar * (int)$r['total']) break;
        $seguent = ORDRE_ZONES[count($obertes)];
        $obertes[] = $seguent;
        config_desa($pdo, 'zones', implode(',', $obertes));
        registre_afegeix($pdo, $ara, 'automàtic', 'zones.obre', null, $seguent);
        $canvi = true;
    }
    return $canvi ? $obertes : null;
}
