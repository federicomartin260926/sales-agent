<?php

namespace App\Tests\Unit;

use App\Entity\RuntimeSetting;
use App\Repository\RuntimeSettingRepository;
use App\Service\RuntimeSettingCatalog;
use App\Service\RuntimeSettingCipher;
use App\Service\RuntimeSettingsManager;
use PHPUnit\Framework\TestCase;

final class RuntimeSettingsManagerTest extends TestCase
{
    private function createRepositoryFake(array $settings = []): RuntimeSettingRepository
    {
        return new class($settings) extends RuntimeSettingRepository {
            /**
             * @param array<string, RuntimeSetting> $settings
             */
            public function __construct(private array $settings)
            {
            }

            public function findOneByKey(string $settingKey): ?RuntimeSetting
            {
                return $this->settings[$settingKey] ?? null;
            }

            public array $saved = [];

            public function save(RuntimeSetting $setting, bool $flush = true): void
            {
                $this->settings[$setting->getSettingKey()] = $setting;
                $this->saved[] = $setting;
            }
        };
    }

    public function testResolvedValuesUseDefaultsAndDecryptSecrets(): void
    {
        $cipher = new RuntimeSettingCipher('test-secret-key');
        $repository = $this->createRepositoryFake([
            'openai_api_key' => new RuntimeSetting('openai_api_key', $cipher->encrypt('sk-test')),
            'audio_mode' => new RuntimeSetting('audio_mode', 'local'),
        ]);

        $manager = new RuntimeSettingsManager($repository, new RuntimeSettingCatalog(), $cipher);
        $values = $manager->resolvedValues();

        self::assertSame('auto', $values['llm_default_profile']);
        self::assertSame('https://api.openai.com/v1', $values['openai_base_url']);
        self::assertSame('sk-test', $values['openai_api_key']);
        self::assertSame('local', $values['audio_mode']);
    }

    public function testSaveEncryptsSecretsAndFallsBackToDefaultsForEmptyPlainFields(): void
    {
        $cipher = new RuntimeSettingCipher('test-secret-key');
        $repository = $this->createRepositoryFake([
            'openai_api_key' => new RuntimeSetting('openai_api_key', $cipher->encrypt('sk-old')),
        ]);

        $manager = new RuntimeSettingsManager($repository, new RuntimeSettingCatalog(), $cipher);
        $result = $manager->save([
            'llm_default_profile' => 'ollama',
            'openai_base_url' => '',
            'openai_model' => 'gpt-4.1-mini',
            'openai_api_key' => '',
            'ollama_base_url' => 'http://localhost:11434',
            'ollama_model' => 'qwen2.5',
            'audio_mode' => 'gateway',
            'audio_gateway_base_url' => 'http://audio-gateway',
            'audio_gateway_token' => 'audio-token',
        ]);

        self::assertContains('llm_default_profile', $result['saved']);
        self::assertContains('openai_base_url', $result['saved']);
        self::assertContains('audio_gateway_token', $result['saved']);
        self::assertCount(8, $repository->saved);

        $values = $manager->resolvedValues();
        self::assertSame('ollama', $values['llm_default_profile']);
        self::assertSame('https://api.openai.com/v1', $values['openai_base_url']);
        self::assertSame('http://localhost:11434', $values['ollama_base_url']);
        self::assertSame('audio-token', $values['audio_gateway_token']);
        self::assertSame('sk-old', $values['openai_api_key']);
    }

    public function testValidateRejectsInvalidUrlsAndOpenAiKey(): void
    {
        $cipher = new RuntimeSettingCipher('test-secret-key');
        $repository = $this->createRepositoryFake();
        $manager = new RuntimeSettingsManager($repository, new RuntimeSettingCatalog(), $cipher);

        $errors = $manager->validate([
            'openai_base_url' => 'federico@example.com',
            'openai_api_key' => 'wrong-secret',
            'ollama_base_url' => 'http://ollama:11434',
            'audio_gateway_base_url' => 'http://audio-gateway',
        ]);

        self::assertNotEmpty($errors);
        self::assertContains('El endpoint "Endpoint OpenAI" debe ser una URL válida con http o https.', $errors);
        self::assertContains('La clave API de OpenAI no parece válida.', $errors);
    }
}
