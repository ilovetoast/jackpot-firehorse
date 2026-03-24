<?php

namespace Tests\Unit\Services;

use App\Models\Tenant;
use App\Services\AiUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiUsageServiceGenerativeEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generative_editor_images_cap_maps_enterprise_unlimited_to_zero(): void
    {
        $tenant = Tenant::create([
            'name' => 'T',
            'slug' => 't-gen',
            'manual_plan_override' => 'enterprise',
        ]);

        $cap = app(AiUsageService::class)->getMonthlyCap($tenant, 'generative_editor_images');

        $this->assertSame(0, $cap);
    }
}
