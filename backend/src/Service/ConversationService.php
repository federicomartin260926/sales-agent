<?php

namespace App\Service;

use App\Entity\Conversation;
use App\Entity\EntryPoint;
use App\Entity\EntryPointUtm;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Repository\ConversationRepository;

final class ConversationService
{
    public function __construct(private readonly ConversationRepository $conversations)
    {
    }

    /**
     * @param array{utm_source?: mixed, utm_medium?: mixed, utm_campaign?: mixed, utm_term?: mixed, utm_content?: mixed, gclid?: mixed, fbclid?: mixed} $utm
     * @return array{conversation: Conversation, created: bool}
     */
    public function upsert(
        Tenant $tenant,
        string $customerPhone,
        ?Product $product = null,
        ?EntryPoint $entryPoint = null,
        ?EntryPointUtm $entryPointUtm = null,
        ?string $customerName = null,
        ?string $firstMessage = null,
        ?string $externalConversationId = null,
        array $utm = [],
        ?string $crmBranchRef = null,
    ): array {
        $conversation = $this->conversations->findActiveByTenantPhone($tenant, $customerPhone);
        $created = false;

        if (!$conversation instanceof Conversation) {
            $conversation = new Conversation($tenant, $customerPhone);
            $created = true;
        }

        $conversation->setTenant($tenant);
        $conversation->setCustomerPhone($customerPhone);

        if ($conversation->getProduct() === null && $product instanceof Product) {
            $conversation->setProduct($product);
        } elseif ($conversation->getProduct() === null && $entryPoint instanceof EntryPoint) {
            $conversation->setProduct($entryPoint->getProduct());
        }

        if ($conversation->getEntryPoint() === null && $entryPoint instanceof EntryPoint) {
            $conversation->setEntryPoint($entryPoint);
        }

        if ($conversation->getEntryPointUtm() === null && $entryPointUtm instanceof EntryPointUtm) {
            $conversation->setEntryPointUtm($entryPointUtm);
            if ($entryPointUtm->getStatus() === EntryPointUtm::STATUS_PENDING) {
                $entryPointUtm->markMatched();
            }
            $this->copyAttributionFromEntryPointUtm($conversation, $entryPointUtm);
        }

        $this->copyExplicitAttribution($conversation, $utm);

        if ($crmBranchRef !== null && trim($crmBranchRef) !== '') {
            $conversation->setCrmBranchRef(trim($crmBranchRef));
        } elseif ($entryPoint instanceof EntryPoint && $entryPoint->getCrmBranchRef() !== null && trim($entryPoint->getCrmBranchRef()) !== '') {
            $conversation->setCrmBranchRef(trim($entryPoint->getCrmBranchRef()));
        }

        if ($customerName !== null && trim($customerName) !== '') {
            $conversation->setCustomerName(trim($customerName));
        }

        if ($firstMessage !== null && trim($firstMessage) !== '') {
            $conversation->setFirstMessage($created ? trim($firstMessage) : ($conversation->getFirstMessage() ?? trim($firstMessage)));
            $conversation->setLastMessageAt(new \DateTimeImmutable());
        }

        if ($externalConversationId !== null && trim($externalConversationId) !== '') {
            $conversation->setExternalConversationId(trim($externalConversationId));
        }

        $this->conversations->save($conversation);

        return [
            'conversation' => $conversation,
            'created' => $created,
        ];
    }

    private function copyAttributionFromEntryPointUtm(Conversation $conversation, EntryPointUtm $entryPointUtm): void
    {
        if ($conversation->getUtmSource() === null) {
            $conversation->setUtmSource($entryPointUtm->getUtmSource());
        }
        if ($conversation->getUtmMedium() === null) {
            $conversation->setUtmMedium($entryPointUtm->getUtmMedium());
        }
        if ($conversation->getUtmCampaign() === null) {
            $conversation->setUtmCampaign($entryPointUtm->getUtmCampaign());
        }
        if ($conversation->getUtmTerm() === null) {
            $conversation->setUtmTerm($entryPointUtm->getUtmTerm());
        }
        if ($conversation->getUtmContent() === null) {
            $conversation->setUtmContent($entryPointUtm->getUtmContent());
        }
        if ($conversation->getGclid() === null) {
            $conversation->setGclid($entryPointUtm->getGclid());
        }
        if ($conversation->getFbclid() === null) {
            $conversation->setFbclid($entryPointUtm->getFbclid());
        }
    }

    /**
     * @param array{utm_source?: mixed, utm_medium?: mixed, utm_campaign?: mixed, utm_term?: mixed, utm_content?: mixed, gclid?: mixed, fbclid?: mixed} $utm
     */
    private function copyExplicitAttribution(Conversation $conversation, array $utm): void
    {
        if ($conversation->getUtmSource() === null && isset($utm['utm_source']) && is_string($utm['utm_source']) && trim($utm['utm_source']) !== '') {
            $conversation->setUtmSource(trim($utm['utm_source']));
        }
        if ($conversation->getUtmMedium() === null && isset($utm['utm_medium']) && is_string($utm['utm_medium']) && trim($utm['utm_medium']) !== '') {
            $conversation->setUtmMedium(trim($utm['utm_medium']));
        }
        if ($conversation->getUtmCampaign() === null && isset($utm['utm_campaign']) && is_string($utm['utm_campaign']) && trim($utm['utm_campaign']) !== '') {
            $conversation->setUtmCampaign(trim($utm['utm_campaign']));
        }
        if ($conversation->getUtmTerm() === null && isset($utm['utm_term']) && is_string($utm['utm_term']) && trim($utm['utm_term']) !== '') {
            $conversation->setUtmTerm(trim($utm['utm_term']));
        }
        if ($conversation->getUtmContent() === null && isset($utm['utm_content']) && is_string($utm['utm_content']) && trim($utm['utm_content']) !== '') {
            $conversation->setUtmContent(trim($utm['utm_content']));
        }
        if ($conversation->getGclid() === null && isset($utm['gclid']) && is_string($utm['gclid']) && trim($utm['gclid']) !== '') {
            $conversation->setGclid(trim($utm['gclid']));
        }
        if ($conversation->getFbclid() === null && isset($utm['fbclid']) && is_string($utm['fbclid']) && trim($utm['fbclid']) !== '') {
            $conversation->setFbclid(trim($utm['fbclid']));
        }
    }
}
