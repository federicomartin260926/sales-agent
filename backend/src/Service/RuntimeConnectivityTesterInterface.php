<?php

namespace App\Service;

interface RuntimeConnectivityTesterInterface
{
    public function supports(string $target): bool;

    /**
     * @param array<string, string> $settings
     */
    public function test(array $settings): RuntimeConnectivityTestResult;
}
