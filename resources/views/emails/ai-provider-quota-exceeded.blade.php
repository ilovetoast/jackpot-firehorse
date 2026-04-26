{{-- MODE: system | AI provider org quota (OpenAI, Anthropic, Gemini) --}}
<x-email.layout title="AI provider quota exceeded" preheader="Increase billing, quota, or wait for reset">

    <x-email.eyebrow color="#b91c1c">Platform AI</x-email.eyebrow>
    <x-email.heading>Provider quota or billing block</x-email.heading>

    <x-email.text>
        The application received a <strong>quota, billing, or org-level rate limit</strong> from your AI
        @if($provider)
            <strong>{{ $provider }}</strong>
        @else
            provider
        @endif
        — not a tenant or plan limit. Automated jobs that use this provider may fail until the account is
        in good standing.
    </x-email.text>

    <x-email.notice type="warning">{{ $detailMessage }}</x-email.notice>

    <x-email.text :muted="true">Tracked environment: <strong>{{ config('app.env') }}</strong> · App URL: {{ config('app.url') }}</x-email.text>

    @if($provider && strcasecmp($provider, 'OpenAI') === 0)
        <x-email.heading>What to do (OpenAI)</x-email.heading>
        <x-email.text>
            <strong>1.</strong> Open <a href="https://platform.openai.com/settings/organization/billing" style="color:#1d4ed8;">Billing and plan</a> — ensure the org has a valid payment method and is not over budget.<br>
            <strong>2.</strong> Review <a href="https://platform.openai.com/usage" style="color:#1d4ed8;">Usage</a> and <a href="https://platform.openai.com/settings/organization/limits" style="color:#1d4ed8;">Organization limits / rate limits</a>.<br>
            <strong>3.</strong> If you recently rotated keys, confirm <code>OPENAI_API_KEY</code> in the deployment for this app matches a key from that org.<br>
            <strong>4.</strong> This is <strong>not</strong> your in-app “AI Budgets” system cap in Admin — that is separate. This error means OpenAI’s API rejected the call (quota, billing, or 429).
        </x-email.text>
    @else
        <x-email.heading>What to do</x-email.heading>
        <x-email.text>Sign in to this provider’s console (usage, billing, and rate limits) and confirm API keys in your deployment match an active, funded project.</x-email.text>
    @endif

</x-email.layout>
