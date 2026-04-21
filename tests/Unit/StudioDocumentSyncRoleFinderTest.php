<?php

namespace Tests\Unit;

use App\Support\StudioDocumentSyncRoleFinder;
use PHPUnit\Framework\TestCase;

class StudioDocumentSyncRoleFinderTest extends TestCase
{
    public function test_find_text_by_studio_sync_role(): void
    {
        $finder = new StudioDocumentSyncRoleFinder;
        $doc = [
            'layers' => [
                ['id' => 'a', 'type' => 'text', 'studioSyncRole' => 'headline', 'name' => 'Headline', 'content' => 'Hi', 'style' => ['fontSize' => 40], 'visible' => true, 'locked' => false, 'z' => 1, 'transform' => ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 40]],
            ],
        ];
        $this->assertSame('a', $finder->findTextLayerIdForRole($doc, 'headline'));
    }

    public function test_cta_visibility_collects_group(): void
    {
        $finder = new StudioDocumentSyncRoleFinder;
        $doc = [
            'layers' => [
                ['id' => 't1', 'type' => 'text', 'studioSyncRole' => 'cta', 'groupId' => 'g1', 'name' => 'CTA', 'content' => 'Buy', 'style' => ['fontSize' => 18], 'visible' => true, 'locked' => false, 'z' => 2, 'transform' => ['x' => 0, 'y' => 0, 'width' => 80, 'height' => 30]],
                ['id' => 'f1', 'type' => 'fill', 'studioSyncRole' => 'cta', 'groupId' => 'g1', 'fillRole' => 'cta_button', 'fillKind' => 'solid', 'color' => '#000', 'visible' => true, 'locked' => false, 'z' => 1, 'transform' => ['x' => 0, 'y' => 0, 'width' => 80, 'height' => 36]],
            ],
        ];
        $ids = $finder->findLayerIdsForVisibilityRole($doc, 'cta');
        sort($ids);
        $this->assertSame(['f1', 't1'], $ids);
    }
}
