<?php
// Converteix el paquet de disseny v2 als fitxers del visor.
// Ús:  php eines/converteix.php [paquet.zip] [directori de sortida]
// Per defecte: disseny-origen/pessebre-disseny-v2.zip → public_html/disseny/
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }      // eina de terminal: mai des del web
require __DIR__ . '/conversio.php';

$arrel = dirname(__DIR__);
$zip = $argv[1] ?? "$arrel/disseny-origen/pessebre-disseny-v2.zip";
$desti = $argv[2] ?? "$arrel/public_html/disseny";

try {
    [$fitxers, $avisos] = converteix($zip, $arrel);
} catch (ErrorPaquet $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\nNo s'ha escrit res.\n");
    exit(1);
}
if (!is_dir($desti) && !mkdir($desti, 0755, true)) { fwrite(STDERR, "No es pot crear $desti\n"); exit(1); }
foreach ($fitxers as $nom => $contingut) {
    file_put_contents("$desti/$nom.tmp", $contingut);
    rename("$desti/$nom.tmp", "$desti/$nom");      // escriptura atòmica
    printf("  %-18s %7.1f KB\n", $nom, strlen($contingut) / 1024);
}
foreach ($avisos as $a) echo "Avís: $a\n";
echo "Fet: " . count($fitxers) . " fitxers a $desti\n";
