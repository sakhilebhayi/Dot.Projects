<x-app-layout>
    <x-slot name="title">Templates</x-slot>
    <x-slot name="header"><a href="{{ route('dashboard') }}" style="text-decoration:none">Projects</a> <span aria-hidden="true">/</span> <b>Templates</b></x-slot>
    <div class="pg-page">
        <livewire:templates.library />
    </div>
</x-app-layout>
