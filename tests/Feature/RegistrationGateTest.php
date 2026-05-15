<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegistrationGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_gateway_register_redirects_when_registration_disabled_without_bypass(): void
    {
        config(['registration.enabled' => false, 'registration.bypass_secret' => '']);

        $this->get('/gateway?mode=register')
            ->assertRedirect();
    }

    public function test_gateway_register_renders_when_bypass_key_matches(): void
    {
        config(['registration.enabled' => false, 'registration.bypass_secret' => 'team-only-secret']);

        $this->get('/gateway?mode=register&registration_key=team-only-secret')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Gateway/Index')
                ->where('mode', 'register'));
    }

    public function test_gateway_register_post_rejected_when_disabled_without_bypass_session(): void
    {
        config(['registration.enabled' => false, 'registration.bypass_secret' => '']);

        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->post('/gateway/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'newuser-'.uniqid('', true).'@example.com',
            'password' => 'Password1!x',
            'password_confirmation' => 'Password1!x',
            'company_name' => 'Test Co',
        ])->assertSessionHasErrors('email');
    }

    public function test_gateway_register_post_allowed_after_bypass_visit(): void
    {
        config(['registration.enabled' => false, 'registration.bypass_secret' => 'team-only-secret']);

        $this->get('/gateway?mode=register&registration_key=team-only-secret')->assertOk();

        $this->withoutMiddleware(VerifyCsrfToken::class);

        $email = 'gate-bypass-'.uniqid('', true).'@example.com';

        $this->post('/gateway/register', [
            'first_name' => 'Bypass',
            'last_name' => 'User',
            'email' => $email,
            'password' => 'Password1!x',
            'password_confirmation' => 'Password1!x',
            'company_name' => 'Bypass Co '.uniqid(),
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => $email]);
    }
}
