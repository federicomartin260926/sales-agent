<?php

namespace App\Tests\Unit;

use App\Service\AudioGatewayConnectivityTester;
use App\Service\OllamaConnectivityTester;
use App\Service\OpenAIConnectivityTester;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RuntimeConnectivityTestersTest extends TestCase
{
    public function testOpenAITesterSkipsRequestWhenKeyMissing(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = compact('method', 'url', 'options');

            return new MockResponse('[]', ['http_code' => 200]);
        });

        $tester = new OpenAIConnectivityTester($httpClient);
        $result = $tester->test([
            'openai_base_url' => 'https://api.openai.com/v1',
            'openai_api_key' => '',
            'openai_model' => 'gpt-4o-mini',
        ]);

        self::assertSame('blocked', $result->status);
        self::assertCount(0, $requests);
    }

    public function testOpenAITesterHitsModelsEndpoint(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = compact('method', 'url', 'options');

            return new MockResponse('{"data":[]}', ['http_code' => 200]);
        });

        $tester = new OpenAIConnectivityTester($httpClient);
        $result = $tester->test([
            'openai_base_url' => 'https://api.openai.com/v1',
            'openai_api_key' => 'sk-test',
            'openai_model' => 'gpt-4o-mini',
        ]);

        self::assertSame('ready', $result->status);
        self::assertSame('https://api.openai.com/v1/models', $requests[0]['url']);
    }

    public function testOllamaTesterMarksPartialWhenModelMissing(): void
    {
        $httpClient = new MockHttpClient(function (): MockResponse {
            return new MockResponse('{"models":[]}', ['http_code' => 200]);
        });

        $tester = new OllamaConnectivityTester($httpClient);
        $result = $tester->test([
            'ollama_base_url' => 'http://ollama:11434',
            'ollama_model' => '',
        ]);

        self::assertSame('partial', $result->status);
        self::assertSame('http://ollama:11434/api/tags', $result->endpoint);
    }

    public function testAudioTesterResolvesHealthEndpoint(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = compact('method', 'url', 'options');

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });

        $tester = new AudioGatewayConnectivityTester($httpClient);
        $result = $tester->test([
            'audio_mode' => 'gateway',
            'audio_gateway_base_url' => 'http://audio-gateway',
            'audio_gateway_token' => 'audio-token',
        ]);

        self::assertSame('ready', $result->status);
        self::assertSame('http://audio-gateway/health', $requests[0]['url']);
    }
}
