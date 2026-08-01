<div>
    <h2 style="font-family:'Manrope',sans-serif;font-size:0.72rem;font-weight:700;color:#8d90a2;text-transform:uppercase;letter-spacing:0.12em;margin:0 0 0.85rem;">Comments</h2>

    <form wire:submit="addComment" style="margin-bottom:1.25rem;">
        <textarea wire:model="body" rows="2" placeholder="Add a comment…"
                  style="width:100%;background:#0b1326;border:1px solid rgba(67,70,86,0.5);border-radius:0.5rem;padding:0.65rem 0.85rem;font-size:0.82rem;color:#dae2fd;font-family:'Inter',sans-serif;outline:none;resize:vertical;"></textarea>
        @error('body')<p style="margin-top:0.35rem;font-size:0.72rem;color:#f87171;">{{ $message }}</p>@enderror
        <div style="display:flex;justify-content:flex-end;margin-top:0.5rem;">
            <button type="submit" wire:loading.attr="disabled"
                    style="border-radius:9999px;background:linear-gradient(135deg,#7c3aed,#5b21b6);padding:0.5rem 1.1rem;font-family:'Manrope',sans-serif;font-size:0.75rem;font-weight:700;color:#f7f5ff;border:none;cursor:pointer;">
                Post Comment
            </button>
        </div>
    </form>

    @if($this->comments->isEmpty())
        <p style="font-size:0.78rem;color:#52525b;">No comments yet.</p>
    @else
        <div style="display:flex;flex-direction:column;gap:0.75rem;">
            @foreach($this->comments as $comment)
                <div style="background:#131b2e;border:1px solid rgba(67,70,86,0.3);border-radius:0.65rem;padding:0.75rem 1rem;">
                    <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.3rem;">
                        <span style="font-size:0.78rem;font-weight:700;color:#dae2fd;">{{ $comment->user->name ?? 'Unknown' }}</span>
                        <span style="font-size:0.65rem;color:#52525b;">{{ $comment->created_at->diffForHumans() }}</span>
                    </div>
                    <p style="font-size:0.8rem;color:#a1a1aa;margin:0;white-space:pre-line;">{{ $comment->body }}</p>
                </div>
            @endforeach
        </div>
    @endif
</div>
