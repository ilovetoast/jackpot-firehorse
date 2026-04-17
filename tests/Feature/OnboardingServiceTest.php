<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\BrandOnboardingProgress;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Brand $brand;
    protected User $user;
    protected OnboardingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-co',
        ]);

        $this->brand = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        $this->user = User::create([
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
            'email_verified_at' => now(),
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'admin']);
        $this->user->brands()->attach($this->brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->service = new OnboardingService();

        app()->instance('brand', $this->brand);
    }

    // ── Minimum Activation Logic ───────────────────────────────────

    public function test_minimum_activation_requires_all_four_steps(): void
    {
        $progress = $this->service->getOrCreateProgress($this->brand);

        $this->assertFalse($progress->minimumActivationMet());

        $progress->brand_name_confirmed = true;
        $this->assertFalse($progress->minimumActivationMet());

        $progress->primary_color_set = true;
        $this->assertFalse($progress->minimumActivationMet());

        $progress->brand_mark_confirmed = true;
        $this->assertFalse($progress->minimumActivationMet());

        $progress->starter_assets_count = 1;
        $this->assertTrue($progress->minimumActivationMet());
    }

    public function test_activation_percent_reflects_completed_steps(): void
    {
        $progress = $this->service->getOrCreateProgress($this->brand);

        $this->assertEquals(0, $progress->activationPercent());

        $progress->brand_name_confirmed = true;
        $progress->primary_color_set = true;
        $this->assertEquals(50, $progress->activationPercent());

        $progress->brand_mark_confirmed = true;
        $progress->starter_assets_count = 1;
        $this->assertEquals(100, $progress->activationPercent());
    }

    // ── Brand Mark Integrity ───────────────────────────────────────

    public function test_monogram_mark_counts_as_confirmed_immediately(): void
    {
        $this->service->saveBrandShell($this->brand, [
            'name' => 'My Brand',
            'primary_color' => '#ff0000',
            'use_monogram' => true,
        ]);

        $progress = $this->brand->fresh()->onboardingProgress;

        $this->assertTrue($progress->brand_mark_confirmed);
        $this->assertEquals('monogram', $progress->brand_mark_type);
        $this->assertNull($progress->brand_mark_asset_id);
    }

    public function test_logo_intent_without_asset_does_not_confirm(): void
    {
        $this->service->saveBrandShell($this->brand, [
            'name' => 'My Brand',
            'primary_color' => '#ff0000',
            'mark_type' => 'logo',
        ]);

        $progress = $this->brand->fresh()->onboardingProgress;

        $this->assertFalse($progress->brand_mark_confirmed);
        $this->assertEquals('logo', $progress->brand_mark_type);
    }

    public function test_logo_with_real_asset_id_confirms(): void
    {
        $this->service->saveBrandShell($this->brand, [
            'name' => 'My Brand',
            'primary_color' => '#ff0000',
            'logo_id' => 'abc-123-uuid',
        ]);

        $progress = $this->brand->fresh()->onboardingProgress;

        $this->assertTrue($progress->brand_mark_confirmed);
        $this->assertEquals('logo', $progress->brand_mark_type);
        $this->assertEquals('abc-123-uuid', $progress->brand_mark_asset_id);
    }

    public function test_logo_intent_does_not_satisfy_minimum_activation(): void
    {
        $this->service->saveBrandShell($this->brand, [
            'name' => 'My Brand',
            'primary_color' => '#ff0000',
            'mark_type' => 'logo',
        ]);

        $this->service->recordStarterAssets($this->brand, 3);

        $progress = $this->brand->fresh()->onboardingProgress;

        $this->assertFalse($progress->minimumActivationMet());
        $this->assertFalse($progress->isActivated());
    }

    public function test_monogram_satisfies_minimum_activation_with_other_steps(): void
    {
        $this->service->saveBrandShell($this->brand, [
            'name' => 'My Brand',
            'primary_color' => '#ff0000',
            'use_monogram' => true,
        ]);

        $this->service->recordStarterAssets($this->brand, 1);

        $progress = $this->brand->fresh()->onboardingProgress;

        $this->assertTrue($progress->minimumActivationMet());
        $this->assertTrue($progress->isActivated());
    }

    // ── Enrichment Processing ──────────────────────────────────────

    public function test_enrichment_sets_queued_when_actionable_data_provided(): void
    {
        $progress = $this->service->getOrCreateProgress($this->brand);

        $this->service->saveEnrichment($this->brand, [
            'website_url' => 'https://example.com',
        ]);

        $progress = $this->brand->fresh()->onboardingProgress;

        $this->assertEquals('queued', $progress->enrichment_processing_status);
    }

    public function test_enrichment_stays_null_without_actionable_data(): void
    {
        $progress = $this->service->getOrCreateProgress($this->brand);

        $this->service->saveEnrichment($this->brand, [
            'industry' => 'Technology',
        ]);

        $progress = $this->brand->fresh()->onboardingProgress;

        $this->assertNull($progress->enrichment_processing_status);
    }

    public function test_enrichment_transition_to_processing(): void
    {
        $progress = $this->service->getOrCreateProgress($this->brand);
        $progress->enrichment_processing_status = 'queued';
        $progress->save();

        OnboardingService::transitionEnrichmentStatus($this->brand->fresh(), 'processing', 'Reading your guidelines');

        $progress = $this->brand->fresh()->onboardingProgress;

        $this->assertEquals('processing', $progress->enrichment_processing_status);
        $this->assertEquals('Reading your guidelines', $progress->enrichment_processing_detail);
    }

    public function test_enrichment_transition_to_complete(): void
    {
        $progress = $this->service->getOrCreateProgress($this->brand);
        $progress->enrichment_processing_status = 'processing';
        $progress->save();

        OnboardingService::transitionEnrichmentStatus($this->brand->fresh(), 'complete', 'Research complete');

        $progress = $this->brand->fresh()->onboardingProgress;

        $this->assertEquals('complete', $progress->enrichment_processing_status);
    }

    public function test_enrichment_transition_to_failed(): void
    {
        $progress = $this->service->getOrCreateProgress($this->brand);
        $progress->enrichment_processing_status = 'processing';
        $progress->save();

        OnboardingService::transitionEnrichmentStatus($this->brand->fresh(), 'failed', 'We hit a problem');

        $progress = $this->brand->fresh()->onboardingProgress;

        $this->assertEquals('failed', $progress->enrichment_processing_status);
        $this->assertEquals('We hit a problem', $progress->enrichment_processing_detail);
    }

    public function test_enrichment_transition_noop_without_progress(): void
    {
        $brandWithoutProgress = Brand::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'No Progress Brand',
            'slug' => 'no-progress',
        ]);

        OnboardingService::transitionEnrichmentStatus($brandWithoutProgress, 'complete');

        $this->assertNull($brandWithoutProgress->onboardingProgress);
    }

    public function test_enrichment_rejects_invalid_status(): void
    {
        $progress = $this->service->getOrCreateProgress($this->brand);
        $progress->enrichment_processing_status = 'queued';
        $progress->save();

        OnboardingService::transitionEnrichmentStatus($this->brand->fresh(), 'invalid_status');

        $progress = $this->brand->fresh()->onboardingProgress;

        $this->assertEquals('queued', $progress->enrichment_processing_status);
    }

    // ── Blocking / Dismiss ─────────────────────────────────────────

    public function test_is_blocking_true_for_fresh_brand(): void
    {
        $this->assertTrue($this->service->isBlocking($this->brand));
    }

    public function test_is_blocking_false_after_activation(): void
    {
        $progress = $this->service->getOrCreateProgress($this->brand);
        $progress->update([
            'brand_name_confirmed' => true,
            'primary_color_set' => true,
            'brand_mark_confirmed' => true,
            'brand_mark_type' => 'monogram',
            'starter_assets_count' => 1,
            'activated_at' => now(),
        ]);

        $this->assertFalse($this->service->isBlocking($this->brand->fresh()));
    }

    public function test_is_blocking_false_after_dismiss(): void
    {
        $this->service->dismissCinematicFlow($this->brand);

        $this->assertFalse($this->service->isBlocking($this->brand->fresh()));
    }

    public function test_dismiss_sets_dismissed_at(): void
    {
        $this->service->dismissCinematicFlow($this->brand);

        $progress = $this->brand->fresh()->onboardingProgress;

        $this->assertTrue($progress->isDismissed());
        $this->assertNotNull($progress->dismissed_at);
    }

    // ── Card Dismiss (permanent) ───────────────────────────────────

    public function test_card_dismiss_is_separate_from_cinematic_dismiss(): void
    {
        $this->service->dismissCinematicFlow($this->brand);

        $progress = $this->brand->fresh()->onboardingProgress;

        $this->assertTrue($progress->isDismissed());
        $this->assertFalse($progress->isCardDismissed());
    }

    public function test_card_dismiss_sets_card_dismissed_at(): void
    {
        $this->service->dismissCard($this->brand);

        $progress = $this->brand->fresh()->onboardingProgress;

        $this->assertTrue($progress->isCardDismissed());
        $this->assertNotNull($progress->card_dismissed_at);
    }

    public function test_status_payload_includes_card_dismissed(): void
    {
        $this->service->dismissCinematicFlow($this->brand);

        $payload = $this->service->getStatusPayload($this->brand->fresh());

        $this->assertTrue($payload['is_dismissed']);
        $this->assertFalse($payload['is_card_dismissed']);

        $this->service->dismissCard($this->brand->fresh());

        $payload = $this->service->getStatusPayload($this->brand->fresh());

        $this->assertTrue($payload['is_card_dismissed']);
    }

    // ── Recommended Completion ─────────────────────────────────────

    public function test_recommended_completion_requires_three_assets_and_guidelines_or_url(): void
    {
        $progress = $this->service->getOrCreateProgress($this->brand);
        $progress->update([
            'brand_name_confirmed' => true,
            'primary_color_set' => true,
            'brand_mark_confirmed' => true,
            'brand_mark_type' => 'monogram',
            'starter_assets_count' => 3,
        ]);

        $this->assertFalse($progress->recommendedCompletionMet());

        $progress->update(['website_url' => 'https://example.com']);

        $this->assertTrue($progress->fresh()->recommendedCompletionMet());
    }

    // ── Status Payload ─────────────────────────────────────────────

    public function test_status_payload_includes_brand_mark_pending(): void
    {
        $this->service->saveBrandShell($this->brand, [
            'name' => 'My Brand',
            'primary_color' => '#ff0000',
            'mark_type' => 'logo',
        ]);

        $payload = $this->service->getStatusPayload($this->brand->fresh());

        $this->assertTrue($payload['steps']['brand_mark_pending']);
        $this->assertFalse($payload['steps']['brand_mark_confirmed']);
    }

    public function test_status_payload_shows_confirmed_for_monogram(): void
    {
        $this->service->saveBrandShell($this->brand, [
            'name' => 'My Brand',
            'primary_color' => '#ff0000',
            'use_monogram' => true,
        ]);

        $payload = $this->service->getStatusPayload($this->brand->fresh());

        $this->assertFalse($payload['steps']['brand_mark_pending']);
        $this->assertTrue($payload['steps']['brand_mark_confirmed']);
    }

    public function test_status_payload_includes_enrichment_detail(): void
    {
        $progress = $this->service->getOrCreateProgress($this->brand);
        $progress->update([
            'enrichment_processing_status' => 'processing',
            'enrichment_processing_detail' => 'Reading your guidelines',
        ]);

        $payload = $this->service->getStatusPayload($this->brand->fresh());

        $this->assertEquals('processing', $payload['enrichment_processing_status']);
        $this->assertEquals('Reading your guidelines', $payload['enrichment_processing_detail']);
    }

    // ── Checklist Items ────────────────────────────────────────────

    public function test_checklist_shows_logo_still_needed_for_logo_intent(): void
    {
        $this->service->saveBrandShell($this->brand, [
            'name' => 'My Brand',
            'primary_color' => '#ff0000',
            'mark_type' => 'logo',
        ]);

        $items = $this->service->getChecklistItems($this->brand->fresh());

        $brandMarkItem = collect($items)->firstWhere('key', 'brand_mark');

        $this->assertFalse($brandMarkItem['done']);
        $this->assertEquals('Logo still needed', $brandMarkItem['detail']);
    }

    public function test_checklist_shows_using_temporary_monogram(): void
    {
        $this->service->saveBrandShell($this->brand, [
            'name' => 'My Brand',
            'primary_color' => '#ff0000',
            'use_monogram' => true,
        ]);

        $items = $this->service->getChecklistItems($this->brand->fresh());

        $brandMarkItem = collect($items)->firstWhere('key', 'brand_mark');

        $this->assertTrue($brandMarkItem['done']);
        $this->assertEquals('Using temporary monogram', $brandMarkItem['detail']);
    }

    // ── Auto-Activation ────────────────────────────────────────────

    public function test_auto_activation_triggers_when_all_criteria_met(): void
    {
        $this->service->saveBrandShell($this->brand, [
            'name' => 'My Brand',
            'primary_color' => '#ff0000',
            'use_monogram' => true,
        ]);

        $this->assertNull($this->brand->fresh()->onboardingProgress->activated_at);

        $this->service->recordStarterAssets($this->brand, 1);

        $progress = $this->brand->fresh()->onboardingProgress;

        $this->assertNotNull($progress->activated_at);
        $this->assertTrue($progress->isActivated());
    }

    public function test_confirm_logo_mark_triggers_auto_activation(): void
    {
        $progress = $this->service->getOrCreateProgress($this->brand);
        $progress->update([
            'brand_name_confirmed' => true,
            'primary_color_set' => true,
            'brand_mark_type' => 'logo',
            'brand_mark_confirmed' => false,
            'starter_assets_count' => 1,
        ]);

        $this->assertFalse($progress->isActivated());

        $this->service->confirmLogoMark($this->brand->fresh(), 'asset-uuid-123');

        $progress = $this->brand->fresh()->onboardingProgress;

        $this->assertTrue($progress->brand_mark_confirmed);
        $this->assertEquals('asset-uuid-123', $progress->brand_mark_asset_id);
        $this->assertTrue($progress->isActivated());
    }
}
