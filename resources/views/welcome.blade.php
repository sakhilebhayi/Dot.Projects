<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dot.Projects — Project &amp; Programme Delivery for the Dot Ecosystem</title>
    <meta name="description" content="Plan, track, and deliver multi-phase initiatives with milestones, tasks, and team collaboration — with AI-assisted plan generation.">

    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{background:#0a0d14;color:#e4e4e7;font-family:'Inter',system-ui,sans-serif;font-size:15px;line-height:1.6;overflow-x:hidden}
        :root{--accent:#60a5fa;--accent-soft:rgba(96,165,250,0.12)}
        a{color:inherit}
        h1,h2,h3{font-family:'Space Grotesk',sans-serif}
        .wrap{max-width:1180px;margin:0 auto;padding-inline:max(1.5rem,5vw)}
        .btn-primary{display:inline-flex;align-items:center;gap:8px;padding:12px 26px;border-radius:10px;background:var(--accent);color:#0a0d14;font-weight:700;text-decoration:none;transition:filter .15s}
        .btn-primary:hover{filter:brightness(1.12)}
        .btn-ghost{display:inline-flex;align-items:center;gap:8px;padding:12px 26px;border-radius:10px;background:transparent;border:1px solid rgba(255,255,255,0.14);color:#a1a1aa;text-decoration:none;font-weight:600;transition:all .15s}
        .btn-ghost:hover{border-color:rgba(96,165,250,0.5);color:#f4f4f5}
        .badge{display:inline-flex;align-items:center;gap:7px;padding:6px 14px;background:var(--accent-soft);border:1px solid rgba(96,165,250,0.3);border-radius:100px;font-size:12px;font-weight:600;color:#93c5fd}
        .card{background:#10141d;border:1px solid rgba(255,255,255,0.08);border-radius:14px;padding:1.75rem;transition:border-color .2s}
        .card:hover{border-color:rgba(96,165,250,0.35)}
        .card-icon{width:44px;height:44px;border-radius:12px;background:var(--accent-soft);border:1px solid rgba(96,165,250,0.25);display:flex;align-items:center;justify-content:center;margin-bottom:1.1rem;font-size:20px}
        .kanban-col{background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);border-radius:10px;padding:10px;min-width:110px;text-align:center;font-size:11.5px;color:#a1a1aa;font-weight:600;}
    </style>
</head>
<body>
    {{-- Nav --}}
    <nav style="position:sticky;top:0;z-index:50;background:rgba(10,13,20,0.85);backdrop-filter:blur(14px);border-bottom:1px solid rgba(255,255,255,0.06);">
        <div class="wrap" style="height:64px;display:flex;align-items:center;justify-content:space-between;">
            <a href="/" style="display:flex;align-items:center;gap:10px;text-decoration:none;">
                <img src="{{ asset('images/logo.png') }}" alt="Dot.Projects" style="height:34px;width:auto;">
                <span style="font-family:'Space Grotesk',sans-serif;font-size:16px;font-weight:700;letter-spacing:-0.01em;color:#f4f4f5;">Dot.Projects</span>
            </a>
            <div style="display:flex;align-items:center;gap:12px;">
                @if (Route::has('login'))
                    @auth
                        <a href="{{ url('/dashboard') }}" class="btn-primary" style="padding:9px 20px;font-size:14px;">Dashboard</a>
                    @else
                        <a href="{{ route('login') }}" class="btn-ghost" style="padding:9px 20px;font-size:14px;">Sign in</a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="btn-primary" style="padding:9px 20px;font-size:14px;">Get started</a>
                        @endif
                    @endauth
                @endif
            </div>
        </div>
    </nav>

    {{-- Hero --}}
    <section style="position:relative;padding:8rem max(1.5rem,5vw) 6rem;overflow:hidden;">
        <!-- Photographic Background: real kanban-board-task-management photo by Gabriel Carvalho (@sent1nel4s), unsplash.com/photos/people-use-a-kanban-board-for-task-management-WqYgZLbDjhQ -->
        <div style="position:absolute;inset:0;background-image:url('https://images.unsplash.com/photo-1746729798021-129315426424?q=80&amp;w=2400&amp;auto=format&amp;fit=crop');background-size:cover;background-position:center;"></div>
        <div style="position:absolute;inset:0;background:linear-gradient(180deg,rgba(10,13,20,0.88) 0%,rgba(10,13,20,0.93) 55%,#0a0d14 100%);"></div>
        <div style="position:absolute;inset:0;background:linear-gradient(90deg,#0a0d14 0%,rgba(10,13,20,0.55) 45%,transparent 80%);"></div>

        <div class="wrap" style="position:relative;max-width:760px;">
            <div class="badge">
                <span>Project &amp; Programme Delivery</span>
            </div>
            <h1 style="font-size:clamp(2.3rem,5.5vw,3.6rem);font-weight:700;color:#f4f4f5;line-height:1.12;letter-spacing:-0.02em;margin:1.4rem 0 1.3rem;">
                From project brief to<br>milestone plan in seconds
            </h1>
            <p style="font-size:1.08rem;color:#a1a1aa;max-width:600px;margin-bottom:2.2rem;line-height:1.7;">
                Dot.Projects is the project and programme delivery platform in the InfoDot ecosystem: plan, track, and deliver multi-phase initiatives with milestones, tasks, and team collaboration — with AI-assisted plan generation that turns a project brief into a structured milestone/task breakdown instantly.
            </p>
            <div style="display:flex;gap:14px;flex-wrap:wrap;">
                @guest
                    <a href="{{ route('register') }}" class="btn-primary">Get started</a>
                    <a href="#features" class="btn-ghost">See how it works</a>
                @endguest
                @auth
                    <a href="{{ url('/dashboard') }}" class="btn-primary">Go to Dashboard</a>
                @endauth
            </div>
        </div>
    </section>

    {{-- Features --}}
    <section id="features" style="padding:1rem max(1.5rem,5vw) 5rem;">
        <div class="wrap">
            <div style="text-align:center;max-width:640px;margin:0 auto 3rem;">
                <h2 style="font-size:2rem;font-weight:700;color:#f4f4f5;letter-spacing:-0.02em;margin-bottom:0.75rem;">Everything a delivery team needs</h2>
                <p style="color:#a1a1aa;font-size:15px;">A real data model behind a Livewire-driven kanban board.</p>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1.25rem;">
                <div class="card">
                    <div class="card-icon">✨</div>
                    <h3 style="font-size:1rem;font-weight:700;color:#f4f4f5;margin-bottom:0.5rem;">AI Plan Generation</h3>
                    <p style="font-size:13.5px;color:#a1a1aa;">Describe your project and get 3–5 milestones with 3–6 tasks each, with every prompt and response logged to a full audit trail.</p>
                </div>
                <div class="card">
                    <div class="card-icon">📋</div>
                    <h3 style="font-size:1rem;font-weight:700;color:#f4f4f5;margin-bottom:0.5rem;">Kanban Board</h3>
                    <p style="font-size:13.5px;color:#a1a1aa;margin-bottom:0.75rem;">Move tasks through a real workflow, from backlog to done.</p>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <span class="kanban-col">Backlog</span><span class="kanban-col">To Do</span><span class="kanban-col">In Progress</span><span class="kanban-col">Review</span><span class="kanban-col">Done</span>
                    </div>
                </div>
                <div class="card">
                    <div class="card-icon">🎯</div>
                    <h3 style="font-size:1rem;font-weight:700;color:#f4f4f5;margin-bottom:0.5rem;">Milestones &amp; Progress</h3>
                    <p style="font-size:13.5px;color:#a1a1aa;">Completion percentage derived directly from done tasks — no manual status guesswork.</p>
                </div>
                <div class="card">
                    <div class="card-icon">💬</div>
                    <h3 style="font-size:1rem;font-weight:700;color:#f4f4f5;margin-bottom:0.5rem;">Team Collaboration</h3>
                    <p style="font-size:13.5px;color:#a1a1aa;">Comment on projects and individual tasks, assign owners, and set priority and due dates.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- CTA --}}
    <section style="padding:2rem max(1.5rem,5vw) 7rem;text-align:center;">
        <div class="wrap" style="max-width:600px;padding:3rem 2.5rem;background:#10141d;border:1px solid rgba(96,165,250,0.18);border-radius:20px;">
            <h2 style="font-size:1.7rem;font-weight:700;color:#f4f4f5;letter-spacing:-0.02em;margin-bottom:0.75rem;">Turn a brief into a plan today</h2>
            <p style="font-size:14px;color:#a1a1aa;margin-bottom:2rem;">Create a project, let Dot.Projects draft the milestones, and start moving tasks across the board.</p>
            @guest
                <a href="{{ route('register') }}" class="btn-primary">Create your free account</a>
            @else
                <a href="{{ url('/dashboard') }}" class="btn-primary">Go to your Dashboard</a>
            @endguest
        </div>
    </section>

    {{-- Footer --}}
    <footer style="border-top:1px solid rgba(255,255,255,0.06);padding:2.5rem max(1.5rem,5vw);">
        <div class="wrap" style="display:flex;flex-direction:column;align-items:center;gap:1rem;text-align:center;">
            <img src="{{ asset('images/logo.png') }}" alt="Dot.Projects" style="height:30px;width:auto;opacity:0.9;">
            <p style="font-size:12px;color:#52525b;">&copy; {{ date('Y') }} Dot.Projects · Project &amp; programme delivery for the Dot Ecosystem</p>
        </div>
    </footer>
</body>
</html>
