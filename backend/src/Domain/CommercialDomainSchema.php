<?php

namespace App\Domain;

final class CommercialDomainSchema
{
    private const TENANT_SALES_POLICY_KEYS = [
        'positioning',
        'qualificationFocus',
        'handoffRules',
        'salesBoundaries',
        'notes',
    ];

    private const PRODUCT_SALES_POLICY_KEYS = [
        'positioning',
        'pricingNotes',
        'objections',
        'handoffRules',
        'notes',
    ];

    private const PLAYBOOK_CONFIG_KEYS = [
        'objective',
        'qualificationQuestions',
        'scoring',
        'agendaRules',
        'handoffRules',
        'allowedActions',
        'notes',
    ];

    private const PLAYBOOK_SCORING_KEYS = [
        'maxScore',
        'handoffThreshold',
        'positiveSignals',
        'negativeSignals',
    ];

    public static function normalizeTenantSalesPolicy(mixed $value): array
    {
        return self::normalizeArrayValue($value);
    }

    public static function normalizeProductSalesPolicy(mixed $value): array
    {
        return self::normalizeArrayValue($value);
    }

    public static function normalizePlaybookConfig(mixed $value): array
    {
        return self::normalizeArrayValue($value);
    }

    public static function validateTenantSalesPolicy(array $policy): ?string
    {
        return self::validateTopLevelPolicy($policy, self::TENANT_SALES_POLICY_KEYS, ['positioning', 'qualificationFocus', 'handoffRules'], [], ['salesBoundaries']);
    }

    public static function validateProductSalesPolicy(array $policy): ?string
    {
        return self::validateTopLevelPolicy($policy, self::PRODUCT_SALES_POLICY_KEYS, ['positioning'], [], ['objections']);
    }

    public static function validatePlaybookConfig(array $config): ?string
    {
        $error = self::validateTopLevelPolicy(
            $config,
            self::PLAYBOOK_CONFIG_KEYS,
            ['objective'],
            ['qualificationQuestions', 'handoffRules', 'allowedActions'],
            ['agendaRules']
        );
        if ($error !== null) {
            return $error;
        }

        if (!isset($config['scoring']) || !is_array($config['scoring'])) {
            return 'playbook config.scoring must be an object';
        }

        $scoring = $config['scoring'];
        $unknownKeys = self::unknownKeys($scoring, self::PLAYBOOK_SCORING_KEYS);
        if ($unknownKeys !== []) {
            return sprintf('playbook config.scoring contains unsupported keys: %s', implode(', ', $unknownKeys));
        }

        if (!isset($scoring['maxScore']) || !is_int($scoring['maxScore']) || $scoring['maxScore'] < 1) {
            return 'playbook config.scoring.maxScore must be an integer greater than or equal to 1';
        }

        if (!isset($scoring['handoffThreshold']) || !is_int($scoring['handoffThreshold']) || $scoring['handoffThreshold'] < 0) {
            return 'playbook config.scoring.handoffThreshold must be a non-negative integer';
        }

        if ($scoring['handoffThreshold'] > $scoring['maxScore']) {
            return 'playbook config.scoring.handoffThreshold cannot be greater than maxScore';
        }

        if (($error = self::validateStringList($scoring['positiveSignals'] ?? null, false, 'playbook config.scoring.positiveSignals')) !== null) {
            return $error;
        }

        if (($error = self::validateStringList($scoring['negativeSignals'] ?? null, false, 'playbook config.scoring.negativeSignals')) !== null) {
            return $error;
        }

        return null;
    }

    public static function summarizeTenantSalesPolicy(array $policy): string
    {
        return self::summarizeByKeys($policy, ['positioning', 'qualificationFocus', 'handoffRules']);
    }

    public static function summarizeProductSalesPolicy(array $policy): string
    {
        return self::summarizeByKeys($policy, ['positioning', 'pricingNotes', 'handoffRules']);
    }

    public static function summarizePlaybookConfig(array $config): string
    {
        $parts = [];

        $objective = $config['objective'] ?? null;
        if (is_string($objective) && trim($objective) !== '') {
            $parts[] = trim($objective);
        }

        $questions = $config['qualificationQuestions'] ?? null;
        if (is_array($questions)) {
            $firstQuestion = self::firstString($questions);
            if ($firstQuestion !== null) {
                $parts[] = $firstQuestion;
            }
        }

        $scoring = $config['scoring'] ?? null;
        if (is_array($scoring) && isset($scoring['handoffThreshold'], $scoring['maxScore']) && is_int($scoring['handoffThreshold']) && is_int($scoring['maxScore'])) {
            $parts[] = sprintf('score %d/%d', $scoring['handoffThreshold'], $scoring['maxScore']);
        }

        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));

        return $parts !== [] ? implode(' · ', array_slice($parts, 0, 3)) : 'Sin resumen';
    }

    private static function validateTopLevelPolicy(array $payload, array $allowedKeys, array $requiredStringKeys, array $requiredListKeys, array $optionalListKeys): ?string
    {
        $unknownKeys = self::unknownKeys($payload, $allowedKeys);
        if ($unknownKeys !== []) {
            return sprintf('contains unsupported keys: %s', implode(', ', $unknownKeys));
        }

        foreach ($requiredStringKeys as $key) {
            if (!array_key_exists($key, $payload)) {
                return sprintf('missing required key: %s', $key);
            }

            if (!is_string($payload[$key]) || trim($payload[$key]) === '') {
                return sprintf('%s must be a non-empty string', $key);
            }
        }

        foreach ($requiredListKeys as $key) {
            if (!array_key_exists($key, $payload)) {
                return sprintf('missing required key: %s', $key);
            }

            if (($error = self::validateStringList($payload[$key], true, $key)) !== null) {
                return $error;
            }
        }

        foreach ($optionalListKeys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            if (($error = self::validateStringList($payload[$key], false, $key)) !== null) {
                return $error;
            }
        }

        return null;
    }

    private static function normalizeArrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private static function unknownKeys(array $payload, array $allowedKeys): array
    {
        return array_values(array_diff(array_keys($payload), $allowedKeys));
    }

    private static function validateStringList(mixed $value, bool $required, string $path): ?string
    {
        if ($value === null) {
            return $required ? sprintf('%s must be a non-empty array of strings', $path) : null;
        }

        if (!is_array($value)) {
            return sprintf('%s must be an array of strings', $path);
        }

        if ($value === []) {
            return $required ? sprintf('%s must not be empty', $path) : null;
        }

        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                return sprintf('%s must contain only non-empty strings', $path);
            }
        }

        return null;
    }

    private static function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private static function summarizeByKeys(array $payload, array $keys): string
    {
        $parts = [];

        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $parts[] = trim($value);
                continue;
            }

            if (is_array($value)) {
                $first = self::firstString($value);
                if ($first !== null) {
                    $parts[] = $first;
                }
            }
        }

        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));

        return $parts !== [] ? implode(' · ', array_slice($parts, 0, 3)) : 'Sin resumen';
    }
}
