<?php
declare(strict_types=1);

final class SiQrSvg
{
    private const VERSION = 5;
    private const SIZE = 37;
    private const DATA_CODEWORDS = 86; // QR v5-M
    private const BLOCKS = 2;
    private const DATA_PER_BLOCK = 43;
    private const ECC_PER_BLOCK = 24;

    public static function svg(string $payload, int $border = 4): string
    {
        $matrix = self::encode($payload);
        $n = self::SIZE;
        $view = $n + 2 * $border;
        $path = '';
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ($matrix[$r][$c]) {
                    $x = $c + $border;
                    $y = $r + $border;
                    $path .= "M{$x},{$y}h1v1h-1z";
                }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $view . ' ' . $view . '" shape-rendering="crispEdges" role="img" aria-label="Código QR">'
            . '<rect width="100%" height="100%" fill="#fff"/>'
            . '<path d="' . $path . '" fill="#000"/>'
            . '</svg>';
    }

    public static function encode(string $payload): array
    {
        if (!preg_match('//u', $payload)) {
            throw new InvalidArgumentException('El contenido QR no es UTF-8 válido.');
        }
        if (strlen($payload) > 84) {
            throw new InvalidArgumentException('El contenido QR excede la capacidad admitida.');
        }
        $data = self::makeDataCodewords($payload);
        $all = self::addEccAndInterleave($data);

        $best = null;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            [$m, $func] = self::baseMatrix();
            self::drawCodewords($m, $func, $all, $mask);
            self::drawFormatBits($m, $func, $mask);
            $p = self::penalty($m);
            if ($p < $bestPenalty) {
                $bestPenalty = $p;
                $best = $m;
            }
        }
        return $best;
    }

    private static function makeDataCodewords(string $payload): array
    {
        $bits = [];
        $append = static function(int $val, int $len) use (&$bits): void {
            for ($i = $len - 1; $i >= 0; $i--) $bits[] = (($val >> $i) & 1) !== 0;
        };
        $append(0x4, 4); // byte mode
        $append(strlen($payload), 8);
        for ($i = 0, $n = strlen($payload); $i < $n; $i++) $append(ord($payload[$i]), 8);
        $capacityBits = self::DATA_CODEWORDS * 8;
        $terminator = min(4, $capacityBits - count($bits));
        for ($i = 0; $i < $terminator; $i++) $bits[] = false;
        while (count($bits) % 8 !== 0) $bits[] = false;
        $bytes = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $v = 0;
            for ($j = 0; $j < 8; $j++) $v = ($v << 1) | ($bits[$i + $j] ? 1 : 0);
            $bytes[] = $v;
        }
        $pad = [0xEC, 0x11]; $pi = 0;
        while (count($bytes) < self::DATA_CODEWORDS) {
            $bytes[] = $pad[$pi++ & 1];
        }
        return $bytes;
    }

    private static function addEccAndInterleave(array $data): array
    {
        $blocks = [];
        for ($b = 0; $b < self::BLOCKS; $b++) {
            $chunk = array_slice($data, $b * self::DATA_PER_BLOCK, self::DATA_PER_BLOCK);
            $blocks[] = ['data' => $chunk, 'ecc' => self::reedSolomon($chunk, self::ECC_PER_BLOCK)];
        }
        $out = [];
        for ($i = 0; $i < self::DATA_PER_BLOCK; $i++) {
            foreach ($blocks as $b) $out[] = $b['data'][$i];
        }
        for ($i = 0; $i < self::ECC_PER_BLOCK; $i++) {
            foreach ($blocks as $b) $out[] = $b['ecc'][$i];
        }
        return $out;
    }

    private static function reedSolomon(array $data, int $degree): array
    {
        $gen = [1];
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $coef) {
                $next[$j] ^= $coef;
                $next[$j + 1] ^= self::gfMul($coef, $root);
            }
            $gen = $next;
            $root = self::gfMul($root, 0x02);
        }
        $msg = array_merge($data, array_fill(0, $degree, 0));
        for ($i = 0; $i < count($data); $i++) {
            $factor = $msg[$i];
            if ($factor === 0) continue;
            foreach ($gen as $j => $coef) $msg[$i + $j] ^= self::gfMul($coef, $factor);
        }
        return array_slice($msg, -$degree);
    }

    private static function gfMul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ ((($z >> 7) & 1) * 0x11D)) & 0xFF;
            if ((($y >> $i) & 1) !== 0) $z ^= $x;
        }
        return $z;
    }

    private static function baseMatrix(): array
    {
        $n = self::SIZE;
        $m = array_fill(0, $n, array_fill(0, $n, false));
        $f = array_fill(0, $n, array_fill(0, $n, false));
        $set = static function(int $r, int $c, bool $dark) use (&$m, &$f, $n): void {
            if ($r >= 0 && $r < $n && $c >= 0 && $c < $n) { $m[$r][$c] = $dark; $f[$r][$c] = true; }
        };
        self::finder($set, 3, 3);
        self::finder($set, 3, $n - 4);
        self::finder($set, $n - 4, 3);
        for ($i = 8; $i < $n - 8; $i++) {
            $set(6, $i, ($i % 2) === 0);
            $set($i, 6, ($i % 2) === 0);
        }
        // Version 5 alignment centers: 6, 30. Finder-overlapping (6,6) is omitted.
        self::alignment($set, 30, 30);
        self::drawFormatBits($m, $f, 0, true);
        $set(4 * self::VERSION + 9, 8, true); // dark module
        return [$m, $f];
    }

    private static function finder(callable $set, int $cr, int $cc): void
    {
        for ($dr = -4; $dr <= 4; $dr++) for ($dc = -4; $dc <= 4; $dc++) {
            $dist = max(abs($dr), abs($dc));
            $dark = $dist !== 2 && $dist !== 4;
            $set($cr + $dr, $cc + $dc, $dark);
        }
    }

    private static function alignment(callable $set, int $cr, int $cc): void
    {
        for ($dr = -2; $dr <= 2; $dr++) for ($dc = -2; $dc <= 2; $dc++) {
            $set($cr + $dr, $cc + $dc, max(abs($dr), abs($dc)) !== 1);
        }
    }

    private static function drawCodewords(array &$m, array $f, array $bytes, int $mask): void
    {
        $bits = [];
        foreach ($bytes as $b) for ($i = 7; $i >= 0; $i--) $bits[] = (($b >> $i) & 1) !== 0;
        $i = 0; $up = true; $n = self::SIZE;
        for ($right = $n - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right--;
            for ($v = 0; $v < $n; $v++) {
                $r = $up ? $n - 1 - $v : $v;
                for ($j = 0; $j < 2; $j++) {
                    $c = $right - $j;
                    if ($f[$r][$c]) continue;
                    $bit = $i < count($bits) ? $bits[$i] : false;
                    $i++;
                    if (self::mask($mask, $r, $c)) $bit = !$bit;
                    $m[$r][$c] = $bit;
                }
            }
            $up = !$up;
        }
    }

    private static function mask(int $mask, int $r, int $c): bool
    {
        return match ($mask) {
            0 => (($r + $c) % 2) === 0,
            1 => ($r % 2) === 0,
            2 => ($c % 3) === 0,
            3 => (($r + $c) % 3) === 0,
            4 => ((intdiv($r, 2) + intdiv($c, 3)) % 2) === 0,
            5 => (($r * $c) % 2 + ($r * $c) % 3) === 0,
            6 => (((($r * $c) % 2) + (($r * $c) % 3)) % 2) === 0,
            7 => (((($r + $c) % 2) + (($r * $c) % 3)) % 2) === 0,
        };
    }

    private static function drawFormatBits(array &$m, array &$f, int $mask, bool $reserveOnly = false): void
    {
        // ECL M = 00. Format data therefore consists only of the 3 mask bits.
        $data = $mask;
        $rem = $data << 10;
        for ($i = 14; $i >= 10; $i--) if ((($rem >> $i) & 1) !== 0) $rem ^= 0x537 << ($i - 10);
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $get = static fn(int $i): bool => (($bits >> $i) & 1) !== 0;
        $set = static function(int $r, int $c, bool $dark) use (&$m, &$f, $reserveOnly): void {
            $m[$r][$c] = $reserveOnly ? false : $dark; $f[$r][$c] = true;
        };
        for ($i = 0; $i <= 5; $i++) $set($i, 8, $get($i));
        $set(7, 8, $get(6));
        $set(8, 8, $get(7));
        $set(8, 7, $get(8));
        for ($i = 9; $i < 15; $i++) $set(8, 14 - $i, $get($i));
        $n = self::SIZE;
        for ($i = 0; $i < 8; $i++) $set(8, $n - 1 - $i, $get($i));
        for ($i = 8; $i < 15; $i++) $set($n - 15 + $i, 8, $get($i));
        // dark module is drawn separately and must stay dark.
    }

    private static function penalty(array $m): int
    {
        $n = self::SIZE; $p = 0;
        // Runs
        for ($r=0;$r<$n;$r++) { $runColor=$m[$r][0]; $run=1; for($c=1;$c<$n;$c++){ if($m[$r][$c]===$runColor){$run++; if($run===5)$p+=3; elseif($run>5)$p++;}else{$runColor=$m[$r][$c];$run=1;}} }
        for ($c=0;$c<$n;$c++) { $runColor=$m[0][$c]; $run=1; for($r=1;$r<$n;$r++){ if($m[$r][$c]===$runColor){$run++; if($run===5)$p+=3; elseif($run>5)$p++;}else{$runColor=$m[$r][$c];$run=1;}} }
        // 2x2
        for ($r=0;$r<$n-1;$r++) for($c=0;$c<$n-1;$c++) { $v=$m[$r][$c]; if($m[$r][$c+1]===$v && $m[$r+1][$c]===$v && $m[$r+1][$c+1]===$v)$p+=3; }
        // Finder-like pattern, ISO/IEC penalty N3. Evaluate the exact 11-module
        // sequences 10111010000 and 00001011101 in rows and columns.
        $pat1 = [true,false,true,true,true,false,true,false,false,false,false];
        $pat2 = [false,false,false,false,true,false,true,true,true,false,true];
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c <= $n - 11; $c++) {
                $match1 = true; $match2 = true;
                for ($k = 0; $k < 11; $k++) {
                    if ($m[$r][$c + $k] !== $pat1[$k]) $match1 = false;
                    if ($m[$r][$c + $k] !== $pat2[$k]) $match2 = false;
                }
                if ($match1 || $match2) $p += 40;
            }
        }
        for ($c = 0; $c < $n; $c++) {
            for ($r = 0; $r <= $n - 11; $r++) {
                $match1 = true; $match2 = true;
                for ($k = 0; $k < 11; $k++) {
                    if ($m[$r + $k][$c] !== $pat1[$k]) $match1 = false;
                    if ($m[$r + $k][$c] !== $pat2[$k]) $match2 = false;
                }
                if ($match1 || $match2) $p += 40;
            }
        }
        $dark=0; foreach($m as $row) foreach($row as $v) if($v)$dark++;
        $total=$n*$n; $p += intdiv(abs($dark*20-$total*10), $total) * 10;
        return $p;
    }
}
