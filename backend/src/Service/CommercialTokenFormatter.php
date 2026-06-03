<?php

namespace App\Service;

final class CommercialTokenFormatter
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function millionOptions(array $millionValues): array
    {
        $options = [];
        foreach ($millionValues as $millionValue) {
            $tokens = (int) round(((float) $millionValue) * 1_000_000);
            $options[] = [
                'value' => (string) $tokens,
                'label' => self::formatCommercialMillionTokens($tokens),
            ];
        }

        return $options;
    }

    /**
     * @return array{primary: string, secondary: string|null}
     */
    public static function formatCommercialDual(?int $tokens): array
    {
        if ($tokens === null) {
            return [
                'primary' => '—',
                'secondary' => null,
            ];
        }

        return [
            'primary' => self::formatCommercialMillionTokens($tokens),
            'secondary' => self::formatRawTokens($tokens),
        ];
    }

    /**
     * @return array{primary: string, secondary: string|null}
     */
    public static function formatUsageDual(?int $tokens): array
    {
        if ($tokens === null) {
            return [
                'primary' => '—',
                'secondary' => null,
            ];
        }

        $commercial = self::formatCommercialMillionTokens($tokens);

        return [
            'primary' => self::formatRawTokens($tokens),
            'secondary' => $commercial !== self::formatRawTokens($tokens) ? $commercial : null,
        ];
    }

    public static function formatCommercialMillionTokens(?int $tokens): string
    {
        if ($tokens === null) {
            return '—';
        }

        $millions = max(0, $tokens) / 1_000_000;
        if ($millions === 0.0) {
            return '0M';
        }

        $formatted = rtrim(rtrim(number_format($millions, 6, ',', ''), '0'), ',');

        return $formatted === '' ? '0M' : $formatted.'M';
    }

    public static function formatRawTokens(?int $tokens): string
    {
        if ($tokens === null) {
            return '—';
        }

        $suffix = $tokens === 1 ? 'token real' : 'tokens reales';

        return number_format(max(0, $tokens), 0, ',', '.').' '.$suffix;
    }
}
