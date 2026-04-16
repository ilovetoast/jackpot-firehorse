{{-- MODE: system | Ownership Transfer Completed --}}
<x-email.layout title="Ownership transfer complete" preheader="Ownership of {{ $tenant->name }} has been transferred">

    <x-email.eyebrow color="#059669">Ownership Transfer</x-email.eyebrow>
    <x-email.heading>Ownership transfer complete</x-email.heading>

    <x-email.text>Hi {{ $recipient->name }},</x-email.text>

    <x-email.text>The ownership transfer of <strong>{{ $tenant->name }}</strong> has been completed.</x-email.text>

    <x-email.details :items="[
        'Previous owner' => $previousOwner->name . ' (' . $previousOwner->email . ')',
        'New owner' => $newOwner->name . ' (' . $newOwner->email . ')',
    ]" />

    <x-email.notice type="success">
        The previous owner has been downgraded to Admin role. The new owner now has full administrative control.
    </x-email.notice>

    <x-email.button :url="config('app.url') . '/app/companies/settings'">Access company settings</x-email.button>

    <x-email.text :muted="true">This transfer has been logged in the activity history for audit purposes.</x-email.text>

</x-email.layout>
