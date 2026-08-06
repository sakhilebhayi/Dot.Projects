<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dot.Projects — Project & programme delivery for the Dot Ecosystem</title>
        <meta name="description" content="Plan, track, and deliver multi-phase initiatives with milestones, tasks, and team collaboration — with AI-assisted plan generation.">

        <!-- Favicon -->
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700;800&family=Source+Sans+3:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])

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
                --line: rgba(238, 240, 245, 0.1);
                --font-display: 'Archivo', system-ui, sans-serif;
                --font-body: 'Source Sans 3', system-ui, sans-serif;
                --font-mono: 'DM Mono', ui-monospace, monospace;
                --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            }
            html { background: var(--ink); }
            body { font-family: var(--font-body); background: var(--ink); color: var(--paper); }
            .font-display { font-family: var(--font-display); }
            .font-mono { font-family: var(--font-mono); }

            .press { transition: transform 160ms var(--ease-out); }
            .press:active { transform: scale(0.97); }

            @media (prefers-reduced-motion: no-preference) {
                .reveal {
                    opacity: 0;
                    transform: translateY(14px);
                    transition: opacity 600ms var(--ease-out), transform 600ms var(--ease-out);
                }
                .reveal.is-visible { opacity: 1; transform: translateY(0); }
            }
            @media (prefers-reduced-motion: reduce) {
                .reveal { opacity: 1; transform: none; }
            }

            @media (hover: hover) and (pointer: fine) {
                .row-hover:hover { background: rgba(238, 240, 245, 0.03); }
                .link-underline { background-size: 0% 1px; }
                .link-underline:hover { background-size: 100% 1px; }
            }
            .link-underline {
                background-image: linear-gradient(currentColor, currentColor);
                background-position: 0 100%;
                background-repeat: no-repeat;
                transition: background-size 220ms var(--ease-out);
            }
        </style>
    </head>
    <body class="antialiased">

        <!-- Nav -->
        <header
            id="site-header"
            class="fixed top-0 left-0 right-0 z-50 transition-colors duration-300 border-b border-transparent"
        >
            <nav class="max-w-[1400px] mx-auto px-5 sm:px-8 py-3 flex items-center justify-between">
                <a href="/" class="flex items-center gap-2.5 press">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Projects" class="h-14 sm:h-[4.5rem] w-auto">
                </a>

                <div class="hidden md:flex items-center gap-8 font-mono text-[13px] tracking-wide uppercase text-[var(--mist)]">
                    <a href="#capabilities" class="link-underline hover:text-[var(--paper)] pb-0.5">Capabilities</a>
                    <a href="#board" class="link-underline hover:text-[var(--paper)] pb-0.5">The board</a>
                </div>

                @if (Route::has('login'))
                    <div class="flex items-center gap-3">
                        @auth
                            <a href="{{ url('/dashboard') }}" class="press flex items-center gap-2 px-5 py-2.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[#13141d] text-sm font-display font-semibold rounded-lg transition-colors">
                                Dashboard
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="hidden sm:block text-sm font-medium text-[var(--mist)] hover:text-[var(--paper)] transition-colors">
                                Sign in
                            </a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="press px-5 py-2.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[#13141d] text-sm font-display font-semibold rounded-lg transition-colors">
                                    Get started
                                </a>
                            @endif
                        @endauth

                        <button id="menu-toggle" class="md:hidden press p-2 -mr-2 text-[var(--paper)]" aria-label="Toggle menu" aria-expanded="false">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path id="icon-open" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7h16M4 12h16M4 17h16"></path>
                                <path id="icon-close" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                @endif
            </nav>

            <div id="mobile-menu" class="hidden md:hidden border-t border-[var(--line)] bg-[#13141d]">
                <div class="flex flex-col px-5 py-4 gap-1 font-mono text-sm uppercase tracking-wide">
                    <a href="#capabilities" class="px-3 py-2.5 text-[var(--mist)] hover:text-[var(--paper)]">Capabilities</a>
                    <a href="#board" class="px-3 py-2.5 text-[var(--mist)] hover:text-[var(--paper)]">The board</a>
                    @guest
                        <a href="{{ route('login') }}" class="px-3 py-2.5 text-[var(--mist)] hover:text-[var(--paper)]">Sign in</a>
                    @endguest
                </div>
            </div>
        </header>

        <!-- Hero -->
        <section class="relative min-h-[100dvh] flex items-end overflow-hidden">
            <!-- Photo: a real To Do / Doing / Done kanban board, hands moving sticky-note cards, by Gabriel Carvalho, unsplash.com/photos/WqYgZLbDjhQ -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1746729798021-129315426424?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(19,20,29,0.6) 0%, rgba(19,20,29,0.8) 45%, #13141d 92%);"></div>
            <div class="absolute inset-0" style="background: linear-gradient(90deg, #13141d 0%, rgba(19,20,29,0.62) 40%, transparent 72%);"></div>
            <div class="absolute inset-0" style="background: radial-gradient(ellipse 80% 60% at 15% 0%, rgba(74,79,128,0.18) 0%, transparent 60%);"></div>

            <!-- Signature element: line-art starred project folder — echoes the logo's own folder/document/star icon -->
            <svg class="hidden lg:block absolute right-[5%] bottom-[8%] h-[62%] w-auto opacity-[0.16] pointer-events-none" viewBox="0 0 260 260" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M20 70C20 64.4772 24.4772 60 30 60H90L110 80H210C215.523 80 220 84.4772 220 90V210C220 215.523 215.523 220 210 220H30C24.4772 220 20 215.523 20 210V70Z" stroke="#eef0f5" stroke-width="4" stroke-linejoin="round"/>
                <path d="M100 40H175L200 65V150C200 155.523 195.523 160 190 160H100C94.4772 160 90 155.523 90 150V50C90 44.4772 94.4772 40 100 40Z" stroke="#eef0f5" stroke-width="3" stroke-linejoin="round"/>
                <path d="M110 75H160M110 95H160M110 115H140" stroke="#eef0f5" stroke-width="2.5" stroke-linecap="round"/>
                <path d="M120 175L128 191L146 194L133 206L136 224L120 215L104 224L107 206L94 194L112 191L120 175Z" fill="#f0c33a"/>
            </svg>

            <div class="relative z-10 max-w-[1400px] mx-auto px-5 sm:px-8 pt-32 pb-16 sm:pb-20 w-full">
                <div class="max-w-2xl reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-6">
                        Project &amp; programme delivery
                    </p>

                    <h1 class="font-display font-bold text-4xl sm:text-5xl lg:text-6xl leading-[1.05] tracking-tight text-[var(--paper)] mb-6">
                        From brief to milestone<br>plan in seconds.
                    </h1>

                    <p class="text-lg text-[var(--mist)] leading-relaxed max-w-xl mb-10">
                        Plan, track, and deliver multi-phase initiatives with milestones, tasks, and team collaboration. Describe a project and get a structured milestone/task breakdown instantly — every prompt and response logged to a full audit trail.
                    </p>

                    @guest
                        <div class="flex flex-wrap items-center gap-4">
                            <a href="{{ route('register') }}" class="press px-7 py-3.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[#13141d] font-display font-semibold rounded-lg transition-colors">
                                Get started
                            </a>
                            <a href="#capabilities" class="press flex items-center gap-2 px-7 py-3.5 text-[var(--paper)] font-medium rounded-lg border border-[var(--line)] hover:border-[var(--mist)] transition-colors">
                                See how it works
                            </a>
                        </div>
                    @endguest
                </div>
            </div>

            <!-- Status strip — real ProjectTask statuses from wiki.md §3, not fabricated metrics -->
            <div class="relative z-10 w-full border-t border-[var(--line)] bg-[#13141d]/60 backdrop-blur-sm">
                <div class="max-w-[1400px] mx-auto px-5 sm:px-8 py-4 flex flex-wrap gap-x-8 gap-y-2 font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--mist)]">
                    <span>Backlog</span>
                    <span class="text-[var(--gold)]">·</span>
                    <span>To do</span>
                    <span class="text-[var(--gold)]">·</span>
                    <span>In progress</span>
                    <span class="text-[var(--gold)]">·</span>
                    <span>Review</span>
                    <span class="text-[var(--gold)]">·</span>
                    <span>Done</span>
                </div>
            </div>
        </section>

        <!-- Features -->
        <section id="capabilities" class="py-24 sm:py-28 px-5 sm:px-8">
            <div class="max-w-[1400px] mx-auto">
                <div class="max-w-xl mb-16 reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-4">What it does</p>
                    <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--paper)] leading-tight">
                        Everything a delivery team needs, in one place
                    </h2>
                </div>

                <div class="grid md:grid-cols-2 border-t border-[var(--line)]">
                    @php
                        $features = [
                            ['tag' => 'AI', 'title' => 'AI-assisted plan generation', 'body' => 'Describe your project and get 3–5 milestones with 3–6 tasks each. Falls back to a working mock plan when no live AI key is set — it never blocks project creation.'],
                            ['tag' => 'Board', 'title' => 'Kanban board', 'body' => 'Move tasks through a real workflow — backlog, to do, in progress, review, done — with priority and due dates on every card.'],
                            ['tag' => 'Milestones', 'title' => 'Milestones & progress', 'body' => 'Completion percentage derived directly from done tasks. A milestone completes itself once every task on it does.'],
                            ['tag' => 'Team', 'title' => 'Team collaboration', 'body' => 'Comment on projects and individual tasks, assign owners, and get notified on assignment and on milestones due soon.'],
                            ['tag' => 'Audit', 'title' => 'Full AI audit trail', 'body' => 'Every plan-generation call is logged with its prompt, response, and token usage — not just the milestones it produced.'],
                            ['tag' => 'Events', 'title' => 'Real delivery events', 'body' => 'Milestone completion and project closure dispatch real domain events other tooling in the ecosystem can build on.'],
                        ];
                    @endphp
                    @foreach ($features as $i => $f)
                        <div class="row-hover border-b border-[var(--line)] {{ $i % 2 === 0 ? 'md:border-r' : '' }} px-1 py-8 sm:py-10 transition-colors reveal" data-reveal>
                            <p class="font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--gold)] mb-3">{{ $f['tag'] }}</p>
                            <h3 class="font-display font-semibold text-xl text-[var(--paper)] mb-2.5">{{ $f['title'] }}</h3>
                            <p class="text-[var(--mist)] leading-relaxed max-w-md">{{ $f['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- The board — real kanban workflow from wiki.md §3, styled as the platform's own data artifact -->
        <section id="board" class="py-24 sm:py-28 px-5 sm:px-8 bg-[var(--ink-soft)] border-y border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto">
                <div class="grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)] gap-12 lg:gap-20">
                    <div class="reveal" data-reveal>
                        <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-4">The board</p>
                        <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--paper)] leading-tight mb-5">
                            One workflow, from backlog to done
                        </h2>
                        <p class="text-[var(--mist)] leading-relaxed max-w-sm">
                            Every task carries a priority and an owner. Move it across the board and the milestone it belongs to tracks its own completion automatically.
                        </p>
                    </div>

                    <div class="reveal overflow-x-auto" data-reveal>
                        <div class="flex items-stretch gap-0 min-w-[560px] font-mono text-xs uppercase tracking-[0.1em]">
                            @php
                                $columns = [
                                    ['label' => 'Backlog', 'note' => 'Not yet scheduled'],
                                    ['label' => 'To do', 'note' => 'Scheduled, not started'],
                                    ['label' => 'In progress', 'note' => 'Being worked on now'],
                                    ['label' => 'Review', 'note' => 'Awaiting sign-off'],
                                    ['label' => 'Done', 'note' => 'Counted toward completion'],
                                ];
                            @endphp
                            @foreach ($columns as $i => $c)
                                <div class="flex-1 {{ $i > 0 ? 'border-l border-[var(--line)] pl-4' : '' }} {{ $i < count($columns) - 1 ? 'pr-4' : '' }}">
                                    <div class="flex items-center gap-2 mb-3">
                                        <span class="text-[var(--navy-soft)]">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</span>
                                        <span class="text-[var(--paper)] font-display normal-case text-sm font-semibold tracking-normal">{{ $c['label'] }}</span>
                                    </div>
                                    <p class="text-[var(--mist)] normal-case tracking-normal leading-relaxed">{{ $c['note'] }}</p>
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-8 text-sm text-[var(--mist)] normal-case font-body max-w-md">
                            When every task on a milestone reaches Done, the milestone completes itself — and once every milestone on a project is complete, the project closes itself too.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="relative py-28 sm:py-36 px-5 sm:px-8 overflow-hidden">
            <!-- Photo: a team planning together at a sticky-note-covered glass wall, by Vitaly Gariev, unsplash.com/photos/UtIr_UaiDmg -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1758691736836-0413b066787a?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0" style="background: linear-gradient(180deg, #13141d 0%, rgba(19,20,29,0.85) 50%, #13141d 100%);"></div>
            <div class="absolute inset-0" style="background: radial-gradient(ellipse 70% 50% at 50% 100%, rgba(240,195,58,0.08) 0%, transparent 65%);"></div>

            <div class="relative z-10 max-w-2xl mx-auto text-center reveal" data-reveal>
                <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--paper)] leading-tight mb-5">
                    Turn a brief into a plan today
                </h2>
                <p class="text-[var(--mist)] leading-relaxed mb-10 max-w-lg mx-auto">
                    Create a project, let Dot.Projects draft the milestones, and start moving tasks across the board.
                </p>

                @guest
                    <div class="flex flex-wrap justify-center gap-4">
                        <a href="{{ route('register') }}" class="press px-8 py-3.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[#13141d] font-display font-semibold rounded-lg transition-colors">
                            Get started
                        </a>
                        <a href="{{ route('login') }}" class="press px-8 py-3.5 text-[var(--paper)] font-medium rounded-lg border border-[var(--line)] hover:border-[var(--mist)] transition-colors">
                            Sign in
                        </a>
                    </div>
                @endguest
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-14 px-5 sm:px-8 border-t border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto flex flex-col sm:flex-row items-center justify-between gap-6">
                <a href="/" class="flex items-center gap-2.5">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Projects" class="h-11 w-auto opacity-90">
                </a>
                <p class="font-mono text-xs tracking-wide text-[var(--mist)]">
                    &copy; {{ date('Y') }} Dot.Projects. Project &amp; programme delivery for the Dot Ecosystem.
                </p>
            </div>
        </footer>

        <script>
            // Nav scroll state + mobile menu (vanilla JS — no Alpine dependency on this guest page)
            const header = document.getElementById('site-header');
            const onScroll = () => {
                header.classList.toggle('bg-[#13141d]/95', window.pageYOffset > 24);
                header.classList.toggle('backdrop-blur-md', window.pageYOffset > 24);
                header.classList.toggle('border-[var(--line)]', window.pageYOffset > 24);
                header.classList.toggle('border-transparent', window.pageYOffset <= 24);
            };
            window.addEventListener('scroll', onScroll, { passive: true });
            onScroll();

            const menuToggle = document.getElementById('menu-toggle');
            const mobileMenu = document.getElementById('mobile-menu');
            const iconOpen = document.getElementById('icon-open');
            const iconClose = document.getElementById('icon-close');
            if (menuToggle) {
                menuToggle.addEventListener('click', () => {
                    const isOpen = !mobileMenu.classList.contains('hidden');
                    mobileMenu.classList.toggle('hidden', isOpen);
                    iconOpen.classList.toggle('hidden', !isOpen);
                    iconClose.classList.toggle('hidden', isOpen);
                    menuToggle.setAttribute('aria-expanded', String(!isOpen));
                });
            }

            if (window.matchMedia('(prefers-reduced-motion: no-preference)').matches && 'IntersectionObserver' in window) {
                const io = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('is-visible');
                            io.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
                document.querySelectorAll('[data-reveal]').forEach((el) => io.observe(el));
            } else {
                document.querySelectorAll('[data-reveal]').forEach((el) => el.classList.add('is-visible'));
            }
        </script>
    </body>
</html>
