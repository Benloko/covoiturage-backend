<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class BeninPhoneNumber implements ValidationRule
{
    /**
     * @var array<string, string>
     */
    private const NETWORK_LABELS = [
        'mtn_momo' => 'MTN Mobile Money',
        'moov_money' => 'Moov Money',
        'celtiis_cash' => 'Celtiis Cash',
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private const NETWORK_PREFIXES = [
        'mtn_momo' => [
            '0142', '0146', '0150', '0151', '0152', '0153', '0154', '0156', '0157', '0159',
            '0161', '0162', '0166', '0167', '0169', '0190', '0191', '0196', '0197',
        ],
        'moov_money' => [
            '0145', '0155', '0158', '0160', '0163', '0164', '0165', '0168', '0194', '0195',
            '0198', '0199',
        ],
        'celtiis_cash' => [
            '0120', '0121', '0122', '0123', '0124', '0128', '0129', '0140', '0141', '0143',
            '0144', '0147', '0148', '0149', '0192', '0193',
        ],
    ];

    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', trim($value));

        if (! is_string($normalized) || $normalized === '') {
            return null;
        }

        if (strlen($normalized) !== 10) {
            return null;
        }

        return $normalized;
    }

    public static function normalizeAny(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', trim($value));

        if (! is_string($normalized) || $normalized === '') {
            return null;
        }

        if (str_starts_with($normalized, '00229') && strlen($normalized) === 15) {
            $normalized = substr($normalized, 5);
        } elseif (str_starts_with($normalized, '229') && strlen($normalized) === 13) {
            $normalized = substr($normalized, 3);
        }

        if (strlen($normalized) !== 10) {
            return null;
        }

        return $normalized;
    }

    public static function detectNetwork(?string $value, bool $allowCountryCode = false): ?string
    {
        $normalized = $allowCountryCode
            ? self::normalizeAny($value)
            : self::normalize($value);

        if (! $normalized) {
            return null;
        }

        $prefix = substr($normalized, 0, 4);

        foreach (self::NETWORK_PREFIXES as $network => $prefixes) {
            if (in_array($prefix, $prefixes, true)) {
                return $network;
            }
        }

        return null;
    }

    public static function networkLabel(string $network): string
    {
        return self::NETWORK_LABELS[$network] ?? $network;
    }

    /**
     * @return array<int, string>
     */
    public static function supportedPrefixes(): array
    {
        return collect(self::NETWORK_PREFIXES)
            ->flatten()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function networkLabels(): array
    {
        return self::NETWORK_LABELS;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Le numero doit contenir exactement 10 chiffres.');

            return;
        }

        $normalized = self::normalize($value);

        if (! $normalized) {
            $fail('Le numero doit contenir exactement 10 chiffres.');

            return;
        }

        if (! self::detectNetwork($normalized)) {
            $fail('Le numero doit commencer par un prefixe mobile valide au Benin.');
        }
    }
}
