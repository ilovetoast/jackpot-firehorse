@php
    $code = 429;
    $title = 'Too many requests';
    $message = $exception->getMessage() ?: 'You\'re making too many requests. Please slow down and try again in a moment.';
@endphp
@include('errors.layout')
