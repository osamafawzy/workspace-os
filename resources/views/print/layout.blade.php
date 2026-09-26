{{--
    A bare page for printing: no panel, no sidebar, no remote fonts.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title ?? 'Print' }}</title>
</head>
<body>
    {{ $slot }}
</body>
</html>
