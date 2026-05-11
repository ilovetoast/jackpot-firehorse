{{-- MODE: tenant | Collection Invitation --}}
@php
    $logoUrl = $brand?->logoUrlForTransactionalEmail();
    $cta = \App\Support\TransactionalEmailHtml::transactionalCtaPlaceholdersForBrand($brand);
    $buttonColor = $cta['primary_button_color'];
    $barColor = $cta['card_accent_bar'];
    $linkColor = $cta['link_accent_color'];
    $orgName = $brand?->name ?? $tenant?->name;
@endphp
<x-email.layout
    title="Collection invitation"
    mode="tenant"
    :tenantName="$orgName"
    :tenantLogoUrl="$logoUrl"
    :tenantAccentColor="$barColor"
    :tenantLinkColor="$linkColor"
    preheader="{{ $inviter->name }} invited you to view {{ $collection->name }}"
>

    <x-email.eyebrow>Collection Invitation</x-email.eyebrow>
    <x-email.heading>You&rsquo;ve been invited to a collection</x-email.heading>

    <x-email.text>
        <strong>{{ $inviter->name }}</strong> has invited you to view the collection
        <strong>{{ $collection->name }}</strong>@if($brand) for <strong>{{ $brand->name }}</strong>@elseif($tenant) on <strong>{{ $tenant->name }}</strong>@endif
        on {{ config('app.name') }}.
    </x-email.text>

    <x-email.text>Click below to accept the invitation:</x-email.text>

    <x-email.button :url="$inviteUrl" :color="$buttonColor">View collection invitation</x-email.button>
    <x-email.link-fallback :url="$inviteUrl" :color="$linkColor" />

    <x-email.text :muted="true">If you didn&rsquo;t expect this invitation, you can safely ignore this email.</x-email.text>

</x-email.layout>
