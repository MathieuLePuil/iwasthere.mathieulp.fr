/*
 * Ce que chaque page fait pour la PWA, hors de tout script inline (la CSP
 * n'en admet pas) :
 *  - enregistrer le service worker ;
 *  - hors session, lui demander de vider le cache des pages du compte précédent ;
 *  - en session avec la permission accordée, renvoyer l'abonnement push au
 *    serveur — seulement s'il a changé depuis le dernier envoi.
 * Les données de la page (utilisateur, URL d'abonnement) viennent de <html data-*>.
 */
import { csrfHeaders } from './csrf.js';

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js').catch(() => {});

    const root = document.documentElement;
    const userId = root.dataset.userId || '';
    const subscribeUrl = root.dataset.subscribeUrl || '';

    if (!userId) {
        navigator.serviceWorker.ready.then((reg) => {
            reg.active && reg.active.postMessage({ type: 'clear-pages' });
        }).catch(() => {});
    } else if (subscribeUrl && 'Notification' in window && Notification.permission === 'granted') {
        navigator.serviceWorker.ready
            .then((reg) => reg.pushManager.getSubscription())
            .then((sub) => {
                if (!sub) return;
                const key = 'iwt-push-sent';
                const fingerprint = sub.endpoint + '|' + userId;
                try { if (localStorage.getItem(key) === fingerprint) return; } catch (e) {}
                return fetch(subscribeUrl, {
                    method: 'POST',
                    headers: csrfHeaders({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify(sub),
                    credentials: 'same-origin',
                }).then((res) => {
                    if (res.ok) { try { localStorage.setItem(key, fingerprint); } catch (e) {} }
                });
            })
            .catch(() => {});
    }
}
