{{-- MODE: operations | System monthly AI cap — blocked --}}
<x-email.layout title="System AI cap reached" preheader="AI requests are being blocked at the platform cap">

    <x-email.eyebrow color="#b91c1c">Platform budget</x-email.eyebrow>
    <x-email.heading>System AI monthly cap reached — requests blocked</x-email.heading>

    <x-email.text>
        A new AI run was <strong>blocked</strong> because projected spend would exceed the
        <strong>system-wide</strong> monthly cap. End users should see a generic
        &ldquo;AI service unavailable&rdquo; message, not internal dollar details.
    </x-email.text>

    <x-email.notice type="danger">
        Cap: <strong>${{ number_format($capUsd, 2) }}</strong> ·
        Usage before this request: <strong>${{ number_format($currentUsageUsd, 2) }}</strong> ·
        Estimated this request: <strong>${{ number_format($estimatedCostUsd, 4) }}</strong> ·
        Projected total: <strong>${{ number_format($projectedUsageUsd, 2) }}</strong>
    </x-email.notice>

    <x-email.text :muted="true">
        Tracked <strong>environment</strong> (budget scope): <code>{{ $budgetEnvironment }}</code><br>
        <strong>APP_ENV</strong>: <code>{{ $appEnv }}</code><br>
        <strong>App URL</strong>: {{ config('app.url') }}
    </x-email.text>

    <x-email.text>
        Raise the system cap, wait for the next calendar month, or reduce usage. Alerts are throttled
        (at most about once per hour) but each block is logged in application logs and AI agent runs.
    </x-email.text>

</x-email.layout>
