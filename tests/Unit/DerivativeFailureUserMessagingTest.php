<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DerivativeFailureUserMessaging;
use PHPUnit\Framework\TestCase;

final class DerivativeFailureUserMessagingTest extends TestCase
{
    public function test_persisted_thumbnail_error_uses_short_summary(): void
    {
        $msg = DerivativeFailureUserMessaging::persistedThumbnailError(
            'Thumbnail generation failed: No thumbnails were generated (all styles failed)'
        );
        $this->assertStringContainsString('Thumbnail generation failed', $msg);
    }

    public function test_workspace_thumbnail_error_redacts_libreoffice_stack(): void
    {
        $raw = "Thumbnail generation failed: x\n\nFatal exception: Signal 6 Stack:\n/usr/lib/libreoffice/foo.so";
        $out = DerivativeFailureUserMessaging::workspaceThumbnailError($raw);
        $this->assertSame(DerivativeFailureUserMessaging::genericPreviewFailed(), $out);
    }

    public function test_workspace_metadata_strips_engine_keys(): void
    {
        $meta = [
            'category_id' => 1,
            'thumbnail_engine_error_summary' => 'secret',
            'thumbnail_error_technical' => 'stack',
        ];
        $pruned = DerivativeFailureUserMessaging::workspaceMetadata($meta);
        $this->assertSame(1, $pruned['category_id']);
        $this->assertArrayNotHasKey('thumbnail_engine_error_summary', $pruned);
        $this->assertArrayNotHasKey('thumbnail_error_technical', $pruned);
    }

    public function test_workspace_metadata_strips_preview_3d_storage_paths(): void
    {
        $meta = [
            'preview_3d' => [
                'status' => 'ready',
                'viewer_path' => 'secret/viewer.glb',
                'poster_path' => 'secret/poster.webp',
                'thumbnail_path' => 'secret/thumb.webp',
                'debug' => ['poster_stub' => true],
            ],
        ];
        $pruned = DerivativeFailureUserMessaging::workspaceMetadata($meta);
        $this->assertArrayHasKey('preview_3d', $pruned);
        $this->assertArrayNotHasKey('viewer_path', $pruned['preview_3d']);
        $this->assertArrayNotHasKey('poster_path', $pruned['preview_3d']);
        $this->assertArrayNotHasKey('thumbnail_path', $pruned['preview_3d']);
        $this->assertSame('ready', $pruned['preview_3d']['status']);
        $this->assertTrue($pruned['preview_3d']['poster_stub']);
    }
}
