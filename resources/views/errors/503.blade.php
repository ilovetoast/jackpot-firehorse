@php
    $code = 503;
    $title = 'Service unavailable';
    $message = $exception->getMessage() ?: 'We\'re temporarily unavailable. Please try again in a few moments.';
@endphp
@include('errors.layout')
