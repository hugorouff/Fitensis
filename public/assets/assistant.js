(() => {
    "use strict";
    const form = document.querySelector("#ai-form");
    if (!form) return;
    const get = (id) => document.getElementById(id);
    let ready = false;
    let busy = false;
    let pantry = [];
    try {
        const stored = JSON.parse(
            localStorage.getItem(`user-${form.dataset.userId}-gymapp-pantry`) || "[]",
        );
        pantry = Array.isArray(stored)
            ? stored.filter((item) => typeof item === "string").slice(0, 30)
            : [];
    } catch (_) {
        /* Le frigo reste facultatif. */
    }
    get("ai-pantry-preview").textContent = pantry.length
        ? pantry.join(", ")
        : "Ton frigo est vide sur ce navigateur.";
    async function checkStatus() {
        try {
            const response = await fetch("/api/assistant/status");
            const data = await response.json();
            ready = response.ok && data.ready === true;
            get("ai-status").textContent =
                data.message || (ready ? "Moteur prêt." : "Moteur indisponible.");
        } catch (_) {
            ready = false;
            get("ai-status").textContent = "Connexion impossible. Lance Ollama puis réessaie.";
        }
        get("ai-send").disabled = !ready || busy;
    }
    get("ai-recheck").addEventListener("click", checkStatus);
    document.querySelectorAll("[data-ai-example]").forEach((button) => {
        button.addEventListener("click", () => {
            get("ai-message").value = button.dataset.aiExample;
            get("ai-topic").value = button.dataset.aiTopic;
            get("ai-message").focus();
        });
    });
    get("ai-new").addEventListener("click", () => {
        if (busy) return;
        get("ai-parent").value = "";
        document.querySelectorAll(".ai-block-group").forEach((select) => (select.value = ""));
        get("ai-message").value = "";
        get("ai-results").replaceChildren();
        history.replaceState(null, "", "/assistant");
    });
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (busy || !ready || !form.reportValidity()) return;
        busy = true;
        get("ai-send").disabled = true;
        get("ai-loading").hidden = false;
        get("ai-error").hidden = true;
        get("ai-results").replaceChildren();
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 210000);
        try {
            const manualBlocks = Array.from(document.querySelectorAll(".ai-training-block"))
                .map((row) => ({
                    group: row.querySelector(".ai-block-group").value,
                    minutes: Number(row.querySelector(".ai-block-minutes").value),
                }))
                .filter((block) => block.group);
            const wizardBlocks =
                form.dataset.generatedMessage === get("ai-message").value
                    ? JSON.parse(form.dataset.wizardBlocks || "[]")
                    : [];
            const response = await fetch("/api/assistant/message", {
                method: "POST",
                signal: controller.signal,
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({
                    message: get("ai-message").value,
                    idea_count: Number(get("ai-ideas-count").value),
                    requested_muscles: JSON.parse(
                        form.dataset.generatedMessage === get("ai-message").value
                            ? form.dataset.requestedMuscles || "[]"
                            : "[]",
                    ),
                    portions: Number(form.dataset.portions || 2),
                    training_blocks: manualBlocks.length ? manualBlocks : wizardBlocks,
                    topic: get("ai-topic").value,
                    goal: get("ai-goal").value,
                    constraints: get("ai-constraints").value,
                    parent_id: get("ai-parent").value || null,
                    share_pantry: get("ai-share-pantry").checked,
                    pantry: get("ai-share-pantry").checked ? pantry : [],
                    share_training: get("ai-share-training").checked,
                }),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.detail || "La demande a échoué.");
            get("ai-results").innerHTML = data.html;
            get("ai-parent").value = data.run_id;
            history.replaceState(null, "", `/assistant?run=${data.run_id}#ai-results`);
            get("ai-results").scrollIntoView({ behavior: "smooth", block: "start" });
        } catch (error) {
            get("ai-error").textContent =
                error.name === "AbortError"
                    ? "Le modèle met trop de temps. Essaie une demande plus courte."
                    : error.message;
            get("ai-error").hidden = false;
        } finally {
            clearTimeout(timer);
            busy = false;
            get("ai-loading").hidden = true;
            get("ai-send").disabled = !ready;
        }
    });
    checkStatus();
})();
