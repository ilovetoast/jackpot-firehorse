{{-- MODE: system | Trial Expired --}}
<x-email.layout title="Your trial has expired" preheader="Your trial has expired">

    <x-email.eyebrow color="#dc2626">Trial Ended</x-email.eyebrow>
    <x-email.heading>Your trial has expired</x-email.heading>

    <x-email.text>Your free trial has ended. Your workspace data is still safe, but access is limited until you choose a plan.</x-email.text>

    <x-email.details :items="$details ?? []" />

    @if(!empty($billingUrl))
        <x-email.button :url="$billingUrl">Choose a plan</x-email.button>
    @endif

    <x-email.text :muted="true">Questions? Contact support and we&rsquo;ll help you find the right plan.</x-email.text>

</x-email.layout>
