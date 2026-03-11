<?php

namespace Tests\Unit\Services\BrandDNA;

use App\Models\Brand;
use App\Models\BrandModel;
use App\Models\BrandModelVersion;
use App\Models\BrandModelVersionInsightState;
use App\Models\Tenant;
use App\Services\BrandDNA\BuilderResumeStepService;
use App\Services\BrandDNA\ResearchFinalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuilderResumeStepServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected BrandModelVersion $draft;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);
        $brandModel = BrandModel::create([
            'brand_id' => $this->brand->id,
            'is_enabled' => true,
        ]);
        $this->draft = BrandModelVersion::create([
            'brand_model_id' => $brandModel->id,
            'version_number' => 1,
            'source_type' => 'manual',
            'model_payload' => [],
            'status' => 'draft',
        ]);
    }

    public function test_processing_incomplete_resumes_to_processing(): void
    {
        $this->mock(ResearchFinalizationService::class, function ($mock) {
            $mock->shouldReceive('compute')->andReturn(['research_finalized' => false]);
        });

        $service = app(BuilderResumeStepService::class);
        $result = $service->resolve($this->brand, $this->draft);

        $this->assertSame('processing', $result['step']);
        $this->assertSame('Continue Processing', $result['label']);
    }

    public function test_finalized_research_but_not_reviewed_resumes_to_research_summary(): void
    {
        $this->mock(ResearchFinalizationService::class, function ($mock) {
            $mock->shouldReceive('compute')->andReturn(['research_finalized' => true]);
        });

        $service = app(BuilderResumeStepService::class);
        $result = $service->resolve($this->brand, $this->draft);

        $this->assertSame('research-summary', $result['step']);
        $this->assertSame('Review Research', $result['label']);
    }

    public function test_last_visited_archetype_resumes_to_archetype(): void
    {
        $this->mock(ResearchFinalizationService::class, function ($mock) {
            $mock->shouldReceive('compute')->andReturn(['research_finalized' => true]);
        });

        $this->draft->update([
            'builder_progress' => [
                'last_visited_step' => 'archetype',
                'last_completed_step' => 'research-summary',
            ],
        ]);

        $service = app(BuilderResumeStepService::class);
        $result = $service->resolve($this->brand, $this->draft->fresh());

        $this->assertSame('archetype', $result['step']);
        $this->assertSame('Continue Archetype', $result['label']);
    }

    public function test_last_visited_purpose_resumes_to_purpose(): void
    {
        $this->mock(ResearchFinalizationService::class, function ($mock) {
            $mock->shouldReceive('compute')->andReturn(['research_finalized' => true]);
        });

        $this->draft->update([
            'builder_progress' => [
                'last_visited_step' => 'purpose_promise',
                'last_completed_step' => 'archetype',
            ],
        ]);

        $service = app(BuilderResumeStepService::class);
        $result = $service->resolve($this->brand, $this->draft->fresh());

        $this->assertSame('purpose_promise', $result['step']);
        $this->assertSame('Continue Purpose', $result['label']);
    }

    public function test_research_reviewed_via_insight_state_viewed_at(): void
    {
        $this->mock(ResearchFinalizationService::class, function ($mock) {
            $mock->shouldReceive('compute')->andReturn(['research_finalized' => true]);
        });

        BrandModelVersionInsightState::create([
            'brand_model_version_id' => $this->draft->id,
            'viewed_at' => now(),
        ]);

        $this->draft->update([
            'builder_progress' => [
                'last_visited_step' => 'archetype',
            ],
        ]);

        $service = app(BuilderResumeStepService::class);
        $result = $service->resolve($this->brand, $this->draft->fresh());

        $this->assertSame('archetype', $result['step']);
    }

    public function test_ambiguous_state_falls_back_to_background(): void
    {
        $this->mock(ResearchFinalizationService::class, function ($mock) {
            $mock->shouldReceive('compute')->andReturn(['research_finalized' => true]);
        });

        $this->draft->update([
            'builder_progress' => [
                'last_visited_step' => 'invalid_step',
            ],
        ]);

        $service = app(BuilderResumeStepService::class);
        $result = $service->resolve($this->brand, $this->draft->fresh());

        $this->assertSame('background', $result['step']);
        $this->assertSame('Continue Builder', $result['label']);
    }

    public function test_no_draft_returns_background(): void
    {
        $service = app(BuilderResumeStepService::class);
        $result = $service->resolve($this->brand, null);

        $this->assertSame('background', $result['step']);
        $this->assertSame('Start Brand Guidelines', $result['label']);
    }
}
