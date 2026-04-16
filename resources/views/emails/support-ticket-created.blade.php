{{-- MODE: system | New Support Ticket (assignee notification) --}}
<x-email.layout title="New support ticket {{ $ticketNumber }}" preheader="Ticket {{ $ticketNumber }} assigned to you">

    <x-email.eyebrow>Support</x-email.eyebrow>
    <x-email.heading>New ticket assigned to you</x-email.heading>

    <x-email.text>A support ticket has been created and assigned to you.</x-email.text>

    <x-email.details :items="[
        'Ticket' => $ticketNumber,
        'Subject' => $ticketSubject,
        'Category' => $categoryLabel,
        'Tenant' => $tenantName,
        'Created by' => $creatorName,
        'Assignee' => $assigneeName,
    ]" />

    <x-email.button :url="$ticketUrl">View ticket</x-email.button>

</x-email.layout>
