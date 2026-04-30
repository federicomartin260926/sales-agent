<?php

namespace App\Service;

use App\Entity\RuntimeSetting;
use App\Repository\RuntimeSettingRepository;

final class RuntimeSettingsManager
{
    public function __construct(
        private readonly RuntimeSettingRepository $repository,
        private readonly RuntimeSettingCatalog $catalog,
        private readonly RuntimeSettingCipher $cipher,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function resolvedValues(): array
    {
        $values = $this->catalog->defaults();

        foreach ($this->catalog->indexed() as $key => $definition) {
            $setting = $this->repository->findOneByKey($key);
            if ($setting === null) {
                continue;
            }

            $values[$key] = $definition['secret']
                ? $this->cipher->decrypt($setting->getValue())
                : $setting->getValue();
        }

        return $values;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function formState(): array
    {
        $values = $this->resolvedValues();
        $state = [];

        foreach ($this->catalog->indexed() as $key => $definition) {
            $configured = $this->repository->findOneByKey($key) !== null;
            $value = $definition['secret'] ? '' : $values[$key];

            $state[$key] = [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'inputType' => $definition['inputType'],
                'group' => $definition['group'],
                'options' => $definition['options'],
                'defaultValue' => $definition['defaultValue'],
                'secret' => $definition['secret'],
                'configured' => $configured,
                'value' => $value,
            ];
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $submitted
     *
     * @return array{saved: list<string>, unchanged: list<string>}
     */
    public function save(array $submitted): array
    {
        $saved = [];
        $unchanged = [];
        $resolved = $this->resolveFromSubmission($submitted);

        foreach ($this->catalog->indexed() as $key => $definition) {
            $existing = $this->repository->findOneByKey($key);
            $rawValue = array_key_exists($key, $submitted) ? trim((string) $submitted[$key]) : '';

            if ($definition['secret']) {
                if ($rawValue === '') {
                    if ($existing === null) {
                        $unchanged[] = $key;
                    }

                    continue;
                }

                $value = $this->cipher->encrypt($rawValue);
            } else {
                $value = $resolved[$key] ?? $definition['defaultValue'];
            }

            if ($existing === null) {
                $existing = new RuntimeSetting($key, $value);
            } else {
                $existing->setValue($value);
            }

            $this->repository->save($existing);
            $saved[] = $key;
        }

        return [
            'saved' => $saved,
            'unchanged' => $unchanged,
        ];
    }

    /**
     * @param array<string, mixed> $submitted
     *
     * @return list<string>
     */
    public function validate(array $submitted): array
    {
        $errors = [];

        foreach ($this->catalog->indexed() as $key => $definition) {
            $rawValue = trim((string) ($submitted[$key] ?? ''));

            if ($rawValue === '') {
                continue;
            }

            if (($definition['inputType'] ?? 'text') === 'select') {
                $allowedValues = array_map(
                    static fn (array $option): string => (string) ($option['value'] ?? ''),
                    is_array($definition['options'] ?? null) ? $definition['options'] : []
                );
                if (!in_array($rawValue, $allowedValues, true)) {
                    $errors[] = sprintf('El valor de "%s" no es válido.', $definition['label']);
                }

                continue;
            }

            if (str_ends_with($key, '_base_url') && !$this->isValidHttpUrl($rawValue)) {
                $errors[] = sprintf('El endpoint "%s" debe ser una URL válida con http o https.', $definition['label']);
                continue;
            }

            if ($key === 'openai_api_key' && !$this->isLikelyOpenAiKey($rawValue)) {
                $errors[] = 'La clave API de OpenAI no parece válida.';
            }
        }

        return $errors;
    }

    private function isValidHttpUrl(string $value): bool
    {
        if ($value === '') {
            return true;
        }

        $parts = parse_url($value);
        if (!is_array($parts) || ($parts['scheme'] ?? '') === '' || ($parts['host'] ?? '') === '') {
            return false;
        }

        return in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true);
    }

    private function isLikelyOpenAiKey(string $value): bool
    {
        return preg_match('/^sk-(proj-)?[A-Za-z0-9_-]{16,}$/', $value) === 1;
    }

    /**
     * @param array<string, mixed> $submitted
     *
     * @return array<string, string>
     */
    public function resolveFromSubmission(array $submitted): array
    {
        $values = $this->resolvedValues();

        foreach ($this->catalog->indexed() as $key => $definition) {
            if (!array_key_exists($key, $submitted)) {
                continue;
            }

            $rawValue = trim((string) $submitted[$key]);
            if ($definition['secret']) {
                $values[$key] = $rawValue !== '' ? $rawValue : $values[$key];
                continue;
            }

            $values[$key] = $rawValue !== '' ? $rawValue : $definition['defaultValue'];
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $definitions = $this->catalog->indexed();
        $values = $this->resolvedValues();
        $configured = [];

        foreach ($definitions as $key => $definition) {
            $configured[$key] = $this->repository->findOneByKey($key) !== null;
        }

        return [
            'definitions' => $definitions,
            'values' => $values,
            'configured' => $configured,
        ];
    }
}
