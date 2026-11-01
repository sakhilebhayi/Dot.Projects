<x-app-layout>
    @php
        $item = $risk ?? $issue ?? null;
        $crumb = $item ? $item->ref().' '.$item->title : ($section === 'risks' ? 'Risks' : 'Issues');
    @endphp
    <x-slot name="title">{{ $crumb }}, {{ $project->name }}</x-slot>
    <x-slot name="header"><a href="{{ route('dashboard') }}" style="text-decoration:none">Projects</a> <span aria-hidden="true">/</span> <b>{{ $project->name }}</b></x-slot>
    <div class="pg-page">
        @include('projects._head', ['view' => 'governance'])
        <nav class="pg-subnav" aria-label="Governance">
            <a href="{{ route('projects.risks', $project) }}" @if ($section === 'risks') aria-current="page" @endif>Risks <span>{{ $liveRisks }}</span></a>
            <a href="{{ route('projects.issues', $project) }}" @if ($section === 'issues') aria-current="page" @endif>Issues <span>{{ $openIssues }}</span></a>
        </nav>
        @if (isset($risk))
            <livewire:governance.risk-panel :project="$project" :risk="$risk" />
        @elseif (isset($issue))
            <livewire:governance.issue-panel :project="$project" :issue="$issue" />
        @elseif ($section === 'risks')
            <livewire:governance.risk-register :project="$project" />
        @else
            <livewire:governance.issue-log :project="$project" />
        @endif
    </div>
</x-app-layout>
