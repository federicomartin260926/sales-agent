<?php

namespace App\Service;

use App\Entity\EntryPoint;
use App\Entity\EntryPointUtm;
use App\Repository\EntryPointUtmRepository;

final class EntryPointUtmFactory
{
    public function __construct(private readonly EntryPointUtmRepository $entryPointUtms)
    {
    }

    public function create(EntryPoint $entryPoint, array $attribution = []): EntryPointUtm
    {
        $utm = new EntryPointUtm($entryPoint, $this->generateRef());
        $utm->setUtmSource($this->stringOrNull($attribution['utm_source'] ?? null));
        $utm->setUtmMedium($this->stringOrNull($attribution['utm_medium'] ?? null));
        $utm->setUtmCampaign($this->stringOrNull($attribution['utm_campaign'] ?? null));
        $utm->setUtmTerm($this->stringOrNull($attribution['utm_term'] ?? null));
        $utm->setUtmContent($this->stringOrNull($attribution['utm_content'] ?? null));
        $utm->setGclid($this->stringOrNull($attribution['gclid'] ?? null));
        $utm->setFbclid($this->stringOrNull($attribution['fbclid'] ?? null));

        $this->entryPointUtms->save($utm);

        return $utm;
    }

    private function generateRef(): string
    {
        do {
            $ref = rtrim(strtr(base64_encode(random_bytes(6)), '+/', '-_'), '=');
        } while ($this->entryPointUtms->findByRef($ref) instanceof EntryPointUtm);

        return $ref;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
