'use strict';
document.querySelectorAll('form[data-confirm]').forEach((f) =>
    f.addEventListener('submit', (e) => {
        if (!confirm(f.dataset.confirm)) e.preventDefault();
    }),
);
document.querySelectorAll('.table-search').forEach((input) =>
    input.addEventListener('input', () => {
        const q = input.value.toLocaleLowerCase('fr');
        input
            .closest('.panel')
            .querySelectorAll('tbody tr')
            .forEach((row) => (row.hidden = !row.textContent.toLocaleLowerCase('fr').includes(q)));
    }),
);

let restTimer = null;
const display = document.querySelector('#rest-display');
const format = (s) =>
    Math.floor(s / 60)
        .toString()
        .padStart(2, '0') +
    ':' +
    (s % 60).toString().padStart(2, '0');
document.querySelectorAll('[data-rest]').forEach((b) =>
    b.addEventListener('click', () => {
        clearInterval(restTimer);
        const until = Date.now() + Number(b.dataset.rest) * 1000;
        display.textContent = format(Number(b.dataset.rest));
        restTimer = setInterval(() => {
            const left = Math.max(0, Math.ceil((until - Date.now()) / 1000));
            display.textContent = left ? format(left) : 'Repos terminé ✓';
            if (!left) clearInterval(restTimer);
        }, 250);
    }),
);
document.querySelector('#rest-stop')?.addEventListener('click', () => {
    clearInterval(restTimer);
    display.textContent = 'Arrêté';
});
const presetSelect = document.querySelector('#training-preset');
presetSelect?.addEventListener('change', () => {
    let values = [];
    try {
        values = JSON.parse(presetSelect.selectedOptions[0].dataset.muscles || '[]');
    } catch {}
    document
        .querySelectorAll('.muscle-selector input[type=checkbox]')
        .forEach((c) => (c.checked = values.includes(c.value)));
});
document.querySelectorAll('.exercise-autocomplete').forEach((input) => {
    let timer,
        request = 0;
    input.addEventListener('input', () => {
        clearTimeout(timer);
        const q = input.value.trim(),
            current = ++request;
        if (q.length < 2) return;
        timer = setTimeout(async () => {
            try {
                const response = await fetch(
                    '/api/exercises?' + new URLSearchParams({ search: q, limit: '12' }),
                );
                if (!response.ok) return;
                const payload = await response.json();
                if (current !== request) return;
                const list = document.getElementById(input.getAttribute('list'));
                if (!list) return;
                list.replaceChildren();
                for (const item of payload.data || []) {
                    const option = document.createElement('option');
                    option.value = item.name_fr || item.name;
                    list.appendChild(option);
                }
            } catch {}
        }, 350);
    });
});
