<x-app-layout>
    <x-slot name="title">{{ $task->title }}</x-slot>
    <x-slot name="header"><a href="{{ route('dashboard') }}" style="text-decoration:none">Projects</a> <span aria-hidden="true">/</span> <a href="{{ route('projects.work', $project) }}" style="text-decoration:none">{{ $project->name }}</a> <span aria-hidden="true">/</span> <b>{{ Str::limit($task->title, 40) }}</b></x-slot>
    <div class="pg-page">
        <p class="pg-crumb"><a href="{{ route('dashboard') }}">Projects</a> <span aria-hidden="true">/</span> <a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a> <span aria-hidden="true">/</span> <a href="{{ route('projects.work', $project) }}">Work</a></p>
        @if ($project->isArchived())
            <div class="pg-notice" role="status"><span><b>This project is archived.</b> Its work is read-only.</span></div>
        @endif
        <livewire:projects.task-panel :project="$project" :task="$task" />
    </div>
</x-app-layout>
