{{-- MODE: system | Stripe refund completed --}}
<x-email.layout title="Refund processed" preheader="A payment has been refunded">

    <x-email.eyebrow>Billing</x-email.eyebrow>
    <x-email.heading>Refund processed</x-email.heading>

    <x-email.text>Hi {{ $owner_name }},</x-email.text>
    <x-email.text>
        We’ve issued a <strong>{{ $refund_amount }}</strong> refund
        @if(!empty($invoice_reference))
            related to invoice <strong>{{ $invoice_reference }}</strong>
        @endif
        for <strong>{{ $tenant_name }}</strong>. Depending on your bank, it may take several business days to appear on your statement.
    </x-email.text>

    @if(!empty($billing_url))
        <x-email.button :url="$billing_url">View billing</x-email.button>
    @endif

</x-email.layout>
