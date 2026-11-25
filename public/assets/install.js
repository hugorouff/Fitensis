if (window.isSecureContext && 'serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js').catch(error => {
        console.warn('Installation de Fitensis indisponible.', error);
    });
}
