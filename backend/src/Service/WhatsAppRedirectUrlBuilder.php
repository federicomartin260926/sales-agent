<?php

namespace App\Service;

use App\Entity\EntryPoint;
use InvalidArgumentException;

final class WhatsAppRedirectUrlBuilder
{
    public function build(EntryPoint $entryPoint, string $ref): string
    {
        $phone = $this->resolvePhone($entryPoint);
        if ($phone === '') {
            throw new InvalidArgumentException('No public WhatsApp phone is configured for this entry point');
        }

        $message = $this->resolveMessage($entryPoint, $ref);

        return sprintf('https://wa.me/%s?text=%s', $phone, rawurlencode($message));
    }

    private function resolvePhone(EntryPoint $entryPoint): string
    {
        $phone = $entryPoint->getTenant()->getWhatsappPublicPhone() ?? '';
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return $digits;
    }

    private function resolveMessage(EntryPoint $entryPoint, string $ref): string
    {
        $baseMessage = $entryPoint->getDefaultMessage();
        if (!is_string($baseMessage) || trim($baseMessage) === '') {
            $baseMessage = 'Hola, quiero información.';
        }

        return trim($baseMessage).' Ref: '.$ref;
    }
}
