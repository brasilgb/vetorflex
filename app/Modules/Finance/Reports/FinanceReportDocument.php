<?php

namespace App\Modules\Finance\Reports;

use App\Modules\Core\Documents\CommercialDocument;
use App\Modules\Core\Documents\DocumentFormat;
use App\Modules\Core\Documents\PdfWriter;
use App\Modules\Tenancy\Models\Company;

/**
 * PDF dos relatórios financeiros, com o leiaute comum dos documentos da plataforma. A coluna descritiva vem
 * primeiro, pois é a única que quebra em várias linhas.
 *
 * @phpstan-import-type Table from FinanceReports
 */
class FinanceReportDocument
{
    public const TOTAL_LABELS = [
        'amount' => 'Valor',
        'settled' => 'Liquidado',
        'open' => 'Em aberto',
        'income' => 'Receitas',
        'expense' => 'Despesas',
        'result' => 'Resultado gerencial',
        'opening' => 'Saldo inicial',
        'closing' => 'Saldo final',
        'realized_in' => 'Entradas realizadas',
        'realized_out' => 'Saídas realizadas',
        'forecast_in' => 'Entradas previstas',
        'forecast_out' => 'Saídas previstas',
    ];

    private const MONEY_COLUMNS = ['amount', 'settled', 'open', 'total', 'balance', 'realized_in', 'realized_out', 'forecast_in', 'forecast_out'];

    private const WRAPPING_COLUMNS = ['description', 'party', 'label', 'category'];

    /** @param Table $table */
    public function render(Company $company, array $table, string $generatedAt): string
    {
        $settings = $company->settings ?? [];
        $document = new CommercialDocument(
            title: 'RELATÓRIO FINANCEIRO',
            notice: 'Relatório gerencial, sem valor contábil ou fiscal.',
            issuer: ['name' => $company->name, 'lines' => array_filter([($settings['document'] ?? null) ? "CNPJ/CPF: {$settings['document']}" : null])],
            facts: [['Relatório', $table['title']], ['Emitido em', $generatedAt]],
        );
        $document->section('Filtros', [$table['subtitle']]);
        $columns = $table['columns'];

        if ($columns !== []) {
            $first = collect($columns)->search(fn (array $column): bool => in_array($column['key'], self::WRAPPING_COLUMNS, true));

            if (is_int($first) && $first > 0) {
                array_unshift($columns, ...array_splice($columns, $first, 1));
            }

            $available = PdfWriter::WIDTH - 72;
            $mainWidth = min(220.0, $available * 0.4);
            $otherWidth = count($columns) > 1 ? ($available - $mainWidth) / (count($columns) - 1) : 0;
            $document->table(
                array_map(fn (array $column, int $index): array => ['label' => $column['label'], 'width' => $index === 0 ? (count($columns) === 1 ? $available : $mainWidth) : $otherWidth, 'align' => ($column['align'] ?? 'left') === 'right' ? 'right' : 'left'], $columns, array_keys($columns)),
                array_map(fn (array $row): array => array_map(fn (array $column): string => $this->cell($column['key'], $row[$column['key']] ?? null), $columns), $table['rows'] ?: []),
            );
        }

        if ($table['rows'] === []) {
            $document->section('Resultado', ['Nenhum registro no período.']);
        }

        $document->totals(array_map(fn (string $key, string $value): array => [self::TOTAL_LABELS[$key] ?? $key, DocumentFormat::money($value), in_array($key, ['open', 'result', 'closing'], true)], array_keys($table['totals']), array_values($table['totals'])));

        return $document->output();
    }

    private function cell(string $key, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return in_array($key, self::MONEY_COLUMNS, true) && is_numeric($value) ? DocumentFormat::decimal($value) : $value;
    }
}
