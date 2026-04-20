{{-- MODE: system | Inbound lead notification to sales inbox --}}
@php
    use App\Models\ContactLead;

    // Qualification block — only render rows that actually have a value, and
    // translate enum codes to human-readable labels so the sales inbox doesn't
    // see raw strings like "retail_cpg" or "3-6mo".
    $qualification = array_filter([
        'Company size'   => ContactLead::label($lead->company_size),
        'Industry'       => ContactLead::label($lead->industry),
        'Primary use case' => ContactLead::label($lead->use_case),
        'Number of brands' => ContactLead::label($lead->brand_count),
        'Timeline'       => ContactLead::label($lead->timeline),
        'Heard from'     => $lead->heard_from,
    ], fn ($v) => filled($v));

    $contactDetails = array_filter([
        'Email'         => $lead->email,
        'Name'          => $lead->name,
        'Phone'         => $lead->phone,
        'Job title'     => $lead->job_title,
        'Company'       => $lead->company,
        'Website'       => $lead->company_website,
        'Plan interest' => $lead->plan_interest,
        'Source page'   => $lead->source,
        'Marketing consent' => $lead->consent_marketing ? 'Yes' : 'No',
        'IP'            => $lead->ip_address,
    ], fn ($v) => filled($v));
@endphp
<x-email.layout title="{{ $kindLabel }} — {{ $lead->email }}" preheader="New lead captured from the marketing site">

    <x-email.eyebrow>{{ $kindLabel }}</x-email.eyebrow>
    <x-email.heading>New lead from {{ $lead->company ?: ($lead->name ?: $lead->email) }}</x-email.heading>

    <x-email.text>
        A visitor just submitted through the {{ strtolower($kindLabel) }}. Reply-to on this email goes straight to them.
    </x-email.text>

    <x-email.details :items="$contactDetails" />

    @if (count($qualification) > 0)
        <x-email.divider />
        <x-email.eyebrow>Qualification</x-email.eyebrow>
        <x-email.details :items="$qualification" />
    @endif

    @if ($lead->message)
        <x-email.divider />
        <x-email.eyebrow>Message</x-email.eyebrow>
        <x-email.text>
            {!! nl2br(e($lead->message)) !!}
        </x-email.text>
    @endif

</x-email.layout>
