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
