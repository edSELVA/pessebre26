<?php
// Logo de l'escola al cel del pessebre, en píxels d'un sol color (estil arcade).
// Llegeix el PNG de disseny-propi/logo/ i escriu public_html/disseny/logo.svg. Tots dos són a .gitignore:
// el logo NO es puja mai al repositori públic (CLAUDE.md §9); es copia a l'hosting a mà.
//
//   php eines/logo.php [--cel=130] [--bloc=2] [--x=560] [--y=40] [--png=CAMÍ] [--sortida=CAMÍ]
//
//   --cel     quantes cel·les (píxels del logo) d'ample
//   --bloc    quantes unitats de l'escena fa cada cel·la (2 o 3 = píxels grossos, d'arcade)
//   --x --y   cantonada superior esquerra a l'escena (2400 × 800); les muntanyes el tapen per baix
//
// Cada cel·la és d'una de tres parts (o buida), segons el color dominant del PNG: el text (negre), el lòbul clar
// del cor i el traç fosc del cor. El visor les pinta amb un sol color que canvia amb el moment del dia i les
// distingeix amb dos nivells de transparència (estil.css).
declare(strict_types=1);

$arrel = dirname(__DIR__);
$opc = ['cel' => '130', 'bloc' => '2', 'x' => '560', 'y' => '40', 'png' => '', 'sortida' => "$arrel/public_html/disseny/logo.svg"];
foreach (array_slice($argv, 1) as $a) {
    if (!preg_match('/^--(\w+)=(.*)$/', $a, $m) || !isset($opc[$m[1]])) { fwrite(STDERR, "Opció desconeguda: $a\n"); exit(1); }
    $opc[$m[1]] = $m[2];
}
if ($opc['png'] === '') $opc['png'] = (glob("$arrel/disseny-propi/logo/*.png") ?: [''])[0];
if (!is_file($opc['png'])) { fwrite(STDERR, "No trobo el PNG del logo a disseny-propi/logo/\n"); exit(1); }
if (!function_exists('imagecreatefrompng')) { fwrite(STDERR, "Cal l'extensió GD de PHP.\n"); exit(1); }

$im = imagecreatefrompng($opc['png']);
$w = imagesx($im);
$h = imagesy($im);
$cel = max(20, (int)$opc['cel']);
$mida = $w / $cel;                                  // píxels del PNG per cel·la
$files = (int)ceil($h / $mida);

// Colors de referència del logo: text negre, lòbul clar (carmí) i traç fosc (granat)
const PARTS = ['text' => [35, 31, 30], 'cor-clar' => [190, 0, 71], 'cor-fosc' => [125, 20, 50]];

function part_de(int $c): ?string
{
    $alfa = ($c >> 24) & 0x7F;                      // 0 opac … 127 transparent
    if ($alfa > 63) return null;
    $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
    if ($r > 220 && $g > 220 && $b > 220) return null;    // fons blanc
    $millor = null; $dist = PHP_INT_MAX;
    foreach (PARTS as $nom => [$pr, $pg, $pb]) {
        $d = ($r - $pr) ** 2 + ($g - $pg) ** 2 + ($b - $pb) ** 2;
        if ($d < $dist) { $dist = $d; $millor = $nom; }
    }
    return $millor;
}

// Per a cada cel·la: plena si almenys la meitat dels píxels són del logo; la part, la que hi té més píxels
$graella = [];
for ($fy = 0; $fy < $files; $fy++) {
    for ($fx = 0; $fx < $cel; $fx++) {
        $x0 = (int)floor($fx * $mida); $x1 = min($w, (int)floor(($fx + 1) * $mida));
        $y0 = (int)floor($fy * $mida); $y1 = min($h, (int)floor(($fy + 1) * $mida));
        $compte = []; $total = 0;
        for ($y = $y0; $y < $y1; $y++) for ($x = $x0; $x < $x1; $x++) {
            $total++;
            $p = part_de(imagecolorat($im, $x, $y));
            if ($p !== null) $compte[$p] = ($compte[$p] ?? 0) + 1;
        }
        if ($total && array_sum($compte) * 2 >= $total) {
            arsort($compte);
            $graella[$fy][$fx] = array_key_first($compte);
        }
    }
}

// Camins: tires horitzontals de cel·les seguides de la mateixa part
$camins = array_fill_keys(array_keys(PARTS), '');
foreach ($graella as $fy => $fila) {
    ksort($fila);
    $inici = null; $part = null; $abans = null;
    $tanca = function () use (&$camins, &$inici, &$part, &$abans, $fy) {
        if ($part !== null) $camins[$part] .= "M{$inici} {$fy}h" . ($abans - $inici + 1) . 'v1h-' . ($abans - $inici + 1) . 'z';
    };
    foreach ($fila as $fx => $p) {
        if ($p !== $part || $fx !== $abans + 1) { $tanca(); $inici = $fx; $part = $p; }
        $abans = $fx;
    }
    $tanca();
}

$bloc = max(1, (int)$opc['bloc']);
$x = (int)$opc['x'];
$y = (int)$opc['y'];
$paths = '';
foreach ($camins as $part => $d) if ($d !== '') $paths .= "<path class=\"logo-$part\" d=\"$d\"/>";
$svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 2400 800\">\n"
     . "<!-- Generat per eines/logo.php a partir de " . basename($opc['png']) . ". No es puja mai al repositori públic. -->\n"
     . "<g class=\"logo\" transform=\"translate($x $y) scale($bloc)\">$paths</g>\n</svg>\n";
file_put_contents($opc['sortida'], $svg);
printf("Logo: %d × %d cel·les, %d × %d unitats de l'escena, a (%d, %d) → %s\n", $cel, $files, $cel * $bloc, $files * $bloc, $x, $y, $opc['sortida']);
