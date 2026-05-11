<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * The audio entry in `config/file_types.php` is the public contract
 * surfaced by the help panel + supported-types modal. Pin the per-format
 * details and capabilities so a future cleanup can't quietly drop a
 * codec or a codec note.
 */
class AudioFileTypesRegistryTest extends TestCase
{
    public function test_audio_registry_lists_all_five_formats_in_extensions(): void
    {
        $audio = config('file_types.types.audio');

        $this->assertIsArray($audio);
        foreach (['mp3', 'wav', 'aac', 'm4a', 'ogg', 'flac'] as $ext) {
            $this->assertContains($ext, $audio['extensions'], "missing extension: {$ext}");
        }
    }

    public function test_audio_registry_has_codec_details_for_every_format(): void
    {
        $audio = config('file_types.types.audio');
        $details = $audio['codec_details'] ?? [];

        foreach (['mp3', 'wav', 'aac', 'm4a', 'ogg', 'flac'] as $ext) {
            $this->assertArrayHasKey($ext, $details, "missing codec_details for {$ext}");
            $this->assertArrayHasKey('browser_playback', $details[$ext]);
            $this->assertArrayHasKey('ai_ingest', $details[$ext]);
            $this->assertNotEmpty($details[$ext]['note']);
        }
    }

    public function test_wav_and_flac_are_marked_for_browser_transcoding(): void
    {
        $details = config('file_types.types.audio.codec_details');

        $this->assertSame('transcoded', $details['wav']['browser_playback']);
        $this->assertSame('transcoded', $details['flac']['browser_playback']);
    }

    public function test_mp3_aac_m4a_ogg_play_natively_in_browser(): void
    {
        $details = config('file_types.types.audio.codec_details');

        foreach (['mp3', 'aac', 'm4a', 'ogg'] as $ext) {
            $this->assertSame('native', $details[$ext]['browser_playback'], "{$ext} should play natively");
        }
    }

    public function test_audio_capabilities_advertise_web_playback_derivative(): void
    {
        $caps = config('file_types.types.audio.capabilities');

        $this->assertTrue($caps['web_playback_derivative'] ?? false);
        $this->assertTrue($caps['ai_analysis'] ?? false);
        $this->assertTrue($caps['preview'] ?? false);
    }

    public function test_audio_handlers_include_web_playback_step(): void
    {
        $handlers = config('file_types.types.audio.handlers');

        $this->assertSame('generateAudioWebPlayback', $handlers['web_playback'] ?? null);
        $this->assertSame('generateAudioWaveform', $handlers['thumbnail'] ?? null);
        $this->assertSame('runAudioAiAnalysis', $handlers['ai_analysis'] ?? null);
    }

    public function test_oversized_for_ai_error_message_is_user_friendly(): void
    {
        $errors = config('file_types.types.audio.errors');

        $this->assertArrayHasKey('oversized_for_ai', $errors);
        $this->assertNotEmpty($errors['oversized_for_ai']);
        $this->assertStringNotContainsStringIgnoringCase('null', (string) $errors['oversized_for_ai']);
    }
}
