<?php

namespace App\Service;

final class RuntimeSettingCatalog
{
    /**
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     inputType: string,
     *     defaultValue: string,
     *     group: string,
     *     options: array<int, array{value: string, label: string}>,
     *     secret: bool
     * }>
     */
    public function all(): array
    {
        return [
            [
                'key' => 'llm_default_profile',
                'label' => 'Perfil LLM por defecto',
                'description' => 'Proveedor preferido para el runtime. Si no hay respuesta válida, el sistema queda parcial o bloqueado según el resto de ajustes.',
                'inputType' => 'select',
                'defaultValue' => 'auto',
                'group' => 'llm',
                'options' => $this->profileOptions(),
                'secret' => false,
            ],
            [
                'key' => 'openai_base_url',
                'label' => 'Endpoint OpenAI',
                'description' => 'Base URL del API compatible con OpenAI. Se usa para conectividad y, más adelante, para la generación de texto.',
                'inputType' => 'text',
                'defaultValue' => 'https://api.openai.com/v1',
                'group' => 'llm',
                'options' => [],
                'secret' => false,
            ],
            [
                'key' => 'openai_model',
                'label' => 'Modelo OpenAI',
                'description' => 'Modelo operativo que usará el runtime cuando OpenAI sea el perfil activo.',
                'inputType' => 'select',
                'defaultValue' => 'gpt-4o-mini',
                'group' => 'llm',
                'options' => $this->openaiModelOptions(),
                'secret' => false,
            ],
            [
                'key' => 'openai_api_key',
                'label' => 'Clave API OpenAI',
                'description' => 'Se guarda cifrada en base de datos y no se expone en la UI ni en logs.',
                'inputType' => 'password',
                'defaultValue' => '',
                'group' => 'llm',
                'options' => [],
                'secret' => true,
            ],
            [
                'key' => 'ollama_base_url',
                'label' => 'Endpoint Ollama',
                'description' => 'Base URL del servicio Ollama local o remoto.',
                'inputType' => 'text',
                'defaultValue' => 'http://ollama:11434',
                'group' => 'llm',
                'options' => [],
                'secret' => false,
            ],
            [
                'key' => 'ollama_model',
                'label' => 'Modelo Ollama',
                'description' => 'Modelo local que usará el runtime si Ollama es el perfil activo.',
                'inputType' => 'select',
                'defaultValue' => 'llama3.1',
                'group' => 'llm',
                'options' => $this->ollamaModelOptions(),
                'secret' => false,
            ],
            [
                'key' => 'audio_mode',
                'label' => 'Modo audio',
                'description' => 'Permite desactivar audio o apuntarlo a un gateway local/remoto.',
                'inputType' => 'select',
                'defaultValue' => 'disabled',
                'group' => 'audio',
                'options' => $this->audioModeOptions(),
                'secret' => false,
            ],
            [
                'key' => 'audio_gateway_base_url',
                'label' => 'Endpoint audio-gateway',
                'description' => 'Base URL del servicio de audio cuando el modo seleccionado es gateway.',
                'inputType' => 'text',
                'defaultValue' => 'http://audio-gateway',
                'group' => 'audio',
                'options' => [],
                'secret' => false,
            ],
            [
                'key' => 'audio_gateway_token',
                'label' => 'Token audio-gateway',
                'description' => 'Token secreto para el gateway de audio; se cifra en base de datos.',
                'inputType' => 'password',
                'defaultValue' => '',
                'group' => 'audio',
                'options' => [],
                'secret' => true,
            ],
        ];
    }

    /**
     * @return array<string, array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     inputType: string,
     *     defaultValue: string,
     *     group: string,
     *     options: array<int, array{value: string, label: string}>,
     *     secret: bool
     * }>
     */
    public function indexed(): array
    {
        $indexed = [];
        foreach ($this->all() as $definition) {
            $indexed[$definition['key']] = $definition;
        }

        return $indexed;
    }

    /**
     * @return array<string, string>
     */
    public function defaults(): array
    {
        $defaults = [];
        foreach ($this->all() as $definition) {
            $defaults[$definition['key']] = $definition['defaultValue'];
        }

        return $defaults;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function profileOptions(): array
    {
        return [
            ['value' => 'auto', 'label' => 'Auto'],
            ['value' => 'openai', 'label' => 'OpenAI'],
            ['value' => 'ollama', 'label' => 'Ollama'],
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function openaiModelOptions(): array
    {
        return [
            ['value' => 'gpt-4o-mini', 'label' => 'gpt-4o-mini'],
            ['value' => 'gpt-4.1-mini', 'label' => 'gpt-4.1-mini'],
            ['value' => 'gpt-4.1', 'label' => 'gpt-4.1'],
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function ollamaModelOptions(): array
    {
        return [
            ['value' => 'llama3.1', 'label' => 'llama3.1'],
            ['value' => 'qwen2.5', 'label' => 'qwen2.5'],
            ['value' => 'mistral', 'label' => 'mistral'],
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function audioModeOptions(): array
    {
        return [
            ['value' => 'disabled', 'label' => 'Desactivado'],
            ['value' => 'local', 'label' => 'Local'],
            ['value' => 'gateway', 'label' => 'Audio gateway'],
        ];
    }
}
