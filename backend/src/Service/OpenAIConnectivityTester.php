<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AutoconfigureTag('app.runtime_connectivity_tester')]
final class OpenAIConnectivityTester implements RuntimeConnectivityTesterInterface
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function supports(string $target): bool
    {
        return $target === 'openai';
    }

    public function test(array $settings): RuntimeConnectivityTestResult
    {
        $baseUrl = trim($settings['openai_base_url'] ?? '');
        $apiKey = trim($settings['openai_api_key'] ?? '');
        $model = trim($settings['openai_model'] ?? '');

        if ($baseUrl === '') {
            return new RuntimeConnectivityTestResult('blocked', 'Falta el endpoint de OpenAI.');
        }

        if ($apiKey === '') {
            return new RuntimeConnectivityTestResult('blocked', 'Falta la clave API de OpenAI.');
        }

        if ($model === '') {
            return new RuntimeConnectivityTestResult('partial', 'El endpoint de OpenAI responde, pero no hay modelo seleccionado.');
        }

        $endpoint = rtrim($baseUrl, '/').'/models';

        try {
            $response = $this->httpClient->request('GET', $endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                ],
                'timeout' => 6.0,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                return new RuntimeConnectivityTestResult('ready', 'Conectividad OpenAI verificada con /models.', $endpoint, $statusCode);
            }

            return new RuntimeConnectivityTestResult('blocked', sprintf('OpenAI respondió con HTTP %d.', $statusCode), $endpoint, $statusCode);
        } catch (\Throwable $exception) {
            return new RuntimeConnectivityTestResult('blocked', sprintf('No fue posible conectar con OpenAI: %s', $exception->getMessage()), $endpoint);
        }
    }
}
