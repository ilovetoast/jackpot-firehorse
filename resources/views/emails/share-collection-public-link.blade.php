@php
    $logoUrl     = $brand?->logoUrlForTransactionalEmail();
    $accentColor = $brand?->primary_color;
    $orgName     = $brand?->name ?? $tenant?->name;
@endphp
<x-email.layout
    title="Shared collection"
    mode="tenant"
    :tenantName="$orgName"
    :tenantLogoUrl="$logoUrl"
    :tenantAccentColor="$accentColor"
    preheader="{{ $sender->name }} shared {{ $collection->name }} with you"
>

    <x-email.eyebrow>Shared link</x-email.eyebrow>
    <x-email.heading>View this collection</x-email.heading>

    <x-email.text>
        <strong>{{ $sender->name }}</strong> shared the collection
        <strong>{{ $collection->name }}</strong>@if($brand) for <strong>{{ $brand->name }}</strong>@elseif($tenant) on <strong>{{ $tenant->name }}</strong>@endif.
        Open the link below and enter the password when prompted.
    </x-email.text>

    @if($personalMessage)
        <x-email.text>{{ $personalMessage }}</x-email.text>
    @endif

    <x-email.button :url="$shareUrl">Open shared collection</x-email.button>
    <x-email.link-fallback :url="$shareUrl" />

    @if($verifiedPasswordPlain)
        <x-email.text>
            <strong>Password</strong> (needed on the first screen):<br />
            <span style="font-family: ui-monospace, monospace; font-size: 15px; letter-spacing: 0.02em;">{{ $verifiedPasswordPlain }}</span>
        </x-email.text>
        <x-email.text :muted="true">
            Treat this like a sensitive link. Anyone with the URL and password can open the collection according to your team&rsquo;s settings.
        </x-email.text>
    @endif

    <x-email.text :muted="true">If you didn&rsquo;t expect this message, you can ignore it.</x-email.text>

</x-email.layout>
