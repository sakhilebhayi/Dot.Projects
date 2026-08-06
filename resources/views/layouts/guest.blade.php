<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Dot.Projects') }}</title>

        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

        <!-- Fonts — same Archivo / Source Sans 3 / DM Mono pairing as resources/views/welcome.blade.php -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700;800&family=Source+Sans+3:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <!-- Styles -->
        @livewireStyles

        {{-- Brand palette for guest/auth pages — the same CSS custom properties as welcome.blade.php's :root block.
             Guest pages now render in the platform's single dark brand palette, matching the welcome page (which
             has no light/dark toggle either); the old manual theme toggle only ever affected guest pages, so
             removing it here doesn't touch the authenticated dashboard's own layout/toggle. --}}
        <style>
            :root {
                --ink: #13141d;
                --ink-soft: #191b26;
                --navy: #4a4f80;
                --navy-soft: #6167a0;
                --gold: #f0c33a;
                --gold-soft: #f5d573;
                --paper: #eef0f5;
                --mist: #9a9db3;
                --line: rgba(238, 240, 245, 0.14);
                --font-display: 'Archivo', system-ui, sans-serif;
                --font-body: 'Source Sans 3', system-ui, sans-serif;
                --font-mono: 'DM Mono', ui-monospace, monospace;
            }
            html, body { background: var(--ink); }
            body { font-family: var(--font-body); color: var(--paper); }
            .font-display { font-family: var(--font-display); }

            /* Brand overrides for Jetstream's shared x-input/x-label/x-button/x-checkbox components.
               This stylesheet only ever loads via layouts/guest.blade.php (guest pages), never the
               authenticated app layout, so these rules can't leak into the dashboard. !important wins
               over the compiled Tailwind utility classes already in the shared component markup, which
               are left untouched (so their name/action/model bindings stay exactly as Fortify expects). */
            .dark .dark\:bg-gray-900,
            .dark .dark\:bg-gray-800 { background-color: var(--ink-soft) !important; }
            .bg-gray-100.dark\:bg-gray-900 { background-color: var(--ink) !important; }
            .dark .dark\:text-gray-400,
            .dark .dark\:text-gray-300 { color: var(--mist) !important; }
            .dark .dark\:hover\:text-gray-100:hover { color: var(--paper) !important; }
            .dark .dark\:border-gray-700 { border-color: var(--line) !important; }
            input:focus, textarea:focus, select:focus {
                border-color: var(--gold) !important;
                box-shadow: 0 0 0 3px rgba(240, 195, 58, 0.28) !important;
                outline: none !important;
            }
            .dark .dark\:bg-gray-200 { background-color: var(--gold) !important; }
            .dark .dark\:text-gray-800 { color: #13141d !important; }
            .dark .dark\:hover\:bg-white:hover,
            .dark .dark\:focus\:bg-white:focus,
            .dark .dark\:active\:bg-gray-300:active { background-color: var(--gold-soft) !important; }
            button:focus { box-shadow: 0 0 0 3px rgba(240, 195, 58, 0.35) !important; }
            .text-indigo-600 { color: var(--navy-soft) !important; }
            /* Checkbox needs its own contrast against the ink-soft card, not the same fill as the card itself */
            input[type="checkbox"].dark\:bg-gray-900 { background-color: var(--ink) !important; border-color: rgba(238, 240, 245, 0.35) !important; }
            .dark .dark\:text-green-400 { color: #6ee7a0 !important; }
            .dark .dark\:text-red-400 { color: #f6907f !important; }
            .dark .dark\:prose-invert a { color: var(--gold-soft) !important; }
        </style>
    </head>
    <body>
        <div class="font-sans antialiased">
            {{ $slot }}
        </div>

        @livewireScripts
    </body>
</html>
