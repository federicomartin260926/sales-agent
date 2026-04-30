<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AutoconfigureTag('app.runtime_connectivity_tester')]
final class AudioGatewayConnectivityTester implements RuntimeConnectivityTesterInterface
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function supports(string $target): bool
    {
        return $target === 'audio';
    }

    public function test(array $settings): RuntimeConnectivityTestResult
    {
        $mode = trim($settings['audio_mode'] ?? 'disabled');
        $baseUrl = trim($settings['audio_gateway_base_url'] ?? '');
        $token = trim($settings['audio_gateway_token'] ?? '');

        if ($mode === 'disabled') {
            return new RuntimeConnectivityTestResult('ready', 'Audio desactivado por configuración.');
        }

        if ($mode === 'local') {
            return new RuntimeConnectivityTestResult('ready', 'Audio local activado. La conectividad remota no aplica.');
        }

        if ($baseUrl === '') {
            return new RuntimeConnectivityTestResult('blocked', 'Falta el endpoint de audio-gateway.');
        }

        $endpoint = rtrim($baseUrl, '/').'/health';

        try {
            $options = ['timeout' => 6.0];
            if ($token !== '') {
                $options['headers'] = ['Authorization' => 'Bearer '.$token];
            }

            $response = $this->httpClient->request('GET', $endpoint, $options);
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                return new RuntimeConnectivityTestResult('ready', 'Conectividad de audio verificada con /health.', $endpoint, $statusCode);
            }

            return new RuntimeConnectivityTestResult('blocked', sprintf('Audio gateway respondió con HTTP %d.', $statusCode), $endpoint, $statusCode);
        } catch (\Throwable $exception) {
            return new RuntimeConnectivityTestResult('blocked', sprintf('No fue posible conectar con audio-gateway: %s', $exception->getMessage()), $endpoint);
        }
    }
}
