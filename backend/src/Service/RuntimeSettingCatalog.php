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
     *     secret: bool,
     *     min?: int
     * }>
     */
    public function all(): array
    {
        return [
            [
                'key' => 'llm_default_profile',
                'label' => 'LLM por defecto',
                'description' => 'Perfil usado cuando un playbook no define uno propio o el perfil configurado no está disponible.',
                'inputType' => 'select',
                'defaultValue' => 'auto',
                'group' => 'llm',
                'options' => $this->profileOptions(),
                'secret' => false,
            ],
            [
                'key' => 'openai_base_url',
                'label' => 'Base URL de OpenAI',
                'description' => 'Endpoint de la API compatible con chat completions.',
                'inputType' => 'text',
                'defaultValue' => 'https://api.openai.com/v1',
                'group' => 'llm',
                'options' => [],
                'secret' => false,
            ],
            [
                'key' => 'openai_model',
                'label' => 'Modelo de OpenAI',
                'description' => 'Modelo que usa el worker cuando el perfil LLM apunta a OpenAI.',
                'inputType' => 'select',
                'defaultValue' => 'gpt-4o-mini',
                'group' => 'llm',
                'options' => $this->openaiModelOptions(),
                'secret' => false,
            ],
            [
                'key' => 'openai_api_key',
                'label' => 'API key de OpenAI',
                'description' => 'Clave usada por OpenAI y por la extracción con IA.',
                'inputType' => 'password',
                'defaultValue' => '',
                'group' => 'llm',
                'options' => [],
                'secret' => true,
            ],
            [
                'key' => 'openai_timeout_seconds',
                'label' => 'Timeout de OpenAI',
                'description' => 'Tiempo maximo de espera para respuestas de OpenAI.',
                'inputType' => 'number',
                'defaultValue' => '15',
                'group' => 'llm',
                'options' => [],
                'secret' => false,
                'min' => 1,
            ],
            [
                'key' => 'ollama_base_url',
                'label' => 'Base URL de Ollama',
                'description' => 'Endpoint interno o de red compartida para el servicio Ollama.',
                'inputType' => 'text',
                'defaultValue' => 'http://ollama-vpn-bridge:11434',
                'group' => 'llm',
                'options' => [],
                'secret' => false,
            ],
            [
                'key' => 'ollama_model',
                'label' => 'Modelo de Ollama',
                'description' => 'Modelo que usa el worker cuando el perfil LLM apunta a Ollama.',
                'inputType' => 'select',
                'defaultValue' => 'qwen2.5:7b-instruct',
                'group' => 'llm',
                'options' => $this->ollamaModelOptions(),
                'secret' => false,
            ],
            [
                'key' => 'ollama_timeout_seconds',
                'label' => 'Timeout de Ollama',
                'description' => 'Tiempo maximo de espera para respuestas de Ollama.',
                'inputType' => 'number',
                'defaultValue' => '15',
                'group' => 'llm',
                'options' => [],
                'secret' => false,
                'min' => 1,
            ],
            [
                'key' => 'audio_gateway_base_url',
                'label' => 'Base URL del audio-gateway',
                'description' => 'Endpoint interno del servicio transversal preparado para audio.',
                'inputType' => 'text',
                'defaultValue' => 'http://audio-gateway',
                'group' => 'audio',
                'options' => [],
                'secret' => false,
            ],
            [
                'key' => 'audio_timeout_seconds',
                'label' => 'Timeout de audio',
                'description' => 'Tiempo maximo de espera para respuestas del audio-gateway.',
                'inputType' => 'number',
                'defaultValue' => '15',
                'group' => 'audio',
                'options' => [],
                'secret' => false,
                'min' => 1,
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
     *     secret: bool,
     *     min?: int
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
            ['value' => 'qwen2.5:7b-instruct', 'label' => 'qwen2.5:7b-instruct'],
            ['value' => 'qwen2.5:14b-instruct', 'label' => 'qwen2.5:14b-instruct'],
            ['value' => 'llama3.2:3b', 'label' => 'llama3.2:3b'],
        ];
    }

}
