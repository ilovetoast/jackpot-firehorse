<?php

namespace Tests\Unit\Support;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Support\GenerativeAiProvenance;
use Tests\TestCase;

class GenerativeAiProvenanceTest extends TestCase
{
    public function test_published_composition_pure_generative_uses_trained_source_type(): void
    {
        $user = new User;
        $user->forceFill(['id' => 5, 'first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $brand = new Brand;
        $brand->forceFill(['id' => 2, 'name' => 'Acme']);
        $hints = [
            'has_generative' => true,
            'has_images' => false,
            'reference_asset_ids' => [],
        ];

        $p = GenerativeAiProvenance::forPublishedComposition($user, $brand, null, $hints);

        $this->assertSame(GenerativeAiProvenance::DIGITAL_SOURCE_TRAINED, $p['digital_source_type']);
        $this->assertSame('editor_publish', $p['operation']);
        $this->assertSame(5, $p['creator']['user_id']);
        $this->assertSame('Ada Lovelace', $p['creator']['display_name']);
    }

    public function test_published_composition_with_references_uses_composite_source_type(): void
    {
        $user = new User;
        $user->forceFill(['id' => 1, 'first_name' => '', 'last_name' => '']);
        $brand = new Brand;
        $brand->forceFill(['id' => 1, 'name' => 'B']);
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $hints = [
            'has_generative' => true,
            'has_images' => false,
            'reference_asset_ids' => [$uuid],
        ];

        $p = GenerativeAiProvenance::forPublishedComposition($user, $brand, null, $hints);

        $this->assertSame(GenerativeAiProvenance::DIGITAL_SOURCE_COMPOSITE_TRAINED, $p['digital_source_type']);
        $this->assertSame([$uuid], $p['hints']['reference_asset_ids']);
        $this->assertSame('User #1', $p['creator']['display_name']);
    }

    public function test_persisted_output_edit_uses_composite_when_source_asset(): void
    {
        $user = new User;
        $user->forceFill(['id' => 3, 'first_name' => 'X', 'last_name' => 'Y']);
        $brand = new Brand;
        $brand->forceFill(['id' => 9, 'name' => 'Z']);
        $tenant = new Tenant;
        $tenant->forceFill(['id' => 99]);
        $ctx = [
            'source_asset_id' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
        ];

        $p = GenerativeAiProvenance::forPersistedGenerativeOutput($user, $brand, $tenant, $ctx, 'generative_edit');

        $this->assertSame(GenerativeAiProvenance::DIGITAL_SOURCE_COMPOSITE_TRAINED, $p['digital_source_type']);
        $this->assertSame('generative_edit', $p['operation']);
        $this->assertSame(99, $p['workspace']['tenant_id']);
    }
}
