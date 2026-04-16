{{-- MODE: tenant (paid plan) | Download Share — branded --}}
@php
    $accentColor = $branding['branding_options']['accent_color'] ?? '#4f46e5';
    $logoUrl     = $branding['branding_options']['logo_url'] ?? null;
@endphp
<x-email.layout
    title="{{ $brandName }} shared files with you"
    mode="tenant"
    :tenantName="$brandName"
    :tenantLogoUrl="$logoUrl"
    :tenantAccentColor="$accentColor"
    preheader="{{ $brandName }} shared files with you"
>

    <x-email.eyebrow>Shared Files</x-email.eyebrow>
    <x-email.heading>{{ $brandName }} shared files with you</x-email.heading>

    <x-email.text>{{ $brandName }} has shared files with you.</x-email.text>

    @if($personalMessage)
        <div style="margin:0 0 20px;padding:14px 18px;background-color:#f9fafb;border-radius:8px;border:1px solid #e5e7eb;font-size:14px;font-style:italic;color:#4b5563;line-height:1.55;">
            &ldquo;{{ $personalMessage }}&rdquo;
        </div>
    @endif

    <x-email.button :url="$shareUrl">Download files</x-email.button>
    <x-email.link-fallback :url="$shareUrl" />

    <x-email.text :muted="true">This link may expire. If you didn&rsquo;t expect this email, you can safely ignore it.</x-email.text>

</x-email.layout>
