<?php

namespace App\Tests\Unit;

use App\Command\NormalizeAiTopUpRequestsCommand;
use App\Entity\Tenant;
use App\Entity\TenantAiTopUpRequest;
use App\Entity\TenantAiUsagePolicy;
use App\Repository\TenantAiTopUpRequestRepository;
use App\Repository\TenantAiUsagePolicyRepository;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class NormalizeAiTopUpRequestsCommandTest extends TestCase
{
    public function testLegacyApprovedTopUpIsNormalizedToCurrentPeriodWithoutChangingEffectiveCurrentQuota(): void
    {
        $tenant = $this->tenant('Tech Investments', 'tech-investments');
        $policy = $this->policy($tenant, 0.42);
        $requestEntity = $this->topUpRequest($tenant, 100000.0, 'Legacy top-up for dev');
        $requestEntity->approve($this->user('owner@example.com', ['super_admin'], 'Owner'), 100000);

        $tenantRepository = $this->createStub(TenantRepository::class);
        $tenantRepository->method('findAllOrdered')->willReturn([$tenant]);

        $topUpRepository = $this->createStub(TenantAiTopUpRequestRepository::class);
        $topUpRepository->method('findLegacyApprovedWithoutPeriodByTenant')->willReturn([$requestEntity]);
        $topUpRepository->method('save')->willReturnCallback(static function (TenantAiTopUpRequest $request): void {
            // Persisted through the in-memory object reference.
        });

        $policyRepository = $this->createStub(TenantAiUsagePolicyRepository::class);
        $policyRepository->method('findOneByTenant')->willReturn($policy);
        $policyRepository->method('save')->willReturnCallback(static function (TenantAiUsagePolicy $policy): void {
            // Persisted through the in-memory object reference.
        });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $command = new NormalizeAiTopUpRequestsCommand(
            $tenantRepository,
            $topUpRepository,
            $policyRepository,
            $entityManager
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame((new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->format('Y-m'), $requestEntity->getApprovedPeriodKey());
        self::assertSame(0.35, round($policy->getMonthlyCostLimitEur() ?? 0.0, 2));
        self::assertStringContainsString('Normalized 1 legacy top-up request(s); adjusted 1 policy base limit(s); skipped 0 tenant(s).', $tester->getDisplay());
        self::assertSame(TenantAiTopUpRequest::STATUS_APPROVED, $requestEntity->getStatus());
    }

    private function tenant(string $name, string $slug): Tenant
    {
        $tenant = new Tenant($name, $slug);
        $tenant->setActive(true);

        return $tenant;
    }

    private function user(string $email, array $roles, ?string $name = null): \App\Entity\User
    {
        return new \App\Entity\User($email, $roles, $name);
    }

    private function policy(Tenant $tenant, float $monthlyCostLimitEur): TenantAiUsagePolicy
    {
        $policy = new TenantAiUsagePolicy($tenant);
        $policy->setAiEnabled(true);
        $policy->setMonthlyCostLimitEur($monthlyCostLimitEur);
        $policy->setDefaultModel('gpt-4.1-mini');
        $policy->setFallbackModel('gpt-4.1-nano');

        return $policy;
    }

    private function topUpRequest(Tenant $tenant, float $amount, string $message): TenantAiTopUpRequest
    {
        return new TenantAiTopUpRequest($tenant, $amount, $message);
    }
}
