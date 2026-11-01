<div class="pg-menu" style="position:relative" x-data @click.outside="$wire.open = false" @keydown.escape.window="$wire.open = false">
    <button type="button" wire:click="toggle" class="pg-ic" aria-label="Notifications{{ $this->unreadCount ? ', '.$this->unreadCount.' unread' : '' }}" aria-expanded="{{ $open ? 'true' : 'false' }}">
        <span class="material-symbols-rounded" aria-hidden="true">notifications</span>
        @if ($this->unreadCount > 0)
            <span aria-hidden="true" style="position:absolute;top:3px;right:2px;min-width:16px;height:16px;padding:0 4px;border-radius:999px;background:var(--pg-primary);color:var(--pg-on-primary);font-size:10.5px;font-weight:600;display:grid;place-items:center;line-height:1">{{ $this->unreadCount > 9 ? '9+' : $this->unreadCount }}</span>
        @endif
    </button>

    @if ($open)
        <div class="pg-menu-panel" style="width:340px;max-height:440px;overflow-y:auto;padding:0">
            <div style="display:flex;justify-content:space-between;align-items:baseline;padding:12px 14px 8px">
                <b style="font-size:14px;font-weight:600">Notifications</b>
                @if ($this->unreadCount > 0)
                    <button type="button" wire:click="markAllAsRead" style="width:auto;padding:2px 6px;font-size:12.5px;color:var(--pg-muted)">Mark all read</button>
                @endif
            </div>
            @forelse ($this->notifications as $notification)
                <div role="button" tabindex="0" wire:click="markAsRead('{{ $notification->id }}')" wire:keydown.enter="markAsRead('{{ $notification->id }}')" wire:keydown.space.prevent="markAsRead('{{ $notification->id }}')" style="padding:10px 14px;border-top:1px solid var(--pg-rule);cursor:pointer">
                    <div style="font-size:13px;font-weight:{{ $notification->read_at ? 400 : 600 }}">{{ $notification->data['title'] ?? 'Notification' }}</div>
                    <div style="font-size:12.5px;color:var(--pg-muted);margin-top:2px">{{ $notification->data['message'] ?? '' }}</div>
                </div>
            @empty
                <p class="pg-empty" style="padding:6px 14px 14px">Nothing yet.</p>
            @endforelse
            <div style="padding:10px 14px;border-top:1px solid var(--pg-rule)"><a href="{{ route('notifications.index') }}" style="padding:0;font-size:13px">See all notifications</a></div>
        </div>
    @endif
</div>
