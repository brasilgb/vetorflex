<?php

namespace App\Modules\Core\Support;

/**
 * Neutraliza fórmulas em planilhas (CSV injection) sem perder o valor original na reimportação.
 */
class CsvCell
{
    private const FORMULA_PATTERN = '/^[\s\x00]*[=+\-@\t\r\n]/u';

    public static function escape(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = (string) $value;

        return preg_match(self::FORMULA_PATTERN, $text) === 1 ? "'".$text : $text;
    }

    public static function unescape(?string $value): ?string
    {
        return $value !== null && str_starts_with($value, "'") && preg_match(self::FORMULA_PATTERN, substr($value, 1)) === 1
            ? substr($value, 1)
            : $value;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return list<string|null>
     */
    public static function escapeRow(array $row): array
    {
        return array_map(self::escape(...), array_values($row));
    }
}
