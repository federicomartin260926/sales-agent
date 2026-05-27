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
        $draft = $this->completeDraft($this->buildBaseDraft($form), $history, $message);
        $isReady = $this->isReadyToFinalize($draft, $history, $message);

        if (!$isReady) {
            $questions = $this->buildFollowUpQuestions($draft, $history, $message);

            return [
                'answer' => $this->buildFollowUpAnswer($questions),
                'status' => 'asking',
                'questions' => $questions,
                'draft' => $draft,
            ];
        }

        return [
            'answer' => 'Ya he completado una propuesta inicial en la ficha. Revísala y dime si quieres ajustar tono, límites, cualificación o derivación a humano.',
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
                ? 'Ya he completado una propuesta inicial en la ficha. Revísala y dime si quieres ajustar tono, límites, cualificación o derivación a humano.'
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

        $draft = $this->completeDraft($this->buildBaseDraft($form), $history, $message);
        if (isset($payload['draft']) && is_array($payload['draft'])) {
            $draft = $this->mergeDraft($draft, $payload['draft']);
        }

        $draft = $this->completeDraft($draft, $history, $message);
        $isReady = $this->isReadyToFinalize($draft, $history, $message);

        if (!$isReady) {
            $status = 'asking';
        }

        if (!$isReady) {
            $questions = $this->buildFollowUpQuestions($draft, $history, $message);
            if ($answer === '' || $status !== 'ready') {
                $answer = $this->buildFollowUpAnswer($questions);
            }
        } else {
            $questions = [];
        }

        if ($isReady && $answer === '') {
            $answer = 'Ya he completado una propuesta inicial en la ficha. Revísala y dime si quieres ajustar tono, límites, cualificación o derivación a humano.';
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
            'humanHandoffEnabled' => false,
            'humanHandoffWhatsappPublic' => $form['humanHandoffWhatsappPublic'] ?? '',
            'humanHandoffMessage' => $form['humanHandoffMessage'] ?? '',
            'humanHandoffStrategy' => 'disabled',
            'isActive' => (bool) ($form['isActive'] ?? true),
            'businessContext' => $this->buildBusinessContext($form, '', []),
            'salesPolicyWelcome' => '',
            'salesPolicyQualification' => '',
            'salesPolicyHandoff' => '',
            'salesPolicyLimits' => '',
            'salesPolicyNotes' => '',
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
            'humanHandoffWhatsappPublic',
            'humanHandoffMessage',
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

        if (array_key_exists('humanHandoffEnabled', $payloadDraft)) {
            $draft['humanHandoffEnabled'] = (bool) $payloadDraft['humanHandoffEnabled'];
        }

        if (array_key_exists('humanHandoffStrategy', $payloadDraft)) {
            $candidate = $this->cleanText((string) $payloadDraft['humanHandoffStrategy'], 50);
            if (in_array($candidate, ['disabled', 'manual_wa_link', 'n8n_webhook', 'manual_wa_link_and_n8n'], true)) {
                $draft['humanHandoffStrategy'] = $candidate;
            }
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

        if (array_key_exists('humanHandoffWhatsappPublic', $payloadDraft)) {
            $candidate = $this->cleanText((string) $payloadDraft['humanHandoffWhatsappPublic'], 50);
            if ($draft['humanHandoffWhatsappPublic'] === '' && $candidate !== '') {
                // No inventar números de WhatsApp humano.
                $candidate = '';
            }
            if ($candidate !== '') {
                $draft['humanHandoffWhatsappPublic'] = $candidate;
            }
        }

        if (array_key_exists('humanHandoffMessage', $payloadDraft)) {
            $candidate = $this->cleanText((string) $payloadDraft['humanHandoffMessage'], 4000);
            if ($candidate !== '') {
                $draft['humanHandoffMessage'] = $candidate;
            }
        }

        return $draft;
    }

    /**
     * @param array<string, mixed> $draft
     * @param list<array{role: string, content: string}> $history
     *
     * @return array<string, mixed>
     */
    private function completeDraft(array $draft, array $history, string $message): array
    {
        $context = $this->buildBusinessContext($draft, $message, $history);
        if ($context !== '') {
            $draft['businessContext'] = $context;
        }

        $tone = $this->buildTone($draft, $context);
        if ($tone !== '') {
            $draft['tone'] = $tone;
        }

        if (($draft['salesPolicyWelcome'] ?? '') === '') {
            $draft['salesPolicyWelcome'] = $this->buildWelcomePolicy($draft, $context);
        }

        if (($draft['salesPolicyQualification'] ?? '') === '') {
            $draft['salesPolicyQualification'] = $this->buildQualificationPolicy($draft, $context);
        }

        if (($draft['salesPolicyHandoff'] ?? '') === '') {
            $draft['salesPolicyHandoff'] = $this->buildHandoffPolicy($draft, $context);
        }

        if (($draft['salesPolicyLimits'] ?? '') === '') {
            $draft['salesPolicyLimits'] = $this->buildLimitsPolicy($draft, $context);
        }

        if (($draft['salesPolicyNotes'] ?? '') === '') {
            $draft['salesPolicyNotes'] = $this->buildNotesPolicy($draft, $context);
        }

        if (($draft['humanHandoffWhatsappPublic'] ?? '') !== '') {
            $draft['humanHandoffEnabled'] = true;
            if (($draft['humanHandoffStrategy'] ?? 'disabled') === 'disabled') {
                $draft['humanHandoffStrategy'] = 'manual_wa_link';
            }
        }

        if (($draft['humanHandoffEnabled'] ?? false) === true && ($draft['humanHandoffStrategy'] ?? 'disabled') === 'disabled') {
            $draft['humanHandoffStrategy'] = 'manual_wa_link';
        }

        return $draft;
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
            'humanHandoffEnabled' => array_key_exists('humanHandoffEnabled', $values) ? (bool) $values['humanHandoffEnabled'] : false,
            'humanHandoffWhatsappPublic' => $this->cleanText((string) ($values['humanHandoffWhatsappPublic'] ?? ''), 50),
            'humanHandoffMessage' => $this->cleanText((string) ($values['humanHandoffMessage'] ?? ''), 4000),
            'humanHandoffStrategy' => $this->cleanText((string) ($values['humanHandoffStrategy'] ?? 'disabled'), 50),
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
        return 'Eres un asistente de configuración de Sales Agent. Tu objetivo es ayudar al usuario a completar la ficha de un nuevo negocio. Haz preguntas breves, pero agrupa de 3 a 5 cuando falte información. No inventes datos técnicos. No finalices con status ready cuando solo tengas nombre o actividad; espera a tener información suficiente para una ficha comercial útil. El borrador debe ser claro, comercial, práctico y completo. Intenta completar siempre name, slug, tone, businessContext, salesPolicyWelcome, salesPolicyQualification, salesPolicyHandoff, salesPolicyLimits, salesPolicyNotes, whatsappPhoneNumberId, whatsappPublicPhone, humanHandoffEnabled, humanHandoffWhatsappPublic, humanHandoffMessage y humanHandoffStrategy con texto útil y prudente a partir de la información disponible. Nunca inventes phoneNumberId ni números de WhatsApp. Si faltan datos técnicos, déjalos vacíos. No confundas el WhatsApp público del agente IA con el WhatsApp humano para derivaciones. Devuelve solo JSON válido con las claves answer, status, questions y draft.';
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

    /**
     * @param array<string, mixed> $draft
     * @param list<array{role: string, content: string}> $history
     */
    private function buildBusinessContext(array $draft, string $message, array $history): string
    {
        $current = $this->cleanText((string) ($draft['businessContext'] ?? ''), 5000);
        if ($current !== '') {
            return $current;
        }

        $candidate = $this->bestContextCandidate($message, $history);
        if ($candidate !== '') {
            return $this->cleanText($candidate, 5000);
        }

        $name = $this->cleanText((string) ($draft['name'] ?? ''), 255);
        if ($name !== '') {
            return sprintf('Negocio llamado %s. Falta detallar la actividad, el cliente ideal y la zona de trabajo.', $name);
        }

        return '';
    }

    private function buildTone(array $draft, string $context): string
    {
        $tone = $this->cleanText((string) ($draft['tone'] ?? ''), 120);
        if ($tone !== '') {
            return $tone;
        }

        if (stripos($context, 'estética') !== false || stripos($context, 'belleza') !== false) {
            return 'cercano, profesional y de confianza';
        }

        if (stripos($context, 'salud') !== false || stripos($context, 'clínica') !== false) {
            return 'cercano, profesional y prudente';
        }

        return 'cercano, profesional y directo';
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function buildWelcomePolicy(array $draft, string $context): string
    {
        $name = $this->cleanText((string) ($draft['name'] ?? ''), 255);
        $tone = $this->buildTone($draft, $context);
        $nameFragment = $name !== '' ? sprintf('presentando el negocio como %s', $name) : 'presentando el negocio de forma clara';
        $contextFragment = $context !== '' ? sprintf('y usando el contexto del negocio: %s', $context) : 'y adaptando el mensaje al servicio principal';

        return sprintf(
            'Con un tono %s, saludar %s %s. El agente debe transmitir confianza, cercanía y una orientación práctica a la cita o siguiente paso.',
            $tone,
            $nameFragment,
            $contextFragment
        );
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function buildQualificationPolicy(array $draft, string $context): string
    {
        $questions = [
            'qué servicio o tratamiento le interesa',
            'si ya es clienta o viene por primera vez',
            'qué resultado o necesidad busca',
            'disponibilidad horaria aproximada',
        ];

        if ($context !== '') {
            $questions[] = 'si hay alguna preferencia o matiz importante sobre el servicio';
        }

        return 'Preguntar '.implode(', ', array_slice($questions, 0, 4)).'. Registrar los matices útiles para orientar mejor la conversación y preparar la siguiente acción sin saturar al usuario.';
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function buildHandoffPolicy(array $draft, string $context): string
    {
        $name = $this->cleanText((string) ($draft['name'] ?? ''), 255);
        $fallback = $name !== '' ? $name : 'una persona responsable';

        return sprintf(
            'Derivar a %s cuando haya dudas médicas o técnicas, petición de presupuesto especial, cambios complejos, urgencia fuera de la agenda habitual o cualquier caso que requiera validación humana antes de confirmar.',
            $fallback
        );
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function buildLimitsPolicy(array $draft, string $context): string
    {
        return 'No prometer resultados garantizados, no diagnosticar problemas de salud o piel, no inventar precios ni promociones, no confirmar huecos de agenda no verificados y no comprometer servicios fuera de la oferta habitual sin validación humana.';
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function buildNotesPolicy(array $draft, string $context): string
    {
        $name = $this->cleanText((string) ($draft['name'] ?? ''), 255);
        $pieces = [];

        if ($name !== '') {
            $pieces[] = $name.' debe seguir un tono consistente y cercano.';
        }

        if ($context !== '') {
            $pieces[] = 'Contexto útil para el agente: '.$context;
        }

        $pieces[] = 'Mantener respuestas breves, amables y orientadas a cita o siguiente paso.';

        return implode(' ', $pieces);
    }

    /**
     * @param list<array{role: string, content: string}> $history
     */
    private function bestContextCandidate(string $message, array $history): string
    {
        $candidates = [];
        $message = $this->cleanText($message, 1200);
        if ($message !== '') {
            $candidates[] = $message;
        }

        foreach (array_reverse($history) as $item) {
            if (($item['role'] ?? '') !== 'user') {
                continue;
            }

            $content = $this->cleanText((string) ($item['content'] ?? ''), 1200);
            if ($content !== '') {
                $candidates[] = $content;
            }
        }

        foreach ($candidates as $candidate) {
            if (mb_strlen($candidate) >= 30) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $draft
     * @param list<array{role: string, content: string}> $history
     *
     * @return list<string>
     */
    private function buildFollowUpQuestions(array $draft, array $history, string $message): array
    {
        $signals = $this->assessCommercialSignals($draft, $history, $message);
        $questions = [];

        if (($draft['name'] ?? '') === '') {
            $questions[] = '¿Cuál es el nombre comercial?';
        }

        if (!$signals['services']) {
            $questions[] = '¿Qué servicios o productos principales ofrece?';
        }

        if (!$signals['audience']) {
            $questions[] = '¿Qué tipo de cliente quiere captar?';
        }

        if (!$signals['location']) {
            $questions[] = '¿En qué ciudad o zona atiende?';
        }

        if (!$signals['tone']) {
            $questions[] = '¿Qué tono debe usar el agente IA?';
        }

        if (!$signals['whatsapp_public']) {
            $questions[] = '¿Cuál es el WhatsApp público del agente IA para enlaces wa.me, si ya lo tienes?';
        }

        if (!$signals['human_whatsapp_public']) {
            $questions[] = 'Si quieres derivación humana, ¿cuál es el WhatsApp humano para atender conversaciones manuales?';
        }

        if (!$signals['qualification']) {
            $questions[] = '¿Qué debe preguntar el agente antes de proponer cita o siguiente paso?';
        }

        if (!$signals['handoff']) {
            $questions[] = '¿Qué mensaje quieres que use el agente cuando derive a una persona?';
        }

        if (!$signals['limits']) {
            $questions[] = '¿Hay límites importantes? Por ejemplo precios, diagnósticos, disponibilidad o promesas de resultados.';
        }

        if (!$signals['schedule']) {
            $questions[] = '¿Quieres añadir horarios, zona de atención, promociones, precios o alguna regla especial?';
        }

        if (!$signals['notes']) {
            $questions[] = '¿Hay alguna nota diferencial del negocio que deba quedar reflejada?';
        }

        return array_values(array_slice(array_unique($questions), 0, 7));
    }

    /**
     * @param list<string> $questions
     */
    private function buildFollowUpAnswer(array $questions): string
    {
        if ($questions === []) {
            return 'Perfecto. Dime un poco más y te preparo una propuesta útil para la ficha.';
        }

        $parts = ['Perfecto. Para completar bien la ficha necesito algunos datos:'];
        foreach ($questions as $index => $question) {
            $parts[] = sprintf('%d. %s', $index + 1, $question);
        }

        return implode("\n", $parts);
    }

    /**
     * @param array<string, mixed> $draft
     * @param list<array{role: string, content: string}> $history
     *
     * @return array{services: bool, audience: bool, location: bool, whatsapp_public: bool, qualification: bool, handoff: bool, limits: bool, schedule: bool, notes: bool}
     */
    private function assessCommercialSignals(array $draft, array $history, string $message): array
    {
        $text = $this->conversationText($draft, $history, $message);
        $normalized = mb_strtolower($text);

        return [
            'services' => $this->containsAny($normalized, ['especializ', 'tratamient', 'depil', 'masaj', 'indiba', 'facial', 'servici', 'ofrece', 'belleza', 'estética', 'clinica', 'clínica', 'centro']),
            'audience' => $this->containsAny($normalized, ['mujer', 'hombre', 'empresa', 'pyme', 'famil', 'niñ', 'cliente', 'paciente', 'recurrent', 'nuevo']),
            'location' => $this->containsAny($normalized, ['madrid', 'barcelona', 'valencia', 'sevilla', 'villanueva', 'zona', 'barrio', 'local', 'ciudad', 'atiende en', 'ubicad', 'sede']),
            'whatsapp_public' => trim((string) ($draft['whatsappPublicPhone'] ?? '')) !== '',
            'human_whatsapp_public' => trim((string) ($draft['humanHandoffWhatsappPublic'] ?? '')) !== '',
            'tone' => trim((string) ($draft['tone'] ?? '')) !== '',
            'qualification' => $this->containsAny($normalized, ['qué debe pedir', 'pedir', 'cualif', 'cualificar', 'cita', 'siguiente paso', 'nuevo', 'recurrent', 'tratamiento', 'servicio', 'presupuesto', 'disponibilidad']),
            'handoff' => $this->containsAny($normalized, ['derivar', 'humano', 'mary', 'persona', 'responsable', 'validación', 'confirmar', 'escalar', 'seguimiento']),
            'limits' => $this->containsAny($normalized, ['precio', 'precios', 'diagnos', 'diagnóst', 'prometer', 'resultad', 'disponibilidad', 'agenda', 'sensib', 'salud', 'piel', 'contraindic', 'no dar']),
            'schedule' => $this->containsAny($normalized, ['lunes', 'martes', 'miércoles', 'miercoles', 'jueves', 'viernes', 'sábado', 'sabado', 'domingo', 'agenda', 'horario', '10:', '20:', 'mañana', 'tarde', 'comer']),
            'notes' => $this->containsAny($normalized, ['trabaja sola', 'clientela', 'fiel', 'trato', 'simpatía', 'simpatia', 'agenda única', 'agenda unica', 'personal', 'diferencial']),
        ];
    }

    /**
     * @param array<string, mixed> $draft
     * @param list<array{role: string, content: string}> $history
     */
    private function conversationText(array $draft, array $history, string $message): string
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

        foreach (['businessContext'] as $key) {
            $value = $this->cleanText((string) ($draft[$key] ?? ''), 1200);
            if ($value !== '' && !str_starts_with($value, 'Negocio llamado ')) {
                $parts[] = $value;
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

    /**
     * @param array<string, mixed> $draft
     * @param list<array{role: string, content: string}> $history
     */
    private function isReadyToFinalize(array $draft, array $history, string $message): bool
    {
        $signals = $this->assessCommercialSignals($draft, $history, $message);

        if (($draft['name'] ?? '') === '') {
            return false;
        }

        if (!$signals['services']) {
            return false;
        }

        if (!$signals['audience']) {
            return false;
        }

        if (!($signals['location'] || $signals['schedule'])) {
            return false;
        }

        if (!($signals['qualification'] || $signals['handoff'] || $signals['limits'])) {
            return false;
        }

        return true;
    }
}
