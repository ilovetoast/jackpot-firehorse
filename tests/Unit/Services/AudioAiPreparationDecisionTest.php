<?php

namespace Tests\Unit\Services;

use App\Models\Asset;
use App\Services\Audio\AudioAiPreparationService;
use Tests\TestCase;

/**
 * Pin the per-asset Whisper-prep decisions. The actual transcoder shells
 * out to FFmpeg, but the *choice* (web derivative vs original vs transcode)
 * is pure config + metadata logic — exactly the kind of thing that should
 * be regressed in fast unit tests so we don't accidentally ship a change
 * that uploads the wrong bytes.
 */
class AudioAiPreparationDecisionTest extends TestCase
{
    private function makeAsset(array $attrs = [], array $audioMeta = []): Asset
    {
        $asset = new Asset;
        foreach ($attrs as $key => $value) {
            $asset->setAttribute($key, $value);
        }
        $asset->setAttribute('storage_root_path', $attrs['storage_root_path'] ?? 't/asset/v1/audio.mp3');
        $asset->setAttribute('metadata', ['audio' => $audioMeta]);

        return $asset;
    }

    public function test_prefers_web_derivative_when_it_fits(): void
    {
        config()->set('assets.audio.ai_prep.whisper_max_bytes', 24 * 1024 * 1024);
        $service = new AudioAiPreparationService;

        $asset = $this->makeAsset(
            [
                'size_bytes' => 80 * 1024 * 1024,        // original is too big
                'original_filename' => 'pod.flac',
                'mime_type' => 'audio/flac',
            ],
            [
                'codec' => 'flac',
                'web_playback_path' => 't/asset/v1/previews/audio_web.mp3',
                'web_playback_size_bytes' => 18 * 1024 * 1024,
            ]
        );

        $decision = $service->chooseSource($asset);

        $this->assertSame('use_as_is', $decision['action']);
        $this->assertSame('web_derivative', $decision['source_kind']);
        $this->assertSame('web_derivative_fits', $decision['reason']);
        $this->assertSame('t/asset/v1/previews/audio_web.mp3', $decision['source_key']);
    }

    public function test_uses_original_when_small_and_accepted_codec(): void
    {
        config()->set('assets.audio.ai_prep.whisper_max_bytes', 24 * 1024 * 1024);
        config()->set('assets.audio.ai_prep.whisper_accepted_codecs', ['mp3', 'aac', 'flac', 'wav', 'ogg', 'm4a']);
        $service = new AudioAiPreparationService;

        $asset = $this->makeAsset(
            [
                'size_bytes' => 8 * 1024 * 1024,
                'original_filename' => 'voice.mp3',
                'mime_type' => 'audio/mpeg',
                'storage_root_path' => 't/asset/v1/voice.mp3',
            ],
            ['codec' => 'mp3']
        );

        $decision = $service->chooseSource($asset);

        $this->assertSame('use_as_is', $decision['action']);
        $this->assertSame('original', $decision['source_kind']);
        $this->assertSame('original_fits', $decision['reason']);
    }

    public function test_oversized_mp3_with_no_web_derivative_transcodes(): void
    {
        config()->set('assets.audio.ai_prep.whisper_max_bytes', 24 * 1024 * 1024);
        config()->set('assets.audio.ai_prep.whisper_accepted_codecs', ['mp3', 'aac', 'flac', 'wav', 'ogg']);
        $service = new AudioAiPreparationService;

        $asset = $this->makeAsset(
            [
                'size_bytes' => 60 * 1024 * 1024,
                'original_filename' => 'long.mp3',
                'mime_type' => 'audio/mpeg',
            ],
            ['codec' => 'mp3']
        );

        $decision = $service->chooseSource($asset);

        $this->assertSame('transcode', $decision['action']);
        $this->assertSame('oversized_for_whisper', $decision['reason']);
        $this->assertSame('original', $decision['source_kind']);
    }

    public function test_unaccepted_codec_transcodes_even_when_small(): void
    {
        config()->set('assets.audio.ai_prep.whisper_max_bytes', 24 * 1024 * 1024);
        config()->set('assets.audio.ai_prep.whisper_accepted_codecs', ['mp3', 'aac', 'm4a']);
        $service = new AudioAiPreparationService;

        $asset = $this->makeAsset(
            [
                'size_bytes' => 1 * 1024 * 1024,
                'original_filename' => 'odd.amr',
                'mime_type' => 'audio/amr',
            ],
            ['codec' => 'amr']
        );

        $decision = $service->chooseSource($asset);

        $this->assertSame('transcode', $decision['action']);
        $this->assertSame('codec_not_accepted', $decision['reason']);
    }

    public function test_picks_smaller_input_for_transcode_when_web_derivative_smaller_than_original(): void
    {
        config()->set('assets.audio.ai_prep.whisper_max_bytes', 24 * 1024 * 1024);
        $service = new AudioAiPreparationService;

        $asset = $this->makeAsset(
            [
                'size_bytes' => 200 * 1024 * 1024,
                'original_filename' => 'master.flac',
                'mime_type' => 'audio/flac',
            ],
            [
                'codec' => 'flac',
                // Web derivative exists but is itself > whisper_max (long podcast),
                // and it's still smaller than the FLAC original — better starting
                // point for AI transcoding (already 128 kbps stereo MP3).
                'web_playback_path' => 't/asset/v1/previews/audio_web.mp3',
                'web_playback_size_bytes' => 60 * 1024 * 1024,
            ]
        );

        $decision = $service->chooseSource($asset);

        $this->assertSame('transcode', $decision['action']);
        $this->assertSame('web_derivative', $decision['source_kind']);
    }
}
