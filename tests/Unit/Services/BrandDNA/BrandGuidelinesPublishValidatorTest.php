<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Models\Brand;
use App\Models\BrandModel;
use App\Models\BrandModelVersion;
use App\Models\Tenant;
use App\Services\BrandDNA\BrandGuidelinesPublishValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandGuidelinesPublishValidatorTest extends TestCase
{
    use RefreshDatabase;

    protected function createBrandWithVersion(array $payload): Brand
    {
        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test-' . uniqid()]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand-' . uniqid(),
        ]);
        $brandModel = $brand->brandModel ?? BrandModel::create([
            'brand_id' => $brand->id,
            'is_enabled' => false,
        ]);
        BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
            'source_type' => 'manual',
            'model_payload' => $payload,
            'status' => 'draft',
        ]);

        return $brand;
    }

    public function test_publish_requires_minimum_brand_completeness(): void
    {
        $validator = new BrandGuidelinesPublishValidator;

        $brand = $this->createBrandWithVersion([
            'identity' => ['mission' => '', 'positioning' => ''],
            'personality' => ['primary_archetype' => null],
            'scoring_rules' => ['tone_keywords' => [], 'allowed_color_palette' => []],
            'typography' => ['primary_font' => null],
        ]);
        $version = $brand->brandModel->versions()->first();

        $missing = $validator->validate($version, $brand);

        $this->assertNotEmpty($missing);
        $this->assertContains('Archetype: Primary archetype is required', $missing);
        $this->assertContains('Purpose: Mission (WHY) is required', $missing);
        $this->assertContains('Purpose: Positioning statement (WHAT) is required', $missing);
        $this->assertContains('Expression: At least 3 tone keywords are required', $missing);
        $this->assertContains('Standards: At least 1 color in allowed palette is required', $missing);
        $this->assertContains('Standards: Primary font is required', $missing);
    }

    public function test_publish_passes_when_all_required_fields_present(): void
    {
        $validator = new BrandGuidelinesPublishValidator;

        $brand = $this->createBrandWithVersion([
            'identity' => [
                'mission' => 'We empower creators.',
                'positioning' => 'The leading platform for creative professionals.',
            ],
            'personality' => ['primary_archetype' => 'Creator'],
            'scoring_rules' => [
                'tone_keywords' => ['artistic', 'visionary', 'bold'],
                'allowed_color_palette' => [['hex' => '#003388']],
            ],
            'typography' => ['primary_font' => 'Inter'],
        ]);
        $version = $brand->brandModel->versions()->first();

        $missing = $validator->validate($version, $brand);

        $this->assertEmpty($missing);
    }
}
