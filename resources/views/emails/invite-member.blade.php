{{-- MODE: tenant | Team Invitation --}}
@php
    $brand = \App\Models\Brand::where('tenant_id', $tenant->id)->orderByDesc('is_default')->first();
    $logoUrl = $brand?->logoUrlForTransactionalEmail();
    $accentColor = $brand?->primary_color;
@endphp
<x-email.layout
    title="You've been invited to {{ $tenant->name }}"
    mode="tenant"
    :tenantName="$tenant->name"
    :tenantLogoUrl="$logoUrl"
    :tenantAccentColor="$accentColor"
    preheader="{{ $inviter->name }} invited you to join {{ $tenant->name }}"
>

    <x-email.eyebrow>Team Invitation</x-email.eyebrow>
    <x-email.heading>You&rsquo;ve been invited!</x-email.heading>

    <x-email.text>
        <strong>{{ $inviter->name }}</strong> has invited you to join
        <strong>{{ $tenant->name }}</strong> on {{ config('app.name') }}.
    </x-email.text>

    <x-email.text>Click below to accept the invitation and create your account:</x-email.text>

    <x-email.button :url="$inviteUrl">Accept invitation</x-email.button>
    <x-email.link-fallback :url="$inviteUrl" />

    <x-email.text :muted="true">If you didn&rsquo;t expect this invitation, you can safely ignore this email.</x-email.text>

</x-email.layout>
