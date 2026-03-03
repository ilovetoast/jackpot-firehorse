<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Models\Brand;
use App\Models\BrandModel;
use App\Models\BrandModelVersion;
use App\Models\Tenant;
use App\Services\BrandDNA\SuggestionApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SuggestionApplierTest extends TestCase
{
    use RefreshDatabase;

    protected function createBrandModel(): BrandModel
    {
        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test-' . uniqid()]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand-' . uniqid(),
        ]);

        return $brand->brandModel;
    }

    public function test_user_draft_cannot_be_overridden(): void
    {
        $brandModel = $this->createBrandModel();
        $draft = BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
            'source_type' => 'manual',
            'model_payload' => [
                'personality' => ['primary_archetype' => 'Creator'],
            ],
            'status' => 'draft',
        ]);

        $applier = new SuggestionApplier;
        $suggestion = [
            'key' => 'SUG:personality.primary_archetype',
            'path' => 'personality.primary_archetype',
            'type' => 'update',
            'value' => 'Ruler',
            'weight' => 0.7,
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot override user-defined value.');

        $applier->apply($draft, $suggestion);
    }
}
