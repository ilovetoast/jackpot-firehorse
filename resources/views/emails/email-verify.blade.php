{{-- MODE: system | Email Verification --}}
<x-email.layout title="Verify your email" preheader="Click to verify your Jackpot email address">

    <x-email.eyebrow color="#4f46e5">Account Setup</x-email.eyebrow>
    <x-email.heading>Verify your email address</x-email.heading>

    <x-email.text>Welcome to Jackpot! To start uploading assets and using your workspace, please verify your email address.</x-email.text>

    <x-email.button :url="$url">Verify email</x-email.button>
    <x-email.link-fallback :url="$url" />

    <x-email.notice type="info">This link expires in 60&nbsp;minutes. If you didn&rsquo;t create this account, no action is needed.</x-email.notice>

</x-email.layout>
