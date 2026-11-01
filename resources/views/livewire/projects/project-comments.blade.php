<div>
    @can('comment', $project)
        <form wire:submit="addComment" class="pg-form" style="margin-bottom:6px">
            <div class="pg-field">
                <label for="comment-body" class="pg-sr">Write to the team</label>
                <textarea id="comment-body" wire:model="body" rows="2" class="pg-textarea" placeholder="Write to the team about this project"></textarea>
                @error('body')<p class="pg-error">{{ $message }}</p>@enderror
            </div>
            <div style="display:flex;justify-content:flex-end"><button type="submit" class="pg-btn pg-btn--secondary pg-btn--sm" wire:loading.attr="disabled">Post comment</button></div>
        </form>
    @else
        <p class="pg-help" style="margin:0 0 10px">You have read-only access to this business, so you can read the discussion but not post.</p>
    @endcan

    @forelse ($this->comments as $comment)
        <article class="pg-comment">
            <header><b>{{ $comment->user->name ?? 'Someone no longer here' }}</b><time datetime="{{ $comment->created_at->toIso8601String() }}" title="{{ $comment->created_at->format('j M Y, H:i') }}">{{ $comment->created_at->diffForHumans() }}</time></header>
            <p>{{ $comment->body }}</p>
        </article>
    @empty
        <p class="pg-empty">No comments yet.</p>
    @endforelse
</div>
