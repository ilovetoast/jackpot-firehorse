<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class E2EStudioVersionsBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_route_is_not_available_when_disabled(): void
    {
        Config::set('e2e_studio_versions.enabled', false);
        Config::set('e2e_studio_versions.token', 'x');

        $this->get('/__e2e__/studio-versions/bootstrap?token=x')->assertNotFound();
    }

    public function test_bootstrap_rejects_bad_token(): void
    {
        Config::set('e2e_studio_versions.enabled', true);
        Config::set('e2e_studio_versions.token', 'correct-token');

        $this->get('/__e2e__/studio-versions/bootstrap?token=wrong')->assertForbidden();
    }

    public function test_bootstrap_redirects_to_generative_with_authenticated_session(): void
    {
        Config::set('e2e_studio_versions.enabled', true);
        Config::set('e2e_studio_versions.token', 'correct-token');

        $response = $this->get('/__e2e__/studio-versions/bootstrap?token=correct-token');

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('/app/generative?composition=', $location);
        $this->assertAuthenticated();
    }
}
