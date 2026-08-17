<?php

namespace App\Support;

class BrazilianDocument
{
    public const CPF = 'CPF';

    public const CNPJ = 'CNPJ';

    public static function digits(?string $value): string
    {
        return preg_replace('/\D/', '', $value ?? '') ?? '';
    }

    public static function type(?string $value): ?string
    {
        return match (strlen(self::digits($value))) {
            11 => self::CPF,
            14 => self::CNPJ,
            default => null,
        };
    }

    public static function isValid(?string $value): bool
    {
        return match (self::type($value)) {
            self::CPF => self::isValidCpf($value),
            self::CNPJ => self::isValidCnpj($value),
            default => false,
        };
    }

    public static function isValidCpf(?string $value): bool
    {
        $digits = self::digits($value);

        if (strlen($digits) !== 11 || preg_match('/^(\d)\1{10}$/', $digits)) {
            return false;
        }

        for ($position = 9; $position <= 10; $position++) {
            $sum = 0;
            for ($index = 0; $index < $position; $index++) {
                $sum += (int) $digits[$index] * (($position + 1) - $index);
            }

            $digit = 11 - ($sum % 11);
            if ($digit >= 10) {
                $digit = 0;
            }

            if ($digit !== (int) $digits[$position]) {
                return false;
            }
        }

        return true;
    }

    public static function isValidCnpj(?string $value): bool
    {
        $digits = self::digits($value);

        if (strlen($digits) !== 14 || preg_match('/^(\d)\1{13}$/', $digits)) {
            return false;
        }

        $weights = [
            [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2],
            [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2],
        ];

        foreach ($weights as $offset => $weightSet) {
            $sum = 0;
            foreach ($weightSet as $index => $weight) {
                $sum += (int) $digits[$index] * $weight;
            }

            $remainder = $sum % 11;
            $digit = $remainder < 2 ? 0 : 11 - $remainder;
            if ($digit !== (int) $digits[12 + $offset]) {
                return false;
            }
        }

        return true;
    }
}
