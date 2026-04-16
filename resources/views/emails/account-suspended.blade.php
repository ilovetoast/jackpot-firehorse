{{-- MODE: system | Account Suspended --}}
<x-email.layout title="Account suspended" preheader="Your account has been suspended">

    <x-email.eyebrow color="#dc2626">Account Notice</x-email.eyebrow>
    <x-email.heading>Your account has been suspended</x-email.heading>

    <x-email.text>Your {{ config('app.name') }} account has been suspended. Access to your workspace has been restricted.</x-email.text>

    <x-email.notice type="danger">If you believe this is an error, please contact support immediately.</x-email.notice>

    <x-email.details :items="$details ?? []" />

</x-email.layout>
