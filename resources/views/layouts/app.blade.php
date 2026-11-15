<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto" data-dh-theme-class="dark" data-dh-theme-key="dot-theme" data-dh-theme-default="system">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' — Dot.Projects' : 'Dot.Projects' }}</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    {{-- Roboto, self-hosted from this site's own public/dot-ui (R-52). No third-party font request, no CDN. --}}
    <link rel="preload" href="{{ asset('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
    {{-- dot-ui 0.6.1: Roboto, type scale, tokens, search, switcher and header in ONE minified stylesheet, first in the head, before the app CSS so the
         token mapping at the end of programme.css wins. --}}
    <link rel="stylesheet" href="{{ asset('dot-ui/css/dot-ui.min.css') }}">
    {{-- Theme before first paint: a stored choice wins, otherwise the system preference (dot-ui's own boot script, an external file). --}}
    <script src="{{ asset('dot-ui/js/dot-theme-boot.min.js') }}"></script>
    {{-- workspace.css keeps the Tailwind utilities Jetstream's account and team pages use;
         programme.css is the design system (DESIGN.md) and wins after it. --}}
    @vite(['resources/css/workspace.css', 'resources/css/programme.css', 'resources/js/app.js'])
    @livewireStyles
    {{-- No stand-alone Alpine here: Livewire 3 bundles and starts its own (@livewireScripts). A second copy
         replaces window.Alpine and breaks every Livewire re-render. --}}
</head>
<body class="pg">
    @php
        $user = Auth::user();
        $team = $user?->currentTeam;
        $oversees = $team && App\Services\Workload::oversees($user, $team);
        $is = fn (string ...$patterns) => request()->routeIs(...$patterns);
        // Five daily places stay in the bar; the rest sit in named groups (the drawer on a phone lists the same).
        $nav = [
            ['label' => 'Projects', 'href' => route('dashboard'), 'current' => $is('dashboard', 'projects.*')],
            ['label' => 'Portfolio', 'href' => route('portfolio.index'), 'current' => $is('portfolio.*')],
            ['label' => 'Calendar', 'href' => route('calendar.index'), 'current' => $is('calendar.*')],
            ['label' => 'Reports', 'href' => route('reporting.index'), 'current' => $is('reporting.*')],
            ['label' => 'Dashboards', 'href' => route('dashboards.index'), 'current' => $is('dashboards.*')],
            ['label' => 'Work', 'items' => [
                ['label' => 'My time', 'href' => route('time.index'), 'current' => $is('time.*')],
                ['label' => 'Workload', 'href' => route('workload.index'), 'current' => $is('workload.*')],
                ['label' => 'Automations', 'href' => route('automations.index'), 'current' => $is('automations.*')],
            ]],
        ];
        // Integrations and Data and security are for the business owner and admins only.
        if ($oversees) {
            $nav[] = ['label' => 'Admin', 'items' => [
                ['label' => 'Integrations', 'href' => route('integrations.index'), 'current' => $is('integrations.*')],
                ['label' => 'Data and security', 'href' => route('security.index'), 'current' => $is('security.*')],
            ]];
        }
        $links = [];
        if ($user) {
            $links[] = ['label' => 'Profile and security', 'href' => route('profile.show'), 'current' => $is('profile.*')];
            if (Laravel\Jetstream\Jetstream::hasApiFeatures()) {
                $links[] = ['label' => 'API tokens', 'href' => route('api-tokens.index'), 'current' => $is('api-tokens.*')];
            }
            if ($team) {
                $links[] = ['label' => 'Team members and settings', 'href' => route('teams.show', $team), 'current' => $is('teams.*')];
            }
            if (Laravel\Jetstream\Jetstream::hasTeamFeatures() && $user->can('create', Laravel\Jetstream\Jetstream::newTeamModel())) {
                $links[] = ['label' => 'Create another business', 'href' => route('teams.create')];
            }
            // Switching business: one form row per business, the same PUT the Jetstream switcher sends.
            if (Laravel\Jetstream\Jetstream::hasTeamFeatures() && $team && $user->allTeams()->count() > 1) {
                $links[] = ['heading' => 'Switch business'];
                foreach ($user->allTeams() as $other) {
                    $links[] = ['label' => $other->name, 'current' => $user->isCurrentTeam($other),
                        'form' => ['action' => route('current-team.update'), 'method' => 'PUT', 'fields' => ['team_id' => $other->id]]];
                }
            }
        }
        $account = $user ? [
            'name' => $user->name,
            'email' => $user->email,
            'team' => $team?->name,
            'links' => $links,
            'signOut' => ['action' => route('logout')],
            'theme' => true,
        ] : null;
        // The switcher asks InfoDot; when even the package cannot build its answer it still prints the honest degraded state.
        try {
            $switcher = Dot\Ui\PlatformSwitcher::fromConfig('projects')->for($team?->infodot_team_uuid);
        } catch (Throwable) {
            $switcher = Dot\Ui\PlatformSwitcher::plain('projects');
        }
    @endphp

    <x-dot.header class="dh--swpanel" :brand-href="$user ? route('dashboard') : url('/')" brand-label="Dot.Projects home" :nav="$nav" :account="$account" skip="#main"
                  :context="$team && ! $team->personal_team ? $team->name : null">
        <x-slot:brand>
            <img class="dh-logo-day" src="{{ asset('images/logo-mark.png') }}" alt="" width="40" height="26">
            <img class="dh-logo-night" src="{{ asset('images/logo-light-mark.png') }}" alt="" width="40" height="26">
            <span class="dh-brand__name">Dot.Projects</span>
        </x-slot:brand>
        @auth
            <x-slot:search>
                <livewire:header-search />
            </x-slot:search>
            <x-slot:tools>
                <x-dot.switcher platform="projects" :model="$switcher" :company="$team?->infodot_team_uuid" :company-name="$team?->name" logos="/dot-ui/img/platforms" />
                <livewire:notification-bell />
            </x-slot:tools>
        @endauth
    </x-dot.header>

    <div class="pg-content">
        <x-banner />
        <main id="main" tabindex="-1">{{ $slot }}</main>
        @auth
            {{-- The command palette (R-34 P13b): a native dialog, so focus stays inside and Escape closes it. Ctrl or Cmd and K. --}}
            <dialog class="pg-cmd" data-cmd aria-label="Search and commands">
                <livewire:command-palette :project-id="($p = request()->route('project')) instanceof App\Models\Project ? $p->id : null" />
            </dialog>
        @endauth
    </div>

    @stack('modals')
    @livewireScripts
    <script src="{{ asset('dot-ui/js/dot-ui-core.min.js') }}" defer nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
</body>
</html>
