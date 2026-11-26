(() => {
    const timer = document.querySelector('[data-session-timer]');
    if (!timer) return;

    const storageKey = `fitensis.rest.${timer.dataset.sessionTimer}`;
    const duration = timer.querySelector('[data-timer-duration]');
    const output = timer.querySelector('[data-timer-display]');
    const status = timer.querySelector('[data-timer-status]');
    const start = timer.querySelector('[data-timer-start]');
    const pause = timer.querySelector('[data-timer-pause]');
    const sound = timer.querySelector('[data-timer-sound]');
    let audioContext;
    let end = 0;
    let remaining = Number(duration.value) * 1000;
    let paused = false;

    try {
        const saved = JSON.parse(sessionStorage.getItem(storageKey));
        if (saved && Number.isFinite(saved.end) && Number.isFinite(saved.remaining)) {
            ({ end, remaining, paused } = saved);
            duration.value = saved.duration;
        }
    } catch {
        // Le minuteur fonctionne même lorsque le stockage du navigateur est bloqué.
    }

    function save() {
        try {
            sessionStorage.setItem(storageKey, JSON.stringify({ end, remaining, paused, duration: duration.value }));
        } catch {}
    }

    function prepareSound() {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext || !sound.checked) return;
        try {
            audioContext ??= new AudioContext();
            audioContext.resume().catch(() => {});
        } catch {
            // L'indication visuelle reste disponible.
        }
    }

    function soundFinished() {
        if (!sound.checked || audioContext?.state !== 'running') return;
        const tone = audioContext.createOscillator();
        const gain = audioContext.createGain();
        const now = audioContext.currentTime;
        tone.frequency.setValueAtTime(880, now);
        gain.gain.setValueAtTime(0.14, now);
        gain.gain.exponentialRampToValueAtTime(0.001, now + 0.35);
        tone.connect(gain);
        gain.connect(audioContext.destination);
        tone.start(now);
        tone.stop(now + 0.36);
    }

    function render() {
        if (end) {
            remaining = Math.max(0, end - Date.now());
            if (remaining === 0) {
                end = 0;
                status.textContent = 'Repos terminé.';
                soundFinished();
                save();
            }
        }
        const seconds = Math.ceil(remaining / 1000);
        output.value = `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
        start.disabled = Boolean(end);
        pause.disabled = !end;
        duration.disabled = Boolean(end);
        start.textContent = paused ? 'Reprendre' : 'Démarrer';
    }

    start.addEventListener('click', () => {
        if (!duration.reportValidity()) return;
        prepareSound();
        if (!paused) remaining = Number(duration.value) * 1000;
        end = Date.now() + remaining;
        paused = false;
        status.textContent = '';
        save();
        render();
    });

    pause.addEventListener('click', () => {
        render();
        if (!end) return;
        end = 0;
        paused = true;
        status.textContent = 'Minuteur en pause.';
        save();
        render();
    });

    function reset() {
        end = 0;
        remaining = Number(duration.value) * 1000;
        paused = false;
        status.textContent = '';
        save();
        render();
    }

    timer.querySelector('[data-timer-reset]').addEventListener('click', reset);
    duration.addEventListener('change', () => {
        if (duration.checkValidity()) reset();
    });
    sound.addEventListener('change', prepareSound);
    render();
    setInterval(render, 250);
})();
