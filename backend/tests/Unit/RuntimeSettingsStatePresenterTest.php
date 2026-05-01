<?php

namespace App\Tests\Unit;

use App\Service\RuntimeSettingsStatePresenter;
use PHPUnit\Framework\TestCase;

final class RuntimeSettingsStatePresenterTest extends TestCase
{
    public function testOverallReadyWhenDefaultProfileIsOperational(): void
    {
        $presenter = new RuntimeSettingsStatePresenter();

        $state = $presenter->present([
            'llm_default_profile' => 'openai',
            'openai_base_url' => 'https://api.openai.com/v1',
            'openai_api_key' => 'sk-test',
            'openai_model' => 'gpt-4o-mini',
            'ollama_base_url' => 'http://localhost:11434',
            'ollama_model' => 'llama3.1',
            'audio_gateway_base_url' => 'http://audio-gateway',
        ]);

        self::assertSame('ready', $state['overall']['status']);
        self::assertSame('ready', $state['llm']['status']);
        self::assertSame('ready', $state['openai']['status']);
        self::assertSame('ready', $state['audio']['status']);
    }

    public function testOverallBlockedWhenDefaultProfileIsIncomplete(): void
    {
        $presenter = new RuntimeSettingsStatePresenter();

        $state = $presenter->present([
            'llm_default_profile' => 'openai',
            'openai_base_url' => '',
            'openai_api_key' => '',
            'openai_model' => 'gpt-4o-mini',
            'ollama_base_url' => 'http://localhost:11434',
            'ollama_model' => 'llama3.1',
            'audio_gateway_base_url' => '',
        ]);

        self::assertSame('blocked', $state['overall']['status']);
        self::assertSame('blocked', $state['llm']['status']);
        self::assertSame('blocked', $state['openai']['status']);
        self::assertSame('blocked', $state['audio']['status']);
    }
}
