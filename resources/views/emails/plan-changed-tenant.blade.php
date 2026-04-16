{{-- MODE: system | Plan Changed (Tenant owner notification) --}}
<x-email.layout title="Your plan has changed" preheader="Your subscription plan has been updated">

    <x-email.eyebrow>Billing</x-email.eyebrow>
    <x-email.heading>Your plan has been updated</x-email.heading>

    <x-email.text>Your subscription has been changed. Here are the details:</x-email.text>

    <x-email.details :items="$details ?? []" />

    @if(!empty($billingUrl))
        <x-email.button :url="$billingUrl">View billing</x-email.button>
    @endif

    <x-email.text :muted="true">If you didn&rsquo;t make this change, please contact support.</x-email.text>

</x-email.layout>
