<x-app-layout>
    <div style="padding:2rem 2.5rem;">

        {{-- Page header --}}
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;">
            <div>
                <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 0.25rem;">Projects Dashboard</h1>
                <p style="font-size:0.8rem;color:#71717a;margin:0;">Overview of all your team projects</p>
            </div>
            <a href="{{ route('projects.create') }}" style="display:flex;align-items:center;gap:0.5rem;border-radius:9999px;background:linear-gradient(135deg,#7c3aed,#5b21b6);padding:0.65rem 1.25rem;font-family:'Syne',sans-serif;font-size:0.8rem;font-weight:700;color:#f7f5ff;text-decoration:none;box-shadow:0 8px 20px rgba(124,58,237,0.3);">
                <span class="material-symbols-rounded" style="font-size:18px;">add_circle</span>
                New Project
            </a>
        </div>

        {{-- KPI Cards --}}
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:2rem;">
            <div style="background:#141416;border:1px solid rgba(67,70,86,0.3);border-radius:0.75rem;padding:1.25rem 1.5rem;">
                <div style="font-size:0.68rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:0.5rem;">Total Projects</div>
                <div style="font-family:'Syne',sans-serif;font-size:2.25rem;font-weight:800;color:#f4f4f5;line-height:1;">{{ $projects->count() }}</div>
            </div>
            <div style="background:#141416;border:1px solid rgba(67,70,86,0.3);border-radius:0.75rem;padding:1.25rem 1.5rem;">
                <div style="font-size:0.68rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:0.5rem;">Active</div>
                <div style="font-family:'Syne',sans-serif;font-size:2.25rem;font-weight:800;color:#60a5fa;line-height:1;">{{ $projects->where('status','active')->count() }}</div>
            </div>
            <div style="background:#141416;border:1px solid rgba(67,70,86,0.3);border-radius:0.75rem;padding:1.25rem 1.5rem;">
                <div style="font-size:0.68rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:0.5rem;">Completed</div>
                <div style="font-family:'Syne',sans-serif;font-size:2.25rem;font-weight:800;color:#22c55e;line-height:1;">{{ $projects->where('status','completed')->count() }}</div>
            </div>
            <div style="background:#141416;border:1px solid rgba(67,70,86,0.3);border-radius:0.75rem;padding:1.25rem 1.5rem;">
                <div style="font-size:0.68rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:0.5rem;">Total Tasks</div>
                <div style="font-family:'Syne',sans-serif;font-size:2.25rem;font-weight:800;color:#a78bfa;line-height:1;">{{ $projects->sum('tasks_count') }}</div>
            </div>
        </div>

        {{-- Search & filter --}}
        <form method="GET" action="{{ route('dashboard') }}" style="display:flex;gap:0.75rem;margin-bottom:1.5rem;flex-wrap:wrap;">
            <div style="position:relative;flex:1;min-width:220px;">
                <span class="material-symbols-rounded" style="position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);font-size:17px;color:#52525b;">search</span>
                <input type="text" name="search" value="{{ $search }}" placeholder="Search projects by name…"
                       style="width:100%;background:#141416;border:1px solid rgba(67,70,86,0.4);border-radius:0.6rem;padding:0.6rem 0.85rem 0.6rem 2.25rem;font-size:0.8rem;color:#f4f4f5;outline:none;">
            </div>
            <select name="status" onchange="this.form.submit()" style="background:#141416;border:1px solid rgba(67,70,86,0.4);border-radius:0.6rem;padding:0.6rem 0.85rem;font-size:0.8rem;color:#f4f4f5;">
                <option value="" {{ $statusFilter === '' ? 'selected' : '' }}>All statuses</option>
                <option value="planning" {{ $statusFilter === 'planning' ? 'selected' : '' }}>Planning</option>
                <option value="active" {{ $statusFilter === 'active' ? 'selected' : '' }}>Active</option>
                <option value="on_hold" {{ $statusFilter === 'on_hold' ? 'selected' : '' }}>On Hold</option>
                <option value="completed" {{ $statusFilter === 'completed' ? 'selected' : '' }}>Completed</option>
            </select>
            <button type="submit" style="border-radius:0.6rem;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.08);padding:0.6rem 1.1rem;font-size:0.8rem;font-weight:600;color:#d4d4d8;cursor:pointer;">Filter</button>
            @if($search !== '' || $statusFilter !== '')
                <a href="{{ route('dashboard') }}" style="display:flex;align-items:center;font-size:0.75rem;color:#71717a;text-decoration:none;padding:0 0.5rem;">Clear</a>
            @endif
        </form>

        {{-- Projects Grid / Empty State --}}
        @if($projects->isEmpty())
            <div style="text-align:center;padding:5rem 2rem;background:#141416;border:1px solid rgba(67,70,86,0.3);border-radius:1rem;">
                <span class="material-symbols-rounded" style="font-size:52px;color:#3d4566;display:block;margin-bottom:1rem;">folder_open</span>
                @if($search !== '' || $statusFilter !== '')
                    <p style="font-family:'Syne',sans-serif;font-size:1.05rem;font-weight:700;color:#f4f4f5;margin:0 0 0.4rem;">No matching projects</p>
                    <p style="font-size:0.8rem;color:#71717a;margin:0 0 1.75rem;">Try a different search term or clear the filter.</p>
                    <a href="{{ route('dashboard') }}" style="display:inline-flex;align-items:center;gap:0.5rem;border-radius:9999px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.08);padding:0.7rem 1.5rem;font-family:'Syne',sans-serif;font-size:0.8rem;font-weight:700;color:#d4d4d8;text-decoration:none;">
                        Clear filters
                    </a>
                @else
                    <p style="font-family:'Syne',sans-serif;font-size:1.05rem;font-weight:700;color:#f4f4f5;margin:0 0 0.4rem;">No projects yet</p>
                    <p style="font-size:0.8rem;color:#71717a;margin:0 0 1.75rem;">Create your first project to get your team started.</p>
                    <a href="{{ route('projects.create') }}" style="display:inline-flex;align-items:center;gap:0.5rem;border-radius:9999px;background:linear-gradient(135deg,#7c3aed,#5b21b6);padding:0.7rem 1.5rem;font-family:'Syne',sans-serif;font-size:0.8rem;font-weight:700;color:#f7f5ff;text-decoration:none;box-shadow:0 8px 20px rgba(124,58,237,0.3);">
                        <span class="material-symbols-rounded" style="font-size:16px;">add_circle</span>
                        Create your first project
                    </a>
                @endif
            </div>
        @else
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1.25rem;">
                @foreach($projects as $project)
                    @php
                        $pct = $project->tasks_count > 0
                            ? round(($project->done_tasks_count / $project->tasks_count) * 100)
                            : 0;
                        [$badgeBg, $badgeColor, $barColor] = match($project->status) {
                            'active'    => ['rgba(96,165,250,0.12)', '#60a5fa', '#60a5fa'],
                            'planning'  => ['rgba(148,163,184,0.12)', '#94a3b8', '#94a3b8'],
                            'on_hold'   => ['rgba(251,146,60,0.12)', '#fb923c', '#fb923c'],
                            'completed' => ['rgba(34,197,94,0.12)', '#22c55e', '#22c55e'],
                            default     => ['rgba(148,163,184,0.12)', '#94a3b8', '#a78bfa'],
                        };
                    @endphp
                    <a href="{{ route('projects.show', $project) }}"
                       style="display:block;background:#141416;border:1px solid rgba(67,70,86,0.3);border-radius:0.75rem;padding:1.5rem;text-decoration:none;transition:border-color 0.2s,box-shadow 0.2s;"
                       onmouseover="this.style.borderColor='rgba(124,58,237,0.45)';this.style.boxShadow='0 4px 24px rgba(124,58,237,0.1)'"
                       onmouseout="this.style.borderColor='rgba(67,70,86,0.3)';this.style.boxShadow='none'">

                        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:0.65rem;gap:0.5rem;">
                            <h3 style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:#f4f4f5;margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $project->name }}</h3>
                            <span style="flex-shrink:0;font-size:0.62rem;font-weight:700;padding:0.2rem 0.55rem;border-radius:9999px;text-transform:uppercase;letter-spacing:0.07em;background:{{ $badgeBg }};color:{{ $badgeColor }};">
                                {{ str_replace('_', ' ', ucfirst($project->status)) }}
                            </span>
                        </div>

                        @if($project->description)
                            <p style="font-size:0.78rem;color:#6b7a9a;margin:0 0 1rem;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">{{ $project->description }}</p>
                        @else
                            <div style="margin-bottom:1rem;"></div>
                        @endif

                        {{-- Progress bar --}}
                        <div style="margin-bottom:0.85rem;">
                            <div style="display:flex;justify-content:space-between;margin-bottom:0.35rem;">
                                <span style="font-size:0.65rem;color:#71717a;">Progress</span>
                                <span style="font-size:0.65rem;color:#71717a;">{{ $project->done_tasks_count }}/{{ $project->tasks_count }} tasks done</span>
                            </div>
                            <div style="height:5px;background:rgba(67,70,86,0.45);border-radius:9999px;overflow:hidden;">
                                <div style="height:100%;width:{{ $pct }}%;background:{{ $barColor }};border-radius:9999px;transition:width 0.3s;"></div>
                            </div>
                        </div>

                        @if($project->due_date)
                            <div style="display:flex;align-items:center;gap:0.35rem;">
                                <span class="material-symbols-rounded" style="font-size:14px;color:#71717a;">calendar_today</span>
                                <span style="font-size:0.68rem;color:#71717a;">Due {{ \Carbon\Carbon::parse($project->due_date)->format('M d, Y') }}</span>
                            </div>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif

    </div>
</x-app-layout>
