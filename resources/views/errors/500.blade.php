@php
    $code = 500;
    $title = 'Server error';
    $message = config('app.debug') && $exception->getMessage()
        ? $exception->getMessage()
        : 'Something went wrong on our end. Please try again later.';
@endphp
@include('errors.layout')
