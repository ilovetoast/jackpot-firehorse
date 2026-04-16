{{-- MODE: system | Ownership Transfer Acceptance (to new owner) --}}
<x-email.layout title="Accept ownership transfer" preheader="Accept the ownership transfer for {{ $tenant->name }}">

    <x-email.eyebrow color="#059669">Ownership Transfer</x-email.eyebrow>
    <x-email.heading>Accept ownership transfer</x-email.heading>

    <x-email.text>Hi {{ $newOwner->name }},</x-email.text>

    <x-email.text>
        <strong>{{ $currentOwner->name }}</strong> ({{ $currentOwner->email }}) has confirmed the transfer of ownership
        of <strong>{{ $tenant->name }}</strong> to you.
    </x-email.text>

    <x-email.notice type="success">
        <strong>What this means:</strong><br>
        &bull; You will become the owner of {{ $tenant->name }}<br>
        &bull; You will have full administrative control<br>
        &bull; {{ $currentOwner->name }} will be downgraded to Admin role
    </x-email.notice>

    <x-email.button :url="$acceptanceUrl" color="#059669">Accept ownership</x-email.button>
    <x-email.link-fallback :url="$acceptanceUrl" color="#059669" />

    <x-email.notice type="danger">This link expires in 7 days. If you did not expect this transfer, please contact support immediately.</x-email.notice>

</x-email.layout>
