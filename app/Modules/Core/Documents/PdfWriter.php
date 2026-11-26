<?php

namespace App\Modules\Core\Documents;

/**
 * Gerador mínimo de PDF (A4, Helvetica, WinAnsi), sem dependências externas. Atende documentos comerciais
 * com texto, linhas e faixas; não suporta imagens, fontes incorporadas nem caracteres fora do Windows-1252.
 */
class PdfWriter
{
    public const WIDTH = 595.28;

    public const HEIGHT = 841.89;

    /** Larguras (em milésimos do corpo) da Helvetica; caracteres ausentes usam a largura padrão. */
    private const REGULAR = [
        ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667, "'" => 191, '(' => 333, ')' => 333,
        '*' => 389, '+' => 584, ',' => 278, '-' => 333, '.' => 278, '/' => 278, ':' => 278, ';' => 278, '<' => 584, '=' => 584,
        '>' => 584, '?' => 556, '@' => 1015, 'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778,
        'H' => 722, 'I' => 278, 'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722, 'O' => 778, 'P' => 667, 'Q' => 778,
        'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944, 'X' => 667, 'Y' => 667, 'Z' => 611, '[' => 278,
        ']' => 278, '_' => 556, 'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556, 'f' => 278, 'g' => 556, 'h' => 556,
        'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556, 'o' => 556, 'p' => 556, 'q' => 556, 'r' => 333,
        's' => 500, 't' => 278, 'u' => 556, 'v' => 500, 'w' => 722, 'x' => 500, 'y' => 500, 'z' => 500, '|' => 260,
    ];

    private const BOLD = [
        ' ' => 278, '!' => 333, '"' => 474, '&' => 722, "'" => 238, '(' => 333, ')' => 333, ',' => 278, '-' => 333, '.' => 278,
        '/' => 278, ':' => 333, ';' => 333, '?' => 611, '@' => 975, 'A' => 722, 'B' => 722, 'C' => 722, 'D' => 722, 'E' => 667,
        'F' => 611, 'G' => 778, 'H' => 722, 'I' => 278, 'J' => 556, 'K' => 722, 'L' => 611, 'M' => 833, 'N' => 722, 'O' => 778,
        'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944, 'X' => 667, 'Y' => 667,
        'Z' => 611, 'a' => 556, 'b' => 611, 'c' => 556, 'd' => 611, 'e' => 556, 'f' => 333, 'g' => 611, 'h' => 611, 'i' => 278,
        'j' => 278, 'k' => 556, 'l' => 278, 'm' => 889, 'n' => 611, 'o' => 611, 'p' => 611, 'q' => 611, 'r' => 389, 's' => 556,
        't' => 333, 'u' => 611, 'v' => 556, 'w' => 778, 'x' => 556, 'y' => 556, 'z' => 500,
    ];

    /** @var list<string> */
    private array $pages = [];

    private string $current = '';

    public function addPage(): void
    {
        if ($this->current !== '') {
            $this->pages[] = $this->current;
        }

        $this->current = "\n";
    }

    /** Coordenadas a partir do canto superior esquerdo, em pontos. */
    public function text(float $x, float $top, string $text, float $size = 9, bool $bold = false, string $align = 'left'): void
    {
        $width = $this->width($text, $size, $bold);
        $x = match ($align) {
            'right' => $x - $width,
            'center' => $x - $width / 2,
            default => $x,
        };
        $this->current .= sprintf("BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $x, self::HEIGHT - $top - $size, $this->escape($text));
    }

    public function line(float $x1, float $top1, float $x2, float $top2, float $width = 0.5): void
    {
        $this->current .= sprintf("%.2F w %.2F %.2F m %.2F %.2F l S\n", $width, $x1, self::HEIGHT - $top1, $x2, self::HEIGHT - $top2);
    }

    public function band(float $x, float $top, float $width, float $height, float $gray = 0.93): void
    {
        $this->current .= sprintf("%.3F g %.2F %.2F %.2F %.2F re f 0 g\n", $gray, $x, self::HEIGHT - $top - $height, $width, $height);
    }

    public function width(string $text, float $size, bool $bold = false): float
    {
        $widths = $bold ? self::BOLD : self::REGULAR;
        $total = 0;

        foreach (mb_str_split($text) as $character) {
            $base = ctype_digit($character) ? '0' : (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $character);
            $total += $widths[$base] ?? (ctype_digit($character) ? 556 : ($bold ? 611 : 556));
        }

        return $total * $size / 1000;
    }

    /**
     * Quebra o texto em linhas que cabem na largura informada.
     *
     * @return list<string>
     */
    public function wrap(string $text, float $maxWidth, float $size, bool $bold = false): array
    {
        $lines = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $paragraph) {
            $line = '';

            foreach (preg_split('/\s+/u', trim($paragraph)) ?: [] as $word) {
                $candidate = $line === '' ? $word : "{$line} {$word}";

                if ($line !== '' && $this->width($candidate, $size, $bold) > $maxWidth) {
                    $lines[] = $line;
                    $candidate = $word;
                }

                while ($this->width($candidate, $size, $bold) > $maxWidth && mb_strlen($candidate) > 1) {
                    $cut = mb_strlen($candidate) - 1;

                    while ($cut > 1 && $this->width(mb_substr($candidate, 0, $cut), $size, $bold) > $maxWidth) {
                        $cut--;
                    }

                    $lines[] = mb_substr($candidate, 0, $cut);
                    $candidate = mb_substr($candidate, $cut);
                }

                $line = $candidate;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    public function output(string $title): string
    {
        $pages = [...$this->pages, ...($this->current === '' ? [] : [$this->current])];
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            5 => '<< /Title ('.$this->escape($title).') /Producer (VetorFlex) >>',
        ];
        $kids = [];

        foreach ($pages as $index => $content) {
            $pageId = 6 + $index * 2;
            $kids[] = "{$pageId} 0 R";
            $objects[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::WIDTH, self::HEIGHT, $pageId + 1);
            $objects[$pageId + 1] = '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream";
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R /Info 5 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function escape(string $text): string
    {
        $encoded = (string) iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $encoded);
    }
}
