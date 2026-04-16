{{-- MODE: system | Ownership Transfer Confirmation (to current owner) --}}
<x-email.layout title="Confirm ownership transfer" preheader="Confirm the ownership transfer for {{ $tenant->name }}">

    <x-email.eyebrow color="#d97706">Ownership Transfer</x-email.eyebrow>
    <x-email.heading>Confirm ownership transfer</x-email.heading>

    <x-email.text>Hi {{ $currentOwner->name }},</x-email.text>

    <x-email.text>
        You initiated a request to transfer ownership of <strong>{{ $tenant->name }}</strong> to
        <strong>{{ $newOwner->name }}</strong> ({{ $newOwner->email }}).
    </x-email.text>

    <x-email.notice type="warning">
        This action will transfer all ownership rights and responsibilities to {{ $newOwner->name }}.
        You will be downgraded to an Admin role after the transfer is completed.
    </x-email.notice>

    <x-email.button :url="$confirmationUrl" color="#d97706">Confirm transfer</x-email.button>
    <x-email.link-fallback :url="$confirmationUrl" color="#d97706" />

    <x-email.notice type="danger">This link expires in 7 days. If you did not initiate this transfer, please contact support immediately.</x-email.notice>

</x-email.layout>
