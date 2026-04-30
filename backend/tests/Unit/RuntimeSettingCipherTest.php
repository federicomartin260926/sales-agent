<?php

namespace App\Tests\Unit;

use App\Service\RuntimeSettingCipher;
use PHPUnit\Framework\TestCase;

final class RuntimeSettingCipherTest extends TestCase
{
    public function testEncryptAndDecryptRoundTrip(): void
    {
        $cipher = new RuntimeSettingCipher('test-secret-key');
        $encrypted = $cipher->encrypt('super-secret-value');

        self::assertNotSame('super-secret-value', $encrypted);
        self::assertStringStartsWith('enc:v1:', $encrypted);
        self::assertSame('super-secret-value', $cipher->decrypt($encrypted));
    }

    public function testDecryptPassesThroughPlainValuesForLegacyRows(): void
    {
        $cipher = new RuntimeSettingCipher('test-secret-key');

        self::assertSame('plain-value', $cipher->decrypt('plain-value'));
    }
}
