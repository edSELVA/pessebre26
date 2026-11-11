<?php
// Gestió des del terminal mentre no hi ha pàgina d'administració (Fase D).
//
//   php eines/gestio.php installa                 crea la base de dades i carrega els llocs (en local, també config.php de proves)
//   php eines/gestio.php classes FITXER            carrega la llista de classes (format: etapa;nom)
//   php eines/gestio.php enllac ETAPA|totes|claustre  crea o regenera enllaços d'etapa o del claustre (es mostren UN SOL COP)
//   php eines/gestio.php codis N                   crea un lot de N codis de família i el full d'etiquetes per imprimir
//   php eines/gestio.php ronda 1|2                 segona ronda: cada codi permet una segona participació
//   php eines/gestio.php calendari CLAU "AAAA-MM-DD hh:mm"   families_des, families_fins, claustre_des, claustre_tanca (hora de Barcelona)
//   php eines/gestio.php allibera LLOC             allibera una casa (p. ex. C014)
//   php eines/gestio.php tanca "AAAA-MM-DD hh:mm"  hora de tancament de les classes (hora de Barcelona)
//   php eines/gestio.php aturada si|no             atura o reprèn les reserves
//   php eines/gestio.php estat                     resum
//
// Opció: --config=CAMÍ (per defecte pessebre/config.php)
declare(strict_types=1);
require __DIR__ . '/../pessebre/src/app.php';
require __DIR__ . '/../pessebre/src/etiquetes.php';

const NOM_CLASSE = '/^[\p{L}\p{N} .·\'-]{1,8}$/u';

$args = array_values(array_filter(array_slice($argv, 1), function ($a) { return strncmp($a, '--config=', 9) !== 0; }));
$opc = array_values(array_filter($argv, function ($a) { return strncmp($a, '--config=', 9) === 0; }));
$cami_config = $opc ? substr($opc[0], 9) : dirname(__DIR__) . '/pessebre/config.php';
$ordre = $args[0] ?? '';
$ara = new DateTimeImmutable('now', new DateTimeZone('UTC'));

function surt(string $m, int $codi = 1): void { fwrite($codi ? STDERR : STDOUT, $m . "\n"); exit($codi); }

if ($ordre === 'installa' && !is_file($cami_config)) {
    $arrel = dirname(__DIR__);
    $text = (string)file_get_contents("$arrel/pessebre/config.exemple.php");
    $text = preg_replace("/('pebre'\\s*=>\\s*)'CANVIA-AIXO'/", "\$1'" . bin2hex(random_bytes(32)) . "'", $text);
    $text = preg_replace("/('llavor'\\s*=>\\s*)'CANVIA-AIXO'/", "\$1'" . bin2hex(random_bytes(16)) . "'", $text);
    $text = str_replace("'https://pessebre.exemple.cat/'", "'http://localhost:8026/'", $text);
    $text = str_replace('// Configuració d\'EXEMPLE (valors falsos).', '// Configuració LOCAL DE PROVES creada per eines/gestio.php (no es puja mai).', $text);
    file_put_contents($cami_config, $text);
    echo "Creat $cami_config amb valors aleatoris (només per a proves en local).\n";
}

try {
    $cfg = app_config($cami_config);
} catch (RuntimeException $e) {
    surt($e->getMessage());
}
if (($cfg['bd_tipus'] ?? 'sqlite') === 'sqlite' && !is_dir(dirname($cfg['bd_sqlite']))) mkdir(dirname($cfg['bd_sqlite']), 0700, true);
if (!is_dir(dirname($cfg['bloqueig']))) mkdir(dirname($cfg['bloqueig']), 0700, true);
$pdo = bd_connecta($cfg);

switch ($ordre) {
    case 'installa':
        bd_esquema($pdo);
        $n = llocs_carrega($pdo, disseny($cfg, 'mapa.json'));
        $defectes = DEFECTES_CALENDARI + ['classes_tanca' => '2026-12-21 22:59:00', 'moment' => 'dia', 'zones' => 'Z0', 'aturada' => '0',
                                          'claustre_limit' => '50', 'ronda' => '1', 'llindar_zona' => '75'];
        foreach ($defectes as $k => $v) if (config_llegeix($pdo, $k) === null) config_desa($pdo, $k, $v);
        snapshot_regenera($pdo, $cfg, $ara);
        echo "Base de dades a punt: $n llocs. snapshot.json regenerat.\n";
        break;

    case 'classes':
        $fitxer = $args[1] ?? surt('Falta el fitxer. Ús: php eines/gestio.php classes classes.csv');
        $linies = @file($fitxer, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: surt("No es pot llegir $fitxer");
        $noves = [];
        foreach ($linies as $i => $linia) {
            $linia = trim(preg_replace('/^\xEF\xBB\xBF/', '', $linia));
            if ($linia === '' || $linia[0] === '#' || $linia === 'etapa;nom') continue;
            $parts = array_map('trim', explode(';', $linia));
            if (count($parts) !== 2 || !isset(ETAPES[$parts[0]])) surt("Línia " . ($i + 1) . ": cal «etapa;nom» amb etapa " . implode(', ', array_keys(ETAPES)));
            if (!preg_match(NOM_CLASSE, $parts[1])) surt("Línia " . ($i + 1) . ": el nom «{$parts[1]}» ha de tenir com a molt 8 caràcters (lletres, xifres, espais, punts o guions)");
            if (isset($noves[$parts[1]])) surt("Línia " . ($i + 1) . ": el nom «{$parts[1]}» està repetit");
            $noves[$parts[1]] = ['etapa' => $parts[0], 'ordre' => count($noves)];
        }
        $poble = (int)$pdo->query("SELECT COUNT(*) FROM llocs WHERE grup = 'poble'")->fetchColumn();
        if (count($noves) > $poble) surt('Hi ha ' . count($noves) . " classes i només $poble cases de classe.");
        $pdo->beginTransaction();
        foreach ($noves as $nom => $c) {
            $st = $pdo->prepare('UPDATE classes SET etapa = ?, ordre = ? WHERE nom = ?');
            $st->execute([$c['etapa'], $c['ordre'], $nom]);
            $existeix = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE nom = ?');
            $existeix->execute([$nom]);
            if (!(int)$existeix->fetchColumn()) $pdo->prepare('INSERT INTO classes (etapa, nom, ordre) VALUES (?, ?, ?)')->execute([$c['etapa'], $nom, $c['ordre']]);
        }
        $mantingudes = 0;
        foreach ($pdo->query('SELECT c.id, c.nom, o.lloc_id FROM classes c LEFT JOIN ocupacions o ON o.classe_id = c.id')->fetchAll() as $c) {
            if (isset($noves[$c['nom']])) continue;
            if ($c['lloc_id']) { $mantingudes++; echo "Avís: «{$c['nom']}» no és al fitxer però ja té casa ({$c['lloc_id']}); es manté.\n"; continue; }
            $pdo->prepare('DELETE FROM classes WHERE id = ?')->execute([$c['id']]);
        }
        $pdo->commit();
        $per = $pdo->query('SELECT etapa, COUNT(*) AS n FROM classes GROUP BY etapa')->fetchAll(PDO::FETCH_KEY_PAIR);
        echo "Classes carregades: " . count($noves) . " (" . implode(', ', array_map(function ($e, $n) { return "$e $n"; }, array_keys($per), $per)) . ").\n";
        break;

    case 'enllac':
        $quina = $args[1] ?? surt('Ús: php eines/gestio.php enllac ETAPA|totes|claustre  (etapes: ' . implode(', ', array_keys(ETAPES)) . ')');
        if ($quina === 'claustre') {
            $t = enllac_crea($pdo, $cfg['pebre'], 'claustre', null, $ara);
            registre_afegeix($pdo, $ara, 'terminal', 'admin.enllac', null, 'claustre');
            echo "Enllaç nou del claustre (es mostra UN SOL COP; l'anterior deixa de funcionar):\n\n  {$cfg['url_base']}#k=$t\n\n";
            break;
        }
        $etapes = $quina === 'totes' ? array_keys(ETAPES) : [$quina];
        foreach ($etapes as $e) if (!isset(ETAPES[$e])) surt("Etapa desconeguda: $e");
        echo "Enllaços nous (es mostren UN SOL COP; els anteriors deixen de funcionar):\n\n";
        foreach ($etapes as $e) {
            $t = enllac_crea($pdo, $cfg['pebre'], 'etapa', $e, $ara);
            registre_afegeix($pdo, $ara, 'terminal', 'admin.enllac', null, "etapa $e");
            printf("  %-26s %sclasses.html#e=%s\n", ETAPES[$e], $cfg['url_base'], $t);
        }
        echo "\n";
        break;

    case 'codis':
        $n = (int)($args[1] ?? 0);
        if ($n < 1 || $n > 5000) surt('Ús: php eines/gestio.php codis N  (entre 1 i 5000)');
        $pdo->beginTransaction();
        $lot = codis_genera($pdo, $cfg['pebre'], $n, $ara);
        registre_afegeix($pdo, $ara, 'terminal', 'admin.generar_codis', null, "lot {$lot['lot']}: $n codis");
        $pdo->commit();
        $fitxer = dirname($cfg['bloqueig']) . "/etiquetes-lot-{$lot['lot']}.html";
        file_put_contents($fitxer, etiquetes_html($lot['codis'], $cfg['url_base'], $lot['lot']));
        chmod($fitxer, 0600);
        echo "Lot {$lot['lot']}: $n codis.\nFull d'etiquetes: $fitxer\n";
        echo "Obre'l amb el navegador i imprimeix-lo a mida real. Conté codis vàlids: ESBORRA'L quan l'hagis imprès.\n";
        break;

    case 'ronda':
        $v = $args[1] ?? '';
        if ($v !== '1' && $v !== '2') surt('Ús: php eines/gestio.php ronda 1|2');
        app_escriu($pdo, $cfg, $ara, function () use ($pdo, $v) { config_desa($pdo, 'ronda', $v); });
        echo $v === '2' ? "Segona ronda ACTIVADA: cada codi permet una segona participació.\n" : "Una participació per codi.\n";
        break;

    case 'calendari':
        $clau = $args[1] ?? '';
        if (!isset(DEFECTES_CALENDARI[$clau]) || !isset($args[2])) surt('Ús: php eines/gestio.php calendari ' . implode('|', array_keys(DEFECTES_CALENDARI)) . ' "AAAA-MM-DD hh:mm"');
        $t = DateTimeImmutable::createFromFormat('Y-m-d H:i', $args[2], new DateTimeZone('Europe/Madrid')) ?: surt('Format: AAAA-MM-DD hh:mm');
        app_escriu($pdo, $cfg, $ara, function () use ($pdo, $clau, $t) { config_desa($pdo, $clau, bd_hora($t)); });
        echo "$clau = {$args[2]} (hora de Barcelona).\n";
        break;

    case 'allibera':
        $lloc = $args[1] ?? surt('Ús: php eines/gestio.php allibera LLOC  (p. ex. C014)');
        $fet = app_escriu($pdo, $cfg, $ara, function () use ($pdo, $lloc, $ara) { return reserva_allibera($pdo, $lloc, 'terminal', $ara); });
        echo $fet ? "Lloc $lloc alliberat. snapshot.json regenerat.\n" : "El lloc $lloc no estava ocupat.\n";
        break;

    case 'tanca':
        $local = $args[1] ?? surt('Ús: php eines/gestio.php tanca "2026-12-21 23:59"  (hora de Barcelona)');
        $t = DateTimeImmutable::createFromFormat('Y-m-d H:i', $local, new DateTimeZone('Europe/Madrid')) ?: surt('Format: AAAA-MM-DD hh:mm');
        app_escriu($pdo, $cfg, $ara, function () use ($pdo, $t) { config_desa($pdo, 'classes_tanca', bd_hora($t)); });
        echo "Les classes es tanquen el $local (hora de Barcelona).\n";
        break;

    case 'aturada':
        $v = ($args[1] ?? '') === 'si' ? '1' : (($args[1] ?? '') === 'no' ? '0' : surt('Ús: php eines/gestio.php aturada si|no'));
        app_escriu($pdo, $cfg, $ara, function () use ($pdo, $v) { config_desa($pdo, 'aturada', $v); });
        echo $v === '1' ? "Reserves ATURADES.\n" : "Reserves en marxa.\n";
        break;

    case 'estat':
        $tanca = new DateTimeImmutable(config_llegeix($pdo, 'classes_tanca', '2026-12-21 22:59:00'), new DateTimeZone('UTC'));
        echo "Classes es tanquen: " . $tanca->setTimezone(new DateTimeZone('Europe/Madrid'))->format('Y-m-d H:i') . " · aturada: " . (config_llegeix($pdo, 'aturada') === '1' ? 'SÍ' : 'no') . "\n";
        foreach (ETAPES as $e => $nom) {
            $st = $pdo->prepare("SELECT COUNT(*) AS n, SUM(CASE WHEN o.estat = 'confirmat' THEN 1 ELSE 0 END) AS fetes FROM classes c LEFT JOIN ocupacions o ON o.classe_id = c.id WHERE c.etapa = ?");
            $st->execute([$e]);
            $r = $st->fetch();
            $enl = $pdo->prepare("SELECT COUNT(*) FROM enllacos WHERE tipus = 'etapa' AND etapa = ? AND activa = 1");
            $enl->execute([$e]);
            printf("  %-26s %2d classes, %2d amb casa · enllaç %s\n", $nom, $r['n'], (int)$r['fetes'], (int)$enl->fetchColumn() ? 'actiu' : 'NO');
        }
        $ret = $pdo->prepare("SELECT COUNT(*) FROM ocupacions WHERE estat = 'retingut' AND caduca > ?");
        $ret->execute([bd_hora($ara)]);
        echo "Retencions actives ara: " . $ret->fetchColumn() . "\n";
        $mad = function ($clau) use ($pdo) { return (new DateTimeImmutable(calendari($pdo, $clau), new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Madrid'))->format('Y-m-d H:i'); };
        echo "Famílies: " . $mad('families_des') . " → " . $mad('families_fins') . " · ronda " . config_llegeix($pdo, 'ronda', '1') . "\n";
        echo "Claustre: " . $mad('claustre_des') . " → " . $mad('claustre_tanca') . " · " . claustre_estat($pdo, $ara)['fetes'] . " de " . config_llegeix($pdo, 'claustre_limit', '50') . "\n";
        $cod = $pdo->query('SELECT COUNT(*) AS n, SUM(CASE WHEN usos > 0 THEN 1 ELSE 0 END) AS usats FROM codis')->fetch();
        echo "Codis: {$cod['n']} creats, " . (int)$cod['usats'] . " usats · zones obertes: " . implode(', ', zones_obertes($pdo)) . "\n";
        break;

    default:
        surt("Ordres: installa, classes, enllac, codis, ronda, calendari, allibera, tanca, aturada, estat. Mira la capçalera d'eines/gestio.php.");
}
