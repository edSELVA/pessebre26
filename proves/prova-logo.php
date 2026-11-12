<?php
// Logo al cel (eines/logo.php): amb un PNG inventat (el logo real no és al repositori) comprova que es passa a
// píxels per parts (text, lòbul clar i traç fosc del cor) i que el logo real mai no es pot pujar.
if (!function_exists('imagecreatetruecolor')) { echo "    (sense GD: no es prova)\n"; return; }

$tmp = sys_get_temp_dir() . '/pessebre-logo-' . getmypid();
@mkdir($tmp, 0700, true);
// PNG de 200 × 100: fons transparent, un bloc negre (text), un de carmí (lòbul) i un de granat (traç)
$im = imagecreatetruecolor(200, 100);
imagesavealpha($im, true);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagefilledrectangle($im, 0, 60, 199, 99, imagecolorallocate($im, 35, 31, 30));
imagefilledrectangle($im, 40, 0, 79, 39, imagecolorallocate($im, 190, 0, 71));
imagefilledrectangle($im, 120, 0, 159, 39, imagecolorallocate($im, 125, 20, 50));
imagepng($im, "$tmp/prova.png");

$php = PHP_BINARY;
$sortida = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg("$ARREL/eines/logo.php") . ' --cel=20 --bloc=3 --fi=1 --x=100 --y=50 --png='
    . escapeshellarg("$tmp/prova.png") . ' --sortida=' . escapeshellarg("$tmp/logo.svg") . ' 2>&1');
comprova(is_file("$tmp/logo.svg"), 'l\'eina escriu el logo en SVG (' . trim((string)$sortida) . ')');
$svg = (string)@file_get_contents("$tmp/logo.svg");
$doc = new DOMDocument();
comprova(@$doc->loadXML($svg) === true, 'és un SVG vàlid');
$g = $doc->getElementsByTagName('g')->item(0);
igual('logo', $g ? $g->getAttribute('class') : null, 'un grup amb la classe «logo» (el visor el busca així)');
igual('translate(100 50) scale(3)', $g ? $g->getAttribute('transform') : null, 'posició i mida de píxel a l\'escena');
$parts = [];
foreach ($doc->getElementsByTagName('path') as $p) $parts[] = $p->getAttribute('class');
sort($parts);
igual(['logo-cor-clar', 'logo-cor-fosc', 'logo-text'], $parts, 'tres parts: text, lòbul clar i traç fosc del cor');
// 20 cel·les d'ample = 10 px per cel·la: el text ocupa les files 6–9, el lòbul les columnes 4–7 de les files 0–3
comprova(strpos($svg, 'class="logo-text" d="M0 6h20v1h-20z') !== false, 'el text és una tira sencera de 20 cel·les a la fila 6');
comprova(strpos($svg, 'class="logo-cor-clar" d="M4 0h4v1h-4z') !== false, 'el lòbul clar va de la columna 4 a la 7');
comprova(strpos($svg, 'class="logo-cor-fosc" d="M12 0h4v1h-4z') !== false, 'el traç fosc va de la columna 12 a la 15');
// Gra doble: el cor amb cel·les grosses (2 × 2 unitats fines) i el text amb cel·les fines
shell_exec(escapeshellarg($php) . ' ' . escapeshellarg("$ARREL/eines/logo.php") . ' --cel=20 --bloc=4 --fi=2 --x=0 --y=0 --png='
    . escapeshellarg("$tmp/prova.png") . ' --sortida=' . escapeshellarg("$tmp/logo2.svg") . ' 2>&1');
$svg2 = (string)@file_get_contents("$tmp/logo2.svg");
comprova(strpos($svg2, 'scale(2)') !== false, 'amb --bloc=4 --fi=2, cada unitat fina fa 2 unitats de l\'escena');
comprova(strpos($svg2, 'class="logo-cor-clar" d="M8 0h8v1h-8zM8 1h8v1h-8z') !== false, 'el cor conserva el gra gros (cel·les de 2 × 2)');
comprova(strpos($svg2, 'class="logo-text" d="M0 12h40v1h-40z') !== false, 'el text va amb el gra fi (40 cel·les d\'ample)');
comprova(strpos($svg, '<script') === false && strpos($svg, 'href') === false, 'l\'SVG només porta camins (res executable ni enllaços)');

// El logo real i el que en surt no es poden pujar mai (repositori públic)
$ignora = (string)file_get_contents("$ARREL/.gitignore");
comprova(strpos($ignora, "disseny-propi/logo/") !== false && strpos($ignora, "public_html/disseny/logo.svg") !== false, 'el logo és a .gitignore');

array_map('unlink', glob("$tmp/*"));
rmdir($tmp);
