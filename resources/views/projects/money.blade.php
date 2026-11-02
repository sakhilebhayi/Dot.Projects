<x-app-layout>
    <x-slot name="title">{{ $project->name }}, money</x-slot>
    <x-slot name="header"><a href="{{ route('dashboard') }}" style="text-decoration:none">Projects</a> <span aria-hidden="true">/</span> <b>{{ $project->name }}</b></x-slot>
    <div class="pg-page">
        @include('projects._head', ['view' => 'money'])
        <livewire:projects.project-money :project="$project" />
    </div>
</x-app-layout>
