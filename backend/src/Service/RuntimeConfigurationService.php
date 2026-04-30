<?php

namespace App\Service;

class RuntimeConfigurationService
{
    public function __construct(
        private readonly RuntimeSettingsManager $settingsManager,
        private readonly RuntimeSettingsStatePresenter $presenter,
        private readonly RuntimeConnectivityTesterRegistry $testers,
    ) {
    }

    /**
     * @param array<string, mixed> $submitted
     * @param array<string, RuntimeConnectivityTestResult> $testResults
     *
     * @return array<string, mixed>
     */
    public function pageData(array $submitted = [], array $testResults = []): array
    {
        $formState = $this->settingsManager->formState();
        $values = $this->settingsManager->resolveFromSubmission($submitted);

        foreach ($submitted as $key => $value) {
            if (!array_key_exists($key, $formState) || $formState[$key]['secret']) {
                continue;
            }

            $formState[$key]['value'] = is_string($value) ? trim($value) : '';
        }

        return [
            'formState' => $formState,
            'values' => $values,
            'status' => $this->presenter->present($values, $testResults),
        ];
    }

    /**
     * @param array<string, mixed> $submitted
     *
     * @return array{saved: list<string>, unchanged: list<string>}
     */
    public function save(array $submitted): array
    {
        return $this->settingsManager->save($submitted);
    }

    /**
     * @param array<string, mixed> $submitted
     *
     * @return list<string>
     */
    public function validate(array $submitted): array
    {
        return $this->settingsManager->validate($submitted);
    }

    /**
     * @param array<string, mixed> $submitted
     */
    public function test(string $target, array $submitted): RuntimeConnectivityTestResult
    {
        $resolved = $this->settingsManager->resolveFromSubmission($submitted);

        return $this->testers->test($target, $resolved);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $snapshot = $this->settingsManager->snapshot();
        $snapshot['status'] = $this->presenter->present($snapshot['values']);

        return $snapshot;
    }
}
