<?php

namespace App\Modules\Parties\Support;

class BrazilianDocument
{
    public static function normalize(?string $value): ?string
    {
        $normalized = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', (string) $value));

        return $normalized === '' ? null : $normalized;
    }

    public static function isCpf(string $document): bool
    {
        if (preg_match('/^\d{11}$/', $document) !== 1 || preg_match('/^(\d)\1{10}$/', $document) === 1) {
            return false;
        }

        return substr($document, 9) === self::cpfCheckDigits(substr($document, 0, 9));
    }

    /**
     * Aceita o CNPJ numérico e o alfanumérico (12 posições alfanuméricas e 2 dígitos verificadores).
     */
    public static function isCnpj(string $document): bool
    {
        if (preg_match('/^[0-9A-Z]{12}\d{2}$/', $document) !== 1 || preg_match('/^(.)\1{13}$/', $document) === 1) {
            return false;
        }

        return substr($document, 12) === self::cnpjCheckDigits(substr($document, 0, 12));
    }

    public static function cpfCheckDigits(string $base): string
    {
        $digits = '';

        foreach ([10, 11] as $weight) {
            $sum = 0;

            foreach (str_split($base.$digits) as $index => $digit) {
                $sum += (int) $digit * ($weight - $index);
            }

            $remainder = $sum % 11;
            $digits .= $remainder < 2 ? '0' : (string) (11 - $remainder);
        }

        return $digits;
    }

    public static function cnpjCheckDigits(string $base): string
    {
        $digits = '';

        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $weights) {
            $sum = 0;

            foreach (str_split($base.$digits) as $index => $character) {
                $sum += (ord($character) - 48) * $weights[$index];
            }

            $remainder = $sum % 11;
            $digits .= $remainder < 2 ? '0' : (string) (11 - $remainder);
        }

        return $digits;
    }

    public static function format(?string $document): ?string
    {
        return match (strlen((string) $document)) {
            11 => preg_replace('/^(.{3})(.{3})(.{3})(.{2})$/', '$1.$2.$3-$4', $document),
            14 => preg_replace('/^(.{2})(.{3})(.{3})(.{4})(.{2})$/', '$1.$2.$3/$4-$5', $document),
            default => $document,
        };
    }

    public static function mask(?string $document): ?string
    {
        return match (strlen((string) $document)) {
            0 => null,
            11 => '***.'.substr($document, 3, 3).'.***-**',
            14 => '**.'.substr($document, 2, 3).'.***/****-**',
            default => '***',
        };
    }
}
