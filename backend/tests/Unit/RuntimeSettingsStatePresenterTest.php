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
            'audio_gateway_bearer_token' => 'runtime-token',
            'openai_transcription_model' => 'gpt-4o-mini-transcribe',
            'audio_max_bytes' => '26214400',
            'audio_transcription_cost_per_unit_eur' => '0.02',
            'audio_llm_followup_reserve_cost_eur' => '0.01',
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
            'audio_gateway_bearer_token' => '',
            'openai_transcription_model' => '',
            'audio_max_bytes' => '',
            'audio_transcription_cost_per_unit_eur' => '',
            'audio_llm_followup_reserve_cost_eur' => '',
        ]);

        self::assertSame('blocked', $state['overall']['status']);
        self::assertSame('blocked', $state['llm']['status']);
        self::assertSame('blocked', $state['openai']['status']);
        self::assertSame('blocked', $state['audio']['status']);
    }
}
