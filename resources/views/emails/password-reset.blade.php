{{-- MODE: system | Password Reset --}}
<x-email.layout title="Reset your password" preheader="Reset your Jackpot password">

    <x-email.eyebrow color="#4f46e5">Account Security</x-email.eyebrow>
    <x-email.heading>Reset your password</x-email.heading>

    <x-email.text>We received a request to reset your password. If you didn&rsquo;t make this request, you can safely ignore this email.</x-email.text>
    <x-email.text>Click the button below to choose a new password:</x-email.text>

    <x-email.button :url="$url">Reset password</x-email.button>
    <x-email.link-fallback :url="$url" />

    <x-email.notice type="danger">This link expires in 60&nbsp;minutes. If you didn&rsquo;t request a password reset, no action is needed.</x-email.notice>

</x-email.layout>
