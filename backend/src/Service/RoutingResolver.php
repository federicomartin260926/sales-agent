<?php

namespace App\Service;

use App\Entity\EntryPoint;
use App\Entity\EntryPointUtm;
use App\Entity\Tenant;
use App\Repository\EntryPointRepository;
use App\Repository\EntryPointUtmRepository;
use App\Repository\TenantRepository;

final class RoutingResolver
{
    public function __construct(
        private readonly EntryPointRepository $entryPoints,
        private readonly EntryPointUtmRepository $entryPointUtms,
        private readonly TenantRepository $tenants,
    ) {
    }

    public function findEntryPointByCode(string $code): ?EntryPoint
    {
        return $this->entryPoints->findActiveByCode(trim($code));
    }

    public function findEntryPointUtmByRef(string $ref): ?EntryPointUtm
    {
        return $this->entryPointUtms->findByRef(trim($ref));
    }

    public function findTenantByWhatsappPhoneNumberId(string $phoneNumberId): ?Tenant
    {
        $phoneNumberId = trim($phoneNumberId);
        if ($phoneNumberId === '') {
            return null;
        }

        $tenant = $this->tenants->findOneBy(['whatsappPhoneNumberId' => $phoneNumberId]);

        return $tenant instanceof Tenant ? $tenant : null;
    }
}
