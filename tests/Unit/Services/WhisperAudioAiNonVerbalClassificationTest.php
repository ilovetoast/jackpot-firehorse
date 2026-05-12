<?php

namespace Tests\Unit\Services;

use App\Services\Audio\Providers\WhisperAudioAiProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WhisperAudioAiNonVerbalClassificationTest extends TestCase
{
    #[DataProvider('nonVerbalProvider')]
    public function test_classifies_non_verbal_or_hallucination(string $input, bool $expected): void
    {
        $provider = app(WhisperAudioAiProvider::class);
        $m = new \ReflectionMethod(WhisperAudioAiProvider::class, 'isLikelyNonVerbalOrHallucination');
        $m->setAccessible(true);
        $this->assertSame($expected, $m->invoke($provider, $input));
    }

    public static function nonVerbalProvider(): array
    {
        return [
            'empty' => ['', true],
            'thanks for watching' => ['Thanks for watching!', true],
            'thank you for watching' => ['Thank you for watching.', true],
            'bracket music' => ['[Music]', true],
            'real speech' => [
                'In this episode we walk through the quarterly roadmap and next steps for the design team.',
                false,
            ],
            'thanks plus real content' => [
                'Thanks for watching. Today we discuss the migration plan and rollout schedule for the API changes.',
                false,
            ],
            'thanks with short tail' => [
                'Thanks for watching! Hope you enjoyed this one.',
                true,
            ],
            'thank you with subscribe tail' => [
                'Thank you for watching and please subscribe to our channel',
                true,
            ],
        ];
    }

    public function test_build_verbal_insights_instrumental_strips_transcript(): void
    {
        $provider = app(WhisperAudioAiProvider::class);
        $m = new \ReflectionMethod(WhisperAudioAiProvider::class, 'buildVerbalInsights');
        $m->setAccessible(true);
        $out = $m->invoke($provider, 'Thanks for watching!', [['start' => 0, 'end' => 1, 'text' => 'Thanks']]);
        $this->assertSame('instrumental', $out['content_kind']);
        $this->assertNull($out['transcript']);
        $this->assertSame([], $out['transcript_chunks']);
        $this->assertIsString($out['summary']);
        $this->assertContains('instrumental', $out['mood']);
    }

    public function test_build_verbal_insights_speech_keeps_chunks(): void
    {
        $provider = app(WhisperAudioAiProvider::class);
        $m = new \ReflectionMethod(WhisperAudioAiProvider::class, 'buildVerbalInsights');
        $m->setAccessible(true);
        $chunks = [['start' => 0, 'end' => 2, 'text' => 'Hello world']];
        $out = $m->invoke($provider, 'Hello world', $chunks);
        $this->assertSame('speech', $out['content_kind']);
        $this->assertSame('Hello world', $out['transcript']);
        $this->assertSame($chunks, $out['transcript_chunks']);
    }
}
