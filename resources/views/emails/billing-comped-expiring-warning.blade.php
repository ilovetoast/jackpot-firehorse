{{-- MODE: system | Comped Plan Expiring Warning --}}
<x-email.layout title="Your complimentary plan is expiring" preheader="Your comp plan expires soon">

    <x-email.eyebrow color="#b45309">Billing Notice</x-email.eyebrow>
    <x-email.heading>Your complimentary plan is expiring soon</x-email.heading>

    <x-email.text>Your complimentary access will expire shortly. To continue using all features, please add a payment method or choose a plan.</x-email.text>

    <x-email.details :items="$details ?? []" />

    @if(!empty($billingUrl))
        <x-email.button :url="$billingUrl">Update billing</x-email.button>
    @endif

</x-email.layout>
