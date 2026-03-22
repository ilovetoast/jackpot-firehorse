<?php

namespace Tests\Feature\Mail;

use App\Models\Tenant;
use App\Support\TenantMailBranding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Fixtures\Mail\ProbeTenantBrandingMailable;
use Tests\TestCase;

class TenantMailBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.tenant_branding.enabled' => true]);
        config(['mail.tenant_branding.from_address' => 'no-reply@staging-jackpot.velvetysoft.com']);
        config(['mail.from.address' => 'default@example.com']);
        config(['mail.from.name' => 'Default App']);
    }

    public function test_outgoing_mail_uses_staging_from_address_and_tenant_display_name(): void
    {
        $tenantA = Tenant::query()->create([
            'name' => 'Brand A',
            'slug' => 'brand-a-'.uniqid('', true),
        ]);
        $tenantB = Tenant::query()->create([
            'name' => 'Brand B',
            'slug' => 'brand-b-'.uniqid('', true),
        ]);

        Mail::fake();

        Mail::to('one@example.com')->send(new ProbeTenantBrandingMailable($tenantA));
        Mail::assertSent(ProbeTenantBrandingMailable::class, function (ProbeTenantBrandingMailable $mailable) use ($tenantA) {
            $mailable->envelope();

            return $mailable->tenant->is($tenantA)
                && config('mail.from.address') === 'no-reply@staging-jackpot.velvetysoft.com'
                && config('mail.from.name') === 'Brand A';
        });

        TenantMailBranding::reset();

        Mail::to('two@example.com')->send(new ProbeTenantBrandingMailable($tenantB));
        Mail::assertSent(ProbeTenantBrandingMailable::class, function (ProbeTenantBrandingMailable $mailable) use ($tenantB) {
            $mailable->envelope();

            return $mailable->tenant->is($tenantB)
                && config('mail.from.address') === 'no-reply@staging-jackpot.velvetysoft.com'
                && config('mail.from.name') === 'Brand B';
        });
    }

    public function test_reply_to_is_set_when_tenant_email_present(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Brand A',
            'slug' => 'brand-a-'.uniqid('', true),
            'email' => 'hello@branda.example',
        ]);

        $mailable = new ProbeTenantBrandingMailable($tenant);
        $mailable->envelope();

        $this->assertTrue($mailable->hasReplyTo('hello@branda.example', 'Brand A'));
    }

    public function test_branding_disabled_outside_staging_unless_config_forced(): void
    {
        config(['mail.tenant_branding.enabled' => false]);

        $tenant = Tenant::query()->create([
            'name' => 'Brand A',
            'slug' => 'brand-a-'.uniqid('', true),
        ]);

        TenantMailBranding::apply($tenant);

        $this->assertSame('default@example.com', config('mail.from.address'));
        $this->assertSame('Default App', config('mail.from.name'));
    }
}
