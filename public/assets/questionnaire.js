(() => {
    "use strict";
    const get = (id) => document.getElementById(id);
    if (!get("wizard-next")) return;
    let step = 0;
    const labels = ["Ton objectif", "Ton entraînement", "Tes goûts et précisions"];
    let catalogRequest = 0;
    const presets = {
        upper: ["chest", "back", "shoulders", "biceps", "triceps"],
        lower: ["quads", "hamstrings", "glutes", "calves"],
        full: ["chest", "back", "shoulders", "quads", "hamstrings", "glutes", "abs"],
        arms: ["biceps", "triceps"],
    };
    const selectedMuscles = () =>
        Array.from(document.querySelectorAll(".wizard-extra-muscle:checked")).map(
            (input) => input.value,
        );
    function muscleSummary() {
        const names = Array.from(document.querySelectorAll(".wizard-extra-muscle:checked")).map(
            (input) => input.dataset.label,
        );
        get("wizard-muscle-summary").textContent = names.length
            ? "Même séance : " + names.join(" + ")
            : "Choisis au moins un groupe.";
    }
    function applyMuscles(groups) {
        document
            .querySelectorAll(".wizard-extra-muscle")
            .forEach((input) => (input.checked = groups.includes(input.value)));
        muscleSummary();
        if (step === 1) previewCatalog();
    }
    document
        .querySelectorAll("[data-muscle-preset]")
        .forEach((button) =>
            button.addEventListener("click", () =>
                applyMuscles(button.dataset.musclePreset.split(",")),
            ),
        );
    document.querySelectorAll(".wizard-extra-muscle").forEach((input) =>
        input.addEventListener("change", () => {
            muscleSummary();
            previewCatalog();
        }),
    );
    async function previewCatalog() {
        const current = ++catalogRequest;
        const group = selectedMuscles()[0] || get("wizard-muscle").value;
        const map = { upper: "chest", lower: "quads", full: "chest", arms: "biceps" };
        get("wizard-catalog-preview").textContent =
            "Chargement des exemples du catalogue Exercices…";
        try {
            const response = await fetch(
                "/api/exercises?" + new URLSearchParams({ muscle: map[group] || group }),
            );
            const payload = await response.json();
            if (current !== catalogRequest) return;
            const items = payload.data || [];
            get("wizard-catalog-preview").textContent = items.length
                ? "Exemples du catalogue : " +
                  items
                      .slice(0, 3)
                      .map((item) => item.name_fr || item.name)
                      .join(" · ")
                : "Pas encore d’exemple disponible pour ce groupe.";
        } catch (_) {
            if (current === catalogRequest)
                get("wizard-catalog-preview").textContent =
                    "Catalogue momentanément indisponible. Les exercices de secours restent disponibles selon le groupe.";
        }
    }
    get("wizard-muscle").addEventListener("change", () =>
        applyMuscles(presets[get("wizard-muscle").value] || [get("wizard-muscle").value]),
    );
    function show() {
        document
            .querySelectorAll(".wizard-step")
            .forEach((el, index) => (el.hidden = index !== step));
        const training = ["auto", "sessions"].includes(get("wizard-topic").value);
        get("wizard-progress").textContent =
            `Étape ${training ? step + 1 : step === 0 ? 1 : 2} sur ${training ? 3 : 2} · ${labels[step]}`;
        if (step === 1) previewCatalog();
        get("wizard-back").hidden = step === 0;
        get("wizard-next").hidden = step === 2;
        get("wizard-apply").hidden = step !== 2;
    }
    function valid() {
        return Array.from(
            document.querySelectorAll(`.wizard-step[data-step="${step}"] input`),
        ).every((input) => input.reportValidity());
    }
    get("wizard-next").addEventListener("click", () => {
        if (valid()) {
            step =
                step === 0 && !["auto", "sessions"].includes(get("wizard-topic").value)
                    ? 2
                    : step + 1;
            show();
        }
    });
    get("wizard-back").addEventListener("click", () => {
        step =
            step === 2 && !["auto", "sessions"].includes(get("wizard-topic").value) ? 0 : step - 1;
        show();
    });
    get("wizard-apply").addEventListener("click", () => {
        if (!valid()) return;
        const topic = get("wizard-topic").value;
        const muscle = get("wizard-muscle");
        const count = Number(get("wizard-count").value);
        const minutes = Number(get("wizard-minutes").value);
        const isTraining = ["auto", "sessions"].includes(topic);
        const chosen = selectedMuscles();
        if (isTraining && (!chosen.length || chosen.length > 8 || minutes < chosen.length * 5)) {
            step = 1;
            show();
            get("wizard-muscle-summary").textContent =
                "Choisis de 1 à 8 groupes et prévois au moins 5 minutes par groupe dans la durée totale.";
            return;
        }
        const labels = Array.from(document.querySelectorAll(".wizard-extra-muscle:checked")).map(
            (input) => input.dataset.label,
        );
        const training = `${count} séances combinées ${labels.join(" + ")} de ${minutes} minutes au total chacune avec ${get("wizard-equipment").value}.`;
        get("ai-form").dataset.wizardBlocks = JSON.stringify(
            isTraining
                ? Array.from({ length: count }, () => ({
                      group: "mixed",
                      muscles: chosen,
                      minutes,
                  }))
                : [],
        );
        const food = ` 40 recettes de repas. J’aime : ${get("wizard-likes").value || "variété"}. Sans ${get("wizard-dislikes").value || "exclusion particulière"}.`;
        const question =
            (topic === "recipes"
                ? food
                : topic === "advice"
                  ? "Donne-moi des conseils adaptés."
                  : training + (topic === "auto" ? food : "")) +
            ` Niveau : ${get("wizard-level").value}. ${get("wizard-extra").value}`;
        get("ai-message").value = question;
        get("ai-topic").value = topic;
        get("ai-goal").value = get("wizard-goal").value;
        get("ai-constraints").value =
            `${get("wizard-equipment").value}. ${get("wizard-extra").value}`;
        document.querySelectorAll(".ai-block-group").forEach((select) => (select.value = ""));
        get("ai-form").dataset.requestedMuscles = JSON.stringify(isTraining ? chosen : []);
        get("ai-form").dataset.generatedMessage = question;
        get("ai-form").dataset.portions = get("wizard-portions").value;
        get("wizard-summary").textContent =
            "Ta demande est prête ci-dessous. Tu peux la modifier puis cliquer sur « Me proposer des idées ». Les données facultatives restent à ton choix.";
        get("ai-message").scrollIntoView({ behavior: "smooth", block: "center" });
        get("ai-message").focus({ preventScroll: true });
    });
    applyMuscles(presets[get("wizard-muscle").value] || [get("wizard-muscle").value]);
    show();
})();
