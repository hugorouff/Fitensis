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
    const silence = timer.querySelector('[data-timer-silence]');
    let audioContext;
    let alarm;
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
        if (alarm || !sound.checked || audioContext?.state !== 'running') return;
        const tone = audioContext.createOscillator();
        const gain = audioContext.createGain();
        const now = audioContext.currentTime;
        tone.frequency.value = 660;
        gain.gain.setValueAtTime(0, now);
        gain.gain.linearRampToValueAtTime(0.09, now + 0.08);
        tone.connect(gain);
        gain.connect(audioContext.destination);
        tone.onended = () => {
            tone.disconnect();
            gain.disconnect();
        };
        tone.start();
        alarm = { tone, gain };
        silence.hidden = false;
    }

    function stopAlarm() {
        if (!alarm) return;
        const now = audioContext.currentTime;
        alarm.gain.gain.cancelScheduledValues(now);
        alarm.gain.gain.setTargetAtTime(0, now, 0.008);
        alarm.tone.stop(now + 0.05);
        alarm = null;
        silence.hidden = true;
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
        stopAlarm();
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
        stopAlarm();
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
    silence.addEventListener('click', () => {
        stopAlarm();
        start.focus();
    });
    sound.addEventListener('change', () => {
        if (sound.checked) prepareSound();
        else stopAlarm();
    });
    window.addEventListener('pagehide', stopAlarm);
    render();
    setInterval(render, 250);
})();
