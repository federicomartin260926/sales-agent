<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TenantDraftAssistantService
{
    public function __construct(
        private readonly RuntimeConfigurationService $runtimeConfigurationService,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @param array<int, array{role?: mixed, content?: mixed}> $conversation
     * @param array<string, mixed> $currentFormValues
     *
     * @return array{answer: string, status: string, questions: list<string>, draft: array<string, mixed>}
     */
    public function buildResponse(array $conversation, string $currentMessage, array $currentFormValues): array
    {
        $normalizedForm = $this->normalizeFormValues($currentFormValues);
        $history = $this->normalizeConversation($conversation);
        $message = $this->cleanText($currentMessage, 2000);
        $settings = $this->runtimeConfigurationService->snapshot()['values'] ?? [];

        if ($this->canUseOpenAi($settings)) {
            try {
                $payload = $this->requestOpenAi($settings, $history, $message, $normalizedForm);
                return $this->normalizeResponse($payload, $normalizedForm, $history, $message);
            } catch (\Throwable) {
                // Fallback heurístico si el proveedor falla o devuelve un payload inválido.
            }
        }

        return $this->heuristicResponse($normalizedForm, $history, $message);
    }

    /**
     * @param array<string, string> $settings
     * @param list<array{role: string, content: string}> $history
     * @param array<string, string> $form
     *
     * @return array<string, mixed>
     */
    private function requestOpenAi(array $settings, array $history, string $message, array $form): array
    {
        $baseUrl = trim($settings['openai_base_url'] ?? '');
        $apiKey = trim($settings['openai_api_key'] ?? '');
        $model = trim($settings['openai_model'] ?? '');
        $timeout = max(1, (int) ($settings['openai_timeout_seconds'] ?? 15));

        if ($baseUrl === '' || $apiKey === '' || $model === '') {
            throw new \RuntimeException('OpenAI configuration is incomplete.');
        }

        $prompt = [
            'current_message' => $message,
            'current_form_values' => $form,
            'conversation' => $history,
        ];

        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->systemPrompt(),
                ],
                [
                    'role' => 'user',
                    'content' => json_encode($prompt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
        ];

        $response = $this->httpClient->request('POST', rtrim($baseUrl, '/').'/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
            'timeout' => (float) $timeout,
        ]);

        $response->getStatusCode();
        $data = $response->toArray(false);
        $content = $this->cleanText($this->extractOpenAiContent($data), 12000);
        if ($content === '') {
            throw new \RuntimeException('OpenAI response did not include content.');
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('OpenAI response was not valid JSON.');
        }

        return $decoded;
    }

    /**
     * @param array<string, string> $form
     * @param list<array{role: string, content: string}> $history
     *
     * @return array{answer: string, status: string, questions: list<string>, draft: array<string, mixed>}
     */
    private function heuristicResponse(array $form, array $history, string $message): array
    {
        $draft = $this->buildBaseDraft($form);
        $missingQuestion = $this->nextQuestion($draft);

        if ($missingQuestion !== null) {
            return [
                'answer' => $missingQuestion,
                'status' => 'asking',
                'questions' => [$missingQuestion],
                'draft' => $draft,
            ];
        }

        return [
            'answer' => 'Ya tengo un borrador inicial. Revísalo en la ficha y pulsa "Aplicar a la ficha" si quieres usarlo.',
            'status' => 'ready',
            'questions' => [],
            'draft' => $draft,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $form
     * @param list<array{role: string, content: string}> $history
     *
     * @return array{answer: string, status: string, questions: list<string>, draft: array<string, mixed>}
     */
    private function normalizeResponse(array $payload, array $form, array $history, string $message): array
    {
        $status = strtolower($this->cleanText((string) ($payload['status'] ?? 'asking'), 32));
        if (!in_array($status, ['asking', 'ready'], true)) {
            $status = 'asking';
        }

        $answer = $this->cleanText((string) ($payload['answer'] ?? ''), 2000);
        if ($answer === '') {
            $answer = $status === 'ready'
                ? 'Ya tengo un borrador inicial. Revísalo en la ficha y pulsa "Aplicar a la ficha" si quieres usarlo.'
                : 'Necesito un dato más para completar la ficha.';
        }

        $questions = [];
        $rawQuestions = $payload['questions'] ?? [];
        if (is_array($rawQuestions)) {
            foreach ($rawQuestions as $question) {
                $cleanQuestion = $this->cleanText((string) $question, 240);
                if ($cleanQuestion !== '') {
                    $questions[] = $cleanQuestion;
                }
            }
        }
        $questions = array_values(array_unique(array_slice($questions, 0, 3)));

        $draft = $this->buildBaseDraft($form);
        if (isset($payload['draft']) && is_array($payload['draft'])) {
            $draft = $this->mergeDraft($draft, $payload['draft']);
        }

        if ($status === 'ready' && $this->nextQuestion($draft) !== null) {
            $status = 'asking';
        }

        if ($status === 'ready') {
            $questions = [];
        } elseif ($questions === []) {
            $nextQuestion = $this->nextQuestion($draft);
            if ($nextQuestion !== null) {
                $questions = [$nextQuestion];
            }
        }

        return [
            'answer' => $answer,
            'status' => $status,
            'questions' => $questions,
            'draft' => $draft,
        ];
    }

    /**
     * @param array<string, string> $form
     *
     * @return array<string, mixed>
     */
    private function buildBaseDraft(array $form): array
    {
        $name = $form['name'] ?? '';
        $slug = $form['slug'] ?? '';

        if ($slug === '' && $name !== '') {
            $slug = $this->generateSlug($name);
        }

        return [
            'name' => $name,
            'slug' => $slug,
            'tone' => $form['tone'] !== '' ? $form['tone'] : 'cercano, profesional y directo',
            'whatsappPhoneNumberId' => $form['whatsappPhoneNumberId'] ?? '',
            'whatsappPublicPhone' => $form['whatsappPublicPhone'] ?? '',
            'isActive' => (bool) ($form['isActive'] ?? true),
            'businessContext' => $form['businessContext'] ?? '',
            'salesPolicyWelcome' => $form['positioning'] ?? '',
            'salesPolicyQualification' => $form['qualificationFocus'] ?? '',
            'salesPolicyHandoff' => $form['handoffRules'] ?? '',
            'salesPolicyLimits' => $form['salesBoundaries'] ?? '',
            'salesPolicyNotes' => $form['notes'] ?? '',
        ];
    }

    /**
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $payloadDraft
     *
     * @return array<string, mixed>
     */
    private function mergeDraft(array $draft, array $payloadDraft): array
    {
        $textKeys = [
            'name',
            'slug',
            'tone',
            'businessContext',
            'salesPolicyWelcome',
            'salesPolicyQualification',
            'salesPolicyHandoff',
            'salesPolicyLimits',
            'salesPolicyNotes',
        ];

        foreach ($textKeys as $key) {
            if (!array_key_exists($key, $payloadDraft)) {
                continue;
            }

            $value = $this->cleanText((string) $payloadDraft[$key], 5000);
            if ($value !== '') {
                $draft[$key] = $value;
            }
        }

        if (array_key_exists('isActive', $payloadDraft)) {
            $draft['isActive'] = (bool) $payloadDraft['isActive'];
        }

        if (($draft['slug'] ?? '') === '' && ($draft['name'] ?? '') !== '') {
            $draft['slug'] = $this->generateSlug((string) $draft['name']);
        }

        if (array_key_exists('whatsappPhoneNumberId', $payloadDraft)) {
            $candidate = $this->cleanText((string) $payloadDraft['whatsappPhoneNumberId'], 255);
            if ($draft['whatsappPhoneNumberId'] === '' && $candidate !== '') {
                // No inventar phoneNumberId.
                $candidate = '';
            }
            if ($candidate !== '') {
                $draft['whatsappPhoneNumberId'] = $candidate;
            }
        }

        if (array_key_exists('whatsappPublicPhone', $payloadDraft)) {
            $candidate = $this->cleanText((string) $payloadDraft['whatsappPublicPhone'], 50);
            if ($draft['whatsappPublicPhone'] === '' && $candidate !== '') {
                // No inventar números de WhatsApp.
                $candidate = '';
            }
            if ($candidate !== '') {
                $draft['whatsappPublicPhone'] = $candidate;
            }
        }

        return $draft;
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function nextQuestion(array $draft): ?string
    {
        if ($draft['name'] === '') {
            return '¿Qué nombre quieres darle al negocio?';
        }

        if ($draft['businessContext'] === '') {
            return '¿Qué vende, a quién y en qué zona o mercado opera?';
        }

        if ($draft['salesPolicyWelcome'] === '') {
            return '¿Cómo quieres que se presente el negocio y cuál es su enfoque comercial?';
        }

        if ($draft['salesPolicyQualification'] === '') {
            return '¿Qué datos debe pedir el agente para cualificar bien la oportunidad?';
        }

        if ($draft['salesPolicyHandoff'] === '') {
            return '¿Cuándo debe derivar el caso a una persona del equipo?';
        }

        if ($draft['salesPolicyLimits'] === '') {
            return '¿Qué no debe prometer, cerrar o confirmar el agente?';
        }

        return null;
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function normalizeConversation(array $conversation): array
    {
        $normalized = [];
        foreach ($conversation as $item) {
            if (!is_array($item)) {
                continue;
            }

            $role = $this->cleanText((string) ($item['role'] ?? ''), 24);
            $content = $this->cleanText((string) ($item['content'] ?? ''), 2000);
            if ($role === '' || $content === '') {
                continue;
            }

            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $normalized[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return array_slice($normalized, -12);
    }

    /**
     * @return array<string, string>
     */
    private function normalizeFormValues(array $values): array
    {
        return [
            'name' => $this->cleanText((string) ($values['name'] ?? ''), 255),
            'slug' => $this->cleanText((string) ($values['slug'] ?? ''), 180),
            'tone' => $this->cleanText((string) ($values['tone'] ?? ''), 120),
            'whatsappPhoneNumberId' => $this->cleanText((string) ($values['whatsappPhoneNumberId'] ?? ''), 255),
            'whatsappPublicPhone' => $this->cleanText((string) ($values['whatsappPublicPhone'] ?? ''), 50),
            'businessContext' => $this->cleanText((string) ($values['businessContext'] ?? ''), 5000),
            'positioning' => $this->cleanText((string) ($values['positioning'] ?? ''), 2000),
            'qualificationFocus' => $this->cleanText((string) ($values['qualificationFocus'] ?? ''), 2000),
            'handoffRules' => $this->cleanText((string) ($values['handoffRules'] ?? ''), 2000),
            'salesBoundaries' => $this->cleanText((string) ($values['salesBoundaries'] ?? ''), 2000),
            'notes' => $this->cleanText((string) ($values['notes'] ?? ''), 2000),
            'isActive' => array_key_exists('isActive', $values) ? (bool) $values['isActive'] : true,
        ];
    }

    private function canUseOpenAi(array $settings): bool
    {
        $profile = strtolower($this->cleanText((string) ($settings['llm_default_profile'] ?? 'auto'), 32));
        if ($profile === 'heuristic' || $profile === 'ollama') {
            return false;
        }

        return $this->cleanText((string) ($settings['openai_base_url'] ?? ''), 2048) !== ''
            && $this->cleanText((string) ($settings['openai_api_key'] ?? ''), 2048) !== ''
            && $this->cleanText((string) ($settings['openai_model'] ?? ''), 120) !== '';
    }

    private function systemPrompt(): string
    {
        return 'Eres un asistente de configuración de Sales Agent. Tu objetivo es ayudar al usuario a completar la ficha de un nuevo negocio. Haz preguntas breves y prácticas. No inventes datos técnicos. Cuando tengas suficiente información, devuelve un borrador estructurado para rellenar el formulario. El borrador debe ser claro, comercial y útil para que un agente IA pueda representar correctamente el negocio. Nunca inventes phoneNumberId ni números de WhatsApp. Si faltan datos, déjalos vacíos. Devuelve solo JSON válido con las claves answer, status, questions y draft.';
    }

    private function extractOpenAiContent(array $payload): string
    {
        $choices = $payload['choices'] ?? [];
        if (!is_array($choices) || $choices === []) {
            return '';
        }

        $firstChoice = $choices[0] ?? [];
        if (!is_array($firstChoice)) {
            return '';
        }

        $message = $firstChoice['message'] ?? [];
        if (!is_array($message)) {
            return '';
        }

        return is_string($message['content'] ?? null) ? $message['content'] : '';
    }

    private function cleanText(string $value, int $maxLength): string
    {
        $value = trim(strip_tags($value));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        if (mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }

        return $value;
    }

    private function generateSlug(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii === false) {
            $ascii = $value;
        }

        $slug = strtolower($ascii);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'negocio';
    }
}
