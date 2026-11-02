<x-app-layout>
    <x-slot name="title">{{ $project->name }}, documents</x-slot>
    <x-slot name="header"><a href="{{ route('dashboard') }}" style="text-decoration:none">Projects</a> <span aria-hidden="true">/</span> <b>{{ $project->name }}</b></x-slot>
    <div class="pg-page">
        @include('projects._head', ['view' => 'documents'])
        @if (session('documents_error'))<p class="pg-error" role="alert" style="margin:0 0 12px">{{ session('documents_error') }}</p>@endif
        <section class="pg-card" aria-labelledby="docs-h">
            <div class="pg-card-head"><h2 id="docs-h">Documents</h2><small>kept in Dot.Files</small></div>
            <livewire:projects.project-documents :project="$project" />
        </section>
    </div>
</x-app-layout>
