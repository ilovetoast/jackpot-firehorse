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
                ->where('mode', 'register')
                ->where('registration_beta_pending', false));
    }

    public function test_gateway_register_shows_beta_step_when_disabled_with_secret(): void
    {
        config(['registration.enabled' => false, 'registration.bypass_secret' => 'team-only-secret']);

        $this->get('/gateway?mode=register')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Gateway/Index')
                ->where('mode', 'register')
                ->where('registration_beta_pending', true));
    }

    public function test_registration_unlock_rejects_wrong_password(): void
    {
        config(['registration.enabled' => false, 'registration.bypass_secret' => 'correct-secret']);
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->from('/gateway?mode=register')
            ->post('/gateway/registration-unlock', [
                'registration_beta_password' => 'wrong-secret',
            ])
            ->assertSessionHasErrors('registration_beta_password');
    }

    public function test_registration_unlock_then_register_succeeds(): void
    {
        config(['registration.enabled' => false, 'registration.bypass_secret' => 'unlock-me']);
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->from('/gateway?mode=register')
            ->post('/gateway/registration-unlock', [
                'registration_beta_password' => 'unlock-me',
            ])
            ->assertRedirect(route('gateway', ['mode' => 'register']));

        $email = 'beta-unlock-'.uniqid('', true).'@example.com';

        $this->post('/gateway/register', [
            'first_name' => 'Beta',
            'last_name' => 'User',
            'email' => $email,
            'password' => 'Password1!x',
            'password_confirmation' => 'Password1!x',
            'company_name' => 'Beta Co '.uniqid(),
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => $email]);
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
