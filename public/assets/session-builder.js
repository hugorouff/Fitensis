(() => {
    const builder = document.querySelector('[data-session-builder]');
    if (!builder) return;

    const form = builder.closest('form');
    const search = builder.querySelector('[data-exercise-search]');
    const results = builder.querySelector('[data-exercise-results]');
    const plan = builder.querySelector('[data-exercise-plan]');
    const status = builder.querySelector('[data-builder-status]');
    const suggest = builder.querySelector('[data-training-suggest]');
    const normalize = value => String(value ?? '').normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
    const nameOf = item => item.name_fr || item.name;
    const recent = new Set(JSON.parse(builder.dataset.recentExercises).map(normalize));
    const familiar = item => recent.has(normalize(nameOf(item))) || recent.has(normalize(item.name));
    const presets = {
        general: { sets: 2, reps: 10, rest: 60 },
        strength: { sets: 3, reps: 5, rest: 120 },
        volume: { sets: 3, reps: 10, rest: 90 },
    };
    let customExercises;
    let nextIndex = 0;
    let revision = 0;
    let debounce;

    async function fetchExercises(path) {
        const response = await fetch(path);
        if (!response.ok) throw new Error('Catalogue indisponible. Réessaie dans un instant.');
        return (await response.json()).data;
    }

    function loadCustomExercises() {
        customExercises ??= fetchExercises('/api/custom-exercises').catch(error => {
            customExercises = undefined;
            throw error;
        });
        return customExercises;
    }

    function button(text, handler) {
        const element = document.createElement('button');
        element.type = 'button';
        element.className = 'button secondary';
        element.textContent = text;
        element.addEventListener('click', handler);
        return element;
    }

    function input(label, key, value, settings) {
        const field = document.createElement('label');
        field.textContent = label;
        const control = document.createElement('input');
        Object.assign(control, { name: `items[${nextIndex}][${key}]`, value, required: true }, settings);
        field.append(control);
        return field;
    }

    function addExercise(name, { sets, reps } = presets.general) {
        if (plan.children.length >= 20) {
            status.textContent = 'Tu as atteint la limite de 20 exercices.';
            return false;
        }
        const row = document.createElement('div');
        row.className = 'session-plan-row';
        row.append(
            input('Exercice', 'name', name, { maxLength: 250 }),
            input('Séries', 'sets', sets, { type: 'number', min: 1, max: 50 }),
            input('Répétitions', 'reps', reps, { type: 'number', min: 1, max: 200 }),
            input('Charge (kg)', 'weight', 0, { type: 'number', min: 0, max: 1000, step: 'any' }),
            button('Retirer', () => row.remove()),
        );
        nextIndex++;
        plan.append(row);
        return true;
    }

    function showResults(items, query) {
        for (const item of items) {
            const label = document.createElement('span');
            label.textContent = [nameOf(item), item.equipment].filter(Boolean).join(' · ');
            const row = document.createElement('div');
            row.className = 'session-result-row';
            row.append(label, button('Ajouter', () => {
                if (addExercise(nameOf(item))) {
                    status.textContent = `${nameOf(item)} ajouté.`;
                }
            }));
            results.append(row);
        }
        results.append(button(`Ajouter « ${query} » manuellement`, () => {
            if (addExercise(query)) status.textContent = `${query} ajouté.`;
        }));
    }

    search.addEventListener('input', () => {
        clearTimeout(debounce);
        const current = ++revision;
        const query = search.value.trim();
        results.replaceChildren();
        status.textContent = '';
        if (query.length < 2) return;

        debounce = setTimeout(async () => {
            try {
                const [remote, custom] = await Promise.allSettled([
                    fetchExercises('/api/exercises?' + new URLSearchParams({ search: query, limit: '12' })),
                    loadCustomExercises(),
                ]);
                if (current !== revision) return;
                const personal = (custom.value ?? []).filter(item => normalize(nameOf(item)).includes(normalize(query)));
                const items = [...personal, ...(remote.value ?? [])].slice(0, 12);
                showResults(items, query);
                if (!items.length) status.textContent = remote.status === 'rejected'
                    ? 'Recherche indisponible. Tu peux saisir un exercice manuellement.'
                    : 'Aucun exercice trouvé dans le catalogue.';
            } catch (error) {
                if (current === revision) status.textContent = error.message;
            }
        }, 300);
    });

    suggest.addEventListener('click', async () => {
        const groups = [...form.querySelectorAll('[name="muscles[]"]:checked')].map(input => input.value);
        if (!groups.length) {
            status.textContent = 'Choisis au moins un groupe musculaire.';
            return;
        }
        const goal = presets[builder.querySelector('[data-training-goal]').value];
        const equipment = builder.querySelector('[data-training-equipment]').value;
        const minutes = Number(form.elements.duration.value);
        if (!Number.isInteger(minutes) || minutes < 10 || minutes > 300) {
            status.textContent = 'Choisis une durée entre 10 et 300 minutes.';
            return;
        }
        clearTimeout(debounce);
        ++revision;
        results.replaceChildren();
        suggest.disabled = true;
        status.textContent = 'Recherche dans le catalogue…';

        try {
            const [custom, ...catalogs] = await Promise.allSettled([
                loadCustomExercises(),
                ...groups.map(muscle => fetchExercises('/api/exercises?' + new URLSearchParams({
                    muscle, include_secondary: '0', limit: '500',
                }))),
            ]);
            const alreadyAdded = new Set([...plan.querySelectorAll('input[name$="[name]"]')].map(input => normalize(input.value)));
            const equipmentPattern = equipment === 'bodyweight'
                ? /body.?weight|poids du corps/
                : /body.?weight|poids du corps|dumbbell|haltere/;
            const lists = groups.map((muscle, index) => [
                ...(custom.value ?? []).filter(item => item.primaryGroup === muscle),
                ...(catalogs[index].value ?? []),
            ].filter(item => nameOf(item) && (equipment === 'all' || equipmentPattern.test(normalize(item.equipment))))
                .sort((a, b) => Number(familiar(b)) - Number(familiar(a))));

            const max = Math.min(8, Math.floor((minutes * 60 - Math.min(300, minutes * 12)) / (goal.sets * (45 + goal.rest))), 20 - plan.children.length);
            let count = 0;
            while (count < max && lists.some(list => list.length)) {
                for (const list of lists) {
                    let item;
                    do { item = list.shift(); } while (item && alreadyAdded.has(normalize(nameOf(item))));
                    if (!item) continue;
                    addExercise(nameOf(item), goal);
                    alreadyAdded.add(normalize(nameOf(item)));
                    if (++count >= max) break;
                }
            }

            status.textContent = count
                ? `${count} exercice(s) suggéré(s). Séries et répétitions modifiables · repos indicatif : ${goal.rest} s.`
                : max < 1
                    ? 'Augmente la durée prévue ou libère une place dans la séance.'
                    : 'Aucun exercice supplémentaire trouvé pour ces muscles et ce matériel.';
            if (catalogs.some(result => result.status === 'rejected')) status.textContent += ' Une partie du catalogue est indisponible.';
        } catch {
            status.textContent = 'Suggestion indisponible. Tu peux toujours ajouter des exercices manuellement.';
        } finally {
            suggest.disabled = false;
        }
    });

    const seed = builder.querySelector('[data-seeded-exercise]');
    if (seed.value) {
        addExercise(seed.value);
        seed.value = '';
    }
})();
