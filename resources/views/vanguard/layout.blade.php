<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vanguard · Backup Manager</title>

    {{-- Vanguard compiled assets (published via vendor:publish) --}}
    <link rel="stylesheet" href="{{ asset('vendor/vanguard/vanguard.css') }}">
</head>
<body>

{{--
    Mount point for the Vue application.
    Config is passed via data attributes — no inline JS, no global variables.
--}}
<div
    id="vanguard-app"
    data-base-path="{{ rtrim(Vanguard::path(), '/') }}"
    data-csrf-token="{{ csrf_token() }}"
    data-realtime-driver="{{ config('vanguard.realtime.driver', 'sse') }}"
    data-poll-interval="{{ config('vanguard.realtime.interval', 5) }}"
></div>

<script src="{{ asset('vendor/vanguard/vanguard.js') }}"></script>
</body>
</html>
