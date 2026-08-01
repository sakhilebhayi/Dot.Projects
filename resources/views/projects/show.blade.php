<x-app-layout>
    <div style="padding:2rem 2.5rem;">

        {{-- Project header --}}
        @php
            [$badgeBg, $badgeColor] = match($project->status) {
                'active'    => ['rgba(96,165,250,0.12)', '#60a5fa'],
                'planning'  => ['rgba(148,163,184,0.12)', '#94a3b8'],
                'on_hold'   => ['rgba(251,146,60,0.12)', '#fb923c'],
                'completed' => ['rgba(34,197,94,0.12)', '#22c55e'],
                default     => ['rgba(148,163,184,0.12)', '#94a3b8'],
            };
        @endphp

        <div style="margin-bottom:2rem;">
            <a href="{{ route('dashboard') }}" style="display:inline-flex;align-items:center;gap:0.35rem;font-size:0.75rem;color:#8d90a2;text-decoration:none;margin-bottom:1rem;"
               onmouseover="this.style.color='#b6c4ff'" onmouseout="this.style.color='#8d90a2'">
                <span class="material-symbols-outlined" style="font-size:16px;">arrow_back</span>
                All Projects
            </a>

            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                <div>
                    <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.4rem;">
                        <h1 style="font-family:'Manrope',sans-serif;font-size:1.6rem;font-weight:800;color:#dae2fd;margin:0;">{{ $project->name }}</h1>
                        <span style="font-size:0.62rem;font-weight:700;padding:0.25rem 0.65rem;border-radius:9999px;text-transform:uppercase;letter-spacing:0.07em;background:{{ $badgeBg }};color:{{ $badgeColor }};">
                            {{ str_replace('_', ' ', ucfirst($project->status)) }}
                        </span>
                    </div>
                    @if($project->due_date)
                        <div style="display:flex;align-items:center;gap:0.35rem;margin-bottom:0.5rem;">
                            <span class="material-symbols-outlined" style="font-size:14px;color:#8d90a2;">calendar_today</span>
                            <span style="font-size:0.72rem;color:#8d90a2;">Due {{ \Carbon\Carbon::parse($project->due_date)->format('M d, Y') }}</span>
                        </div>
                    @endif
                    @if($project->description)
                        <p style="font-size:0.82rem;color:#6b7a9a;margin:0;max-width:640px;">{{ $project->description }}</p>
                    @endif
                </div>

                <div style="display:flex;gap:0.75rem;flex-shrink:0;">
                    <a href="#" style="display:inline-flex;align-items:center;gap:0.4rem;border-radius:9999px;border:1px solid rgba(67,70,86,0.5);background:rgba(19,27,46,0.8);padding:0.55rem 1rem;font-family:'Manrope',sans-serif;font-size:0.75rem;font-weight:700;color:#b7c8e1;text-decoration:none;">
                        <span class="material-symbols-outlined" style="font-size:16px;">flag</span>
                        Add Milestone
                    </a>
                    <a href="#" style="display:inline-flex;align-items:center;gap:0.4rem;border-radius:9999px;background:linear-gradient(135deg,#7c3aed,#5b21b6);padding:0.55rem 1rem;font-family:'Manrope',sans-serif;font-size:0.75rem;font-weight:700;color:#f7f5ff;text-decoration:none;">
                        <span class="material-symbols-outlined" style="font-size:16px;">add_task</span>
                        Add Task
                    </a>
                </div>
            </div>
        </div>

        {{-- Milestones section --}}
        @if($project->milestones->isNotEmpty())
            <div style="margin-bottom:2rem;">
                <h2 style="font-family:'Manrope',sans-serif;font-size:0.72rem;font-weight:700;color:#8d90a2;text-transform:uppercase;letter-spacing:0.12em;margin:0 0 0.85rem;">Milestones</h2>
                <div style="display:flex;flex-direction:column;gap:0.5rem;">
                    @foreach($project->milestones as $milestone)
                        @php
                            $msDone = $milestone->tasks->where('status','done')->count();
                            $msTotal = $milestone->tasks->count();
                            $msPct = $msTotal > 0 ? round(($msDone / $msTotal) * 100) : 0;
                            [$msBadgeBg, $msBadgeColor] = match($milestone->status) {
                                'completed'   => ['rgba(34,197,94,0.12)', '#22c55e'],
                                'in_progress' => ['rgba(96,165,250,0.12)', '#60a5fa'],
                                default       => ['rgba(148,163,184,0.1)', '#94a3b8'],
                            };
                        @endphp
                        <div style="background:#131b2e;border:1px solid rgba(67,70,86,0.3);border-radius:0.65rem;padding:1rem 1.25rem;display:flex;align-items:center;gap:1.25rem;">
                            <span class="material-symbols-outlined" style="font-size:18px;color:#8d90a2;flex-shrink:0;">flag</span>
                            <div style="flex:1;min-width:0;">
                                <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.3rem;">
                                    <span style="font-family:'Manrope',sans-serif;font-size:0.85rem;font-weight:700;color:#dae2fd;">{{ $milestone->title }}</span>
                                    <span style="font-size:0.6rem;font-weight:700;padding:0.15rem 0.5rem;border-radius:9999px;text-transform:uppercase;letter-spacing:0.07em;background:{{ $msBadgeBg }};color:{{ $msBadgeColor }};">
                                        {{ str_replace('_', ' ', ucfirst($milestone->status)) }}
                                    </span>
                                </div>
                                <div style="height:4px;background:rgba(67,70,86,0.4);border-radius:9999px;overflow:hidden;max-width:240px;">
                                    <div style="height:100%;width:{{ $msPct }}%;background:{{ $msBadgeColor }};border-radius:9999px;"></div>
                                </div>
                            </div>
                            <div style="text-align:right;flex-shrink:0;">
                                <div style="font-size:0.72rem;font-weight:700;color:#dae2fd;">{{ $msDone }}/{{ $msTotal }}</div>
                                <div style="font-size:0.6rem;color:#8d90a2;">tasks done</div>
                            </div>
                            @if($milestone->due_date)
                                <div style="flex-shrink:0;display:flex;align-items:center;gap:0.3rem;">
                                    <span class="material-symbols-outlined" style="font-size:13px;color:#8d90a2;">calendar_today</span>
                                    <span style="font-size:0.65rem;color:#8d90a2;">{{ $milestone->due_date->format('M d') }}</span>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Tasks board --}}
        <div style="margin-bottom:2rem;">
            <h2 style="font-family:'Manrope',sans-serif;font-size:0.72rem;font-weight:700;color:#8d90a2;text-transform:uppercase;letter-spacing:0.12em;margin:0 0 0.85rem;">Tasks Board</h2>
            <livewire:projects.project-board :project="$project" />
        </div>

        {{-- Comments --}}
        <div style="max-width:640px;">
            <livewire:projects.project-comments :project="$project" />
        </div>

    </div>
</x-app-layout>
