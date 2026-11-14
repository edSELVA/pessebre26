<?php
// Full d'etiquetes adhesives amb el QR i el codi de cada família, per imprimir des del navegador (A4).
// La mida del full es canvia només aquí (ETIQUETES). Per defecte: A4 de 24 etiquetes de 70×37 mm (3 × 8).
declare(strict_types=1);
require_once __DIR__ . '/qr.php';

const ETIQUETES = ['columnes' => 3, 'files' => 8, 'ample' => 70, 'alt' => 37, 'marge_sup' => 0.5, 'marge_esq' => 0];

/** $classes: noms de classe (en el mateix ordre que els codis) per als codis de classe; null per als de família. */
function etiquetes_html(array $codis, string $url_base, int $lot, int $usos = 3, ?array $classes = null): string
{
    $e = ETIQUETES;
    $per_full = $e['columnes'] * $e['files'];
    $host = parse_url($url_base, PHP_URL_HOST) ?: $url_base;
    $h = function ($t) { return htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); };
    $fulls = array_chunk($codis, $per_full);
    $titol = $classes === null ? 'Etiquetes · lot ' . $lot : 'Codis de classe';
    $o = '<!doctype html><html lang="ca"><head><meta charset="utf-8"><title>' . $titol . '</title><style>'
        . '@page { size: A4; margin: 0; } * { box-sizing: border-box; } body { margin: 0; font-family: system-ui, sans-serif; color: #000; }'
        . '.full { width: 210mm; height: 297mm; padding: ' . $e['marge_sup'] . 'mm 0 0 ' . $e['marge_esq'] . 'mm; display: grid;'
        . ' grid-template-columns: repeat(' . $e['columnes'] . ', ' . $e['ample'] . 'mm); grid-template-rows: repeat(' . $e['files'] . ', ' . $e['alt'] . 'mm);'
        . ' page-break-after: always; break-after: page; }'
        . '.et { display: flex; align-items: center; gap: 2.5mm; padding: 2.5mm 3mm; overflow: hidden; }'
        . '.qr { width: ' . ($e['alt'] - 6) . 'mm; height: ' . ($e['alt'] - 6) . 'mm; flex: 0 0 auto; }'
        . '.tx { min-width: 0; } .t { font-size: 8.5pt; font-weight: 700; margin: 0 0 1mm; }'
        . '.c { font: 700 12.5pt/1 ui-monospace, Menlo, Consolas, monospace; letter-spacing: .3pt; margin: 0 0 1.2mm; white-space: nowrap; }'
        . '.p { font-size: 6.5pt; line-height: 1.25; margin: 0; }'
        . '.avis { padding: 8mm; font-size: 11pt; } @media print { .avis { display: none; } }'
        . '</style></head><body>'
        . '<div class="avis"><strong>' . ($classes === null ? 'Lot ' . $lot . ': ' : '') . count($codis) . ' codis.</strong> Imprimeix a mida real (100 %), sense marges. '
        . 'Aquest fitxer conté codis vàlids: esborra\'l quan els hagis imprès.</div>';
    foreach ($fulls as $full) {
        $o .= '<div class="full">';
        foreach ($full as $c) {
            $classe = $classes === null ? null : array_shift($classes);
            $o .= '<div class="et">' . qr_svg($url_base . '#c=' . codi_normalitza($c)) . '<div class="tx">'
                . '<p class="t">' . ($classe === null ? 'Pessebre de l\'escola' : 'Pessebre · ' . $h($classe)) . '</p>'
                . '<p class="c">' . $h(codi_impres(codi_normalitza($c))) . '</p>'
                . '<p class="p">Escaneja el QR o entra a ' . $h($host) . ', toca «Participar» i escriu el codi. '
                . ($classe === null ? 'Pots posar-hi fins a ' . $usos . ($usos === 1 ? ' figura' : ' figures') . '.' : 'Serveix per triar i pintar la casa de la classe.') . '</p>'
                . '</div></div>';
        }
        $o .= '</div>';
    }
    return $o . '</body></html>';
}
