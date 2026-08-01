<x-app-layout>
    <div style="padding:2rem 2.5rem;">

        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;">
            <div>
                <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 0.25rem;">Notifications</h1>
                <p style="font-size:0.8rem;color:#71717a;margin:0;">Milestones due, comments, and task assignments across your projects</p>
            </div>
        </div>

        @if($notifications->isEmpty())
            <div style="text-align:center;padding:5rem 2rem;background:#141416;border:1px solid rgba(67,70,86,0.3);border-radius:1rem;">
                <span class="material-symbols-rounded" style="font-size:52px;color:#3d4566;display:block;margin-bottom:1rem;">notifications_off</span>
                <p style="font-family:'Syne',sans-serif;font-size:1.05rem;font-weight:700;color:#f4f4f5;margin:0 0 0.4rem;">No notifications yet</p>
                <p style="font-size:0.8rem;color:#71717a;margin:0;">Milestone reminders, comments, and task assignments will show up here.</p>
            </div>
        @else
            <div style="display:flex;flex-direction:column;gap:0.6rem;">
                @foreach($notifications as $notification)
                    <a href="{{ $notification->data['url'] ?? '#' }}" style="display:block;background:#141416;border:1px solid rgba(67,70,86,0.3);border-radius:0.75rem;padding:1rem 1.25rem;text-decoration:none;{{ $notification->read_at ? 'opacity:0.6;' : '' }}">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:0.3rem;">
                            @if(!$notification->read_at)
                                <span style="width:7px;height:7px;border-radius:50%;background:#7c3aed;flex-shrink:0;"></span>
                            @endif
                            <span style="font-size:0.85rem;font-weight:700;color:#f4f4f5;">{{ $notification->data['title'] ?? 'Notification' }}</span>
                            <span style="font-size:0.68rem;color:#52525b;margin-left:auto;">{{ $notification->created_at->diffForHumans() }}</span>
                        </div>
                        <p style="font-size:0.78rem;color:#a1a1aa;margin:0;">{{ $notification->data['message'] ?? '' }}</p>
                    </a>
                @endforeach
            </div>

            <div style="margin-top:1.5rem;">
                {{ $notifications->links() }}
            </div>
        @endif

    </div>
</x-app-layout>
