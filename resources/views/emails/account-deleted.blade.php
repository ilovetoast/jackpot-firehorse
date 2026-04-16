{{-- MODE: system | Account Deleted --}}
<x-email.layout title="Account deleted" preheader="Your account has been deleted">

    <x-email.eyebrow>Account Notice</x-email.eyebrow>
    <x-email.heading>Your account has been deleted</x-email.heading>

    <x-email.text>Your {{ config('app.name') }} account and all associated data have been permanently deleted as requested.</x-email.text>

    <x-email.text>If you did not request this or need assistance, please contact support.</x-email.text>

</x-email.layout>
