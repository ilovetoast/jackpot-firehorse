@php
    $code = 404;
    $title = 'Page not found';
    $message = $exception->getMessage() ?: 'The page you\'re looking for doesn\'t exist or has been moved.';
@endphp
@include('errors.layout')
