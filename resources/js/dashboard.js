// The projects dashboard (R-51): selecting a row swaps the side panel, column headers sort the loaded rows.
// Everything is read from the server-rendered rows and panels; nothing is fetched and nothing is invented.
// The page is complete without this file: the first row is selected on the server and the rows are links.
const root = document.querySelector('[data-pgd]');
const table = root && root.querySelector('#ledger');

if (table) {
    const body = table.tBodies[0];
    const sel = root.querySelector('#sel');
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
    const hover = window.matchMedia('(hover: hover)');
    let selected = (body.querySelector('tr.is-selected') || {}).dataset?.id;

    const show = (id) => {
        const next = sel.querySelector(`.pgd-panel[data-for="${CSS.escape(String(id))}"]`);
        if (!next || String(id) === String(selected)) return;
        selected = String(id);
        body.querySelectorAll('tr').forEach((r) => r.classList.toggle('is-selected', r.dataset.id === selected));
        sel.querySelectorAll('.pgd-panel').forEach((p) => { p.hidden = p !== next; p.classList.remove('is-entering'); });
        if (!reduce.matches) {
            next.querySelectorAll('.pgd-facts > div, .pg-land-line .ms').forEach((el, k) => el.style.setProperty('--k', String(k)));
            void next.offsetWidth;
            next.classList.add('is-entering');
            window.setTimeout(() => next.classList.remove('is-entering'), 700);
        }
    };

    const rowOf = (el) => el.closest('tr[data-panel]');
    body.addEventListener('mouseover', (e) => { const r = rowOf(e.target); if (r && hover.matches) show(r.dataset.id); });
    body.addEventListener('focusin', (e) => { const r = rowOf(e.target); if (r) show(r.dataset.id); });
    body.addEventListener('keydown', (e) => {
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
        const rows = [...body.rows];
        const at = rows.indexOf(e.target.closest('tr'));
        if (at < 0) return;
        e.preventDefault();
        const to = rows[at + (e.key === 'ArrowDown' ? 1 : -1)];
        to && to.querySelector('a').focus();
    });

    const keys = {
        attention: (a, b) => a.dataset.attention - b.dataset.attention,
        status: (a, b) => a.dataset.status.localeCompare(b.dataset.status),
        health: (a, b) => a.dataset.health - b.dataset.health,
        ms: (a, b) => (a.dataset.ms === '' ? 9e15 : +a.dataset.ms) - (b.dataset.ms === '' ? 9e15 : +b.dataset.ms),
        done: (a, b) => b.dataset.done - a.dataset.done,
        over: (a, b) => b.dataset.over - a.dataset.over,
        due: (a, b) => (a.dataset.due === '' ? 9e15 : +a.dataset.due) - (b.dataset.due === '' ? 9e15 : +b.dataset.due),
    };
    table.tHead.addEventListener('click', (e) => {
        const th = e.target.closest('th[data-key]');
        if (!th || !keys[th.dataset.key]) return;
        // The server order is the attention order (descending = most urgent first); every other key starts ascending.
        const first = th.dataset.key === 'attention' ? 'descending' : 'ascending';
        const dir = th.getAttribute('aria-sort') === first ? (first === 'ascending' ? 'descending' : 'ascending') : first;
        [...th.parentNode.children].forEach((c) => c.removeAttribute('aria-sort'));
        th.setAttribute('aria-sort', dir);
        const sign = (dir === 'ascending') !== (th.dataset.key === 'attention') ? 1 : -1;
        const rows = [...body.rows].sort((a, b) => keys[th.dataset.key](a, b) * sign || a.dataset.attention - b.dataset.attention);
        rows.forEach((r) => body.appendChild(r));
    });
}
