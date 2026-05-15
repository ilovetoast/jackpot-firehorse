{{-- MODE: tenant | Team Invitation --}}
@php
    $brand = $brandingBrand ?? \App\Models\Brand::where('tenant_id', $tenant->id)->orderByDesc('is_default')->first();
    $logoUrl = $brand?->logoUrlForTransactionalEmail();
    $cta = \App\Support\TransactionalEmailHtml::transactionalCtaPlaceholdersForBrand($brand);
    $buttonColor = $cta['primary_button_color'];
    $barColor = $cta['card_accent_bar'];
    $linkColor = $cta['link_accent_color'];
@endphp
<x-email.layout
    title="You've been invited to {{ $tenant->name }}"
    mode="tenant"
    :tenantName="$tenant->name"
    :tenantLogoUrl="$logoUrl"
    :tenantAccentColor="$barColor"
    :tenantLinkColor="$linkColor"
    preheader="{{ $inviter->name }} invited you to join {{ $tenant->name }}"
>

    <x-email.eyebrow>Team Invitation</x-email.eyebrow>
    <x-email.heading>You&rsquo;ve been invited!</x-email.heading>

    <x-email.text>
        <strong>{{ $inviter->name }}</strong> has invited you to join
        <strong>{{ $tenant->name }}</strong> on {{ config('app.name') }}.
    </x-email.text>

    <x-email.text>Click below to accept the invitation and create your account:</x-email.text>

    <x-email.button :url="$inviteUrl" :color="$buttonColor">Accept invitation</x-email.button>
    <x-email.link-fallback :url="$inviteUrl" :color="$linkColor" />

    @if(!empty($brandedLoginUrl))
        <x-email.text>Already have an account? Sign in with this brand&rsquo;s themed gateway:</x-email.text>
        <x-email.button :url="$brandedLoginUrl" :color="$buttonColor">Sign in to workspace</x-email.button>
        <x-email.link-fallback :url="$brandedLoginUrl" :color="$linkColor" />
    @endif

    <x-email.text :muted="true">If you didn&rsquo;t expect this invitation, you can safely ignore this email.</x-email.text>

</x-email.layout>
