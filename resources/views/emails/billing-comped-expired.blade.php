{{-- MODE: system | Comped Plan Expired --}}
<x-email.layout title="Your complimentary plan has expired" preheader="Your comp plan has expired">

    <x-email.eyebrow color="#dc2626">Billing Notice</x-email.eyebrow>
    <x-email.heading>Your complimentary plan has expired</x-email.heading>

    <x-email.text>Your complimentary access has ended. To restore full access, please choose a plan and add payment details.</x-email.text>

    <x-email.details :items="$details ?? []" />

    @if(!empty($billingUrl))
        <x-email.button :url="$billingUrl">Choose a plan</x-email.button>
    @endif

</x-email.layout>
