<?php
declare(strict_types=1);

/**
 * Small self-hosted QR Code Model 2 SVG generator.
 * Fixed Version 6-L is chosen for public certificate URLs (up to 134 UTF-8 bytes).
 * No network calls, Composer, GD, or image extension required.
 *
 * QR Code technology is standardized by ISO/IEC 18004. This implementation creates
 * byte-mode QR symbols, Version 6, error correction level L, mask pattern 0.
 */
function workshop_qr_gf_mul(int $x, int $y): int {
    $z = 0;
    for ($i = 7; $i >= 0; $i--) {
        $z = ($z << 1) ^ ((($z >> 7) & 1) * 0x11D);
        $z ^= (($y >> $i) & 1) * $x;
    }
    return $z & 0xFF;
}

function workshop_qr_gf_pow(int $x, int $power): int {
    $v = 1;
    for ($i = 0; $i < $power; $i++) $v = workshop_qr_gf_mul($v, $x);
    return $v;
}

function workshop_qr_remainder(array $data, int $degree): array {
    $generator = [1];
    for ($i = 0; $i < $degree; $i++) {
        $root = workshop_qr_gf_pow(2, $i);
        $next = array_fill(0, count($generator) + 1, 0);
        foreach ($generator as $j => $coef) {
            $next[$j] ^= $coef;
            $next[$j + 1] ^= workshop_qr_gf_mul($coef, $root);
        }
        $generator = $next;
    }
    $remainder = array_fill(0, $degree, 0);
    foreach ($data as $byte) {
        $factor = ($byte ^ $remainder[0]) & 0xFF;
        array_shift($remainder);
        $remainder[] = 0;
        for ($j = 0; $j < $degree; $j++) $remainder[$j] ^= workshop_qr_gf_mul($generator[$j + 1], $factor);
    }
    return $remainder;
}

function workshop_qr_svg(string $text, int $pixels = 240): string {
    $bytes = array_values(unpack('C*', $text) ?: []);
    // Version 6-L supports up to 134 bytes in byte mode.
    if (count($bytes) > 134) throw new RuntimeException('The certificate URL is too long for the built-in QR generator. Set a shorter public certificate URL in Settings.');

    $dataBits = [];
    $appendBits = static function (int $value, int $length) use (&$dataBits): void {
        for ($i = $length - 1; $i >= 0; $i--) $dataBits[] = ($value >> $i) & 1;
    };
    $appendBits(0b0100, 4);           // byte mode
    $appendBits(count($bytes), 8);    // version 6 uses 8-bit byte length
    foreach ($bytes as $byte) $appendBits($byte, 8);
    $capacityBits = 136 * 8;
    for ($i = 0, $n = min(4, $capacityBits - count($dataBits)); $i < $n; $i++) $dataBits[] = 0;
    while (count($dataBits) % 8 !== 0) $dataBits[] = 0;
    $data = [];
    for ($i = 0; $i < count($dataBits); $i += 8) {
        $v = 0;
        for ($j = 0; $j < 8; $j++) $v = ($v << 1) | $dataBits[$i + $j];
        $data[] = $v;
    }
    for ($pad = 0xEC; count($data) < 136; $pad = ($pad === 0xEC ? 0x11 : 0xEC)) $data[] = $pad;

    // Version 6-L has two blocks, each with 68 data bytes and 18 ECC bytes.
    $blockA = array_slice($data, 0, 68);
    $blockB = array_slice($data, 68, 68);
    $eccA = workshop_qr_remainder($blockA, 18);
    $eccB = workshop_qr_remainder($blockB, 18);
    $codewords = [];
    for ($i = 0; $i < 68; $i++) { $codewords[] = $blockA[$i]; $codewords[] = $blockB[$i]; }
    for ($i = 0; $i < 18; $i++) { $codewords[] = $eccA[$i]; $codewords[] = $eccB[$i]; }

    $version = 6;
    $size = 17 + 4 * $version; // 41x41
    $modules = array_fill(0, $size, array_fill(0, $size, false));
    $reserved = array_fill(0, $size, array_fill(0, $size, false));
    $setFunction = static function (int $x, int $y, bool $dark) use (&$modules, &$reserved, $size): void {
        if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) return;
        $modules[$y][$x] = $dark;
        $reserved[$y][$x] = true;
    };

    // Finder patterns with separators.
    foreach ([[0,0],[0,$size-7],[$size-7,0]] as [$fx,$fy]) {
        for ($dy = -1; $dy <= 7; $dy++) for ($dx = -1; $dx <= 7; $dx++) {
            $inside = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6;
            $dark = $inside && ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6 || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4));
            $setFunction($fx + $dx, $fy + $dy, $dark);
        }
    }
    // Timing patterns.
    for ($i = 8; $i < $size - 8; $i++) {
        $setFunction($i, 6, $i % 2 === 0);
        $setFunction(6, $i, $i % 2 === 0);
    }
    // Version 6 has one non-overlapping alignment pattern at (34,34).
    $cx = $size - 7; $cy = $size - 7;
    for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) {
        $setFunction($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
    }

    // Format bits: error correction L has format value 01; mask pattern 0.
    $formatData = (1 << 3) | 0;
    $rem = $formatData;
    for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * 0x537);
    $formatBits = (($formatData << 10) | $rem) ^ 0x5412;
    for ($i = 0; $i <= 5; $i++) $setFunction(8, $i, (($formatBits >> $i) & 1) !== 0);
    $setFunction(8, 7, (($formatBits >> 6) & 1) !== 0);
    $setFunction(8, 8, (($formatBits >> 7) & 1) !== 0);
    $setFunction(7, 8, (($formatBits >> 8) & 1) !== 0);
    for ($i = 9; $i < 15; $i++) $setFunction(14 - $i, 8, (($formatBits >> $i) & 1) !== 0);
    for ($i = 0; $i < 8; $i++) $setFunction($size - 1 - $i, 8, (($formatBits >> $i) & 1) !== 0);
    for ($i = 8; $i < 15; $i++) $setFunction(8, $size - 15 + $i, (($formatBits >> $i) & 1) !== 0);
    $setFunction(8, $size - 8, true); // fixed dark module

    // Place codewords in the standard upward/downward zig-zag, applying mask 0.
    $bitIndex = 0;
    $totalBits = count($codewords) * 8;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) $right = 5;
        $upward = (($right + 1) & 2) === 0;
        for ($vert = 0; $vert < $size; $vert++) {
            $y = $upward ? $size - 1 - $vert : $vert;
            for ($j = 0; $j < 2; $j++) {
                $x = $right - $j;
                if ($reserved[$y][$x]) continue;
                $bit = false;
                if ($bitIndex < $totalBits) $bit = (($codewords[intdiv($bitIndex, 8)] >> (7 - ($bitIndex % 8))) & 1) !== 0;
                $bitIndex++;
                if (($x + $y) % 2 === 0) $bit = !$bit; // mask 0
                $modules[$y][$x] = $bit;
            }
        }
    }

    // Crisp SVG with the standard 4-module quiet zone.
    $quiet = 4;
    $viewSize = $size + 2 * $quiet;
    $path = '';
    for ($y = 0; $y < $size; $y++) {
        $x = 0;
        while ($x < $size) {
            if (!$modules[$y][$x]) { $x++; continue; }
            $start = $x;
            while ($x < $size && $modules[$y][$x]) $x++;
            $path .= 'M'.($start + $quiet).' '.($y + $quiet).'h'.($x - $start).'v1h-'.($x - $start).'z';
        }
    }
    $pixels = max(120, min(600, $pixels));
    return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$pixels.'" height="'.$pixels.'" viewBox="0 0 '.$viewSize.' '.$viewSize.'" role="img" aria-label="QR code"><rect width="100%" height="100%" fill="#fff"/><path d="'.$path.'" fill="#000" shape-rendering="crispEdges"/></svg>';
}
