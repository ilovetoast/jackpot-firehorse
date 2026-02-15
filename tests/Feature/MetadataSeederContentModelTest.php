<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\SystemCategory;
use App\Models\Tenant;
use App\Services\SystemCategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Metadata seeder content model tests.
 *
 * Verifies that:
 * - New category from system template only enables collection + tags
 * - System category template has version = 1
 * - Brand creation clones from system templates
 */
class MetadataSeederContentModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalMetadataFields();
    }

    protected function seedMinimalMetadataFields(): void
    {
        $base = [
            'scope' => 'system',
            'applies_to' => 'all',
            'type' => 'text',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'group_key' => 'general',
            'plan_gate' => null,
            'deprecated_at' => null,
            'replacement_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        foreach (['tags' => 'Tags', 'collection' => 'Collection', 'photo_type' => 'Photo Type'] as $key => $label) {
            if (! DB::table('metadata_fields')->where('key', $key)->exists()) {
                DB::table('metadata_fields')->insert(array_merge($base, ['key' => $key, 'system_label' => $label]));
            }
        }
    }

    public function test_system_category_template_has_version_one(): void
    {
        $this->artisan('db:seed', [
            '--class' => \Database\Seeders\SystemCategoryTemplateSeeder::class,
        ]);

        $templates = SystemCategory::all();
        $this->assertGreaterThan(0, $templates->count());

        foreach ($templates as $template) {
            $this->assertSame(1, $template->version, "Template {$template->slug} should have version=1");
        }
    }

    public function test_new_category_from_template_only_enables_collection_and_tags(): void
    {
        $this->artisan('db:seed', [
            '--class' => \Database\Seeders\SystemCategoryTemplateSeeder::class,
        ]);

        $tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-tenant']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        $template = SystemCategory::where('slug', 'photography')
            ->where('asset_type', AssetType::ASSET)
            ->where('version', 1)
            ->first();

        $this->assertNotNull($template, 'Photography template should exist');

        $category = app(SystemCategoryService::class)->addTemplateToBrand($brand, $template);
        $this->assertNotNull($category);

        $tagsField = DB::table('metadata_fields')->where('key', 'tags')->first();
        $collectionField = DB::table('metadata_fields')->where('key', 'collection')->first();
        $photoTypeField = DB::table('metadata_fields')->where('key', 'photo_type')->first();

        $this->assertNotNull($tagsField, 'tags field should exist');
        $this->assertNotNull($collectionField, 'collection field should exist');
        $this->assertNotNull($photoTypeField, 'photo_type field should exist');

        $visibility = DB::table('metadata_field_visibility')
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('category_id', $category->id)
            ->get()
            ->keyBy('metadata_field_id');

        $tagsVisible = $visibility->get($tagsField->id);
        $collectionVisible = $visibility->get($collectionField->id);
        $photoTypeVisible = $visibility->get($photoTypeField->id);

        $this->assertNotNull($tagsVisible, 'tags should have visibility row');
        $this->assertFalse((bool) $tagsVisible->is_hidden, 'tags should be enabled');

        $this->assertNotNull($collectionVisible, 'collection should have visibility row');
        $this->assertFalse((bool) $collectionVisible->is_hidden, 'collection should be enabled');

        $this->assertNotNull($photoTypeVisible, 'photo_type should have visibility row');
        $this->assertTrue((bool) $photoTypeVisible->is_hidden, 'photo_type should be disabled (only collection+tags enabled)');
    }

    public function test_new_brand_category_has_version_one(): void
    {
        $this->artisan('db:seed', [
            '--class' => \Database\Seeders\SystemCategoryTemplateSeeder::class,
        ]);

        $tenant = Tenant::create(['name' => 'Test Tenant', 'slug' => 'test-tenant']);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        $category = Category::where('brand_id', $brand->id)
            ->where('slug', 'photography')
            ->first();

        $this->assertNotNull($category, 'Photography category should be created for new brand');
        $this->assertSame(1, $category->system_version, 'Category should have system_version=1');
    }
}
