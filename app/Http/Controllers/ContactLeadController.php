<?php

namespace App\Http\Controllers;

use App\Mail\ContactLeadReceived;
use App\Models\ContactLead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Public inbound capture — marketing contact form and newsletter signup.
 *
 * Unauthenticated by design. Rate limiting + a hidden honeypot field are the
 * first line of spam defence; proper bot mitigation (captcha, etc.) can be
 * layered on later without changing this contract.
 *
 * See {@see \App\Models\ContactLead} and the contact_leads migration for
 * rationale on why this doesn't reuse the Ticket system.
 */
class ContactLeadController extends Controller
{
    /**
     * POST /contact — full marketing contact form.
     */
    public function storeContact(Request $request): RedirectResponse
    {
        // Honeypot: a real user never fills `website`. Silent success on bot hits
        // so we don't tip off spammers that we're filtering them.
        if (filled($request->input('website'))) {
            return back()->with('info', "Thanks — we'll be in touch shortly.");
        }

        $data = $request->validate([
            'email' => ['required', 'email:rfc,dns', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'plan_interest' => ['nullable', 'string', Rule::in(['enterprise', 'agency', 'default'])],
            'message' => ['nullable', 'string', 'max:5000'],
            'consent_marketing' => ['nullable', 'boolean'],
        ]);

        $lead = $this->persist(ContactLead::KIND_CONTACT_FORM, $request, $data);
        $this->notifySales($lead);

        return back()->with('info', "Thanks — we received your message and the team will follow up soon.");
    }

    /**
     * POST /contact/sales — qualified sales / demo-request form.
     *
     * Collects the standard B2B lead-gen qualification fields (company size,
     * industry, use case, timeline). Same honeypot + throttling strategy as
     * the quick contact form; different validation surface.
     */
    public function storeSalesInquiry(Request $request): RedirectResponse
    {
        if (filled($request->input('website'))) {
            return back()->with('info', "Thanks — we'll be in touch to schedule a time.");
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc,dns', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'job_title' => ['nullable', 'string', 'max:150'],
            'company' => ['required', 'string', 'max:255'],
            'company_website' => ['nullable', 'string', 'max:255'],
            'plan_interest' => ['nullable', 'string', Rule::in(ContactLead::PLAN_INTERESTS)],
            'company_size' => ['required', 'string', Rule::in(ContactLead::COMPANY_SIZES)],
            'industry' => ['nullable', 'string', Rule::in(ContactLead::INDUSTRIES)],
            'use_case' => ['required', 'string', Rule::in(ContactLead::USE_CASES)],
            'brand_count' => ['nullable', 'string', Rule::in(ContactLead::BRAND_COUNTS)],
            'timeline' => ['nullable', 'string', Rule::in(ContactLead::TIMELINES)],
            'heard_from' => ['nullable', 'string', 'max:150'],
            'message' => ['nullable', 'string', 'max:5000'],
            'consent_marketing' => ['nullable', 'boolean'],
        ]);

        // Collapse first/last into a single `name` column so the CRM list view
        // and email notification have one canonical display string. The raw
        // parts are kept for Salesforce/HubSpot-shaped export later.
        $fullName = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));

        $lead = $this->persist(ContactLead::KIND_SALES_INQUIRY, $request, array_merge($data, [
            'name' => $fullName,
            'details' => [
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
            ],
        ]));

        $this->notifySales($lead);

        return back()->with('info', "Thanks — our team will reach out within one business day to set up time.");
    }

    /**
     * POST /newsletter — email-only signup with explicit marketing consent.
     */
    public function storeNewsletter(Request $request): RedirectResponse
    {
        if (filled($request->input('website'))) {
            return back()->with('info', "You're on the list.");
        }

        $data = $request->validate([
            'email' => ['required', 'email:rfc,dns', 'max:255'],
            // Explicit opt-in is required for newsletter — CAN-SPAM / GDPR.
            'consent_marketing' => ['accepted'],
        ]);

        $lead = $this->persist(ContactLead::KIND_NEWSLETTER, $request, $data);
        $this->notifySales($lead);

        return back()->with('info', "You're on the list — look for the next product update in your inbox.");
    }

    /**
     * Upsert on (email, kind) so re-submissions refresh the row instead of
     * erroring on the unique constraint. Preserves the CRM fields if a sales
     * rep has already triaged this lead.
     *
     * @param  array<string, mixed>  $data
     */
    private function persist(string $kind, Request $request, array $data): ContactLead
    {
        $payload = [
            'kind' => $kind,
            'email' => strtolower(trim((string) $data['email'])),
            'name' => $data['name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'company' => $data['company'] ?? null,
            'company_website' => $data['company_website'] ?? null,
            'plan_interest' => $data['plan_interest'] ?? null,
            'company_size' => $data['company_size'] ?? null,
            'industry' => $data['industry'] ?? null,
            'use_case' => $data['use_case'] ?? null,
            'brand_count' => $data['brand_count'] ?? null,
            'timeline' => $data['timeline'] ?? null,
            'heard_from' => $data['heard_from'] ?? null,
            'message' => $data['message'] ?? null,
            'details' => $data['details'] ?? null,
            'source' => (string) $request->input('source', $request->headers->get('referer') ?? '/contact'),
            'utm' => $this->captureUtm($request),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 512),
            'consent_marketing' => (bool) ($data['consent_marketing'] ?? false),
        ];

        return DB::transaction(function () use ($payload) {
            $lead = ContactLead::where('email', $payload['email'])
                ->where('kind', $payload['kind'])
                ->first();

            if ($lead) {
                // Don't clobber sales triage — only refresh the inbound-owned
                // fields and bump the timestamp. Each new column uses
                // "new ?? existing" so a resubmission that omits a field
                // preserves whatever the lead previously told us.
                $inboundFields = [
                    'name', 'phone', 'job_title', 'company', 'company_website',
                    'plan_interest', 'company_size', 'industry', 'use_case',
                    'brand_count', 'timeline', 'heard_from', 'message', 'source',
                ];
                $merged = [];
                foreach ($inboundFields as $f) {
                    $merged[$f] = $payload[$f] ?? $lead->{$f};
                }
                $merged['utm'] = $payload['utm'] ?? $lead->utm;
                $merged['details'] = array_merge((array) ($lead->details ?? []), (array) ($payload['details'] ?? []));
                $merged['ip_address'] = $payload['ip_address'];
                $merged['user_agent'] = $payload['user_agent'];
                // Consent can only move forward (opt-in), never back to false via resubmission.
                $merged['consent_marketing'] = $lead->consent_marketing || $payload['consent_marketing'];

                $lead->fill($merged)->save();

                return $lead;
            }

            return ContactLead::create($payload);
        });
    }

    /**
     * @return array<string, string>|null
     */
    private function captureUtm(Request $request): ?array
    {
        $keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
        $found = [];
        foreach ($keys as $k) {
            $v = $request->input($k);
            if (is_string($v) && $v !== '') {
                $found[$k] = substr($v, 0, 255);
            }
        }

        return $found ?: null;
    }

    private function notifySales(ContactLead $lead): void
    {
        $to = $this->resolveSalesRecipients();
        if ($to === []) {
            Log::info('[ContactLead] Sales notification skipped — no recipient configured', [
                'lead_id' => $lead->id,
                'kind' => $lead->kind,
            ]);

            return;
        }

        try {
            // Queueable mailable: returns immediately; EmailGate still gates
            // delivery via BaseMailable when MAIL_AUTOMATIONS_ENABLED is off.
            Mail::to($to)->queue(new ContactLeadReceived($lead));
        } catch (\Throwable $e) {
            // Never let a mail failure 500 the public form submission —
            // the row is already persisted and will be picked up manually.
            Log::warning('[ContactLead] Sales notification dispatch failed', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function resolveSalesRecipients(): array
    {
        $raw = (string) (config('services.sales.notify_to') ?: config('mail.from.address') ?: '');
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
