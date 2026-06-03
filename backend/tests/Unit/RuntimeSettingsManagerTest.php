<?php

namespace App\Tests\Unit;

use App\Entity\AiModelCostReference;
use App\Entity\RuntimeSetting;
use App\Repository\AiModelCostReferenceRepository;
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

    /**
     * @param AiModelCostReference[] $references
     */
    private function createAiModelCostRepositoryFake(array $references = []): AiModelCostReferenceRepository
    {
        return new class($references) extends AiModelCostReferenceRepository {
            /**
             * @param AiModelCostReference[] $references
             */
            public function __construct(private array $references)
            {
            }

            /**
             * @return AiModelCostReference[]
             */
            public function findActiveByUsageType(string $usageType): array
            {
                $usageType = strtolower(trim($usageType));

                return array_values(array_filter(
                    $this->references,
                    static fn (AiModelCostReference $reference): bool => $reference->isActive() && $reference->getUsageType() === $usageType
                ));
            }
        };
    }

    private function aiModelCostReference(
        string $model,
        float $input,
        float $cached,
        float $output,
        string $currency = 'USD',
        bool $active = true,
    ): AiModelCostReference {
        $reference = new AiModelCostReference(AiModelCostReference::USAGE_TYPE_LLM_CHAT, $model);
        $reference->setInputCostPerMillion($input);
        $reference->setCachedInputCostPerMillion($cached);
        $reference->setOutputCostPerMillion($output);
        $reference->setCurrency($currency);
        $reference->setActive($active);

        return $reference;
    }

    public function testResolvedValuesUseDefaultsAndDecryptSecrets(): void
    {
        $cipher = new RuntimeSettingCipher('test-secret-key');
        $repository = $this->createRepositoryFake([
            'openai_api_key' => new RuntimeSetting('openai_api_key', $cipher->encrypt('sk-test')),
        ]);
        $aiModelCosts = $this->createAiModelCostRepositoryFake([
            $this->aiModelCostReference('gpt-4.1-mini', 0.4, 0.1, 1.6),
            $this->aiModelCostReference('gpt-5.4-mini', 0.75, 0.075, 4.5),
        ]);

        $manager = new RuntimeSettingsManager($repository, new RuntimeSettingCatalog($aiModelCosts), $cipher);
        $values = $manager->resolvedValues();
        $state = $manager->formState();

        self::assertSame('auto', $values['llm_default_profile']);
        self::assertSame('https://api.openai.com/v1', $values['openai_base_url']);
        self::assertSame('sk-test', $values['openai_api_key']);
        self::assertSame('15', $values['openai_timeout_seconds']);
        self::assertSame('15', $values['ollama_timeout_seconds']);
        self::assertSame('15', $values['audio_timeout_seconds']);
        self::assertSame('http://audio-gateway', $values['audio_gateway_base_url']);
        self::assertSame('********', $state['openai_api_key']['value']);
        self::assertTrue($state['openai_api_key']['fullWidth']);
        self::assertSame([
            ['value' => 'gpt-4.1-mini', 'label' => 'gpt-4.1-mini (0.4/0.1/1.6 USD)'],
            ['value' => 'gpt-5.4-mini', 'label' => 'gpt-5.4-mini (0.75/0.075/4.5 USD)'],
            ['value' => 'gpt-4o-mini', 'label' => 'gpt-4o-mini'],
        ], $state['openai_model']['options']);
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
            'openai_api_key' => '********',
            'openai_timeout_seconds' => '21',
            'ollama_base_url' => 'http://localhost:11434',
            'ollama_model' => 'qwen2.5',
            'ollama_timeout_seconds' => '23',
            'audio_gateway_base_url' => 'http://audio-gateway',
            'audio_timeout_seconds' => '17',
        ]);

        self::assertContains('llm_default_profile', $result['saved']);
        self::assertContains('openai_base_url', $result['saved']);
        self::assertContains('audio_gateway_base_url', $result['saved']);
        self::assertCount(16, $repository->saved);

        $values = $manager->resolvedValues();
        self::assertSame('ollama', $values['llm_default_profile']);
        self::assertSame('https://api.openai.com/v1', $values['openai_base_url']);
        self::assertSame('21', $values['openai_timeout_seconds']);
        self::assertSame('http://localhost:11434', $values['ollama_base_url']);
        self::assertSame('23', $values['ollama_timeout_seconds']);
        self::assertSame('sk-old', $values['openai_api_key']);
        self::assertSame('http://audio-gateway', $values['audio_gateway_base_url']);
        self::assertSame('17', $values['audio_timeout_seconds']);
    }

    public function testValidateKeepsCurrentOpenAiModelWhenItIsNoLongerActive(): void
    {
        $cipher = new RuntimeSettingCipher('test-secret-key');
        $repository = $this->createRepositoryFake([
            'openai_model' => new RuntimeSetting('openai_model', 'gpt-4o-mini'),
        ]);
        $aiModelCosts = $this->createAiModelCostRepositoryFake([
            $this->aiModelCostReference('gpt-4.1-mini', 0.4, 0.1, 1.6),
            $this->aiModelCostReference('gpt-5.4-mini', 0.75, 0.075, 4.5),
        ]);

        $manager = new RuntimeSettingsManager($repository, new RuntimeSettingCatalog($aiModelCosts), $cipher);
        $errors = $manager->validate([
            'openai_base_url' => 'https://api.openai.com/v1',
            'openai_model' => 'gpt-4o-mini',
            'openai_api_key' => 'sk-proj-abcdefghijklmnop',
        ]);

        self::assertSame([], $errors);
    }

    public function testValidateRejectsInvalidUrlsKeysAndTimeouts(): void
    {
        $cipher = new RuntimeSettingCipher('test-secret-key');
        $repository = $this->createRepositoryFake();
        $manager = new RuntimeSettingsManager($repository, new RuntimeSettingCatalog(), $cipher);

        $errors = $manager->validate([
            'openai_base_url' => 'federico@example.com',
            'openai_api_key' => 'wrong-secret',
            'openai_timeout_seconds' => '0',
            'ollama_base_url' => 'http://ollama-vpn-bridge:11434',
            'ollama_timeout_seconds' => 'abc',
            'audio_gateway_base_url' => 'http://audio-gateway',
            'audio_timeout_seconds' => '0',
        ]);

        self::assertNotEmpty($errors);
        self::assertContains('El endpoint "Base URL de OpenAI" debe ser una URL válida con http o https.', $errors);
        self::assertContains('La clave API de OpenAI no parece válida.', $errors);
        self::assertContains('El valor de "Timeout de OpenAI" debe ser mayor o igual que 1.', $errors);
        self::assertContains('El valor de "Timeout de Ollama" debe ser un entero valido.', $errors);
        self::assertContains('El valor de "Timeout de audio" debe ser mayor o igual que 1.', $errors);
    }
}
