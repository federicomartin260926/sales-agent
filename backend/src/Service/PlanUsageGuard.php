<?php

namespace App\Service;

use App\Entity\ExternalTool;
use App\Entity\Tenant;
use App\Exception\PlanLimitExceededException;
use App\Repository\EntryPointRepository;
use App\Repository\ExternalToolRepository;
use App\Repository\PlaybookRepository;
use App\Repository\ProductRepository;

final class PlanUsageGuard
{
    public function __construct(
        private readonly FeatureAccessChecker $featureAccessChecker,
        private readonly PlanLimitResolver $planLimitResolver,
        private readonly ProductRepository $products,
        private readonly PlaybookRepository $playbooks,
        private readonly EntryPointRepository $entryPoints,
        private readonly ExternalToolRepository $externalTools,
    ) {
    }

    public function canUseAudioTranscription(Tenant $tenant): bool
    {
        return $this->hasPlan($tenant) && $this->featureAccessChecker->isFeatureEnabled($tenant, 'audio_transcription');
    }

    public function canUseMcpTools(Tenant $tenant): bool
    {
        return $this->hasPlan($tenant) && $this->featureAccessChecker->isFeatureEnabled($tenant, 'mcp_tools');
    }

    public function assertCanCreateProduct(Tenant $tenant): void
    {
        $this->assertCountLimit($tenant, 'products', count($this->products->findByTenantOrdered($tenant)), 'productos / servicios');
    }

    public function assertCanCreatePlaybook(Tenant $tenant): void
    {
        $this->assertCountLimit($tenant, 'playbooks', count($this->playbooks->findByTenantOrdered($tenant)), 'guías comerciales');
    }

    public function assertCanCreateEntryPoint(Tenant $tenant): void
    {
        $this->assertCountLimit($tenant, 'entry_points', count($this->entryPoints->findByTenantOrdered($tenant)), 'puntos de entrada');
    }

    public function assertCanCreateExternalTool(Tenant $tenant): void
    {
        $this->assertHasPlan($tenant);

        if (!$this->featureAccessChecker->isFeatureEnabled($tenant, 'mcp_tools')) {
            throw new PlanLimitExceededException($this->featureDisabledMessage($tenant, 'servidores MCP'));
        }

        $currentCount = count(array_values(array_filter(
            $this->externalTools->findByTenantOrdered($tenant),
            static fn (ExternalTool $tool): bool => $tool->getType() === 'mcp_remote'
        )));
        $this->assertCountLimit($tenant, 'mcp_tools', $currentCount, 'servidores MCP');
    }

    private function assertCountLimit(Tenant $tenant, string $limitKey, int $currentCount, string $resourceLabel): void
    {
        $plan = $this->assertHasPlan($tenant);
        $limit = $this->limitValue($tenant, $limitKey);
        if ($limit === null) {
            return;
        }

        if ($currentCount < $limit) {
            return;
        }

        $planLabel = $plan->getName() !== '' ? $plan->getName() : $plan->getCode();
        throw new PlanLimitExceededException(sprintf(
            'Tu plan %s ya alcanzó el límite de %s (%d/%d).',
            $planLabel,
            $resourceLabel,
            $currentCount,
            $limit
        ));
    }

    private function featureDisabledMessage(Tenant $tenant, string $resourceLabel): string
    {
        $plan = $this->assertHasPlan($tenant);
        $planLabel = $plan->getName() !== '' ? $plan->getName() : $plan->getCode();

        return sprintf('Tu plan %s no incluye %s.', $planLabel, $resourceLabel);
    }

    private function assertHasPlan(Tenant $tenant): \App\Entity\CommercialPlan
    {
        $plan = $tenant->getCommercialPlan();
        if (!$plan instanceof \App\Entity\CommercialPlan) {
            throw new PlanLimitExceededException('Este negocio no tiene un plan comercial asignado.');
        }

        return $plan;
    }

    private function hasPlan(Tenant $tenant): bool
    {
        return $tenant->getCommercialPlan() instanceof \App\Entity\CommercialPlan;
    }

    private function limitValue(Tenant $tenant, string $limitKey): int|float|string|null
    {
        $limit = $this->planLimitResolver->getLimit($tenant, $limitKey);
        if ($limit === null || $limit === '') {
            return null;
        }

        if (is_string($limit) && !is_numeric($limit)) {
            return null;
        }

        return is_numeric($limit) ? (int) round((float) $limit) : null;
    }
}
