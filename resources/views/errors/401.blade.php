@php
    $code = 401;
    $title = 'Unauthorized';
    $message = $exception->getMessage() ?: 'Please sign in to access this page.';
@endphp
@include('errors.layout')
