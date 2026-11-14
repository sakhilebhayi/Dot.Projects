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

// Calendar (projects/calendar): drag an open task to another day, or focus it and use the arrow keys (a day left or
// right, a week up or down). The component moves it with its length kept; the server checks and saves.
const calendarComponent = (el) => window.Livewire?.find(el.closest('[wire\\:id]')?.getAttribute('wire:id'));
const moveCalendarTask = (chip, date) => {
    const id = chip.dataset.calTask;
    calendarComponent(chip)?.call('moveTask', Number(id), date).then(() => {
        requestAnimationFrame(() => document.querySelector(`[data-cal-task="${id}"]`)?.focus());
    });
};
document.addEventListener('dragstart', (event) => {
    const chip = event.target.closest?.('[data-cal-task]');
    if (!chip) return;
    event.dataTransfer.setData('text/plain', chip.dataset.calTask);
    event.dataTransfer.effectAllowed = 'move';
    chip.classList.add('dragging');
});
document.addEventListener('dragend', (event) => {
    event.target.closest?.('[data-cal-task]')?.classList.remove('dragging');
    document.querySelectorAll('[data-cal-day].drop').forEach((d) => d.classList.remove('drop'));
});
document.addEventListener('dragover', (event) => {
    const day = event.target.closest?.('[data-cal-day]');
    if (!day || !document.querySelector('[data-cal-task].dragging')) return;
    event.preventDefault();
    document.querySelectorAll('[data-cal-day].drop').forEach((d) => d !== day && d.classList.remove('drop'));
    day.classList.add('drop');
});
document.addEventListener('drop', (event) => {
    const day = event.target.closest?.('[data-cal-day]');
    const chip = document.querySelector('[data-cal-task].dragging');
    if (!day || !chip) return;
    event.preventDefault();
    day.classList.remove('drop');
    if (day.dataset.calDay !== chip.dataset.due) moveCalendarTask(chip, day.dataset.calDay);
});
document.addEventListener('keydown', (event) => {
    const chip = event.target.closest?.('[data-cal-task]');
    const step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[event.key];
    if (!chip || step === undefined || event.altKey || event.metaKey || event.ctrlKey) return;
    event.preventDefault();
    moveCalendarTask(chip, addDays(chip.dataset.due, step));
});

// Command palette (layouts/app): Ctrl or Cmd and K, or the search button. Arrow keys move through the results, Enter
// opens the one marked; the native dialog keeps focus inside and closes on Escape.
const palette = () => document.querySelector('dialog[data-cmd]');
const paletteItems = () => [...(palette()?.querySelectorAll('[data-cmd-item]') ?? [])];
let paletteActive = 0;
const markPaletteItem = (index) => {
    const items = paletteItems();
    const input = palette()?.querySelector('[data-cmd-input]');
    paletteActive = items.length ? (index + items.length) % items.length : 0;
    items.forEach((item, i) => {
        item.id = `cmd-opt-${i}`;
        item.setAttribute('aria-selected', i === paletteActive ? 'true' : 'false');
    });
    if (input) items.length ? input.setAttribute('aria-activedescendant', `cmd-opt-${paletteActive}`) : input.removeAttribute('aria-activedescendant');
    items[paletteActive]?.scrollIntoView({ block: 'nearest' });
};
const openPalette = () => {
    const dialog = palette();
    if (!dialog || dialog.open) return;
    dialog.showModal();
    // The first opening asks the component for its results; until then pages carry none.
    const id = dialog.querySelector('[wire\\:id]')?.getAttribute('wire:id');
    const component = id ? window.Livewire?.find(id) : null;
    if (component && !component.get('open')) component.set('open', true);
    const input = dialog.querySelector('[data-cmd-input]');
    input?.focus();
    input?.select();
    markPaletteItem(0);
};
if (/Mac|iPhone|iPad/.test(navigator.platform)) {
    document.querySelectorAll('[data-cmd-key], [data-cmd-open] .dsr-kbd').forEach((k) => { k.textContent = '⌘ K'; });
}
document.addEventListener('click', (event) => {
    if (event.target.closest('[data-cmd-open]')) openPalette();
    // A click on the backdrop (the dialog itself, outside its body) closes it.
    if (event.target === palette()) palette().close();
});
// The header pill is a form that only opens the palette: submitting it (Enter, or the arrow) opens it, nothing is sent.
document.addEventListener('submit', (event) => {
    if (!event.target.matches?.('form[data-cmd-open]')) return;
    event.preventDefault();
    openPalette();
});
document.addEventListener('keydown', (event) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        palette()?.open ? palette().close() : openPalette();
        return;
    }
    if (!palette()?.open) return;
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        markPaletteItem(paletteActive + (event.key === 'ArrowDown' ? 1 : -1));
    } else if (event.key === 'Enter' && event.target.matches('[data-cmd-input]')) {
        event.preventDefault();
        paletteItems()[paletteActive]?.click();
    }
});
// New results after each round trip: mark the first again.
// Livewire may already be loaded when this module runs, or start after it: hook in either way.
const hookPalette = () => window.Livewire.hook('commit', ({ component, succeed }) => {
    if (component.name !== 'command-palette') return;
    succeed(() => requestAnimationFrame(() => markPaletteItem(0)));
});
if (window.Livewire) hookPalette(); else document.addEventListener('livewire:init', hookPalette);
