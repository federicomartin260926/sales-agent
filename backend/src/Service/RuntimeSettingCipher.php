<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class RuntimeSettingCipher
{
    private const PREFIX = 'enc:v1:';

    private string $key;

    public function __construct(#[Autowire('%kernel.secret%')] string $secret)
    {
        $this->key = hash('sha256', $secret, true);
    }

    public function encrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($value, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($encrypted === false) {
            throw new \RuntimeException('Unable to encrypt runtime setting.');
        }

        return self::PREFIX.base64_encode($iv.$tag.$encrypted);
    }

    public function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (!str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        $payload = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($payload === false || strlen($payload) < 28) {
            throw new \RuntimeException('Unable to decrypt runtime setting.');
        }

        $iv = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $ciphertext = substr($payload, 28);
        $decrypted = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($decrypted === false) {
            throw new \RuntimeException('Unable to decrypt runtime setting.');
        }

        return $decrypted;
    }
}
