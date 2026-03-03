<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Models\Brand;
use App\Models\Tenant;
use App\Services\BrandDNA\BrandCoherenceScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\TestCase;
use Tests\TestCase as BaseTestCase;

/**
 * Brand Coherence Scoring Service — unit tests for snapshot influence and color authority.
 */
class BrandCoherenceScoringServiceTest extends BaseTestCase
{
    use RefreshDatabase;

    public function test_coherence_changes_when_snapshot_colors_match(): void
    {
        $service = new BrandCoherenceScoringService;
        $draftPayload = [
            'scoring_rules' => ['allowed_color_palette' => ['#000000']],
        ];

        $resultEmpty = $service->score($draftPayload, [], null, null, 0);
        $standardsEmpty = $resultEmpty['sections']['standards']['score'] ?? 0;

        $snapshotWithMatch = ['primary_colors' => ['#000000']];
        $resultMatch = $service->score($draftPayload, [], $snapshotWithMatch, null, 0);
        $standardsMatch = $resultMatch['sections']['standards']['score'] ?? 0;

        $this->assertGreaterThan($standardsEmpty, $standardsMatch, 'Standards score must be higher when snapshot colors match draft palette');
    }

    public function test_default_colors_not_used_in_scoring(): void
    {
        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        $brandWithDefaults = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Default Brand',
            'slug' => 'default-brand',
            'primary_color' => '#6366f1',
            'secondary_color' => '#8b5cf6',
            'accent_color' => '#06b6d4',
            'primary_color_user_defined' => false,
            'secondary_color_user_defined' => false,
            'accent_color_user_defined' => false,
        ]);

        $service = new BrandCoherenceScoringService;
        $draftPayload = [
            'scoring_rules' => ['allowed_color_palette' => []],
            'typography' => [],
            'visual' => [],
        ];

        $result = $service->score($draftPayload, [], null, $brandWithDefaults, 0);
        $standards = $result['sections']['standards'] ?? [];

        $this->assertSame(0, $standards['coverage'] ?? -1, 'Brand with theme defaults but user_defined=false must not count toward standards coverage');
    }

    public function test_user_defined_color_overrides_extraction(): void
    {
        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        $brandUserDefined = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'User Brand',
            'slug' => 'user-brand',
            'primary_color' => '#FF0000',
            'primary_color_user_defined' => true,
            'secondary_color' => null,
            'secondary_color_user_defined' => false,
            'accent_color' => null,
            'accent_color_user_defined' => false,
        ]);

        $service = new BrandCoherenceScoringService;
        $draftPayload = [
            'scoring_rules' => ['allowed_color_palette' => []],
            'typography' => ['primary_font' => 'Helvetica'],
            'visual' => [],
        ];

        $result = $service->score($draftPayload, [], null, $brandUserDefined, 0);
        $standards = $result['sections']['standards'] ?? [];

        $this->assertGreaterThan(0, $standards['coverage'] ?? 0, 'User-defined primary_color must count toward standards coverage');
    }
}
