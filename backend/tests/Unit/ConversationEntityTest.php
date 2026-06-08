<?php

namespace App\Tests\Unit;

use App\Entity\Conversation;
use App\Entity\Tenant;
use PHPUnit\Framework\TestCase;

final class ConversationEntityTest extends TestCase
{
    public function testToArrayIncludesOpenAiConversationCursor(): void
    {
        $tenant = new Tenant('Negocio Demo', 'negocio-demo');
        $conversation = new Conversation($tenant, '+34999999999');

        $conversation->setLastOpenAiResponseId('resp_123');
        $conversation->setLastOpenAiResponseAt(new \DateTimeImmutable('2026-06-08T10:00:00+00:00'));

        $payload = $conversation->toArray();

        self::assertSame('resp_123', $payload['lastOpenAiResponseId']);
        self::assertSame('2026-06-08T10:00:00+00:00', $payload['lastOpenAiResponseAt']);
    }
}
