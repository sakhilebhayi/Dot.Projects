<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' — Dot.Projects' : 'Dot.Projects' }}</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- The Programme (DESIGN.md): Schibsted Grotesk for everything, DM Mono for identifiers only. --}}
    <link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <script>
        // Theme before first paint: a stored choice wins, otherwise the system preference.
        (function () {
            try {
                const stored = localStorage.getItem('dot-theme');
                if (stored === 'dark' || (!stored && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {}
        })();
    </script>
    {{-- workspace.css keeps the Tailwind utilities Jetstream's account and team pages use;
         programme.css is the design system (DESIGN.md) and wins after it. --}}
    @vite(['resources/css/workspace.css', 'resources/css/programme.css', 'resources/js/app.js'])
    @livewireStyles
    {{-- No stand-alone Alpine here: Livewire 3 bundles and starts its own (@livewireScripts). A second copy
         replaces window.Alpine and breaks every Livewire re-render. --}}
</head>
<body class="pg">
    @php
        $nav = [
            ['dashboard', 'dashboard', 'folder_open', 'Projects'],
            ['dashboards.index', 'dashboards.*', 'space_dashboard', 'Dashboards'],
            ['portfolio.index', 'portfolio.*', 'account_tree', 'Portfolio'],
            ['reporting.index', 'reporting.*', 'table_chart', 'Reports'],
            ['time.index', 'time.*', 'timer', 'Time'],
            ['workload.index', 'workload.*', 'stacked_bar_chart', 'Workload'],
            ['notifications.index', 'notifications.*', 'notifications', 'Notifications'],
        ];
    @endphp
    <nav class="pg-rail" aria-label="Dot.Projects">
        <a href="{{ route('dashboard') }}" class="pg-mark" aria-label="Dot.Projects home">
            <img class="day" src="{{ asset('images/logo-mark.png') }}" alt="" width="40" height="26">
            <img class="night" src="{{ asset('images/logo-light-mark.png') }}" alt="" width="40" height="26">
        </a>
        @foreach ($nav as [$route, $pattern, $icon, $label])
            <a href="{{ route($route) }}" class="pg-ic" aria-label="{{ $label }}" @if (request()->routeIs($pattern) || ($route === 'dashboard' && request()->routeIs('projects.*'))) aria-current="page" @endif>
                <span class="material-symbols-rounded" aria-hidden="true">{{ $icon }}</span><span class="pg-tip">{{ $label }}</span>
            </a>
        @endforeach
        @if (Auth::user()?->currentTeam)
            <a href="{{ route('teams.show', Auth::user()->currentTeam) }}" class="pg-ic" aria-label="Team" @if (request()->routeIs('teams.*')) aria-current="page" @endif>
                <span class="material-symbols-rounded" aria-hidden="true">group</span><span class="pg-tip">Team</span>
            </a>
        @endif
        <span class="pg-spacer"></span>
        <button type="button" class="pg-ic" data-theme-toggle aria-label="Switch light or dark">
            <span class="material-symbols-rounded" aria-hidden="true">contrast</span><span class="pg-tip">Light or dark</span>
        </button>
        <a href="{{ route('profile.show') }}" class="pg-ic" aria-label="Your profile" @if (request()->routeIs('profile.*', 'api-tokens.*')) aria-current="page" @endif>
            <span class="material-symbols-rounded" aria-hidden="true">person</span><span class="pg-tip">Your profile</span>
        </a>
    </nav>

    <header class="pg-top">
        <div class="pg-where">@isset($header){{ $header }}@else<b>Dot.Projects</b>@endisset</div>
        <span class="pg-fill"></span>
        @auth
            @if (Laravel\Jetstream\Jetstream::hasTeamFeatures() && Auth::user()->currentTeam)
                <details class="pg-menu">
                    <summary class="pg-chip" aria-label="Switch business">{{ Auth::user()->currentTeam->name }}<span class="material-symbols-rounded" aria-hidden="true" style="font-size:18px">unfold_more</span></summary>
                    <div class="pg-menu-panel">
                        <div class="pg-menu-label">Your businesses</div>
                        @foreach (Auth::user()->allTeams() as $team)
                            <x-switchable-team :team="$team" />
                        @endforeach
                        <hr>
                        <a href="{{ route('teams.show', Auth::user()->currentTeam) }}">Team members and settings</a>
                        @can('create', Laravel\Jetstream\Jetstream::newTeamModel())
                            <a href="{{ route('teams.create') }}">Create another business</a>
                        @endcan
                    </div>
                </details>
            @endif
            <livewire:notification-bell />
            <details class="pg-menu">
                <summary class="pg-chip" style="padding:0 4px;border:0;background:none" aria-label="Account">
                    <span class="pg-avatar" aria-hidden="true">{{ Str::of(Auth::user()->name)->explode(' ')->map(fn ($p) => Str::substr($p, 0, 1))->take(2)->implode('') }}</span>
                </summary>
                <div class="pg-menu-panel">
                    <div class="pg-menu-label">{{ Auth::user()->name }}</div>
                    <a href="{{ route('profile.show') }}">Profile and security</a>
                    @if (Laravel\Jetstream\Jetstream::hasApiFeatures())
                        <a href="{{ route('api-tokens.index') }}">API tokens</a>
                    @endif
                    <hr>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit">Sign out</button>
                    </form>
                </div>
            </details>
        @endauth
    </header>

    <div class="pg-content">
        {{-- Inside the content area, below the fixed top bar, so a banner is never hidden behind it. --}}
        <x-banner />
        <main>{{ $slot }}</main>
    </div>

    @stack('modals')
    @livewireScripts
</body>
</html>
