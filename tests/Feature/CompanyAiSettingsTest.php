<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\AiTagPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Company AI Settings API Tests
 *
 * Phase J.2.5: Tests for AI settings UI endpoints
 */
class CompanyAiSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $admin;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
        ]);

        // Create admin user with settings permission
        $this->admin = User::factory()->create([
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);

        $this->admin->tenants()->attach($this->tenant->id, [
            'permissions' => json_encode([
                'companies.settings.edit',
                'ai.usage.view'
            ])
        ]);

        // Create regular user without permission
        $this->user = User::factory()->create([
            'first_name' => 'Regular',
            'last_name' => 'User',
        ]);

        $this->user->tenants()->attach($this->tenant->id, [
            'permissions' => json_encode(['assets.view'])
        ]);

        // Set up app context
        app()->instance('tenant', $this->tenant);
    }

    /**
     * Test: Get AI settings as admin
     */
    public function test_get_ai_settings_as_admin(): void
    {
        Auth::login($this->admin);

        $response = $this->getJson('/app/api/companies/ai-settings');

        $response->assertOk();
        $response->assertJsonStructure([
            'settings' => [
                'disable_ai_tagging',
                'enable_ai_tag_suggestions',
                'enable_ai_tag_auto_apply',
                'ai_auto_tag_limit_mode',
                'ai_auto_tag_limit_value',
            ]
        ]);

        // Check default values
        $settings = $response->json('settings');
        $this->assertFalse($settings['disable_ai_tagging']);
        $this->assertTrue($settings['enable_ai_tag_suggestions']);
        $this->assertFalse($settings['enable_ai_tag_auto_apply']); // OFF by default
        $this->assertEquals('best_practices', $settings['ai_auto_tag_limit_mode']);
    }

    /**
     * Test: Get AI settings without permission
     */
    public function test_get_ai_settings_without_permission(): void
    {
        Auth::login($this->user);

        $response = $this->getJson('/app/api/companies/ai-settings');

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'You do not have permission to view AI settings.'
        ]);
    }

    /**
     * Test: Update AI settings as admin
     */
    public function test_update_ai_settings_as_admin(): void
    {
        Auth::login($this->admin);

        $response = $this->patchJson('/app/api/companies/ai-settings', [
            'disable_ai_tagging' => true,
            'enable_ai_tag_suggestions' => false,
            'enable_ai_tag_auto_apply' => true,
            'ai_auto_tag_limit_mode' => 'custom',
            'ai_auto_tag_limit_value' => 8,
        ]);

        $response->assertOk();
        $response->assertJson([
            'message' => 'AI settings updated successfully',
        ]);

        $settings = $response->json('settings');
        $this->assertTrue($settings['disable_ai_tagging']);
        $this->assertFalse($settings['enable_ai_tag_suggestions']);
        $this->assertTrue($settings['enable_ai_tag_auto_apply']);
        $this->assertEquals('custom', $settings['ai_auto_tag_limit_mode']);
        $this->assertEquals(8, $settings['ai_auto_tag_limit_value']);

        // Verify database update
        $this->assertDatabaseHas('tenant_ai_tag_settings', [
            'tenant_id' => $this->tenant->id,
            'disable_ai_tagging' => true,
            'enable_ai_tag_auto_apply' => true,
            'ai_auto_tag_limit_value' => 8,
        ]);
    }

    /**
     * Test: Update AI settings without permission
     */
    public function test_update_ai_settings_without_permission(): void
    {
        Auth::login($this->user);

        $response = $this->patchJson('/app/api/companies/ai-settings', [
            'disable_ai_tagging' => true,
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'You do not have permission to update AI settings.'
        ]);
    }

    /**
     * Test: Validate AI settings input
     */
    public function test_validate_ai_settings_input(): void
    {
        Auth::login($this->admin);

        // Test invalid mode
        $response = $this->patchJson('/app/api/companies/ai-settings', [
            'ai_auto_tag_limit_mode' => 'invalid_mode',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ai_auto_tag_limit_mode']);

        // Test invalid limit value
        $response = $this->patchJson('/app/api/companies/ai-settings', [
            'ai_auto_tag_limit_value' => 0,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ai_auto_tag_limit_value']);

        // Test limit too high
        $response = $this->patchJson('/app/api/companies/ai-settings', [
            'ai_auto_tag_limit_value' => 100,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ai_auto_tag_limit_value']);

        // Test invalid boolean
        $response = $this->patchJson('/app/api/companies/ai-settings', [
            'disable_ai_tagging' => 'not_boolean',
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['disable_ai_tagging']);
    }

    /**
     * Test: Partial updates work
     */
    public function test_partial_ai_settings_update(): void
    {
        Auth::login($this->admin);

        // Set initial settings
        $policyService = app(AiTagPolicyService::class);
        $policyService->updateTenantSettings($this->tenant, [
            'disable_ai_tagging' => false,
            'enable_ai_tag_suggestions' => true,
            'enable_ai_tag_auto_apply' => false,
        ]);

        // Update only one setting
        $response = $this->patchJson('/app/api/companies/ai-settings', [
            'disable_ai_tagging' => true,
        ]);

        $response->assertOk();
        $settings = $response->json('settings');
        
        // Updated setting
        $this->assertTrue($settings['disable_ai_tagging']);
        
        // Other settings should remain unchanged
        $this->assertTrue($settings['enable_ai_tag_suggestions']);
        $this->assertFalse($settings['enable_ai_tag_auto_apply']);
    }

    /**
     * Test: Settings persist across requests
     */
    public function test_ai_settings_persist(): void
    {
        Auth::login($this->admin);

        // Update settings
        $this->patchJson('/app/api/companies/ai-settings', [
            'disable_ai_tagging' => true,
            'ai_auto_tag_limit_mode' => 'custom',
            'ai_auto_tag_limit_value' => 5,
        ]);

        // Fetch settings again
        $response = $this->getJson('/app/api/companies/ai-settings');
        
        $response->assertOk();
        $settings = $response->json('settings');
        $this->assertTrue($settings['disable_ai_tagging']);
        $this->assertEquals('custom', $settings['ai_auto_tag_limit_mode']);
        $this->assertEquals(5, $settings['ai_auto_tag_limit_value']);
    }

    /**
     * Test: Tenant isolation
     */
    public function test_ai_settings_tenant_isolation(): void
    {
        Auth::login($this->admin);

        // Create another tenant
        $otherTenant = Tenant::create([
            'name' => 'Other Company',
            'slug' => 'other-company',
        ]);

        // Update settings for current tenant
        $this->patchJson('/app/api/companies/ai-settings', [
            'disable_ai_tagging' => true,
        ]);

        // Switch tenant context
        app()->instance('tenant', $otherTenant);

        // Settings should be different (defaults) for other tenant
        $policyService = app(AiTagPolicyService::class);
        $otherSettings = $policyService->getTenantSettings($otherTenant);
        
        $this->assertFalse($otherSettings['disable_ai_tagging']); // Should be default
    }

    /**
     * Test: Error handling for invalid tenant context
     */
    public function test_ai_settings_no_tenant_context(): void
    {
        Auth::login($this->admin);
        
        // Remove tenant context
        app()->instance('tenant', null);

        $response = $this->getJson('/app/api/companies/ai-settings');
        $response->assertStatus(400);
        $response->assertJson(['error' => 'Tenant context not found']);

        $response = $this->patchJson('/app/api/companies/ai-settings', [
            'disable_ai_tagging' => true,
        ]);
        $response->assertStatus(400);
        $response->assertJson(['error' => 'Tenant context not found']);
    }
}