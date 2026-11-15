<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto" data-dh-theme-class="dark" data-dh-theme-key="dot-theme" data-dh-theme-default="system">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name', 'Dot.Projects') }}</title>
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
        {{-- Roboto, self-hosted (R-52): no third-party font request. --}}
        <link rel="preload" href="{{ asset('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
        <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-roboto.css') }}">
        <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-type.css') }}">
        <script src="{{ asset('dot-ui/js/dot-theme-boot.js') }}"></script>
        @vite(['resources/css/app.css', 'resources/css/programme.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="pg">
        {{ $slot }}
        @livewireScripts
    </body>
</html>
