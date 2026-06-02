<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class BeninVehiclePlate implements ValidationRule
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $compact = self::compact($value);

        if ($compact === null) {
            return null;
        }

        if (preg_match('/^RB(\d{4})([A-Z]{1,2})$/', $compact, $matches)) {
            return sprintf('RB-%s-%s', $matches[1], $matches[2]);
        }

        if (preg_match('/^([A-Z]{2})(\d{4})RB$/', $compact, $matches)) {
            return sprintf('%s-%s-RB', $matches[1], $matches[2]);
        }

        if (preg_match('/^(\d{4})RB(\d{2})$/', $compact, $matches)) {
            return sprintf('%s-RB-%s', $matches[1], $matches[2]);
        }

        return strtoupper(trim($value));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Plaque invalide. Utilisez un format beninois: RB-1234-AA, AA-1234-RB ou 1234-RB-01.');

            return;
        }

        $compact = self::compact($value);

        if (! $compact) {
            $fail('Plaque invalide. Utilisez un format beninois: RB-1234-AA, AA-1234-RB ou 1234-RB-01.');

            return;
        }

        $valid = preg_match('/^RB\d{4}[A-Z]{1,2}$/', $compact)
            || preg_match('/^[A-Z]{2}\d{4}RB$/', $compact)
            || preg_match('/^\d{4}RB\d{2}$/', $compact);

        if (! $valid) {
            $fail('Plaque invalide. Utilisez un format beninois: RB-1234-AA, AA-1234-RB ou 1234-RB-01.');
        }
    }

    private static function compact(string $value): ?string
    {
        $compact = preg_replace('/[\s-]+/', '', strtoupper(trim($value)));

        return is_string($compact) && $compact !== '' ? $compact : null;
    }
}