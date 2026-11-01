<x-app-layout>
    <x-slot name="title">New project</x-slot>
    <x-slot name="header"><a href="{{ route('dashboard') }}" style="text-decoration:none">Projects</a> <span aria-hidden="true">/</span> <b>New project</b></x-slot>
    <div class="pg-page pg-page--narrow">
        <p class="pg-crumb"><a href="{{ route('dashboard') }}">Projects</a></p>
        <div class="pg-titlerow">
            <div>
                <h1 class="pg-title">New project</h1>
                <p class="pg-finding">A name is enough to start. Milestones, tasks and people can follow on the board.</p>
            </div>
        </div>
        <div class="pg-card" style="padding:20px 22px">
            <livewire:projects.create-project />
        </div>
    </div>
</x-app-layout>
