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

/** Confirma la casa amb els colors triats (índexs de façana, teulada i porta). */
function classes_confirmar(PDO $pdo, array $cfg, string $etapa, $classe_id, $lloc_id, $t, $colors, DateTimeImmutable $ara): array
{
    classes_obertes($pdo, $ara);
    $c = classe_de_etapa($pdo, $classe_id, $etapa);
    $o = retencio_valida($pdo, $cfg, $c, $lloc_id, $t, $ara);
    if (!colors_valids($cfg, $o['peca'], $colors)) throw new ErrorPessebre('Els colors triats no són vàlids.', 400, 'colors');
    $pdo->prepare("UPDATE ocupacions SET estat = 'confirmat', colors = ?, hash_retencio = NULL, caduca = NULL, hora = ? WHERE lloc_id = ?")
        ->execute([json_encode($colors), bd_hora($ara), $o['lloc_id']]);
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
