<?php

namespace App\Tests\Unit;

use App\Service\RuntimeConfigurationService;
use App\Service\TenantDraftAssistantService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TenantDraftAssistantServiceTest extends TestCase
{
    public function testHeuristicFallbackBuildsDraftWithoutCallingOpenAi(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(function () use (&$requests): MockResponse {
            $requests[] = true;

            return new MockResponse('{}');
        });

        $service = new TenantDraftAssistantService(
            $this->runtimeConfigurationService([]),
            $httpClient,
        );

        $response = $service->buildResponse([], 'Hola', [
            'name' => 'Clínica Demo',
            'slug' => '',
            'businessContext' => '',
            'tone' => '',
            'whatsappPhoneNumberId' => '',
            'whatsappPublicPhone' => '',
            'positioning' => '',
            'qualificationFocus' => '',
            'handoffRules' => '',
            'salesBoundaries' => '',
            'notes' => '',
            'isActive' => true,
        ]);

        self::assertSame([], $requests);
        self::assertSame('asking', $response['status']);
        self::assertIsArray($response['questions']);
        self::assertSame('clinica-demo', $response['draft']['slug']);
        self::assertSame('cercano, profesional y directo', $response['draft']['tone']);
        self::assertSame('¿Qué vende, a quién y en qué zona o mercado opera?', $response['answer']);
    }

    public function testOpenAiResponseIsNormalizedIntoDraftPayload(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = compact('method', 'url', 'options');

            $draftContent = json_encode([
                'answer' => 'Perfecto, ya tengo la propuesta.',
                'status' => 'ready',
                'questions' => [],
                'draft' => [
                    'name' => 'Demo Studio',
                    'slug' => 'demo-studio',
                    'tone' => 'cercano, profesional y directo',
                    'businessContext' => 'Estudio que vende servicios de diseño a pymes.',
                    'salesPolicyWelcome' => 'Saluda con tono cercano y profesional.',
                    'salesPolicyQualification' => 'Pregunta por volumen, urgencia y presupuesto.',
                    'salesPolicyHandoff' => 'Deriva si el cliente pide negociación especial.',
                    'salesPolicyLimits' => 'No prometas plazos ni descuentos no aprobados.',
                    'salesPolicyNotes' => 'Opera en remoto.',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $content = json_encode([
                'choices' => [
                    [
                        'message' => [
                            'content' => $draftContent,
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return new MockResponse($content);
        });

        $service = new TenantDraftAssistantService(
            $this->runtimeConfigurationService([
                'llm_default_profile' => 'openai',
                'openai_base_url' => 'https://api.openai.com/v1',
                'openai_model' => 'gpt-4.1-mini',
                'openai_api_key' => 'sk-test',
                'openai_timeout_seconds' => '10',
            ]),
            $httpClient,
        );

        $response = $service->buildResponse(
            [
                ['role' => 'assistant', 'content' => 'Hola'],
                ['role' => 'user', 'content' => 'Necesito una ficha para un estudio de diseño.'],
            ],
            'Necesito una ficha para un estudio de diseño.',
            [
                'name' => 'Demo Studio',
                'slug' => '',
                'businessContext' => '',
                'tone' => '',
                'whatsappPhoneNumberId' => '',
                'whatsappPublicPhone' => '',
                'positioning' => '',
                'qualificationFocus' => '',
                'handoffRules' => '',
                'salesBoundaries' => '',
                'notes' => '',
                'isActive' => true,
            ]
        );

        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('https://api.openai.com/v1/chat/completions', $requests[0]['url']);
        $requestBody = json_decode((string) ($requests[0]['options']['body'] ?? ''), true);
        self::assertIsArray($requestBody);
        self::assertSame('json_object', $requestBody['response_format']['type']);
        self::assertSame('ready', $response['status']);
        self::assertSame('demo-studio', $response['draft']['slug']);
        self::assertSame('Estudio que vende servicios de diseño a pymes.', $response['draft']['businessContext']);
        self::assertSame('Saluda con tono cercano y profesional.', $response['draft']['salesPolicyWelcome']);
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
