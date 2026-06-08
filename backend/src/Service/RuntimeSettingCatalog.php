<?php

namespace App\Service;

use App\Entity\AiModelCostReference;
use App\Repository\AiModelCostReferenceRepository;

final class RuntimeSettingCatalog
{
    public function __construct(
        private readonly ?AiModelCostReferenceRepository $aiModelCosts = null,
    ) {
    }

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
                'options' => $this->openaiModelOptionsForValue(),
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
                'key' => 'audio_gateway_bearer_token',
                'label' => 'Token Bearer del audio-gateway',
                'description' => 'Token usado por sales-agent para llamar al endpoint interno del audio-gateway. Se guarda cifrado y no se muestra de nuevo una vez guardado.',
                'inputType' => 'password',
                'defaultValue' => '',
                'group' => 'audio',
                'options' => [],
                'secret' => true,
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
            [
                'key' => 'audio_max_bytes',
                'label' => 'Tamaño máximo de audio (bytes)',
                'description' => 'Límite máximo descargable para audios entrantes.',
                'inputType' => 'number',
                'defaultValue' => (string) (25 * 1024 * 1024),
                'group' => 'audio',
                'options' => [],
                'secret' => false,
                'min' => 1,
            ],
            [
                'key' => 'audio_transcription_enabled',
                'label' => 'Transcripción de audio',
                'description' => 'Activa o desactiva la transcripción automática de audios WhatsApp.',
                'inputType' => 'select',
                'defaultValue' => '1',
                'group' => 'audio',
                'options' => [
                    ['value' => '1', 'label' => 'Activo'],
                    ['value' => '0', 'label' => 'Inactivo'],
                ],
                'secret' => false,
            ],
            [
                'key' => 'audio_transcription_provider',
                'label' => 'Proveedor de transcripción',
                'description' => 'Proveedor usado para la transcripción de audio.',
                'inputType' => 'select',
                'defaultValue' => 'openai',
                'group' => 'audio',
                'options' => [
                    ['value' => 'openai', 'label' => 'OpenAI'],
                ],
                'secret' => false,
            ],
            [
                'key' => 'openai_transcription_model',
                'label' => 'Modelo de transcripción OpenAI',
                'description' => 'Modelo empleado para audio. No se comparte con el chat.',
                'inputType' => 'text',
                'defaultValue' => 'gpt-4o-mini-transcribe',
                'group' => 'audio',
                'options' => [],
                'secret' => false,
            ],
            [
                'key' => 'audio_transcription_cost_unit',
                'label' => 'Unidad de coste de audio',
                'description' => 'Unidad usada para estimar el coste de transcripción.',
                'inputType' => 'select',
                'defaultValue' => 'minute',
                'group' => 'audio',
                'options' => [
                    ['value' => 'minute', 'label' => 'Por minuto'],
                    ['value' => 'second', 'label' => 'Por segundo'],
                ],
                'secret' => false,
            ],
            [
                'key' => 'audio_transcription_cost_per_unit_eur',
                'label' => 'Coste de transcripción por unidad (€)',
                'description' => 'Coste estimado para la unidad anterior. Soporta decimales.',
                'inputType' => 'text',
                'defaultValue' => '0.02',
                'group' => 'audio',
                'options' => [],
                'secret' => false,
            ],
            [
                'key' => 'audio_llm_followup_reserve_cost_eur',
                'label' => 'Reserva mínima para respuesta LLM (€)',
                'description' => 'Reserva mínima que se mantiene disponible para una respuesta LLM posterior a la transcripción.',
                'inputType' => 'text',
                'defaultValue' => '0.01',
                'group' => 'audio',
                'options' => [],
                'secret' => false,
            ],
            [
                'key' => 'audio_transcription_currency',
                'label' => 'Moneda de transcripción',
                'description' => 'Moneda usada para la referencia de coste de audio.',
                'inputType' => 'text',
                'defaultValue' => 'EUR',
                'group' => 'audio',
                'options' => [],
                'secret' => false,
            ],
            [
                'key' => 'audio_transcription_notes',
                'label' => 'Notas de transcripción',
                'description' => 'Notas internas opcionales sobre la configuración de audio.',
                'inputType' => 'textarea',
                'defaultValue' => '',
                'group' => 'audio',
                'options' => [],
                'secret' => false,
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
            ['value' => 'heuristic', 'label' => 'Heurística / Sin LLM'],
            ['value' => 'openai', 'label' => 'OpenAI'],
            ['value' => 'ollama', 'label' => 'Ollama'],
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function openaiModelOptionsForValue(string $currentModel = ''): array
    {
        $options = [];

        foreach ($this->activeOpenAiReferences() as $reference) {
            $options[] = [
                'value' => $reference->getModel(),
                'label' => $this->formatOpenAiModelLabel($reference),
            ];
        }

        if ($currentModel !== '' && !$this->hasOptionValue($options, $currentModel)) {
            $options[] = [
                'value' => $currentModel,
                'label' => $currentModel,
            ];
        }

        return $options;
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

    /**
     * @return array<int, AiModelCostReference>
     */
    private function activeOpenAiReferences(): array
    {
        if (!$this->aiModelCosts instanceof AiModelCostReferenceRepository) {
            return [];
        }

        return $this->aiModelCosts->findActiveByUsageType(AiModelCostReference::USAGE_TYPE_LLM_CHAT);
    }

    private function formatOpenAiModelLabel(AiModelCostReference $reference): string
    {
        return sprintf(
            '%s (%s/%s/%s %s)',
            $reference->getModel(),
            $this->formatDecimal($reference->getInputCostPerMillion()),
            $this->formatDecimal($reference->getCachedInputCostPerMillion()),
            $this->formatDecimal($reference->getOutputCostPerMillion()),
            $reference->getCurrency()
        );
    }

    private function formatDecimal(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        $formatted = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * @param array<int, array{value: string, label: string}> $options
     */
    private function hasOptionValue(array $options, string $value): bool
    {
        foreach ($options as $option) {
            if (($option['value'] ?? '') === $value) {
                return true;
            }
        }

        return false;
    }

}
