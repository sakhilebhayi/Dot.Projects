<div class="pg-bell" x-on:dot:menu-open="$wire.load()">
    <x-dot.bell :count="$this->unreadCount" id="notifications-panel" panel-label="Notifications">
        <div class="dh-panel__head">
            <span class="dh-panel__title">Notifications</span>
            @if ($this->unreadCount > 0)
                <button type="button" class="dh-mark" wire:click="markAllAsRead">Mark all read</button>
            @endif
        </div>
        <div class="dh-panel__list">
            @if (! $loaded)
                <p class="dh-panel__empty" role="status">Loading</p>
            @else
                @forelse ($this->notifications as $notification)
                    <button type="button" class="dh-panel__item" @if (! $notification->read_at) data-unread @endif wire:click="markAsRead('{{ $notification->id }}')" wire:key="n-{{ $notification->id }}">
                        <span class="dh-panel__item-title">{{ $notification->data['title'] ?? 'Notification' }}</span>
                        <span class="dh-panel__item-text">{{ $notification->data['message'] ?? '' }}</span>
                    </button>
                @empty
                    <p class="dh-panel__empty">Nothing yet.</p>
                @endforelse
            @endif
        </div>
        <div class="dh-panel__foot"><a href="{{ route('notifications.index') }}">See all notifications</a></div>
    </x-dot.bell>
</div>
