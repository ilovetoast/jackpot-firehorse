{{-- MODE: system | AI Monthly Usage Cap --}}
<x-email.layout title="AI monthly limit reached" preheader="Your workspace has hit its AI usage cap">

    <x-email.eyebrow color="#b45309">Usage Alert</x-email.eyebrow>
    <x-email.heading>AI monthly limit reached</x-email.heading>

    <x-email.text>Your workspace <strong>{{ $tenant->name }}</strong> has reached its monthly AI usage limit for automated tagging and related AI features.</x-email.text>

    <x-email.notice type="warning">{{ $detailMessage }}</x-email.notice>

    <x-email.text>Usage typically resets at the start of the next calendar month. Review usage and plan limits in Company settings.</x-email.text>

    @if($aiAgentRunId)
        <x-email.text :muted="true">Reference: AI agent run #{{ $aiAgentRunId }}</x-email.text>
    @endif

</x-email.layout>
