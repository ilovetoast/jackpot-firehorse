{{-- MODE: system | Ownership Transfer Request (to new owner) --}}
<x-email.layout title="Ownership transfer request" preheader="{{ $currentOwner->name }} wants to transfer {{ $tenant->name }} to you">

    <x-email.eyebrow color="#7c3aed">Ownership Transfer</x-email.eyebrow>
    <x-email.heading>Ownership transfer request</x-email.heading>

    <x-email.text>Hi {{ $newOwner->name }},</x-email.text>

    <x-email.text>
        <strong>{{ $currentOwner->name }}</strong> ({{ $currentOwner->email }}) has initiated a request to transfer
        ownership of <strong>{{ $tenant->name }}</strong> to you.
    </x-email.text>

    <x-email.notice type="info">
        <strong>What happens next:</strong><br>
        1. The current owner must confirm this transfer via email<br>
        2. Once confirmed, you will receive an acceptance email<br>
        3. After you accept, ownership will be transferred
    </x-email.notice>

    <x-email.text :muted="true">You will receive another email once the current owner confirms. If you didn&rsquo;t expect this request, you can safely ignore this email.</x-email.text>

</x-email.layout>
