<?php

namespace App\Modules\Core\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Formato de importação e exportação: UTF-8 com BOM, separado por ponto e vírgula. A coluna "nome" é obrigatória.
 */
abstract class CsvFormat
{
    public const DELIMITER = ';';

    public const BOM = "\xEF\xBB\xBF";

    /** @var list<string> Colunas aceitas na importação. */
    public const COLUMNS = [];

    /** @var list<string> Colunas da exportação que a importação reconhece, mas não grava. */
    public const READ_ONLY_COLUMNS = [];

    /** Chave de configuração com o limite de registros por arquivo. */
    protected const MAXIMUM_ROWS_CONFIG = '';

    /**
     * @return list<array{line: int, data: array<string, string>, errors: list<string>}>
     */
    public function read(UploadedFile $file): array
    {
        $contents = (string) $file->getContent();
        $contents = str_starts_with($contents, self::BOM) ? substr($contents, strlen(self::BOM)) : $contents;

        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        $stream = fopen('php://temp', 'r+');

        if ($stream === false || str_contains($contents, "\0")) {
            throw $this->invalid('O arquivo não é um CSV válido.');
        }

        try {
            fwrite($stream, $contents);
            rewind($stream);

            return $this->readRows($stream, $this->delimiter($contents));
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     * @param  array<array-key, mixed>  $row
     */
    public function write($stream, array $row): void
    {
        fputcsv($stream, CsvCell::escapeRow($row), self::DELIMITER, '"', '');
    }

    public static function formatDecimal(?string $value): ?string
    {
        return $value === null ? null : str_replace('.', ',', $value);
    }

    /** Aceita "1.234,56" e "1234.56". Sem vírgula, o ponto é sempre decimal. */
    public static function parseDecimal(string $value): string
    {
        $value = trim($value);

        return str_contains($value, ',') ? str_replace(',', '.', str_replace('.', '', $value)) : $value;
    }

    /**
     * @param  resource  $stream
     * @return list<array{line: int, data: array<string, string>, errors: list<string>}>
     */
    protected function readRows($stream, string $delimiter): array
    {
        $headers = $this->headers(fgetcsv($stream, null, $delimiter, '"', '') ?: []);
        $maximum = (int) config(static::MAXIMUM_ROWS_CONFIG);
        $rows = [];

        for ($line = 2; ($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false; $line++) {
            $cells = array_map(fn (?string $cell): string => trim((string) CsvCell::unescape(trim((string) $cell))), $cells);

            if (implode('', $cells) === '') {
                continue;
            }

            if (count($rows) >= $maximum) {
                throw $this->invalid("O arquivo excede o limite de {$maximum} registros.");
            }

            $hasExtraCells = implode('', array_slice($cells, count($headers))) !== '';
            $rows[] = [
                'line' => $line,
                'data' => array_combine($headers, array_pad(array_slice($cells, 0, count($headers)), count($headers), '')),
                'errors' => $hasExtraCells ? ['A linha tem mais colunas que o cabeçalho.'] : [],
            ];
        }

        if ($rows === []) {
            throw $this->invalid('O arquivo não tem registros.');
        }

        return $rows;
    }

    /**
     * @param  array<int, string|null>  $cells
     * @return list<string>
     */
    protected function headers(array $cells): array
    {
        $headers = array_values(array_map(fn (?string $cell): string => Str::snake(Str::lower(Str::ascii(trim((string) $cell)))), $cells));

        while ($headers !== [] && end($headers) === '') {
            array_pop($headers);
        }

        if (! in_array('nome', $headers, true)) {
            throw $this->invalid('A coluna "nome" é obrigatória. Use o modelo de arquivo.');
        }

        if (count($headers) !== count(array_unique($headers))) {
            throw $this->invalid('O cabeçalho tem colunas repetidas.');
        }

        $unknown = array_diff($headers, static::COLUMNS, static::READ_ONLY_COLUMNS);

        if ($unknown !== []) {
            throw $this->invalid('Colunas desconhecidas: '.Str::limit(implode(', ', $unknown), 200).'.');
        }

        return $headers;
    }

    protected function delimiter(string $contents): string
    {
        $header = (string) strtok($contents, "\r\n");

        return substr_count($header, ',') > substr_count($header, self::DELIMITER) ? ',' : self::DELIMITER;
    }

    protected function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['file' => $message]);
    }
}
