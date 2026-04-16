{{-- MODE: system | Pending Approvals Digest --}}
<x-email.layout title="Pending approvals" preheader="You have assets pending approval">

    <x-email.eyebrow>Daily Digest</x-email.eyebrow>
    <x-email.heading>Upload approvals waiting for you</x-email.heading>

    <x-email.text>Here is a summary of assets pending approval for <strong>{{ $brandName }}</strong>.</x-email.text>

    @if(($teamStats['count'] ?? 0) > 0)
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 16px;background-color:#f9fafb;border-radius:8px;border:1px solid #e5e7eb;">
      <tr>
        <td style="padding:16px 20px;">
          <p style="margin:0 0 6px;font-weight:600;font-size:14px;color:#111827;">Team uploads</p>
          <p style="margin:0 0 14px;font-size:14px;color:#374151;">
            <strong>{{ $teamStats['count'] }}</strong> {{ $teamStats['count'] === 1 ? 'asset' : 'assets' }} pending.
            @if(!empty($teamStats['oldest_summary']))
              Longest wait: {{ $teamStats['oldest_summary'] }}.
            @endif
          </p>
          <x-email.button :url="$teamReviewUrl">Review team uploads</x-email.button>
        </td>
      </tr>
    </table>
    @endif

    @if(($creatorStats['count'] ?? 0) > 0)
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 16px;background-color:#f9fafb;border-radius:8px;border:1px solid #e5e7eb;">
      <tr>
        <td style="padding:16px 20px;">
          <p style="margin:0 0 6px;font-weight:600;font-size:14px;color:#111827;">Creator program</p>
          <p style="margin:0 0 14px;font-size:14px;color:#374151;">
            <strong>{{ $creatorStats['count'] }}</strong> {{ $creatorStats['count'] === 1 ? 'asset' : 'assets' }} pending.
            @if(!empty($creatorStats['oldest_summary']))
              Longest wait: {{ $creatorStats['oldest_summary'] }}.
            @endif
          </p>
          <x-email.button :url="$creatorReviewUrl">Review creator uploads</x-email.button>
        </td>
      </tr>
    </table>
    @endif

    <x-email.text :muted="true">You receive this because approval notifications are enabled. This is a daily summary&mdash;you won&rsquo;t get a separate email per upload.</x-email.text>

</x-email.layout>
