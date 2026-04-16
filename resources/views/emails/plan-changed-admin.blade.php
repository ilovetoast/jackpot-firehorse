{{-- MODE: system | Plan Changed (Admin notification) --}}
<x-email.layout title="Plan changed" preheader="A tenant's plan has changed">

    <x-email.eyebrow>Billing</x-email.eyebrow>
    <x-email.heading>Plan change notification</x-email.heading>

    <x-email.text>A tenant&rsquo;s subscription plan has been updated.</x-email.text>

    <x-email.details :items="$details ?? []" />

    @if(!empty($adminUrl))
        <x-email.button :url="$adminUrl">View in admin</x-email.button>
    @endif

</x-email.layout>
