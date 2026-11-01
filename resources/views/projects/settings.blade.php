<x-app-layout>
    <x-slot name="title">{{ $project->name }}, settings</x-slot>
    <x-slot name="header"><a href="{{ route('dashboard') }}" style="text-decoration:none">Projects</a> <span aria-hidden="true">/</span> <b>{{ $project->name }}</b></x-slot>
    <div class="pg-page">
        @include('projects._head', ['view' => 'settings'])
        <livewire:projects.project-settings :project="$project" />
        <livewire:projects.people-panel :project="$project" />
        <livewire:governance.matrix-settings :project="$project" />
    </div>
</x-app-layout>
