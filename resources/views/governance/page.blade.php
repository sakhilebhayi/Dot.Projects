<x-app-layout>
    @php
        $item = $risk ?? $issue ?? $change ?? $decision ?? $gate ?? $meeting ?? null;
        $crumb = $item ? $item->ref().' '.$item->title : ucfirst($section);
    @endphp
    <x-slot name="title">{{ $crumb }}, {{ $project->name }}</x-slot>
    <x-slot name="header"><a href="{{ route('dashboard') }}" style="text-decoration:none">Projects</a> <span aria-hidden="true">/</span> <b>{{ $project->name }}</b></x-slot>
    <div class="pg-page">
        @include('projects._head', ['view' => 'governance'])
        <nav class="pg-subnav" aria-label="Governance">
            <a href="{{ route('projects.risks', $project) }}" @if ($section === 'risks') aria-current="page" @endif>Risks <span>{{ $liveRisks }}</span></a>
            <a href="{{ route('projects.issues', $project) }}" @if ($section === 'issues') aria-current="page" @endif>Issues <span>{{ $openIssues }}</span></a>
            <a href="{{ route('projects.changes', $project) }}" @if ($section === 'changes') aria-current="page" @endif>Changes <span>{{ $pendingChanges }}</span></a>
            <a href="{{ route('projects.decisions', $project) }}" @if ($section === 'decisions') aria-current="page" @endif>Decisions <span>{{ $openDecisions }}</span></a>
            <a href="{{ route('projects.gates', $project) }}" @if ($section === 'gates') aria-current="page" @endif>Gates <span>{{ $openGates }}</span></a>
            <a href="{{ route('projects.meetings', $project) }}" @if ($section === 'meetings') aria-current="page" @endif>Meetings <span>{{ $meetingCount }}</span></a>
            <a href="{{ route('projects.actions', $project) }}" @if ($section === 'actions') aria-current="page" @endif>Actions <span>{{ $openActions }}</span></a>
        </nav>
        @if (isset($risk))
            <livewire:governance.risk-panel :project="$project" :risk="$risk" />
        @elseif (isset($issue))
            <livewire:governance.issue-panel :project="$project" :issue="$issue" />
        @elseif (isset($change))
            <livewire:governance.change-panel :project="$project" :change="$change" />
        @elseif (isset($decision))
            <livewire:governance.decision-panel :project="$project" :decision="$decision" />
        @elseif (isset($gate))
            <livewire:governance.gate-panel :project="$project" :gate="$gate" />
        @elseif (isset($meeting))
            <livewire:governance.meeting-panel :project="$project" :meeting="$meeting" />
        @elseif ($section === 'gates')
            <livewire:governance.gate-list :project="$project" />
        @elseif ($section === 'meetings')
            <livewire:governance.meeting-list :project="$project" />
        @elseif ($section === 'actions')
            <livewire:governance.action-register :project="$project" />
        @elseif ($section === 'changes')
            <livewire:governance.change-log :project="$project" />
        @elseif ($section === 'decisions')
            <livewire:governance.decision-register :project="$project" />
        @elseif ($section === 'risks')
            <livewire:governance.risk-register :project="$project" />
        @else
            <livewire:governance.issue-log :project="$project" />
        @endif
    </div>
</x-app-layout>
