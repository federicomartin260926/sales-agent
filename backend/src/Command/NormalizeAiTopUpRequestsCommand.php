<?php

namespace App\Command;

use App\Entity\Tenant;
use App\Entity\TenantAiTopUpRequest;
use App\Entity\TenantAiUsagePolicy;
use App\Repository\TenantAiTopUpRequestRepository;
use App\Repository\TenantAiUsagePolicyRepository;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:normalize-ai-top-ups',
    description: 'Normalize legacy AI top-up requests and keep tenant base limits intact.',
)]
final class NormalizeAiTopUpRequestsCommand extends Command
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly TenantAiTopUpRequestRepository $topUpRequests,
        private readonly TenantAiUsagePolicyRepository $aiUsagePolicies,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $normalizedRequests = 0;
        $adjustedPolicies = 0;
        $skippedPolicies = 0;

        foreach ($this->tenants->findAllOrdered() as $tenant) {
            if (!$tenant instanceof Tenant) {
                continue;
            }

            $legacyRequests = $this->topUpRequests->findLegacyApprovedWithoutPeriodByTenant($tenant);
            if ($legacyRequests === []) {
                continue;
            }

            foreach ($legacyRequests as $requestEntity) {
                $requestEntity->setApprovedPeriodKey($this->tenantAiApprovedPeriodKey($requestEntity));
                $this->topUpRequests->save($requestEntity, false);
                $normalizedRequests++;
            }

            $policy = $this->aiUsagePolicies->findOneByTenant($tenant);
            if (!$policy instanceof TenantAiUsagePolicy) {
                $skippedPolicies++;
                continue;
            }

            if (count($legacyRequests) !== 1) {
                $skippedPolicies++;
                continue;
            }

            $legacyRequest = $legacyRequests[0];
            $legacyTokens = $this->legacyApprovedTokens($legacyRequest);
            $monthlyLimitTokens = $this->tokenAmountFromCost($policy->getMonthlyCostLimitEur(), $this->tenantAiUsageTokenRate($policy));
            if ($monthlyLimitTokens === null || $monthlyLimitTokens <= $legacyTokens) {
                $skippedPolicies++;
                continue;
            }

            $policy->setMonthlyCostLimitEur($this->costAmountFromTokens($monthlyLimitTokens - $legacyTokens, $this->tenantAiUsageTokenRate($policy)));
            $this->aiUsagePolicies->save($policy, false);
            $adjustedPolicies++;
        }

        $this->entityManager->flush();

        $output->writeln(sprintf(
            'Normalized %d legacy top-up request(s); adjusted %d policy base limit(s); skipped %d tenant(s).',
            $normalizedRequests,
            $adjustedPolicies,
            $skippedPolicies
        ));

        return Command::SUCCESS;
    }

    private function tenantAiApprovedPeriodKey(TenantAiTopUpRequest $request): string
    {
        $resolvedAt = $request->getResolvedAt();
        if ($resolvedAt instanceof \DateTimeImmutable) {
            return $resolvedAt->format('Y-m');
        }

        return $request->getCreatedAt()->format('Y-m');
    }

    private function legacyApprovedTokens(TenantAiTopUpRequest $request): int
    {
        $approvedTokens = $request->getApprovedTokens();
        if ($approvedTokens !== null && $approvedTokens > 0) {
            return $approvedTokens;
        }

        return max(0, (int) round($request->getRequestedAmountEur()));
    }

    private function tenantAiUsageTokenRate(TenantAiUsagePolicy $policy): float
    {
        $pricing = $this->tenantAiModelPricing($policy->getDefaultModel() ?? $policy->getFallbackModel());
        if ($pricing === null) {
            return 0.000001;
        }

        return (($pricing['input'] + $pricing['output'] + $pricing['cached_input']) / 3) / 1_000_000;
    }

    private function tenantAiModelPricing(?string $model): ?array
    {
        $normalized = strtolower(trim((string) $model));
        if ($normalized === '') {
            return null;
        }

        $pricingTable = [
            'gpt-4.1' => ['input' => 2.0, 'output' => 8.0, 'cached_input' => 0.5],
            'gpt-4.1-mini' => ['input' => 0.4, 'output' => 1.6, 'cached_input' => 0.1],
            'gpt-4o' => ['input' => 2.5, 'output' => 10.0, 'cached_input' => 0.625],
            'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.6, 'cached_input' => 0.0375],
        ];

        if (isset($pricingTable[$normalized])) {
            return $pricingTable[$normalized];
        }

        foreach ($pricingTable as $key => $pricing) {
            if (str_starts_with($normalized, $key)) {
                return $pricing;
            }
        }

        return null;
    }

    private function tokenAmountFromCost(?float $cost, ?float $costPerToken): ?int
    {
        if ($cost === null) {
            return null;
        }

        if ($costPerToken === null || $costPerToken <= 0.0) {
            return (int) round($cost);
        }

        return (int) round($cost / $costPerToken);
    }

    private function costAmountFromTokens(?int $tokens, ?float $costPerToken): ?float
    {
        if ($tokens === null) {
            return null;
        }

        if ($costPerToken === null || $costPerToken <= 0.0) {
            return (float) $tokens;
        }

        return round($tokens * $costPerToken, 8);
    }
}
