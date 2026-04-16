{{-- MODE: system | Trial Expiring Warning --}}
<x-email.layout title="Your trial is ending soon" preheader="Your trial expires soon">

    <x-email.eyebrow color="#b45309">Trial Notice</x-email.eyebrow>
    <x-email.heading>Your trial is ending soon</x-email.heading>

    <x-email.text>Your free trial will expire shortly. To keep your workspace and all your data, upgrade to a paid plan before the trial ends.</x-email.text>

    <x-email.details :items="$details ?? []" />

    @if(!empty($billingUrl))
        <x-email.button :url="$billingUrl">Upgrade now</x-email.button>
    @endif

</x-email.layout>
