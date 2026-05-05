{{-- MODE: system | Subscription cancel scheduled --}}
<x-email.layout title="Subscription canceled" preheader="Your subscription will not renew">

    <x-email.eyebrow>Billing</x-email.eyebrow>
    <x-email.heading>We’ve received your cancellation</x-email.heading>

    <x-email.text>Hi {{ $owner_name }},</x-email.text>
    <x-email.text>Your <strong>{{ $plan_name }}</strong> subscription for <strong>{{ $tenant_name }}</strong> is canceled and will not renew.</x-email.text>
    <x-email.text>You’ll keep your current plan benefits until <strong>{{ $access_ends_at }}</strong>.</x-email.text>

    @if(!empty($billing_url))
        <x-email.button :url="$billing_url">Billing & invoices</x-email.button>
    @endif

</x-email.layout>
