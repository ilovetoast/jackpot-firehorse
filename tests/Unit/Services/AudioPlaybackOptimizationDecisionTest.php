<?php

namespace Tests\Unit\Services;

use App\Models\Asset;
use App\Services\Audio\AudioPlaybackOptimizationService;
use App\Support\AudioPipelineQueueResolver;
use Tests\TestCase;

/**
 * Pin the *decision* surface for the audio playback pipeline. These are the
 * rules the upload UI, billing copy, and help service all depend on:
 *
 *   - WAV / FLAC: always transcoded to a 128 kbps MP3 derivative.
 *   - Large MP3 / AAC / M4A / OGG (>= 5 MB): transcoded to keep streaming
 *     bandwidth predictable.
 *   - Small MP3: passed through (no derivative — the original is the
 *     canonical playback file).
 *   - Source >= 100 MB: pipeline routes to audio-heavy queue so the
 *     re-encode does not block fast images-pipeline workers.
 *
 * The transcoder itself shells out to FFmpeg, but the *decision* (whether
 * to transcode and which queue to land on) is pure config + metadata logic.
 * Keeping these in a unit test lets us refactor the transcoder freely.
 */
class AudioPlaybackOptimizationDecisionTest extends TestCase
{
    private function makeAsset(array $attrs = [], array $audioMeta = []): Asset
    {
        $asset = new Asset;
        // Use loose attribute fill — Asset is unsaved, we only need attribute access.
        foreach ($attrs as $key => $value) {
            $asset->setAttribute($key, $value);
        }
        $asset->setAttribute('metadata', ['audio' => $audioMeta]);

        return $asset;
    }

    public function test_wav_always_transcodes_regardless_of_size(): void
    {
        $service = new AudioPlaybackOptimizationService;
        $asset = $this->makeAsset(
            ['size_bytes' => 200 * 1024, 'original_filename' => 'voice.wav', 'mime_type' => 'audio/wav'],
            ['codec' => 'pcm_s16le']
        );

        $decision = $service->decideStrategy($asset);

        $this->assertSame('transcode', $decision['action']);
        $this->assertStringStartsWith('force_codec:', $decision['reason']);
    }

    public function test_flac_always_transcodes_even_under_size_threshold(): void
    {
        $service = new AudioPlaybackOptimizationService;
        $asset = $this->makeAsset(
            ['size_bytes' => 3 * 1024 * 1024, 'original_filename' => 'song.flac', 'mime_type' => 'audio/flac'],
            ['codec' => 'flac']
        );

        $decision = $service->decideStrategy($asset);

        $this->assertSame('transcode', $decision['action']);
        $this->assertSame('force_codec:flac', $decision['reason']);
    }

    public function test_small_mp3_passes_through(): void
    {
        $service = new AudioPlaybackOptimizationService;
        $asset = $this->makeAsset(
            ['size_bytes' => 1024 * 1024, 'original_filename' => 'voice.mp3', 'mime_type' => 'audio/mpeg'],
            ['codec' => 'mp3']
        );

        $decision = $service->decideStrategy($asset);

        $this->assertSame('skip', $decision['action']);
        $this->assertSame('mp3_under_threshold', $decision['reason']);
    }

    public function test_large_mp3_transcodes_for_bandwidth(): void
    {
        config()->set('assets.audio.web_playback_min_source_bytes', 5 * 1024 * 1024);
        $service = new AudioPlaybackOptimizationService;
        $asset = $this->makeAsset(
            ['size_bytes' => 50 * 1024 * 1024, 'original_filename' => 'podcast.mp3', 'mime_type' => 'audio/mpeg'],
            ['codec' => 'mp3']
        );

        $decision = $service->decideStrategy($asset);

        $this->assertSame('transcode', $decision['action']);
        $this->assertSame('large_source', $decision['reason']);
    }

    public function test_small_aac_skips_transcode_browser_compatible(): void
    {
        $service = new AudioPlaybackOptimizationService;
        $asset = $this->makeAsset(
            ['size_bytes' => 800 * 1024, 'original_filename' => 'jingle.aac', 'mime_type' => 'audio/aac'],
            ['codec' => 'aac']
        );

        $decision = $service->decideStrategy($asset);

        $this->assertSame('skip', $decision['action']);
        $this->assertSame('small_browser_compatible', $decision['reason']);
    }

    public function test_small_m4a_passes_through(): void
    {
        $service = new AudioPlaybackOptimizationService;
        $asset = $this->makeAsset(
            ['size_bytes' => 2 * 1024 * 1024, 'original_filename' => 'note.m4a', 'mime_type' => 'audio/mp4'],
            ['codec' => 'aac']
        );

        $decision = $service->decideStrategy($asset);

        $this->assertSame('skip', $decision['action']);
    }

    public function test_small_ogg_passes_through(): void
    {
        $service = new AudioPlaybackOptimizationService;
        $asset = $this->makeAsset(
            ['size_bytes' => 1024 * 1024, 'original_filename' => 'clip.ogg', 'mime_type' => 'audio/ogg'],
            ['codec' => 'vorbis']
        );

        $decision = $service->decideStrategy($asset);

        $this->assertSame('skip', $decision['action']);
    }

    public function test_pipeline_queue_uses_audio_heavy_when_over_threshold(): void
    {
        config()->set('assets.audio.heavy_queue_min_bytes', 100 * 1024 * 1024);
        config()->set('queue.audio_queue', 'audio');
        config()->set('queue.audio_heavy_queue', 'audio-heavy');

        $this->assertSame('audio', AudioPipelineQueueResolver::forByteSize(50 * 1024 * 1024));
        $this->assertSame('audio-heavy', AudioPipelineQueueResolver::forByteSize(150 * 1024 * 1024));
        $this->assertSame('audio-heavy', AudioPipelineQueueResolver::forByteSize(100 * 1024 * 1024));
    }

    public function test_pipeline_queue_falls_back_when_heavy_min_zero(): void
    {
        // Operators can disable heavy routing by setting min to 0.
        config()->set('assets.audio.heavy_queue_min_bytes', 0);
        config()->set('queue.audio_queue', 'audio');

        $this->assertSame('audio', AudioPipelineQueueResolver::forByteSize(500 * 1024 * 1024));
    }
}
