<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AutoconfigureTag('app.runtime_connectivity_tester')]
final class OllamaConnectivityTester implements RuntimeConnectivityTesterInterface
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function supports(string $target): bool
    {
        return $target === 'ollama';
    }

    public function test(array $settings): RuntimeConnectivityTestResult
    {
        $baseUrl = trim($settings['ollama_base_url'] ?? '');
        $model = trim($settings['ollama_model'] ?? '');

        if ($baseUrl === '') {
            return new RuntimeConnectivityTestResult('blocked', 'Falta el endpoint de Ollama.');
        }

        $endpoint = rtrim($baseUrl, '/').'/api/tags';

        try {
            $response = $this->httpClient->request('GET', $endpoint, [
                'timeout' => 6.0,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                return new RuntimeConnectivityTestResult('blocked', sprintf('Ollama respondió con HTTP %d.', $statusCode), $endpoint, $statusCode);
            }

            if ($model === '') {
                return new RuntimeConnectivityTestResult('partial', 'Ollama responde, pero no hay modelo seleccionado.', $endpoint, $statusCode);
            }

            return new RuntimeConnectivityTestResult('ready', 'Conectividad Ollama verificada con /api/tags.', $endpoint, $statusCode);
        } catch (\Throwable $exception) {
            return new RuntimeConnectivityTestResult('blocked', sprintf('No fue posible conectar con Ollama: %s', $exception->getMessage()), $endpoint);
        }
    }
}
