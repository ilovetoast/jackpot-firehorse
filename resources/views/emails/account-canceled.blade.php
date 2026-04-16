{{-- MODE: system | Account Canceled --}}
<x-email.layout title="Account canceled" preheader="Your account has been canceled">

    <x-email.eyebrow>Account Notice</x-email.eyebrow>
    <x-email.heading>Your account has been canceled</x-email.heading>

    <x-email.text>Your {{ config('app.name') }} subscription has been canceled. Your data will be retained for a limited period.</x-email.text>

    <x-email.text>If you change your mind, you can reactivate your account by logging in and choosing a new plan.</x-email.text>

    <x-email.details :items="$details ?? []" />

</x-email.layout>
