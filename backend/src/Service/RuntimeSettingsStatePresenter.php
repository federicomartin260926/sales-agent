<?php

namespace App\Service;

final class RuntimeSettingsStatePresenter
{
    /**
     * @param array<string, string> $settings
     * @param array<string, RuntimeConnectivityTestResult> $testResults
     *
     * @return array<string, array<string, string|null>>
     */
    public function present(array $settings, array $testResults = []): array
    {
        $openai = $testResults['openai'] ?? $this->presentOpenAI($settings);
        $ollama = $testResults['ollama'] ?? $this->presentOllama($settings);
        $audio = $testResults['audio'] ?? $this->presentAudio($settings);
        $defaultProfile = trim($settings['llm_default_profile'] ?? 'auto');
        $llm = $this->presentLlm($defaultProfile, $openai, $ollama);
        $overall = $this->presentOverall($llm, $openai, $ollama, $audio);

        return [
            'overall' => $overall,
            'llm' => $llm,
            'openai' => $openai,
            'ollama' => $ollama,
            'audio' => $audio,
        ];
    }

    /**
     * @param array<string, string> $settings
     *
     * @return array<string, string|null>
     */
    private function presentOpenAI(array $settings): array
    {
        $baseUrl = trim($settings['openai_base_url'] ?? '');
        $apiKey = trim($settings['openai_api_key'] ?? '');
        $model = trim($settings['openai_model'] ?? '');

        if ($baseUrl === '' || $apiKey === '') {
            return $this->state('blocked', 'Faltan el endpoint o la clave de OpenAI.', null);
        }

        if ($model === '') {
            return $this->state('partial', 'OpenAI está disponible, pero falta seleccionar un modelo.', null);
        }

        return $this->state('ready', 'OpenAI está configurado para uso operativo.', null);
    }

    /**
     * @param array<string, string> $settings
     *
     * @return array<string, string|null>
     */
    private function presentOllama(array $settings): array
    {
        $baseUrl = trim($settings['ollama_base_url'] ?? '');
        $model = trim($settings['ollama_model'] ?? '');

        if ($baseUrl === '') {
            return $this->state('blocked', 'Falta el endpoint de Ollama.', null);
        }

        if ($model === '') {
            return $this->state('partial', 'Ollama está disponible, pero falta seleccionar un modelo.', null);
        }

        return $this->state('ready', 'Ollama está configurado para uso operativo.', null);
    }

    /**
     * @param array<string, string> $settings
     *
     * @return array<string, string|null>
     */
    private function presentAudio(array $settings): array
    {
        $mode = trim($settings['audio_mode'] ?? 'disabled');
        $baseUrl = trim($settings['audio_gateway_base_url'] ?? '');

        if ($mode === 'disabled') {
            return $this->state('ready', 'Audio desactivado por configuración.', null);
        }

        if ($mode === 'local') {
            return $this->state('ready', 'Audio local habilitado.', null);
        }

        if ($baseUrl === '') {
            return $this->state('blocked', 'Falta el endpoint de audio-gateway.', null);
        }

        return $this->state('partial', 'Audio gateway configurado. Falta validar la conectividad manual.', null);
    }

    /**
     * @param array<string, string|null> $openai
     * @param array<string, string|null> $ollama
     *
     * @return array<string, string|null>
     */
    private function presentLlm(string $defaultProfile, array $openai, array $ollama): array
    {
        if ($defaultProfile === '') {
            return $this->state('blocked', 'No hay perfil LLM por defecto configurado.', null);
        }

        if ($defaultProfile === 'openai') {
            return $this->state($openai['status'] === 'ready' ? 'ready' : $openai['status'], $this->profileMessage('OpenAI', $openai), null);
        }

        if ($defaultProfile === 'ollama') {
            return $this->state($ollama['status'] === 'ready' ? 'ready' : $ollama['status'], $this->profileMessage('Ollama', $ollama), null);
        }

        $best = $openai['status'] === 'ready' ? $openai : $ollama;
        if (($best['status'] ?? 'blocked') === 'ready') {
            return $this->state('ready', 'El perfil automático tiene al menos un proveedor listo.', null);
        }

        if (($openai['status'] ?? 'blocked') === 'partial' || ($ollama['status'] ?? 'blocked') === 'partial') {
            return $this->state('partial', 'El perfil automático tiene proveedores parcialmente configurados.', null);
        }

        return $this->state('blocked', 'El perfil automático no tiene proveedores listos.', null);
    }

    /**
     * @param array<string, string|null> $llm
     * @param array<string, string|null> $openai
     * @param array<string, string|null> $ollama
     * @param array<string, string|null> $audio
     *
     * @return array<string, string|null>
     */
    private function presentOverall(array $llm, array $openai, array $ollama, array $audio): array
    {
        if (($llm['status'] ?? 'blocked') === 'blocked') {
            return $this->state('blocked', 'La ruta LLM por defecto está bloqueada.', null);
        }

        if (($llm['status'] ?? 'blocked') === 'partial' || ($audio['status'] ?? 'blocked') === 'partial' || ($audio['status'] ?? 'blocked') === 'blocked') {
            return $this->state('partial', 'La configuración principal funciona, pero aún hay ajustes pendientes.', null);
        }

        return $this->state('ready', 'La configuración operativa principal está lista.', null);
    }

    /**
     * @param array<string, string|null> $providerState
     *
     * @return array<string, string|null>
     */
    private function profileMessage(string $label, array $providerState): string
    {
        return sprintf('%s: %s', $label, $providerState['message'] ?? '');
    }

    /**
     * @return array<string, string|null>
     */
    private function state(string $status, string $message, ?string $endpoint): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'endpoint' => $endpoint,
        ];
    }
}
