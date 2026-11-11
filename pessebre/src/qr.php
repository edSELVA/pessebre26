<?php
// Generador de codis QR (ISO/IEC 18004) sense dependències: mode byte, correcció d'errors M, versions 1–9 (fins a 180 bytes).
// N'hi ha prou per a una adreça curta amb el codi de família (uns 45 caràcters → versió 4).
// Basat en l'algorisme de referència de Project Nayuki (domini públic de l'estàndard).
declare(strict_types=1);

const QR_ECC_PER_BLOC = [1 => 10, 16, 26, 18, 24, 16, 18, 22, 22, 26];   // nivell M
const QR_BLOCS = [1 => 1, 1, 1, 2, 2, 4, 4, 4, 5, 5];

function qr_moduls_bruts(int $v): int
{
    $r = (16 * $v + 128) * $v + 64;
    if ($v >= 2) {
        $n = intdiv($v, 7) + 2;
        $r -= (25 * $n - 10) * $n - 55;
        if ($v >= 7) $r -= 36;
    }
    return $r;
}

function qr_paraules_dades(int $v): int
{
    return intdiv(qr_moduls_bruts($v), 8) - QR_ECC_PER_BLOC[$v] * QR_BLOCS[$v];
}

function qr_mul(int $x, int $y): int
{
    $z = 0;
    for ($i = 7; $i >= 0; $i--) {
        $z = ($z << 1) ^ (($z >> 7) * 0x11D);
        $z ^= (($y >> $i) & 1) * $x;
    }
    return $z & 0xFF;
}

function qr_divisor(int $grau): array
{
    $r = array_fill(0, $grau, 0);
    $r[$grau - 1] = 1;
    $arrel = 1;
    for ($i = 0; $i < $grau; $i++) {
        for ($j = 0; $j < $grau; $j++) {
            $r[$j] = qr_mul($r[$j], $arrel);
            if ($j + 1 < $grau) $r[$j] ^= $r[$j + 1];
        }
        $arrel = qr_mul($arrel, 0x02);
    }
    return $r;
}

function qr_residu(array $dades, array $div): array
{
    $r = array_fill(0, count($div), 0);
    foreach ($dades as $b) {
        $f = $b ^ array_shift($r);
        $r[] = 0;
        foreach ($div as $i => $c) $r[$i] ^= qr_mul($c, $f);
    }
    return $r;
}

function qr_alineacions(int $v): array
{
    if ($v === 1) return [];
    $mida = $v * 4 + 17;
    $n = intdiv($v, 7) + 2;
    $pas = (int)(ceil(($v * 4 + 4) / ($n * 2 - 2)) * 2);
    $r = [6];
    for ($pos = $mida - 7; count($r) < $n; $pos -= $pas) array_splice($r, 1, 0, [$pos]);
    return $r;
}

/** Matriu de mòduls (true = fosc) del QR de $text. */
function qr_matriu(string $text): array
{
    $bytes = array_values(unpack('C*', $text));
    for ($v = 1; $v <= 9; $v++) if (4 + 8 + 8 * count($bytes) <= qr_paraules_dades($v) * 8) break;
    if ($v > 9) throw new InvalidArgumentException('Text massa llarg per al QR');
    $cap = qr_paraules_dades($v) * 8;

    // Bits: mode byte (0100), longitud (8 bits), dades, terminador, farciment
    $bits = '0100' . str_pad(decbin(count($bytes)), 8, '0', STR_PAD_LEFT);
    foreach ($bytes as $b) $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
    $bits .= str_repeat('0', min(4, $cap - strlen($bits)));
    $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
    for ($p = 0xEC; strlen($bits) < $cap; $p ^= 0xEC ^ 0x11) $bits .= str_pad(decbin($p), 8, '0', STR_PAD_LEFT);
    $dades = array_map('bindec', str_split($bits, 8));

    // Correcció d'errors i entrellaçat dels blocs
    $nb = QR_BLOCS[$v];
    $ecc = QR_ECC_PER_BLOC[$v];
    $brutes = intdiv(qr_moduls_bruts($v), 8);
    $curts = $nb - $brutes % $nb;
    $llarg_curt = intdiv($brutes, $nb);
    $div = qr_divisor($ecc);
    $blocs = [];
    for ($i = 0, $k = 0; $i < $nb; $i++) {
        $n = $llarg_curt - $ecc + ($i < $curts ? 0 : 1);
        $d = array_slice($dades, $k, $n);
        $k += $n;
        $e = qr_residu($d, $div);
        if ($i < $curts) $d[] = 0;
        $blocs[] = array_merge($d, $e);
    }
    $final = [];
    for ($i = 0; $i < count($blocs[0]); $i++) {
        foreach ($blocs as $j => $b) if ($i !== $llarg_curt - $ecc || $j >= $curts) $final[] = $b[$i];
    }

    // Patrons fixos
    $mida = $v * 4 + 17;
    $m = array_fill(0, $mida, array_fill(0, $mida, false));
    $fix = array_fill(0, $mida, array_fill(0, $mida, false));
    $posa = function (int $x, int $y, bool $fosc) use (&$m, &$fix) { $m[$y][$x] = $fosc; $fix[$y][$x] = true; };
    for ($i = 0; $i < $mida; $i++) { $posa(6, $i, $i % 2 === 0); $posa($i, 6, $i % 2 === 0); }
    foreach ([[3, 3], [$mida - 4, 3], [3, $mida - 4]] as [$cx, $cy]) {
        for ($dy = -4; $dy <= 4; $dy++) for ($dx = -4; $dx <= 4; $dx++) {
            $x = $cx + $dx; $y = $cy + $dy;
            if ($x >= 0 && $x < $mida && $y >= 0 && $y < $mida) { $d = max(abs($dx), abs($dy)); $posa($x, $y, $d !== 2 && $d !== 4); }
        }
    }
    $al = qr_alineacions($v);
    $na = count($al);
    for ($i = 0; $i < $na; $i++) for ($j = 0; $j < $na; $j++) {
        if (($i === 0 && $j === 0) || ($i === 0 && $j === $na - 1) || ($i === $na - 1 && $j === 0)) continue;
        for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) $posa($al[$i] + $dx, $al[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
    }
    $format = function (int $mascara) use ($posa, $mida) {
        $d = $mascara;                                   // nivell M = 00
        $r = $d;
        for ($i = 0; $i < 10; $i++) $r = ($r << 1) ^ (($r >> 9) * 0x537);
        $b = (($d << 10) | $r) ^ 0x5412;
        $bit = function ($i) use ($b) { return (($b >> $i) & 1) === 1; };
        for ($i = 0; $i <= 5; $i++) $posa(8, $i, $bit($i));
        $posa(8, 7, $bit(6)); $posa(8, 8, $bit(7)); $posa(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) $posa(14 - $i, 8, $bit($i));
        for ($i = 0; $i < 8; $i++) $posa($mida - 1 - $i, 8, $bit($i));
        for ($i = 8; $i < 15; $i++) $posa(8, $mida - 15 + $i, $bit($i));
        $posa(8, $mida - 8, true);
    };
    $format(0);
    if ($v >= 7) {
        $r = $v;
        for ($i = 0; $i < 12; $i++) $r = ($r << 1) ^ (($r >> 11) * 0x1F25);
        $b = ($v << 12) | $r;
        for ($i = 0; $i < 18; $i++) {
            $fosc = (($b >> $i) & 1) === 1;
            $a = $mida - 11 + $i % 3; $c = intdiv($i, 3);
            $posa($a, $c, $fosc); $posa($c, $a, $fosc);
        }
    }

    // Dades en ziga-zaga
    $i = 0;
    $total = count($final) * 8;
    for ($dreta = $mida - 1; $dreta >= 1; $dreta -= 2) {
        if ($dreta === 6) $dreta = 5;
        for ($vert = 0; $vert < $mida; $vert++) for ($j = 0; $j < 2; $j++) {
            $x = $dreta - $j;
            $amunt = (($dreta + 1) & 2) === 0;
            $y = $amunt ? $mida - 1 - $vert : $vert;
            if (!$fix[$y][$x] && $i < $total) { $m[$y][$x] = (($final[$i >> 3] >> (7 - ($i & 7))) & 1) === 1; $i++; }
        }
    }

    // Tria la màscara amb menys penalització
    $millor = null;
    for ($k = 0; $k < 8; $k++) {
        $c = qr_aplica_mascara($m, $fix, $k);
        $format_k = $c;
        $posa2 = function (int $x, int $y, bool $f) use (&$format_k) { $format_k[$y][$x] = $f; };
        $d = $k; $r = $d;
        for ($t = 0; $t < 10; $t++) $r = ($r << 1) ^ (($r >> 9) * 0x537);
        $b = (($d << 10) | $r) ^ 0x5412;
        $bit = function ($t) use ($b) { return (($b >> $t) & 1) === 1; };
        for ($t = 0; $t <= 5; $t++) $posa2(8, $t, $bit($t));
        $posa2(8, 7, $bit(6)); $posa2(8, 8, $bit(7)); $posa2(7, 8, $bit(8));
        for ($t = 9; $t < 15; $t++) $posa2(14 - $t, 8, $bit($t));
        for ($t = 0; $t < 8; $t++) $posa2($mida - 1 - $t, 8, $bit($t));
        for ($t = 8; $t < 15; $t++) $posa2(8, $mida - 15 + $t, $bit($t));
        $posa2(8, $mida - 8, true);
        $p = qr_penalitzacio($format_k);
        if ($millor === null || $p < $millor[0]) $millor = [$p, $format_k];
    }
    return $millor[1];
}

function qr_aplica_mascara(array $m, array $fix, int $k): array
{
    $n = count($m);
    for ($y = 0; $y < $n; $y++) for ($x = 0; $x < $n; $x++) {
        if ($fix[$y][$x]) continue;
        switch ($k) {
            case 0: $inv = ($x + $y) % 2 === 0; break;
            case 1: $inv = $y % 2 === 0; break;
            case 2: $inv = $x % 3 === 0; break;
            case 3: $inv = ($x + $y) % 3 === 0; break;
            case 4: $inv = (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0; break;
            case 5: $inv = $x * $y % 2 + $x * $y % 3 === 0; break;
            case 6: $inv = ($x * $y % 2 + $x * $y % 3) % 2 === 0; break;
            default: $inv = (($x + $y) % 2 + $x * $y % 3) % 2 === 0;
        }
        if ($inv) $m[$y][$x] = !$m[$y][$x];
    }
    return $m;
}

/** Penalització de l'estàndard (regles 1–4, simplificades a la 3). */
function qr_penalitzacio(array $m): int
{
    $n = count($m);
    $p = 0;
    $files = [];
    for ($y = 0; $y < $n; $y++) { $files[] = $m[$y]; $files[] = array_column($m, $y); }
    foreach ($files as $f) {
        $tira = 1;
        for ($i = 1; $i <= $n; $i++) {
            if ($i < $n && $f[$i] === $f[$i - 1]) { $tira++; continue; }
            if ($tira >= 5) $p += 3 + $tira - 5;
            $tira = 1;
        }
        $s = implode('', array_map('intval', $f));
        $p += 40 * (substr_count($s, '10111010000') + substr_count($s, '00001011101'));
    }
    for ($y = 0; $y < $n - 1; $y++) for ($x = 0; $x < $n - 1; $x++) {
        $c = $m[$y][$x];
        if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) $p += 3;
    }
    $foscos = 0;
    foreach ($m as $f) foreach ($f as $c) if ($c) $foscos++;
    $p += intdiv(abs($foscos * 20 - $n * $n * 10), $n * $n) * 10;
    return $p;
}

/** SVG del QR (amb el marge blanc de 4 mòduls que demana l'estàndard). */
function qr_svg(string $text, string $classe = 'qr'): string
{
    $m = qr_matriu($text);
    $n = count($m);
    $d = '';
    foreach ($m as $y => $f) {
        for ($x = 0; $x < $n; $x++) {
            if (!$f[$x]) continue;
            $x0 = $x;
            while ($x < $n && $f[$x]) $x++;
            $d .= 'M' . ($x0 + 4) . ' ' . ($y + 4) . 'h' . ($x - $x0) . 'v1h-' . ($x - $x0) . 'z';
        }
    }
    $t = $n + 8;
    return '<svg xmlns="http://www.w3.org/2000/svg" class="' . $classe . '" viewBox="0 0 ' . $t . ' ' . $t . '" shape-rendering="crispEdges">'
        . '<rect width="' . $t . '" height="' . $t . '" fill="#fff"/><path d="' . $d . '" fill="#000"/></svg>';
}
