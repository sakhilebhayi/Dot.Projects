<x-app-layout>
    <x-slot name="title">New project</x-slot>
    <x-slot name="header"><a href="{{ route('dashboard') }}" style="text-decoration:none">Projects</a> <span aria-hidden="true">/</span> <b>New project</b></x-slot>
    <div class="pg-page">
        <p class="pg-crumb"><a href="{{ route('dashboard') }}">Projects</a></p>
        <div class="pg-titlerow">
            <div>
                <h1 class="pg-title">New project</h1>
                <p class="pg-finding">Start blank, or from a template that lays out phases, tasks, gates and risks you can change afterwards.</p>
            </div>
        </div>
        <livewire:projects.create-project />
    </div>
</x-app-layout>
