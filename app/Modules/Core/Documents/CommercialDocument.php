<?php

namespace App\Modules\Core\Documents;

/**
 * Leiaute comum dos documentos comerciais não fiscais (pedido de compra e, futuramente, pedido de venda e orçamento):
 * cabeçalho com emitente e número, bloco da contraparte, tabela de itens, totais e seções de texto.
 *
 * @phpstan-type Column array{label: string, width: float, align?: 'left'|'right'|'center'}
 */
class CommercialDocument
{
    private const MARGIN = 36.0;

    private const BOTTOM = 790.0;

    private float $top = self::MARGIN;

    private int $pageNumber = 0;

    private PdfWriter $pdf;

    /**
     * @param  array{name: string, lines: list<string>}  $issuer
     * @param  list<array{string, string}>  $facts  Pares rótulo e valor exibidos ao lado do título.
     */
    public function __construct(
        private string $title,
        private string $notice,
        private array $issuer,
        private array $facts,
    ) {
        $this->pdf = new PdfWriter;
        $this->newPage();
    }

    /** @param list<string> $lines */
    public function party(string $label, string $name, array $lines): void
    {
        $this->ensureSpace(30 + count($lines) * 11);
        $this->pdf->text(self::MARGIN, $this->top, mb_strtoupper($label), 7, true);
        $this->top += 10;
        $this->pdf->text(self::MARGIN, $this->top, $name, 10, true);
        $this->top += 13;

        foreach ($lines as $line) {
            $this->pdf->text(self::MARGIN, $this->top, $line, 8.5);
            $this->top += 11;
        }

        $this->top += 8;
    }

    /**
     * @param  list<Column>  $columns  A primeira coluna é quebrada em várias linhas quando necessário.
     * @param  list<list<string>>  $rows
     */
    public function table(array $columns, array $rows): void
    {
        $this->tableHeader($columns);

        foreach ($rows as $row) {
            $lines = $this->pdf->wrap($row[0], $columns[0]['width'] - 6, 8.5);
            $height = count($lines) * 10.5 + 5;

            if ($this->top + $height > self::BOTTOM) {
                $this->newPage();
                $this->tableHeader($columns);
            }

            $x = self::MARGIN;

            foreach ($columns as $index => $column) {
                if ($index === 0) {
                    foreach ($lines as $offset => $line) {
                        $this->pdf->text($x + 3, $this->top + 3 + $offset * 10.5, $line, 8.5);
                    }
                } else {
                    $this->cell($x, $column, $row[$index] ?? '', 8.5, false);
                }

                $x += $column['width'];
            }

            $this->top += $height;
            $this->pdf->line(self::MARGIN, $this->top, PdfWriter::WIDTH - self::MARGIN, $this->top, 0.3);
        }

        $this->top += 8;
    }

    /** @param list<array{string, string, bool}> $totals Rótulo, valor e destaque. */
    public function totals(array $totals): void
    {
        $this->ensureSpace(count($totals) * 13 + 8);
        $right = PdfWriter::WIDTH - self::MARGIN;

        foreach ($totals as [$label, $value, $isStrong]) {
            $this->pdf->text($right - 110, $this->top, $label, $isStrong ? 10 : 8.5, $isStrong, 'right');
            $this->pdf->text($right - 3, $this->top, $value, $isStrong ? 10 : 8.5, $isStrong, 'right');
            $this->top += $isStrong ? 15 : 12;
        }

        $this->top += 6;
    }

    /** @param list<string> $lines */
    public function section(string $title, array $lines): void
    {
        $wrapped = array_merge(...array_map(fn (string $line): array => $this->pdf->wrap($line, PdfWriter::WIDTH - self::MARGIN * 2, 8.5), $lines ?: ['—']));
        $this->ensureSpace(24);
        $this->pdf->text(self::MARGIN, $this->top, mb_strtoupper($title), 7, true);
        $this->top += 11;

        foreach ($wrapped as $line) {
            $this->ensureSpace(11);
            $this->pdf->text(self::MARGIN, $this->top, $line, 8.5);
            $this->top += 11;
        }

        $this->top += 8;
    }

    public function output(): string
    {
        return $this->pdf->output($this->title);
    }

    private function newPage(): void
    {
        $this->pdf->addPage();
        $this->pageNumber++;
        $this->top = self::MARGIN;
        $right = PdfWriter::WIDTH - self::MARGIN;

        $this->pdf->text(self::MARGIN, $this->top, $this->issuer['name'], 12, true);
        $this->pdf->text($right, $this->top, $this->title, 13, true, 'right');
        $issuerTop = $this->top + 16;

        foreach ($this->issuer['lines'] as $line) {
            $this->pdf->text(self::MARGIN, $issuerTop, $line, 8);
            $issuerTop += 10;
        }

        $factsTop = $this->top + 18;

        foreach ($this->facts as [$label, $value]) {
            $this->pdf->text($right - 95, $factsTop, $label, 8, false, 'right');
            $this->pdf->text($right, $factsTop, $value, 8.5, true, 'right');
            $factsTop += 11;
        }

        $this->top = max($issuerTop, $factsTop) + 4;
        $this->pdf->text(self::MARGIN, $this->top, $this->notice, 7);
        $this->pdf->text($right, $this->top, "Página {$this->pageNumber}", 7, false, 'right');
        $this->top += 12;
        $this->pdf->line(self::MARGIN, $this->top, $right, $this->top, 0.8);
        $this->top += 10;
    }

    private function ensureSpace(float $height): void
    {
        if ($this->top + $height > self::BOTTOM) {
            $this->newPage();
        }
    }

    /** @param list<Column> $columns */
    private function tableHeader(array $columns): void
    {
        $this->pdf->band(self::MARGIN, $this->top, PdfWriter::WIDTH - self::MARGIN * 2, 15);
        $x = self::MARGIN;

        foreach ($columns as $column) {
            $this->cell($x, $column, $column['label'], 7.5, true, 3.5);
            $x += $column['width'];
        }

        $this->top += 17;
    }

    /** @param Column $column */
    private function cell(float $x, array $column, string $value, float $size, bool $bold, float $offset = 3): void
    {
        match ($column['align'] ?? 'left') {
            'right' => $this->pdf->text($x + $column['width'] - 3, $this->top + $offset, $value, $size, $bold, 'right'),
            'center' => $this->pdf->text($x + $column['width'] / 2, $this->top + $offset, $value, $size, $bold, 'center'),
            default => $this->pdf->text($x + 3, $this->top + $offset, $value, $size, $bold),
        };
    }
}
