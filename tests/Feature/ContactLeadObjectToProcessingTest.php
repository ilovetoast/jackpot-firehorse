<?php

namespace Tests\Feature;

use App\Models\ContactLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactLeadObjectToProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_object_to_processing_sets_timestamps_and_clears_marketing_consent(): void
    {
        $lead = ContactLead::create([
            'kind' => ContactLead::KIND_NEWSLETTER,
            'email' => 'lead@example.com',
            'consent_marketing' => true,
        ]);

        $response = $this->from('/privacy/object-lead')
            ->post('/privacy/contact-leads/object-to-processing', [
                'email' => 'lead@example.com',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('info');

        $lead->refresh();
        $this->assertNotNull($lead->processing_objected_at);
        $this->assertFalse($lead->consent_marketing);
        $this->assertNotNull($lead->unsubscribed_at);
    }

    public function test_object_to_processing_applies_to_all_kinds_for_email(): void
    {
        $newsletter = ContactLead::create([
            'kind' => ContactLead::KIND_NEWSLETTER,
            'email' => 'multi@example.com',
            'consent_marketing' => true,
        ]);
        $sales = ContactLead::create([
            'kind' => ContactLead::KIND_SALES_INQUIRY,
            'email' => 'multi@example.com',
            'name' => 'Pat Example',
            'company' => 'Co',
            'company_size' => '1-10',
            'use_case' => 'brand_management',
            'consent_marketing' => true,
        ]);

        $this->from('/privacy/object-lead')
            ->post('/privacy/contact-leads/object-to-processing', [
                'email' => 'multi@example.com',
            ])
            ->assertRedirect();

        $this->assertNotNull($newsletter->fresh()->processing_objected_at);
        $this->assertNotNull($sales->fresh()->processing_objected_at);
        $this->assertFalse($sales->fresh()->consent_marketing);
    }

    public function test_honeypot_filled_does_not_update_leads(): void
    {
        $lead = ContactLead::create([
            'kind' => ContactLead::KIND_CONTACT_FORM,
            'email' => 'bot@example.com',
            'consent_marketing' => false,
        ]);

        $this->from('/privacy/object-lead')
            ->post('/privacy/contact-leads/object-to-processing', [
                'email' => 'bot@example.com',
                'website' => 'http://spam.com',
            ])
            ->assertRedirect();

        $lead->refresh();
        $this->assertNull($lead->processing_objected_at);
    }
}
