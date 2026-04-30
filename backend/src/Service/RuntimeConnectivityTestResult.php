<?php

namespace App\Service;

final class RuntimeConnectivityTestResult
{
    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly ?string $endpoint = null,
        public readonly ?int $httpCode = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'endpoint' => $this->endpoint,
            'httpCode' => $this->httpCode,
        ];
    }
}
