{{-- MODE: operations | System monthly AI cap — warning (approaching limit) --}}
<x-email.layout title="System AI spend warning" preheader="Monthly platform cap is almost reached">

    <x-email.eyebrow color="#b45309">Platform budget</x-email.eyebrow>
    <x-email.heading>System AI spend is near the monthly cap</x-email.heading>

    <x-email.text>
        <strong>System-wide</strong> AI spend (all tenants) has reached at least the configured
        <strong>{{ $warningThresholdPercent }}%</strong> warning threshold for this calendar month.
        End users are not blocked yet, but the cap will block new AI calls if spend continues to rise.
    </x-email.text>

    <x-email.notice type="warning">
        Current usage: <strong>${{ number_format($currentUsageUsd, 2) }}</strong> ·
        Monthly cap: <strong>${{ number_format($capUsd, 2) }}</strong> ·
        ~<strong>{{ $percentOfCap }}%</strong> of cap
    </x-email.notice>

    <x-email.text :muted="true">
        Tracked <strong>environment</strong> (budget scope): <code>{{ $budgetEnvironment }}</code><br>
        <strong>APP_ENV</strong>: <code>{{ $appEnv }}</code><br>
        <strong>App URL</strong>: {{ config('app.url') }}
    </x-email.text>

    <x-email.text>
        Review <strong>Admin → AI</strong> (or your cost dashboard), consider raising the system cap or
        reducing load. This message is throttled to at most once per day per environment.
    </x-email.text>

</x-email.layout>
