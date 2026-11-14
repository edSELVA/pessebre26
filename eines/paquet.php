<?php
// Prepara el paquet per pujar a l'hosting:  php eines/paquet.php
//
// Fa la carpeta paquet/pessebre-AAAAMMDD-HHMM/ (i un .zip igual) amb NOMÉS el que cal:
//   public_html/   el web (sense snapshot.json, exemples ni fitxers de proves), amb el logo si és a disseny/logo.svg
//   pessebre/      el codi del servidor, config.exemple.php, .htaccess i la clau d'instal·lació (un sol ús)
//   eines/gestio.php  per si l'hosting té terminal
//   INSTAL·LACIO.md   la guia
// No hi va mai: config.php, la base de dades, dades/, la demo, les proves, el disseny d'origen ni classes.csv.
// paquet/ és a .gitignore.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }      // eina de terminal: mai des del web

$arrel = dirname(__DIR__);
$nom = 'pessebre-' . date('Ymd-Hi');
$desti = "$arrel/paquet/$nom";
if (is_dir($desti)) { fwrite(STDERR, "Ja existeix $desti\n"); exit(1); }

function copia(string $font, string $desti, callable $vol): int
{
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($font, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($font) + 1);
        if (!$vol($rel, $f->isDir())) continue;
        if ($f->isDir()) { @mkdir("$desti/$rel", 0755, true); continue; }
        @mkdir(dirname("$desti/$rel"), 0755, true);
        copy($f->getPathname(), "$desti/$rel");
        $n++;
    }
    return $n;
}

// Web: tot menys el que es genera a l'hosting o només serveix per provar
$fora_web = function (string $rel, bool $dir): bool {
    $part = explode('/', $rel)[0];
    if (in_array($part, ['snapshot.json', 'exemples'], true)) return false;
    if (preg_match('#(^|/)(\.DS_Store|[^/]*\.tmp|prova-[^/]*)$#', $rel)) return false;
    return true;
};
$n = copia("$arrel/public_html", "$desti/public_html", $fora_web);

// Servidor: el codi i la configuració d'exemple (mai config.php ni dades/)
$servidor = function (string $rel, bool $dir): bool {
    if ($rel === 'src' || strpos($rel, 'src/') === 0) return !preg_match('#\.DS_Store$#', $rel);
    return in_array($rel, ['config.exemple.php', '.htaccess'], true);
};
$n += copia("$arrel/pessebre", "$desti/pessebre", $servidor);
mkdir("$desti/eines", 0755, true);
copy("$arrel/eines/gestio.php", "$desti/eines/gestio.php");
copy("$arrel/INSTAL·LACIO.md", "$desti/INSTAL·LACIO.md");

// Clau d'instal·lació (per a installa.php): aleatòria, només en aquest paquet
$clau = bin2hex(random_bytes(16));
file_put_contents("$desti/pessebre/clau-installacio.txt", $clau . "\n");

// Comprovacions: res de secrets ni dades
$prohibits = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($desti, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($desti) + 1);
    if (preg_match('#(^|/)(config\.php|dades/|.*\.sqlite3?|.*\.db|.*\.csv|demo/|proves/)#', $rel)) $prohibits[] = $rel;
}
if ($prohibits) { fwrite(STDERR, "ATENCIÓ: el paquet conté fitxers que no hi haurien de ser:\n  " . implode("\n  ", $prohibits) . "\n"); exit(1); }

// .zip
$zip = new ZipArchive();
$zip->open("$desti.zip", ZipArchive::CREATE);
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($desti, FilesystemIterator::SKIP_DOTS)) as $f) {
    $zip->addFile($f->getPathname(), "$nom/" . substr($f->getPathname(), strlen($desti) + 1));
}
$zip->close();

$logo = is_file("$desti/public_html/disseny/logo.svg");
echo "Paquet a punt: paquet/$nom (i $nom.zip), " . ($n + 2) . " fitxers.\n";
echo $logo ? "  ✓ Inclou el logo de l'escola.\n" : "  ! Sense logo (fes «php eines/logo.php» si el vols al cel).\n";
echo "  Clau d'instal·lació per a installa.php (guarda-la; només serveix per a aquest paquet):\n\n      $clau\n\n";
echo "  La guia és a INSTAL·LACIO.md. Les classes (classes.csv) s'enganxen després a l'administració.\n";
