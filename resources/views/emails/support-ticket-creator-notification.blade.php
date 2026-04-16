{{-- MODE: system | Support Ticket Creator Notification (receipt / reply / resolved) --}}
<x-email.layout title="Support ticket {{ $ticketNumber }}" preheader="Update on your support ticket {{ $ticketNumber }}">

    <x-email.eyebrow>Support</x-email.eyebrow>

    @if($kind === 'receipt')
        <x-email.heading>We received your ticket</x-email.heading>
        <x-email.text>Thanks for reaching out. We&rsquo;ve received your support request and a team member will get back to you shortly.</x-email.text>
    @elseif($kind === 'reply')
        <x-email.heading>New reply on your ticket</x-email.heading>
        <x-email.text><strong>{{ $replierName ?? 'Support' }}</strong> replied to your ticket:</x-email.text>
        @if(!empty($replyExcerpt))
        <div style="margin:0 0 20px;padding:14px 18px;background-color:#f9fafb;border-radius:8px;border:1px solid #e5e7eb;font-size:14px;color:#374151;line-height:1.55;">{{ $replyExcerpt }}</div>
        @endif
    @elseif($kind === 'terminal')
        <x-email.heading>Your ticket has been resolved</x-email.heading>
        <x-email.text>Your support ticket has been updated to: <strong>{{ $statusLabel ?? 'Resolved' }}</strong></x-email.text>
    @endif

    <x-email.details :items="[
        'Ticket' => $ticketNumber,
        'Subject' => $ticketSubject,
        'Category' => $categoryLabel,
    ]" />

    @if(!empty($ticketUrl))
        <x-email.button :url="$ticketUrl">View ticket</x-email.button>
    @endif

</x-email.layout>
