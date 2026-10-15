<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vanguard · Backup Manager</title>

    {{--
        Uses published static files when available (served by nginx/Apache directly).
        Falls back to the package PHP route otherwise (browser-cached via ETag).
        Run `php artisan vendor:publish --tag=vanguard-assets` for zero PHP overhead.
    --}}
    <link rel="stylesheet" href="{{ Vanguard::assetUrl('vanguard.css') }}">
</head>
<body>

{{--
    Mount point for the Vue application.
    Config is passed via data attributes — no inline JS, no global variables.
--}}
@php
    // 'single' only when the mode really is single: an invalid value is
    // refused wherever a backup runs, and must not make the dashboard say
    // tenant backups are "files only".
    $vanguardDatabaseMode = 'multi';
    if (! \SoftArtisan\Vanguard\Services\TenancyResolver::databaseModeIsDefault()) {
        try {
            $vanguardDatabaseMode = app(\SoftArtisan\Vanguard\Services\TenancyResolver::class)->sharesOneDatabase() ? 'single' : 'multi';
        } catch (\RuntimeException) {
            $vanguardDatabaseMode = 'multi';
        }
    }
@endphp
<div
    id="vanguard-app"
    data-base-path="{{ rtrim(Vanguard::path(), '/') }}"
    data-csrf-token="{{ csrf_token() }}"
    data-realtime-driver="{{ config('vanguard.realtime.driver', 'polling') }}"
    data-poll-interval="{{ config('vanguard.realtime.interval', 5) }}"
    data-database-mode="{{ $vanguardDatabaseMode }}"
></div>

<script src="{{ Vanguard::assetUrl('vanguard.js') }}"></script>
</body>
</html>
