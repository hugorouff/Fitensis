(() => {
    const root = document.querySelector('[data-guided-session]');
    if (!root) return;
    const timer = document.querySelector('[data-session-timer]');
    const items = [...root.querySelectorAll('[data-guided-item]')];
    const key = `fitensis.guided.${root.dataset.guidedSession}`;
    let state = null;
    try { state = JSON.parse(sessionStorage.getItem(key)); } catch {}
    if (!state || !items.some(i => i.dataset.guidedItem === state.id && i.dataset.done !== '1')) state = null;
    function save() { try { sessionStorage.setItem(key, JSON.stringify(state)); } catch {} }
    const active = () => items.find(i => i.dataset.guidedItem === state?.id);
    const resetRest = () => timer.querySelector('[data-timer-reset]').click();
    function render() {
        const current = active();
        timer.hidden = state?.phase !== 'rest';
        items.forEach(item => {
            const selected = item === current;
            const phase = selected ? state.phase : '';
            item.querySelector('[data-guided-controls]').hidden = !selected;
            item.querySelector('[data-select-exercise]').hidden = selected;
            item.querySelector('[data-guided-performance]').hidden = phase !== 'performance';
            item.querySelector('[data-guided-start]').hidden = phase !== 'ready';
            item.querySelector('[data-guided-next]').hidden = phase !== 'running';
            item.querySelector('[data-guided-resume]').hidden = phase !== 'rest';
            if (phase === 'rest') item.querySelector('[data-guided-resume]').textContent = timer.querySelector('[data-timer-status]').textContent === 'Repos terminé.' ? 'Reprendre l’exercice' : 'Passer le repos et reprendre';
            if (selected) item.querySelector('[data-guided-status]').textContent = phase === 'performance' ? 'Toutes les séries sont terminées. Note ta performance.' : `Série ${state.set} / ${item.dataset.sets} · ${phase === 'rest' ? 'Repos' : phase === 'running' ? 'En cours' : 'Prêt'}`;
            const elapsed = phase === 'running' ? Math.max(0, Math.floor((Date.now() - state.started) / 1000)) : 0;
            item.querySelector('[data-guided-clock]').value = `${String(Math.floor(elapsed / 60)).padStart(2,'0')}:${String(elapsed % 60).padStart(2,'0')}`;
        });
    }
    function change(phase) { state.phase = phase; save(); render(); }
    items.forEach(item => {
        item.querySelector('[data-select-exercise]').onclick = () => {
            if (state && !confirm('Changer d’exercice ? La série en cours sera abandonnée.')) return;
            resetRest(); state = { id: item.dataset.guidedItem, set: 1, phase: 'ready', started: 0 }; save(); render();
        };
        item.querySelector('[data-guided-start]').onclick = () => { state.started = Date.now(); change('running'); };
        item.querySelector('[data-guided-next]').onclick = () => {
            if (state.set >= Number(item.dataset.sets)) { change('performance'); return; }
            change('rest'); resetRest(); timer.querySelector('[data-timer-start]').click(); timer.scrollIntoView({block: 'nearest', behavior: 'smooth'});
        };
        item.querySelector('[data-guided-resume]').onclick = () => {
            resetRest(); state.set++; state.started = Date.now(); change('running');
        };
    });
    if (state?.phase !== 'rest') resetRest();
    render(); setInterval(render, 500);
})();
