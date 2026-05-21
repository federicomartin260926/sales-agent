<?php

namespace App\Service;

use App\Entity\Tenant;
use App\Repository\TenantRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class ActiveTenantContext
{
    private const SESSION_KEY = 'backend.active_tenant_id';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TenantRepository $tenants,
    ) {
    }

    public function getActiveTenant(): ?Tenant
    {
        $tenantId = $this->getStoredActiveTenantId();
        if ($tenantId === null) {
            return null;
        }

        $tenant = $this->tenants->find($tenantId);
        if (!$tenant instanceof Tenant || !$tenant->isActive()) {
            $this->clear();

            return null;
        }

        return $tenant;
    }

    public function getActiveTenantId(): ?string
    {
        return $this->getActiveTenant()?->getId()->toRfc4122();
    }

    public function setActiveTenant(Tenant $tenant): void
    {
        if (!$tenant->isActive()) {
            $this->clear();

            return;
        }

        $session = $this->session();
        if (!$session instanceof SessionInterface) {
            return;
        }

        $session->set(self::SESSION_KEY, $tenant->getId()->toRfc4122());
    }

    public function clear(): void
    {
        $session = $this->session();
        if (!$session instanceof SessionInterface) {
            return;
        }

        $session->remove(self::SESSION_KEY);
    }

    public function hasActiveTenant(): bool
    {
        return $this->getActiveTenant() instanceof Tenant;
    }

    private function getStoredActiveTenantId(): ?string
    {
        $session = $this->session();
        if (!$session instanceof SessionInterface) {
            return null;
        }

        $tenantId = trim((string) $session->get(self::SESSION_KEY, ''));
        return $tenantId !== '' ? $tenantId : null;
    }

    private function session(): ?SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return null;
        }

        return $request->getSession();
    }
}
