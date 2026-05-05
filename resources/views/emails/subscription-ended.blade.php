{{-- MODE: system | Paid subscription ended --}}
<x-email.layout title="Subscription ended" preheader="You are now on the Free plan">

    <x-email.eyebrow>Billing</x-email.eyebrow>
    <x-email.heading>Your paid subscription has ended</x-email.heading>

    <x-email.text>Hi {{ $owner_name }},</x-email.text>
    <x-email.text>The <strong>{{ $previous_plan }}</strong> subscription for <strong>{{ $tenant_name }}</strong> has ended. Your workspace is now on the <strong>Free</strong> plan.</x-email.text>

    @if(!empty($billing_url))
        <x-email.button :url="$billing_url">View plans</x-email.button>
    @endif

</x-email.layout>
