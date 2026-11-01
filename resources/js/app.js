// Behaviour lives here, wired by data attributes, so the views carry no inline handlers.
// Alpine is not imported: Livewire 3 bundles and starts its own copy.

document.addEventListener('click', (event) => {
    // Light or dark: remembered per browser; without a stored choice the system preference applies.
    if (event.target.closest('[data-theme-toggle]')) {
        const dark = document.documentElement.classList.toggle('dark');
        try { localStorage.setItem('dot-theme', dark ? 'dark' : 'light'); } catch (e) {}
    }

    // Close any other open menu when one is opened or when clicking elsewhere.
    document.querySelectorAll('details.pg-menu[open]').forEach((menu) => {
        if (!menu.contains(event.target)) menu.removeAttribute('open');
    });
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        document.querySelectorAll('details.pg-menu[open]').forEach((menu) => menu.removeAttribute('open'));
    }
});

// Timeline bars (projects/schedule): drag the bar to move a task, drag its end to change the due
// date; arrow keys move by a day, Shift and arrow changes the due date. The server checks and saves.
const addDays = (iso, days) => {
    const d = new Date(iso + 'T00:00:00Z');
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
};
window.pgBar = ({ id, start, due, ppd, width, editable }) => ({
    mode: null,
    x0: 0,
    dx: 0,
    down(event, mode) {
        if (!editable || event.button !== 0) return;
        event.preventDefault();
        this.mode = mode;
        this.x0 = event.clientX;
        event.currentTarget.setPointerCapture?.(event.pointerId);
    },
    move(event) {
        if (this.mode) this.dx = event.clientX - this.x0;
    },
    up() {
        if (!this.mode) return;
        const days = Math.round(this.dx / ppd);
        const mode = this.mode;
        this.mode = null;
        this.dx = 0;
        if (days !== 0) this.commit(mode, days);
    },
    key(event) {
        if (!editable || (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight')) return;
        event.preventDefault();
        this.commit(event.shiftKey ? 'end' : 'move', event.key === 'ArrowLeft' ? -1 : 1);
    },
    // The bar is keyed on its dates, so the server's answer replaces it: give focus back to the new one.
    commit(mode, days) {
        const s = mode === 'move' ? addDays(start, days) : start;
        const focused = document.activeElement === this.$el;
        this.$wire.reschedule(id, s, addDays(due, days)).then(() => {
            if (focused) requestAnimationFrame(() => document.querySelector(`[data-bar="${id}"]`)?.focus());
        });
    },
    // An object, not a string: Alpine merges objects into the inline left and width, a string replaces them.
    shift() {
        return {
            transform: this.mode === 'move' ? `translateX(${this.dx}px)` : '',
            width: this.mode === 'end' ? `${Math.max(ppd, width + this.dx)}px` : `${width}px`,
        };
    },
});
