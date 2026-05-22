<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PlaybookDraftAssistantService
{
    public function __construct(
        private readonly RuntimeConfigurationService $runtimeConfigurationService,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @param array<int, array{role?: mixed, content?: mixed}> $conversation
     * @param array<string, mixed> $currentFormValues
     * @param array<string, mixed>|null $tenantContext
     * @param array<string, mixed>|null $productContext
     *
     * @return array{answer: string, status: string, questions: list<string>, draft: array<string, mixed>}
     */
    public function buildResponse(array $conversation, string $currentMessage, array $currentFormValues, ?array $tenantContext = null, ?array $productContext = null): array
    {
        $form = $this->normalizeFormValues($currentFormValues);
        $history = $this->normalizeConversation($conversation);
        $message = $this->cleanText($currentMessage, 2000);

        if ($tenantContext === null || trim($form['tenantId']) === '') {
            return $this->missingTenantResponse($form, $message, $history);
        }

        $settings = $this->runtimeConfigurationService->snapshot()['values'] ?? [];

        if ($this->canUseOpenAi($settings)) {
            try {
                $payload = $this->requestOpenAi($settings, $history, $message, $form, $tenantContext, $productContext);

                return $this->normalizeResponse($payload, $form, $tenantContext, $productContext, $history, $message);
            } catch (\Throwable) {
                // Fallback heurístico si el proveedor falla o devuelve un payload inválido.
            }
        }

        return $this->heuristicResponse($form, $tenantContext, $productContext, $history, $message);
    }

    /**
     * @param array<string, string> $settings
     * @param list<array{role: string, content: string}> $history
     * @param array<string, string> $form
     * @param array<string, mixed> $tenantContext
     * @param array<string, mixed>|null $productContext
     *
     * @return array<string, mixed>
     */
    private function requestOpenAi(array $settings, array $history, string $message, array $form, array $tenantContext, ?array $productContext): array
    {
        $baseUrl = trim($settings['openai_base_url'] ?? '');
        $apiKey = trim($settings['openai_api_key'] ?? '');
        $model = trim($settings['openai_model'] ?? '');
        $timeout = max(1, (int) ($settings['openai_timeout_seconds'] ?? 15));

        if ($baseUrl === '' || $apiKey === '' || $model === '') {
            throw new \RuntimeException('OpenAI configuration is incomplete.');
        }

        $payload = [
            'current_message' => $message,
            'current_form_values' => $form,
            'conversation' => $history,
            'tenant_context' => $tenantContext,
            'product_context' => $productContext,
        ];

        $response = $this->httpClient->request('POST', rtrim($baseUrl, '/').'/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $this->systemPrompt(),
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ],
                ],
                'temperature' => 0.2,
                'response_format' => ['type' => 'json_object'],
            ],
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
     * @param array<string, mixed> $payload
     * @param array<string, string> $form
     * @param array<string, mixed> $tenantContext
     * @param array<string, mixed>|null $productContext
     * @param list<array{role: string, content: string}> $history
     *
     * @return array{answer: string, status: string, questions: list<string>, draft: array<string, mixed>}
     */
    private function normalizeResponse(array $payload, array $form, array $tenantContext, ?array $productContext, array $history, string $message): array
    {
        $status = strtolower($this->cleanText((string) ($payload['status'] ?? 'asking'), 32));
        if (!in_array($status, ['asking', 'ready'], true)) {
            $status = 'asking';
        }

        $answer = $this->cleanText((string) ($payload['answer'] ?? ''), 2000);
        $questions = $this->normalizeQuestions($payload['questions'] ?? []);
        $draft = $this->buildBaseDraft($form, $tenantContext, $productContext, $history, $message);

        if (isset($payload['draft']) && is_array($payload['draft'])) {
            $draft = $this->mergeDraft($draft, $payload['draft']);
        }

        $draft = $this->completeDraft($draft, $tenantContext, $productContext, $history, $message);
        $ready = $this->shouldFinalize($form, $tenantContext, $productContext, $history, $message);

        if (!$ready) {
            $status = 'asking';
            $questions = $this->buildFollowUpQuestions($tenantContext, $productContext, $history, $message);
            $answer = $answer !== '' && $status === 'ready' ? $answer : $this->buildFollowUpAnswer($questions, $tenantContext, $productContext);
        } else {
            $questions = [];
            if ($answer === '') {
                $answer = $this->buildReadyAnswer();
            }
        }

        if ($answer === '') {
            $answer = $this->buildFollowUpAnswer($questions, $tenantContext, $productContext);
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
     * @param array<string, mixed>|null $tenantContext
     * @param array<string, mixed>|null $productContext
     * @param list<array{role: string, content: string}> $history
     *
     * @return array<string, mixed>
     */
    private function buildBaseDraft(array $form, ?array $tenantContext, ?array $productContext, array $history, string $message): array
    {
        $name = $form['name'] !== '' ? $form['name'] : $this->suggestName($tenantContext, $productContext, $history, $message);

        return [
            'name' => $name,
            'objective' => $form['objective'] !== '' ? $form['objective'] : $this->suggestObjective($tenantContext, $productContext, $history, $message),
            'qualificationQuestions' => $form['qualificationQuestions'] !== '' ? $form['qualificationQuestions'] : '',
            'scoring' => [],
            'maxScore' => $form['maxScore'],
            'handoffThreshold' => $form['handoffThreshold'],
            'positiveSignals' => $form['positiveSignals'],
            'negativeSignals' => $form['negativeSignals'],
            'agendaRules' => $form['agendaRules'],
            'handoffRules' => $form['handoffRules'],
            'allowedActions' => $form['allowedActions'],
            'notes' => $form['notes'],
            'isActive' => $form['isActive'],
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
        foreach (['name', 'objective', 'qualificationQuestions', 'agendaRules', 'handoffRules', 'allowedActions', 'notes'] as $key) {
            if (!array_key_exists($key, $payloadDraft)) {
                continue;
            }

            $value = $this->cleanText((string) $payloadDraft[$key], 5000);
            if ($value !== '') {
                $draft[$key] = $value;
            }
        }

        foreach (['maxScore', 'handoffThreshold', 'positiveSignals', 'negativeSignals'] as $key) {
            if (!array_key_exists($key, $payloadDraft)) {
                continue;
            }

            if (is_array($payloadDraft[$key])) {
                $lines = [];
                foreach ($payloadDraft[$key] as $line) {
                    $cleanLine = $this->cleanText((string) $line, 240);
                    if ($cleanLine !== '') {
                        $lines[] = $cleanLine;
                    }
                }

                if ($lines !== []) {
                    $draft[$key] = implode("\n", $lines);
                }

                continue;
            }

            $value = $this->cleanText((string) $payloadDraft[$key], 5000);
            if ($value !== '') {
                $draft[$key] = $value;
            }
        }

        if (isset($payloadDraft['scoring']) && is_array($payloadDraft['scoring'])) {
            $draft['scoring'] = $payloadDraft['scoring'];
            $scoring = $payloadDraft['scoring'];

            foreach (['maxScore', 'handoffThreshold'] as $numericKey) {
                if (isset($scoring[$numericKey]) && is_scalar($scoring[$numericKey])) {
                    $candidate = $this->cleanText((string) $scoring[$numericKey], 32);
                    if ($candidate !== '') {
                        $draft[$numericKey] = $candidate;
                    }
                }
            }

            foreach (['positiveSignals', 'negativeSignals'] as $listKey) {
                if (!isset($scoring[$listKey]) || !is_array($scoring[$listKey])) {
                    continue;
                }

                $lines = [];
                foreach ($scoring[$listKey] as $line) {
                    $cleanLine = $this->cleanText((string) $line, 240);
                    if ($cleanLine !== '') {
                        $lines[] = $cleanLine;
                    }
                }

                if ($lines !== []) {
                    $draft[$listKey] = implode("\n", $lines);
                }
            }
        }

        if (array_key_exists('isActive', $payloadDraft) && is_bool($payloadDraft['isActive'])) {
            $draft['isActive'] = $payloadDraft['isActive'];
        }

        return $draft;
    }

    /**
     * @param array<string, mixed> $draft
     * @param array<string, mixed>|null $tenantContext
     * @param array<string, mixed>|null $productContext
     * @param list<array{role: string, content: string}> $history
     *
     * @return array<string, mixed>
     */
    private function completeDraft(array $draft, ?array $tenantContext, ?array $productContext, array $history, string $message): array
    {
        $contextText = $this->contextText($tenantContext, $productContext);

        if (($draft['name'] ?? '') === '') {
            $draft['name'] = $this->suggestName($tenantContext, $productContext, $history, $message);
        }

        if (($draft['objective'] ?? '') === '') {
            $draft['objective'] = $this->suggestObjective($tenantContext, $productContext, $history, $message);
        }

        if (($draft['qualificationQuestions'] ?? '') === '') {
            $draft['qualificationQuestions'] = $this->suggestQualificationQuestions($tenantContext, $productContext, $history, $message);
        }

        if (($draft['handoffRules'] ?? '') === '') {
            $draft['handoffRules'] = $this->suggestHandoffRules($tenantContext, $productContext, $history, $message, $contextText);
        }

        if (($draft['allowedActions'] ?? '') === '') {
            $draft['allowedActions'] = $this->suggestAllowedActions($tenantContext, $productContext, $history, $message);
        }

        if (($draft['notes'] ?? '') === '') {
            $draft['notes'] = $this->suggestNotes($tenantContext, $productContext, $history, $message);
        }

        if (($draft['agendaRules'] ?? '') === '' && $this->containsAny($this->conversationText($history, $message), ['agenda', 'cita', 'horario', 'hora', 'lunes', 'martes', 'miércoles', 'miercoles', 'jueves', 'viernes', 'sábado', 'sabado'])) {
            $draft['agendaRules'] = $this->suggestAgendaRules($tenantContext, $productContext);
        }

        if (($draft['maxScore'] ?? '') === '' && $this->shouldSuggestScoring($history, $message)) {
            $scoring = $this->suggestScoring($tenantContext, $productContext, $history, $message);
            if ($scoring !== []) {
                $draft['scoring'] = $scoring;
                $draft['maxScore'] = (string) ($scoring['maxScore'] ?? '');
                $draft['handoffThreshold'] = (string) ($scoring['handoffThreshold'] ?? '');
                $draft['positiveSignals'] = isset($scoring['positiveSignals']) && is_array($scoring['positiveSignals']) ? implode("\n", $scoring['positiveSignals']) : '';
                $draft['negativeSignals'] = isset($scoring['negativeSignals']) && is_array($scoring['negativeSignals']) ? implode("\n", $scoring['negativeSignals']) : '';
            }
        }

        return $draft;
    }

    /**
     * @param array<string, mixed> $tenantContext
     * @param array<string, mixed>|null $productContext
     * @param list<array{role: string, content: string}> $history
     *
     * @return array{answer: string, status: string, questions: list<string>, draft: array<string, mixed>}
     */
    private function heuristicResponse(array $form, array $tenantContext, ?array $productContext, array $history, string $message): array
    {
        $draft = $this->completeDraft($this->buildBaseDraft($form, $tenantContext, $productContext, $history, $message), $tenantContext, $productContext, $history, $message);
        $ready = $this->shouldFinalize($form, $tenantContext, $productContext, $history, $message);

        if (!$ready) {
            $questions = $this->buildFollowUpQuestions($tenantContext, $productContext, $history, $message);

            return [
                'answer' => $this->buildFollowUpAnswer($questions, $tenantContext, $productContext),
                'status' => 'asking',
                'questions' => $questions,
                'draft' => $draft,
            ];
        }

        return [
            'answer' => $this->buildReadyAnswer(),
            'status' => 'ready',
            'questions' => [],
            'draft' => $draft,
        ];
    }

    /**
     * @param array<string, string> $form
     * @param list<array{role: string, content: string}> $history
     *
     * @return array{answer: string, status: string, questions: list<string>, draft: array<string, mixed>}
     */
    private function missingTenantResponse(array $form, string $message, array $history): array
    {
        $draft = [
            'name' => $form['name'],
            'objective' => $form['objective'],
            'qualificationQuestions' => $form['qualificationQuestions'],
            'scoring' => [],
            'maxScore' => $form['maxScore'],
            'handoffThreshold' => $form['handoffThreshold'],
            'positiveSignals' => $form['positiveSignals'],
            'negativeSignals' => $form['negativeSignals'],
            'agendaRules' => $form['agendaRules'],
            'handoffRules' => $form['handoffRules'],
            'allowedActions' => $form['allowedActions'],
            'notes' => $form['notes'],
            'isActive' => $form['isActive'],
        ];

        return [
            'answer' => 'Selecciona primero un negocio para que la guía use su política general como base.',
            'status' => 'asking',
            'questions' => ['Selecciona primero un negocio para usar su política general como base.'],
            'draft' => $draft,
        ];
    }

    /**
     * @param array<string, mixed>|null $tenantContext
     * @param array<string, mixed>|null $productContext
     * @param list<array{role: string, content: string}> $history
     *
     * @return list<string>
     */
    private function buildFollowUpQuestions(?array $tenantContext, ?array $productContext, array $history, string $message): array
    {
        $productName = $this->contextName($productContext);
        $coverage = $this->coverageState($tenantContext, $productContext, $history, $message);
        $questions = [];

        if (empty($coverage['objective'])) {
            $questions[] = '¿Qué debe conseguir el agente con esta guía concreta?';
        }

        if (empty($coverage['scenario'])) {
            $questions[] = $productName !== ''
                ? sprintf('¿La guía es para %s o para otro caso concreto?', $productName)
                : '¿En qué situación concreta se usará: producto, campaña, canal o caso especial?';
        }

        if (empty($coverage['audience'])) {
            $questions[] = '¿Qué tipo de cliente o lead quieres captar o atender?';
        }

        if (empty($coverage['qualification'])) {
            $questions[] = '¿Qué debe preguntar el agente antes de priorizar, proponer cita o avanzar?';
        }

        if (empty($coverage['action'])) {
            $questions[] = '¿Qué debe hacer exactamente el agente con esta guía?';
        }

        if (empty($coverage['handoff'])) {
            $questions[] = '¿Cuándo debe derivar a una persona o no insistir más?';
        }

        return array_values(array_slice($questions, 0, 5));
    }

    /**
     * @param list<string> $questions
     * @param array<string, mixed>|null $tenantContext
     * @param array<string, mixed>|null $productContext
     */
    private function buildFollowUpAnswer(array $questions, ?array $tenantContext, ?array $productContext): string
    {
        if ($questions === []) {
            return 'Perfecto. Dame un poco más de contexto y te preparo una propuesta útil para la guía.';
        }

        $parts = ['Perfecto. Para completar bien esta guía necesito algunos datos:'];
        foreach ($questions as $index => $question) {
            $parts[] = sprintf('%d. %s', $index + 1, $question);
        }

        return implode("\n", $parts);
    }

    private function buildReadyAnswer(): string
    {
        return 'Ya he completado una propuesta inicial en la guía. Revísala y dime si quieres ajustar el objetivo, la cualificación, el scoring, la agenda, el handoff o las notas.';
    }

    /**
     * @param array<string, string|bool> $form
     * @param list<array{role: string, content: string}> $history
     */
    private function shouldFinalize(array $form, ?array $tenantContext, ?array $productContext, array $history, string $message): bool
    {
        $coverage = $this->coverageState($tenantContext, $productContext, $history, $message);
        foreach (['objective', 'scenario', 'audience', 'qualification', 'action', 'handoff'] as $key) {
            if (empty($coverage[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed>|null $tenantContext
     * @param array<string, mixed>|null $productContext
     * @param list<array{role: string, content: string}> $history
     */
    private function suggestName(?array $tenantContext, ?array $productContext, array $history, string $message): string
    {
        $current = $this->cleanText($message, 120);
        if ($current !== '') {
            $current = $this->removeLeadingPrompt($current);
            $current = preg_replace('/[.?!]+$/u', '', $current) ?? $current;
            if ($current !== '') {
                $normalized = mb_strtolower($current);
                $campaignTopic = $this->extractCampaignTopic($current);

                if ($campaignTopic !== '') {
                    return mb_convert_case('Campaña '.$campaignTopic, MB_CASE_TITLE, 'UTF-8');
                }

                if ($this->containsAny($normalized, ['prioriz', 'lead', 'cita', 'cualific', 'calific'])) {
                    return 'Guía de cualificación y cita';
                }

                return mb_convert_case($current, MB_CASE_TITLE, 'UTF-8');
            }
        }

        $productName = $this->contextName($productContext);
        if ($productName !== '') {
            return 'Guía para '.$productName;
        }

        return 'Guía comercial específica';
    }

    /**
     * @param array<string, mixed>|null $tenantContext
     * @param array<string, mixed>|null $productContext
     * @param list<array{role: string, content: string}> $history
     */
    private function suggestObjective(?array $tenantContext, ?array $productContext, array $history, string $message): string
    {
        $normalized = mb_strtolower($this->cleanText($message, 400));
        $productName = $this->contextName($productContext);
        $campaignTopic = $this->extractCampaignTopic($message);
        $productFocus = $this->productFocusSnippet($productContext);

        if ($this->containsAny($normalized, ['campaña'])) {
            if ($campaignTopic !== '') {
                return sprintf('Gestionar una campaña concreta sobre %s, resolver dudas iniciales y orientar hacia cita o siguiente paso.', $campaignTopic);
            }

            if ($productName !== '') {
                return sprintf('Gestionar una estrategia específica para %s, resolver dudas iniciales y orientar hacia cita o siguiente paso.', $productName);
            }

            return 'Gestionar una campaña concreta, resolver dudas iniciales y orientar hacia cita o siguiente paso.';
        }

        if ($this->containsAny($normalized, ['priorice', 'priorizar', 'lead', 'cita'])) {
            if ($campaignTopic !== '') {
                return sprintf('Priorizar leads que pidan cita dentro de la campaña sobre %s y orientar la conversación hacia la reserva o el siguiente paso útil.', $campaignTopic);
            }

            if ($productName !== '') {
                return sprintf('Priorizar leads que pidan cita para %s y orientar la conversación hacia la reserva o el siguiente paso útil.', $productName);
            }

            return 'Priorizar leads que pidan cita y orientar la conversación hacia la reserva o siguiente paso útil.';
        }

        if ($productName !== '') {
            if ($productFocus !== '') {
                return sprintf('Definir una estrategia específica para %s, apoyando %s y orientando hacia cita o siguiente paso.', $productName, $productFocus);
            }

            return sprintf('Definir una estrategia específica para %s, resolver dudas iniciales y orientar hacia cita o siguiente paso.', $productName);
        }

        return $message !== '' ? $message : 'Definir una estrategia específica para esta guía comercial.';
    }

    /**
     * @param array<string, mixed>|null $tenantContext
     * @param array<string, mixed>|null $productContext
     * @param list<array{role: string, content: string}> $history
     */
    private function suggestQualificationQuestions(?array $tenantContext, ?array $productContext, array $history, string $message): string
    {
        $lines = [];
        $productName = $this->contextName($productContext);

        if ($productName !== '') {
            $lines[] = sprintf('Qué quiere conseguir la persona con %s', $productName);
        } else {
            $lines[] = 'Qué quiere conseguir la persona con esta guía';
        }

        $lines[] = 'Si busca información, cita o una valoración inicial';
        $lines[] = 'Qué disponibilidad aproximada tiene';
        $lines[] = 'Si es un caso nuevo o recurrente';
        $lines[] = 'Qué dato debe pedir el agente antes de avanzar';

        return implode("\n", array_values(array_unique($lines)));
    }

    /**
     * @param array<string, mixed>|null $tenantContext
     * @param array<string, mixed>|null $productContext
     * @param list<array{role: string, content: string}> $history
     *
     * @return array{maxScore: int, handoffThreshold: int, positiveSignals: list<string>, negativeSignals: list<string>}
     */
    private function suggestScoring(?array $tenantContext, ?array $productContext, array $history, string $message): array
    {
        return [
            'maxScore' => 10,
            'handoffThreshold' => 7,
            'positiveSignals' => [
                'Pide cita',
                'Tiene urgencia o intención clara',
                'Conoce lo que quiere',
            ],
            'negativeSignals' => [
                'Solo pide información genérica',
                'No hay interés real',
                'No acepta continuar la conversación',
            ],
        ];
    }

    private function suggestAgendaRules(?array $tenantContext, ?array $productContext): string
    {
        $productName = $this->contextName($productContext);

        if ($productName !== '') {
            return sprintf('Proponer cita solo si hay disponibilidad real para %s y la persona ha mostrado intención clara.', $productName);
        }

        return 'Proponer cita solo si hay disponibilidad real y la persona ha mostrado intención clara.';
    }

    /**
     * @param array<string, mixed>|null $tenantContext
     * @param array<string, mixed>|null $productContext
     * @param list<array{role: string, content: string}> $history
     */
    private function suggestHandoffRules(?array $tenantContext, ?array $productContext, array $history, string $message, string $contextText): string
    {
        $parts = ['Derivar a una persona cuando el caso requiera validación humana,'];

        if ($this->containsAny(mb_strtolower($contextText), ['salud', 'clínica', 'clinica', 'estética', 'estetica', 'belleza', 'piel', 'dental'])) {
            $parts[] = 'haya dudas médicas, contraindicaciones o necesidad de valoración personalizada,';
        }

        $parts[] = 'existan precios no configurados, promociones especiales o cambios complejos de cita,';
        $parts[] = 'o la persona pida hablar con una persona concreta.';

        return implode(' ', $parts);
    }

    /**
     * @param array<string, mixed>|null $tenantContext
     * @param array<string, mixed>|null $productContext
     * @param list<array{role: string, content: string}> $history
     */
    private function suggestAllowedActions(?array $tenantContext, ?array $productContext, array $history, string $message): string
    {
        $actions = [
            '- Resolver dudas generales',
            '- Recoger datos útiles para continuar',
            '- Proponer cita o siguiente paso cuando encaje',
            '- Derivar a una persona si hace falta validación humana',
        ];

        return implode("\n", $actions);
    }

    /**
     * @param array<string, mixed>|null $tenantContext
     * @param array<string, mixed>|null $productContext
     * @param list<array{role: string, content: string}> $history
     */
    private function suggestNotes(?array $tenantContext, ?array $productContext, array $history, string $message): string
    {
        $pieces = [];
        $productName = $this->contextName($productContext);

        if ($productName !== '') {
            $pieces[] = sprintf('Caso específico: %s.', $productName);
        }

        $pieces[] = 'Esta guía complementa la política general del negocio sin repetirla.';
        $pieces[] = 'Mantener respuestas breves, amables y orientadas a cita o siguiente paso.';

        return implode(' ', $pieces);
    }

    /**
     * @param list<array{role: string, content: string}> $history
     *
     * @return array{objective: bool, scenario: bool, audience: bool, qualification: bool, action: bool, handoff: bool}
     */
    private function coverageState(?array $tenantContext, ?array $productContext, array $history, string $message): array
    {
        $corpus = mb_strtolower($this->conversationText($history, $message));

        return [
            'objective' => $this->containsAny($corpus, [
                'objetivo',
                'conseguir',
                'captar',
                'resolver',
                'prioriz',
                'prioridad',
                'agendar',
                'cita',
                'derivar',
                'informar',
                'vender',
                'cualific',
                'calific',
            ]),
            'scenario' => $productContext !== null || $this->containsAny($corpus, [
                'campaña',
                'campana',
                'producto',
                'servicio',
                'canal',
                'situación',
                'situacion',
                'caso',
                'promo',
                'promoción',
                'promocion',
            ]),
            'audience' => $this->containsAny($corpus, [
                'lead',
                'leads',
                'cliente',
                'clientes',
                'prospecto',
                'prospectos',
                'mujer',
                'mujeres',
                'hombre',
                'hombres',
                'empresa',
                'empresas',
                'particular',
                'particulares',
                'paciente',
                'pacientes',
                'alumno',
                'alumnos',
                'usuario',
                'usuarios',
            ]),
            'qualification' => $this->containsAny($corpus, [
                'preguntar',
                'preguntas',
                'cualificar',
                'calificar',
                'priorizar',
                'priorice',
                'prioridad',
                'score',
                'scoring',
                'filtrar',
                'disponibilidad',
                'urgencia',
                'presupuesto',
                'interés',
                'interes',
                'nuevo',
                'recurrente',
            ]),
            'action' => $this->containsAny($corpus, [
                'responder',
                'resolver',
                'captar',
                'agendar',
                'cita',
                'orientar',
                'proponer',
                'cerrar',
                'informar',
                'vender',
                'ayudar',
                'acompañar',
                'acompanar',
            ]),
            'handoff' => $this->containsAny($corpus, [
                'derivar',
                'derivación',
                'derivacion',
                'humano',
                'persona',
                'asesor',
                'asesora',
                'mary',
                'equipo',
                'validación',
                'validacion',
                'llamar',
                'no insistir',
            ]),
        ];
    }

    /**
     * @param array<string, mixed>|null $tenantContext
     * @param array<string, mixed>|null $productContext
     */
    private function contextText(?array $tenantContext, ?array $productContext): string
    {
        $parts = [];
        foreach ([$tenantContext, $productContext] as $context) {
            if (!is_array($context)) {
                continue;
            }

            foreach (['name', 'businessContext', 'description', 'valueProposition', 'salesPolicySummary', 'tone', 'whatsappPublicPhone'] as $key) {
                $value = $this->cleanText((string) ($context[$key] ?? ''), 1200);
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
        }

        return implode(' ', array_unique($parts));
    }

    /**
     * @param array<string, mixed>|null $context
     */
    private function contextName(?array $context): string
    {
        if (!is_array($context)) {
            return '';
        }

        return $this->cleanText((string) ($context['name'] ?? ''), 255);
    }

    /**
     * @param array<string, mixed>|null $productContext
     */
    private function productFocusSnippet(?array $productContext): string
    {
        if (!is_array($productContext)) {
            return '';
        }

        foreach (['valueProposition', 'description', 'salesPolicySummary'] as $key) {
            $value = $this->cleanText((string) ($productContext[$key] ?? ''), 120);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $settings
     */
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
        return 'Eres un asistente de configuración de Guías Comerciales para Sales Agent. La política general del negocio ya existe y no debes duplicarla. Tu objetivo es ayudar al usuario a definir una estrategia específica para un producto, campaña, canal o situación comercial. Usa el negocio seleccionado como contexto base. Si hay producto seleccionado, úsalo para orientar la estrategia sin inventar datos. Si no hay negocio seleccionado, pide que seleccione uno y no inventes contexto. Haz preguntas breves y agrupadas. No marques la guía como lista hasta tener información mínima suficiente sobre: objetivo, situación o canal, tipo de cliente o lead, criterio de cualificación o prioridad, acción esperada y derivación a humano. Si la guía ya tiene valores, trátalos como base y céntrate en revisar o completar lo que falte, sin sobrescribir de forma agresiva. Completa solo campos que aporten reglas específicas. Si el usuario no necesita una regla especial, deja ese campo vacío. No inventes datos técnicos ni precios. Devuelve siempre JSON válido con answer, status, questions y draft. En el draft, usa strings para los campos del formulario.';
    }

    /**
     * @param array<string, mixed> $payload
     */
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
     * @return array<string, string|bool>
     */
    private function normalizeFormValues(array $values): array
    {
        return [
            'tenantId' => $this->cleanText((string) ($values['tenantId'] ?? ''), 255),
            'productId' => $this->cleanText((string) ($values['productId'] ?? ''), 255),
            'name' => $this->cleanText((string) ($values['name'] ?? ''), 255),
            'objective' => $this->cleanText((string) ($values['objective'] ?? ''), 2000),
            'qualificationQuestions' => $this->cleanText((string) ($values['qualificationQuestions'] ?? ''), 4000),
            'maxScore' => $this->cleanText((string) ($values['maxScore'] ?? ''), 32),
            'handoffThreshold' => $this->cleanText((string) ($values['handoffThreshold'] ?? ''), 32),
            'positiveSignals' => $this->cleanText((string) ($values['positiveSignals'] ?? ''), 4000),
            'negativeSignals' => $this->cleanText((string) ($values['negativeSignals'] ?? ''), 4000),
            'agendaRules' => $this->cleanText((string) ($values['agendaRules'] ?? ''), 4000),
            'handoffRules' => $this->cleanText((string) ($values['handoffRules'] ?? ''), 4000),
            'allowedActions' => $this->cleanText((string) ($values['allowedActions'] ?? ''), 4000),
            'notes' => $this->cleanText((string) ($values['notes'] ?? ''), 4000),
            'isActive' => array_key_exists('isActive', $values) ? (bool) $values['isActive'] : true,
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeQuestions(mixed $rawQuestions): array
    {
        $questions = [];
        if (!is_array($rawQuestions)) {
            return [];
        }

        foreach ($rawQuestions as $question) {
            $cleanQuestion = $this->cleanText((string) $question, 240);
            if ($cleanQuestion !== '') {
                $questions[] = $cleanQuestion;
            }
        }

        return array_values(array_unique(array_slice($questions, 0, 5)));
    }

    /**
     * @param list<array{role: string, content: string}> $history
     */
    private function conversationText(array $history, string $message): string
    {
        $parts = [];
        $current = $this->cleanText($message, 1200);
        if ($current !== '') {
            $parts[] = $current;
        }

        foreach ($history as $item) {
            if (($item['role'] ?? '') !== 'user') {
                continue;
            }

            $content = $this->cleanText((string) ($item['content'] ?? ''), 1200);
            if ($content !== '') {
                $parts[] = $content;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @param list<string> $patterns
     */
    private function containsAny(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && str_contains($text, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function removeLeadingPrompt(string $value): string
    {
        $patterns = [
            '/^(quiero|necesito|solo quiero)\s+(una\s+)?gu[ií]a\s+(para|de)\s+/iu',
            '/^(quiero|necesito)\s+una\s+gu[ií]a\s+/iu',
            '/^gu[ií]a\s+(para|de)\s+/iu',
        ];

        foreach ($patterns as $pattern) {
            $candidate = preg_replace($pattern, '', $value) ?? $value;
            if ($candidate !== $value) {
                return trim($candidate);
            }
        }

        return trim($value);
    }

    /**
     * @param list<array{role: string, content: string}> $history
     */
    private function shouldSuggestScoring(array $history, string $message): bool
    {
        $normalized = mb_strtolower($this->cleanText($message, 400));

        return $this->containsAny($normalized, ['prioriz', 'prioridad', 'score', 'scoring', 'lead', 'cita']);
    }

    private function extractCampaignTopic(string $message): string
    {
        $message = $this->cleanText($message, 400);
        if ($message === '') {
            return '';
        }

        if (preg_match('/campa[iñ]a(?:\s+de)?\s+(.+?)(?:[.!?]|$)/iu', $message, $matches) !== 1) {
            return '';
        }

        $topic = $this->cleanText((string) ($matches[1] ?? ''), 120);
        if ($topic === '') {
            return '';
        }

        $topic = preg_replace('/^(para|de|la|el|los|las)\s+/iu', '', $topic) ?? $topic;

        return trim($topic);
    }
}
