{{-- Notice / warning / info callout panel --}}
@props(['type' => 'info'])
@php
    $styles = match($type) {
        'warning' => ['bg' => '#fffbeb', 'border' => '#f59e0b', 'label' => '#92400e', 'labelText' => 'Warning'],
        'danger'  => ['bg' => '#fef2f2', 'border' => '#dc2626', 'label' => '#991b1b', 'labelText' => 'Important'],
        'success' => ['bg' => '#f0fdf4', 'border' => '#16a34a', 'label' => '#166534', 'labelText' => 'Success'],
        default   => [
            'bg' => config('mail.branding.info_bg', '#f5f3ff'),
            'border' => config('mail.branding.info_border', '#7c3aed'),
            'label' => config('mail.branding.info_label', '#5b21b6'),
            'labelText' => 'Note',
        ],
    };
@endphp
<div style="margin:20px 0;padding:14px 18px;background-color:{{ $styles['bg'] }};border-left:4px solid {{ $styles['border'] }};border-radius:6px;font-size:13px;line-height:1.5;color:#374151;">
    <strong style="color:{{ $styles['label'] }};">{{ $styles['labelText'] }}</strong> — {{ $slot }}
</div>
