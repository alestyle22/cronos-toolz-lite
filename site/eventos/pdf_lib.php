<?php
/**
 * eventos/pdf_lib.php — Generador de PDF mínimo, sin dependencias: páginas, rectángulos (con esquinas redondeadas), texto con
 * Helvetica / Helvetica-Bold (WinAnsi: tildes y ñ incluidas), imágenes JPEG y el QR como vectores (nítido a cualquier tamaño).
 * Coordenadas en puntos desde ARRIBA a la izquierda (se convierten al sistema del PDF).
 */
final class EvPdf {
    private array $pages = []; private int $cur = -1; private array $images = []; private float $h = 842;
    private const W_REG = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
    private const W_BOLD = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];

    public function page(float $w = 595.28, float $h = 841.89): void { $this->pages[] = ['w' => $w, 'h' => $h, 'c' => '']; $this->cur = count($this->pages) - 1; $this->h = $h; }
    private function add(string $s): void { $this->pages[$this->cur]['c'] .= $s . "\n"; }
    private static function n(float $v): string { return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'); }
    private static function rgb(string $hex): string { $h = ltrim($hex, '#'); if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2]; $c = array_map(fn($x) => hexdec($x) / 255, str_split(str_pad(substr($h, 0, 6), 6, '0'), 2)); return implode(' ', array_map([self::class, 'n'], $c)); }
    /** Texto UTF-8 → WinAnsi (lo que no existe se translitera o se omite). */
    public static function enc(string $s): string { $o = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s); return $o === false ? preg_replace('/[^\x20-\x7e]/', '?', $s) : $o; }
    public function width(string $s, float $size, bool $bold = false): float {
        $w = 0; $tbl = $bold ? self::W_BOLD : self::W_REG; $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s); if ($ascii === false) $ascii = $s;
        foreach (str_split($ascii) as $c) { $o = ord($c); $w += ($o >= 32 && $o <= 126) ? $tbl[$o - 32] : 556; }
        return $w * $size / 1000;
    }
    public function rect(float $x, float $y, float $w, float $h, string $fill, float $r = 0, ?string $stroke = null, float $lw = 1): void {
        $Y = $this->h - $y - $h; $op = $stroke ? 'B' : 'f';
        $this->add(self::rgb($fill) . ' rg' . ($stroke ? ' ' . self::rgb($stroke) . ' RG ' . self::n($lw) . ' w' : ''));
        if ($r <= 0) { $this->add(self::n($x) . ' ' . self::n($Y) . ' ' . self::n($w) . ' ' . self::n($h) . " re $op"); return; }
        $k = 0.5523 * $r; $x2 = $x + $w; $y2 = $Y + $h; $p = fn(...$a) => implode(' ', array_map([self::class, 'n'], $a));
        $this->add($p($x + $r, $Y) . ' m ' . $p($x2 - $r, $Y) . ' l ' . $p($x2 - $r + $k, $Y, $x2, $Y + $r - $k, $x2, $Y + $r) . ' c ' . $p($x2, $y2 - $r) . ' l '
            . $p($x2, $y2 - $r + $k, $x2 - $r + $k, $y2, $x2 - $r, $y2) . ' c ' . $p($x + $r, $y2) . ' l ' . $p($x + $r - $k, $y2, $x, $y2 - $r + $k, $x, $y2 - $r) . ' c '
            . $p($x, $Y + $r) . ' l ' . $p($x, $Y + $r - $k, $x + $r - $k, $Y, $x + $r, $Y) . " c h $op");
    }
    public function line(float $x1, float $y1, float $x2, float $y2, string $color, float $w = 1, bool $dash = false): void {
        $this->add(self::rgb($color) . ' RG ' . self::n($w) . ' w ' . ($dash ? '[4 4] 0 d ' : '') . self::n($x1) . ' ' . self::n($this->h - $y1) . ' m ' . self::n($x2) . ' ' . self::n($this->h - $y2) . ' l S' . ($dash ? ' [] 0 d' : ''));
    }
    /** Texto en una línea. $y = línea de base desde arriba. $align: L, C, R (respecto de $x). */
    public function text(float $x, float $y, string $s, float $size, string $color = '#000', bool $bold = false, string $align = 'L'): void {
        if ($align !== 'L') { $w = $this->width($s, $size, $bold); $x -= $align === 'C' ? $w / 2 : $w; }
        $t = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], self::enc($s));
        $this->add('BT /' . ($bold ? 'F2' : 'F1') . ' ' . self::n($size) . ' Tf ' . self::rgb($color) . ' rg ' . self::n($x) . ' ' . self::n($this->h - $y) . " Td ($t) Tj ET");
    }
    /** Texto en varias líneas dentro de un ancho; devuelve la y siguiente. */
    public function para(float $x, float $y, float $w, string $s, float $size, string $color = '#000', bool $bold = false, float $lh = 1.35, int $maxLines = 50): float {
        $lines = []; foreach (preg_split('/\R/', $s) as $p) { $cur = ''; foreach (preg_split('/\s+/', trim($p)) as $word) { $try = $cur === '' ? $word : "$cur $word"; if ($this->width($try, $size, $bold) > $w && $cur !== '') { $lines[] = $cur; $cur = $word; } else $cur = $try; } $lines[] = $cur; }
        foreach (array_slice($lines, 0, $maxLines) as $i => $l) $this->text($x, $y + $i * $size * $lh, $l, $size, $color, $bold);
        return $y + min(count($lines), $maxLines) * $size * $lh;
    }
    /** Ajusta un texto a un ancho achicando la letra (títulos largos). */
    public function fit(string $s, float $w, float $size, bool $bold, float $min = 9): float { while ($size > $min && $this->width($s, $size, $bold) > $w) $size -= 0.5; return $size; }
    /** QR vectorial desde la matriz (filas de booleanos), con fondo blanco y margen de 4 módulos. */
    public function qr(array $m, float $x, float $y, float $size): void {
        $n = count($m); $q = 4; $u = $size / ($n + 2 * $q);
        $this->rect($x, $y, $size, $size, '#ffffff');
        $ops = '0 0 0 rg';
        foreach ($m as $r => $row) { $c = 0; while ($c < $n) { if (!$row[$c]) { $c++; continue; } $s = $c; while ($c < $n && $row[$c]) $c++;   // tramos horizontales: menos operaciones
            $ops .= ' ' . self::n($x + ($s + $q) * $u) . ' ' . self::n($this->h - $y - ($r + $q + 1) * $u) . ' ' . self::n(($c - $s) * $u + 0.01) . ' ' . self::n($u + 0.01) . ' re'; } }
        $this->add($ops . ' f');
    }
    /** Imagen JPEG (otras no: el PDF quedaría pesado o sin soporte). */
    public function jpeg(string $file, float $x, float $y, float $w, float $h): bool {
        $info = @getimagesize($file); if (!$info || $info[2] !== IMAGETYPE_JPEG || ($info['channels'] ?? 3) === 4) return false;
        $k = 'I' . substr(md5($file), 0, 8);   // la misma imagen en varias páginas se guarda una sola vez
        if (!isset($this->images[$k])) $this->images[$k] = ['data' => file_get_contents($file), 'w' => $info[0], 'h' => $info[1], 'gray' => ($info['channels'] ?? 3) === 1];
        $this->add('q ' . self::n($w) . ' 0 0 ' . self::n($h) . ' ' . self::n($x) . ' ' . self::n($this->h - $y - $h) . " cm /$k Do Q");
        return true;
    }
    public function clip(float $x, float $y, float $w, float $h, float $r, callable $draw): void {
        $this->add('q'); $Y = $this->h - $y - $h; $k = 0.5523 * $r; $x2 = $x + $w; $y2 = $Y + $h; $p = fn(...$a) => implode(' ', array_map([self::class, 'n'], $a));
        $this->add($p($x + $r, $Y) . ' m ' . $p($x2 - $r, $Y) . ' l ' . $p($x2 - $r + $k, $Y, $x2, $Y + $r - $k, $x2, $Y + $r) . ' c ' . $p($x2, $y2 - $r) . ' l ' . $p($x2, $y2 - $r + $k, $x2 - $r + $k, $y2, $x2 - $r, $y2) . ' c '
            . $p($x + $r, $y2) . ' l ' . $p($x + $r - $k, $y2, $x, $y2 - $r + $k, $x, $y2 - $r) . ' c ' . $p($x, $Y + $r) . ' l ' . $p($x, $Y + $r - $k, $x + $r - $k, $Y, $x + $r, $Y) . ' c h W n');
        $draw(); $this->add('Q');
    }
    public function output(string $title = ''): string {
        $objs = []; $add = function (string $s) use (&$objs) { $objs[] = $s; return count($objs); };
        $f1 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $f2 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
        $imgRefs = ''; foreach ($this->images as $k => $im) { $id = $add('<< /Type /XObject /Subtype /Image /Width ' . $im['w'] . ' /Height ' . $im['h'] . ' /ColorSpace /' . ($im['gray'] ? 'DeviceGray' : 'DeviceRGB') . ' /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($im['data']) . " >>\nstream\n" . $im['data'] . "\nendstream"); $imgRefs .= "/$k $id 0 R "; }
        $pagesId = count($objs) + 1 + 2 * count($this->pages); $kids = [];
        foreach ($this->pages as $p) {
            $c = function_exists('gzcompress') ? gzcompress($p['c']) : $p['c'];
            $cid = $add('<< /Length ' . strlen($c) . (function_exists('gzcompress') ? ' /Filter /FlateDecode' : '') . " >>\nstream\n" . $c . "\nendstream");
            $kids[] = $add('<< /Type /Page /Parent ' . $pagesId . ' 0 R /MediaBox [0 0 ' . self::n($p['w']) . ' ' . self::n($p['h']) . '] /Resources << /Font << /F1 ' . $f1 . ' 0 R /F2 ' . $f2 . ' 0 R >> /XObject << ' . $imgRefs . '>> >> /Contents ' . $cid . ' 0 R >>');
        }
        $add('<< /Type /Pages /Kids [' . implode(' ', array_map(fn($k) => "$k 0 R", $kids)) . '] /Count ' . count($kids) . ' >>');
        $info = $add('<< /Title (' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], self::enc($title)) . ') /Producer (Cronos Tickets) /CreationDate (D:' . date('YmdHis') . ') >>');
        $cat = $add('<< /Type /Catalog /Pages ' . $pagesId . ' 0 R >>');
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $off = [];
        foreach ($objs as $i => $o) { $off[] = strlen($out); $out .= ($i + 1) . " 0 obj\n$o\nendobj\n"; }
        $x = strlen($out); $out .= 'xref' . "\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n"; foreach ($off as $o) $out .= sprintf("%010d 00000 n \n", $o);
        return $out . 'trailer << /Size ' . (count($objs) + 1) . ' /Root ' . $cat . ' 0 R /Info ' . $info . " 0 R >>\nstartxref\n$x\n%%EOF";
    }
}
