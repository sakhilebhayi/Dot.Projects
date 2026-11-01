<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name', 'Dot.Projects') }}</title>
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        {{-- The Programme (DESIGN.md), the same system as the signed-in app. --}}
        <link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
        <script>
            (function () {
                try {
                    const stored = localStorage.getItem('dot-theme');
                    if (stored === 'dark' || (!stored && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                        document.documentElement.classList.add('dark');
                    }
                } catch (e) {}
            })();
        </script>
        @vite(['resources/css/app.css', 'resources/css/programme.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="pg">
        {{ $slot }}
        @livewireScripts
    </body>
</html>
