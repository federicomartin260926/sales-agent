<?php

namespace App\Tests\Unit;

use App\Service\PlaybookDraftAssistantService;
use App\Service\RuntimeConfigurationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PlaybookDraftAssistantServiceTest extends TestCase
{
    public function testHeuristicFallbackAsksToSelectBusinessWhenTenantIsMissing(): void
    {
        $service = new PlaybookDraftAssistantService(
            $this->runtimeConfigurationService([]),
            $this->createStub(HttpClientInterface::class),
        );

        $response = $service->buildResponse([], 'Quiero una guía para campaña de depilación láser verano.', [
            'tenantId' => '',
            'productId' => '',
            'name' => '',
            'objective' => '',
            'qualificationQuestions' => '',
            'maxScore' => '',
            'handoffThreshold' => '',
            'positiveSignals' => '',
            'negativeSignals' => '',
            'agendaRules' => '',
            'handoffRules' => '',
            'allowedActions' => '',
            'notes' => '',
            'isActive' => true,
        ]);

        self::assertSame('asking', $response['status']);
        self::assertStringContainsString('Selecciona primero un negocio', $response['answer']);
        self::assertNotEmpty($response['questions']);
        self::assertSame('', $response['draft']['name']);
        self::assertSame('', $response['draft']['objective']);
    }

    public function testHeuristicFallbackKeepsAskingWhenContextIsPartial(): void
    {
        $service = new PlaybookDraftAssistantService(
            $this->runtimeConfigurationService([]),
            $this->createStub(HttpClientInterface::class),
        );

        $response = $service->buildResponse([], 'Quiero una guía simple para campaña de depilación láser verano.', [
            'tenantId' => 'tenant-demo',
            'productId' => 'product-demo',
            'name' => '',
            'objective' => '',
            'qualificationQuestions' => '',
            'maxScore' => '',
            'handoffThreshold' => '',
            'positiveSignals' => '',
            'negativeSignals' => '',
            'agendaRules' => '',
            'handoffRules' => '',
            'allowedActions' => '',
            'notes' => '',
            'isActive' => true,
        ], [
            'id' => 'tenant-demo',
            'name' => 'Centro de estética Mary',
            'businessContext' => 'Centro de estética en Villanueva de la Cañada.',
            'tone' => 'cercano, profesional y de confianza',
            'salesPolicySummary' => 'Saludar con cercanía y orientar a cita.',
            'whatsappPublicPhone' => '34612345678',
        ], [
            'id' => 'product-demo',
            'name' => 'Depilación láser',
            'description' => 'Tratamiento principal',
            'valueProposition' => 'Resultados personalizados',
            'salesPolicySummary' => 'Priorizar leads de cita.',
        ]);

        self::assertSame('asking', $response['status']);
        self::assertNotEmpty($response['questions']);
        self::assertStringContainsString('conseguir', mb_strtolower(implode(' ', $response['questions'])));
        self::assertStringContainsString('cliente o lead', mb_strtolower(implode(' ', $response['questions'])));
        self::assertStringContainsString('derivar', mb_strtolower(implode(' ', $response['questions'])));
        self::assertSame('', $response['draft']['whatsappPhoneNumberId'] ?? '');
        self::assertSame('', $response['draft']['whatsappPublicPhone'] ?? '');
    }

    public function testHeuristicFallbackBuildsUsefulDraftForCampaignStrategy(): void
    {
        $service = new PlaybookDraftAssistantService(
            $this->runtimeConfigurationService([]),
            $this->createStub(HttpClientInterface::class),
        );

        $response = $service->buildResponse([], 'Quiero una guía para campaña de depilación láser verano. Va dirigida a mujeres que buscan cita rápida. El agente debe captar interés, preguntar zona a tratar y disponibilidad, y derivar a Mary si hay dudas médicas o precios no configurados.', [
            'tenantId' => 'tenant-demo',
            'productId' => 'product-demo',
            'name' => '',
            'objective' => '',
            'qualificationQuestions' => '',
            'maxScore' => '',
            'handoffThreshold' => '',
            'positiveSignals' => '',
            'negativeSignals' => '',
            'agendaRules' => '',
            'handoffRules' => '',
            'allowedActions' => '',
            'notes' => '',
            'isActive' => true,
        ], [
            'id' => 'tenant-demo',
            'name' => 'Centro de estética Mary',
            'businessContext' => 'Centro de estética en Villanueva de la Cañada.',
            'tone' => 'cercano, profesional y de confianza',
            'salesPolicySummary' => 'Saludar con cercanía y orientar a cita.',
            'whatsappPublicPhone' => '34612345678',
        ], [
            'id' => 'product-demo',
            'name' => 'Depilación láser',
            'description' => 'Tratamiento principal',
            'valueProposition' => 'Resultados personalizados',
            'salesPolicySummary' => 'Priorizar leads de cita.',
        ]);

        self::assertSame('ready', $response['status']);
        self::assertNotSame('', $response['draft']['name']);
        self::assertStringContainsString('depilación láser', mb_strtolower($response['draft']['objective']));
        self::assertNotSame('', $response['draft']['qualificationQuestions']);
        self::assertNotSame('', $response['draft']['handoffRules']);
        self::assertNotSame('', $response['draft']['allowedActions']);
        self::assertNotSame('', $response['draft']['notes']);
        self::assertSame('', $response['draft']['whatsappPhoneNumberId'] ?? '');
        self::assertSame('', $response['draft']['whatsappPublicPhone'] ?? '');
    }

    public function testHeuristicFallbackPreservesExistingFormValuesDuringRevision(): void
    {
        $service = new PlaybookDraftAssistantService(
            $this->runtimeConfigurationService([]),
            $this->createStub(HttpClientInterface::class),
        );

        $response = $service->buildResponse([], 'Quiero ajustar solo el handoff y las acciones.', [
            'tenantId' => 'tenant-demo',
            'productId' => 'product-demo',
            'name' => 'Guía de depilación láser',
            'objective' => 'Objetivo previo',
            'qualificationQuestions' => 'Pregunta previa',
            'maxScore' => '',
            'handoffThreshold' => '',
            'positiveSignals' => '',
            'negativeSignals' => '',
            'agendaRules' => '',
            'handoffRules' => 'No tocar',
            'allowedActions' => 'Respetar flujo actual',
            'notes' => 'Notas base',
            'isActive' => true,
        ], [
            'id' => 'tenant-demo',
            'name' => 'Centro de estética Mary',
            'businessContext' => 'Centro de estética en Villanueva de la Cañada.',
            'tone' => 'cercano, profesional y de confianza',
            'salesPolicySummary' => 'Saludar con cercanía y orientar a cita.',
            'whatsappPublicPhone' => '34612345678',
        ], [
            'id' => 'product-demo',
            'name' => 'Depilación láser',
            'description' => 'Tratamiento principal',
            'valueProposition' => 'Resultados personalizados',
            'salesPolicySummary' => 'Priorizar leads de cita.',
        ]);

        self::assertSame('asking', $response['status']);
        self::assertSame('Objetivo previo', $response['draft']['objective']);
        self::assertSame('No tocar', $response['draft']['handoffRules']);
        self::assertSame('Respetar flujo actual', $response['draft']['allowedActions']);
        self::assertSame('Notas base', $response['draft']['notes']);
    }

    public function testOpenAiResponseUsesConciseContextPayload(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = compact('method', 'url', 'options');

            $assistantPayload = json_encode([
                'answer' => 'Perfecto, ya tengo una propuesta.',
                'status' => 'ready',
                'questions' => [],
                'draft' => [
                    'name' => 'Campaña depilación láser verano',
                    'objective' => 'Captar leads y orientar a cita.',
                    'qualificationQuestions' => "Qué zona quiere tratar\nSi busca cita o información",
                    'handoffRules' => 'Derivar si pide valoración personalizada.',
                    'allowedActions' => "Resolver dudas generales\nProponer cita",
                    'notes' => 'Caso específico de campaña.',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return new MockResponse(json_encode([
                'choices' => [
                    [
                        'message' => [
                            'content' => $assistantPayload,
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        });

        $service = new PlaybookDraftAssistantService(
            $this->runtimeConfigurationService([
                'llm_default_profile' => 'openai',
                'openai_base_url' => 'https://api.openai.com/v1',
                'openai_model' => 'gpt-4.1-mini',
                'openai_api_key' => 'sk-test',
                'openai_timeout_seconds' => '10',
            ]),
            $httpClient,
        );

        $response = $service->buildResponse([], 'Quiero una guía para campaña de depilación láser verano. Va dirigida a mujeres que buscan cita rápida. El agente debe captar interés, preguntar zona a tratar y disponibilidad, y derivar a Mary si hay dudas médicas o precios no configurados.', [
            'tenantId' => 'tenant-demo',
            'productId' => 'product-demo',
            'name' => '',
            'objective' => '',
            'qualificationQuestions' => '',
            'maxScore' => '',
            'handoffThreshold' => '',
            'positiveSignals' => '',
            'negativeSignals' => '',
            'agendaRules' => '',
            'handoffRules' => '',
            'allowedActions' => '',
            'notes' => '',
            'isActive' => true,
        ], [
            'id' => 'tenant-demo',
            'name' => 'Centro de estética Mary',
            'businessContext' => 'Centro de estética en Villanueva de la Cañada.',
            'tone' => 'cercano, profesional y de confianza',
            'salesPolicySummary' => 'Saludar con cercanía y orientar a cita.',
            'whatsappPublicPhone' => '34612345678',
        ], [
            'id' => 'product-demo',
            'name' => 'Depilación láser',
            'description' => 'Tratamiento principal',
            'valueProposition' => 'Resultados personalizados',
            'salesPolicySummary' => 'Priorizar leads de cita.',
        ]);

        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('https://api.openai.com/v1/chat/completions', $requests[0]['url']);

        $requestBody = json_decode((string) ($requests[0]['options']['body'] ?? ''), true);
        self::assertIsArray($requestBody);
        $prompt = json_decode((string) ($requestBody['messages'][1]['content'] ?? ''), true);
        self::assertIsArray($prompt);
        self::assertArrayHasKey('tenant_context', $prompt);
        self::assertArrayHasKey('product_context', $prompt);
        self::assertArrayHasKey('salesPolicySummary', $prompt['tenant_context']);
        self::assertArrayHasKey('salesPolicySummary', $prompt['product_context']);
        self::assertArrayNotHasKey('salesPolicy', $prompt['tenant_context']);

        self::assertSame('ready', $response['status']);
        self::assertSame('Campaña depilación láser verano', $response['draft']['name']);
        self::assertSame('Captar leads y orientar a cita.', $response['draft']['objective']);
        self::assertSame('Derivar si pide valoración personalizada.', $response['draft']['handoffRules']);
    }

    private function runtimeConfigurationService(array $values): RuntimeConfigurationService
    {
        return new class($values) extends RuntimeConfigurationService {
            public function __construct(private readonly array $values)
            {
            }

            public function snapshot(): array
            {
                return ['values' => $this->values];
            }
        };
    }
}
