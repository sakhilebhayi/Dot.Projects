<x-app-layout>
    <x-slot name="title">Notifications</x-slot>
    <x-slot name="header"><b>Notifications</b></x-slot>
    @php($unread = $notifications->getCollection()->whereNull('read_at')->count())
    <div class="pg-page pg-page--narrow">
        <div class="pg-titlerow">
            <div>
                <h1 class="pg-title">Notifications</h1>
                <p class="pg-finding">{{ $notifications->total() ? ($unread ? $unread.' unread on this page. ' : 'All read. ') : '' }}Milestones coming due, comments on your projects and tasks given to you.</p>
            </div>
        </div>
        <div class="pg-card">
            @forelse ($notifications as $notification)
                <a href="{{ $notification->data['url'] ?? route('dashboard') }}" class="pg-row" style="text-decoration:none">
                    <div>
                        <span style="font-weight:{{ $notification->read_at ? 400 : 600 }}">{{ $notification->data['title'] ?? 'Notification' }}</span>
                        <div class="pg-sub">{{ $notification->data['message'] ?? '' }}</div>
                    </div>
                    <span class="pg-sub" style="text-align:right">
                        <time datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->diffForHumans() }}</time>
                        @unless ($notification->read_at)<span class="pg-pill" style="margin-left:6px">Unread</span>@endunless
                    </span>
                </a>
            @empty
                <p class="pg-empty">Nothing yet. Milestone reminders, comments on your projects and tasks given to you appear here.</p>
            @endforelse
        </div>
        @if ($notifications->hasPages())<div style="margin-top:16px">{{ $notifications->links() }}</div>@endif
    </div>
</x-app-layout>
