<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Models\Brand;
use App\Models\BrandModel;
use App\Models\BrandModelVersion;
use App\Models\Tenant;
use App\Services\BrandDNA\SuggestionApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
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

    public function test_update_type_replaces_value(): void
    {
        $brandModel = $this->createBrandModel();
        $draft = BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
            'source_type' => 'manual',
            'model_payload' => [
                'scoring_rules' => ['allowed_color_palette' => ['#000000']],
            ],
            'status' => 'draft',
        ]);

        $applier = new SuggestionApplier;
        $suggestion = [
            'key' => 'SUG:standards.allowed_color_palette',
            'path' => 'scoring_rules.allowed_color_palette',
            'type' => 'update',
            'value' => ['#FFFFFF', '#003388'],
        ];

        $result = $applier->apply($draft, $suggestion);

        $this->assertSame($draft->id, $result->id);
        $result->refresh();
        $payload = $result->model_payload ?? [];
        $this->assertSame(['#FFFFFF', '#003388'], $payload['scoring_rules']['allowed_color_palette'] ?? null);
    }

    public function test_merge_type_merges_without_duplicates(): void
    {
        $brandModel = $this->createBrandModel();
        $draft = BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
            'source_type' => 'manual',
            'model_payload' => [
                'personality' => ['traits' => ['Creative', 'Bold']],
            ],
            'status' => 'draft',
        ]);

        $applier = new SuggestionApplier;
        $suggestion = [
            'key' => 'SUG:expression.traits',
            'path' => 'personality.traits',
            'type' => 'merge',
            'value' => ['Bold', 'Innovative'],
        ];

        $result = $applier->apply($draft, $suggestion);

        $result->refresh();
        $payload = $result->model_payload ?? [];
        $traits = $payload['personality']['traits'] ?? [];
        $this->assertSame(['Creative', 'Bold', 'Innovative'], $traits);
    }

    public function test_informational_type_does_not_modify_draft(): void
    {
        $brandModel = $this->createBrandModel();
        $draft = BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
            'source_type' => 'manual',
            'model_payload' => ['identity' => ['mission' => 'Original']],
            'status' => 'draft',
        ]);

        $applier = new SuggestionApplier;
        $suggestion = [
            'key' => 'SUG:standards.logo',
            'path' => 'visual.detected_logo',
            'type' => 'informational',
            'value' => 'https://example.com/logo.png',
        ];

        $result = $applier->apply($draft, $suggestion);

        $result->refresh();
        $payload = $result->model_payload ?? [];
        $this->assertSame('Original', $payload['identity']['mission'] ?? null);
        $this->assertArrayNotHasKey('visual', $payload);
    }

    public function test_invalid_path_throws_exception(): void
    {
        $brandModel = $this->createBrandModel();
        $draft = BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
            'source_type' => 'manual',
            'model_payload' => [],
            'status' => 'draft',
        ]);

        $applier = new SuggestionApplier;
        $suggestion = [
            'key' => 'SUG:invalid',
            'path' => '',
            'type' => 'update',
            'value' => 'x',
        ];

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Invalid path');

        $applier->apply($draft, $suggestion);
    }

    public function test_merge_creates_path_if_missing(): void
    {
        $brandModel = $this->createBrandModel();
        $draft = BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
            'source_type' => 'manual',
            'model_payload' => [],
            'status' => 'draft',
        ]);

        $applier = new SuggestionApplier;
        $suggestion = [
            'key' => 'SUG:expression.traits',
            'path' => 'personality.traits',
            'type' => 'merge',
            'value' => ['Creative'],
        ];

        $result = $applier->apply($draft, $suggestion);

        $result->refresh();
        $payload = $result->model_payload ?? [];
        $this->assertSame(['Creative'], $payload['personality']['traits'] ?? null);
    }
}
