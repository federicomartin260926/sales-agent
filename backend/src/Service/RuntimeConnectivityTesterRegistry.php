<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

final class RuntimeConnectivityTesterRegistry
{
    /**
     * @param iterable<RuntimeConnectivityTesterInterface> $testers
     */
    public function __construct(#[TaggedIterator('app.runtime_connectivity_tester')] private readonly iterable $testers)
    {
    }

    public function test(string $target, array $settings): RuntimeConnectivityTestResult
    {
        foreach ($this->testers as $tester) {
            if ($tester->supports($target)) {
                return $tester->test($settings);
            }
        }

        return new RuntimeConnectivityTestResult('blocked', sprintf('No existe tester para "%s".', $target));
    }
}
