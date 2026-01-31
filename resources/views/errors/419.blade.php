@php
    $code = 419;
    $title = 'Page expired';
    $message = $exception->getMessage() ?: 'Your session has expired. Please refresh and try again.';
@endphp
@include('errors.layout')
