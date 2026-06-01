import { Controller } from '@hotwired/stimulus';

const LS_PROMPTED = 'iwt-push-prompted';

export default class extends Controller {
    static targets = ['checkbox', 'btn', 'status', 'wrapper'];
    static values = {
        publicKey: String,
        subscribeUrl: String,
        auto: Boolean,
    };

    connect() {
        // Always update what's visible, even when push is not usable yet.
        this.#refreshUi();

        if (!this.#supported() || !this.#isInPwa()) {
            return;
        }

        if (this.autoValue
            && Notification.permission === 'default'
            && !localStorage.getItem(LS_PROMPTED)) {
            setTimeout(() => this.#openOverlay(), 250);
        }
    }

    async enable() {
        if (!('Notification' in window)) {
            this.#setStatus('Cet appareil ne supporte pas les notifications push.', 'err');
            return;
        }

        if (this.#isIos() && !this.#isInPwa()) {
            this.#setStatus(
                'Ajoute d\'abord IWasThere à l\'écran d\'accueil (Partager → Sur l\'écran d\'accueil), puis ouvre l\'app depuis l\'icône.',
                'warn',
            );
            return;
        }

        if (Notification.permission === 'denied') {
            this.#setStatus(
                'Bloquées au niveau du système. Ouvre Réglages iPhone → Notifications → IWasThere.',
                'err',
            );
            return;
        }

        try {
            this.#setStatus('Demande d\'autorisation…');
            const perm = await Notification.requestPermission();
            localStorage.setItem(LS_PROMPTED, '1');

            if (perm === 'denied') {
                this.#setStatus(
                    'Refusé. Tu peux réautoriser dans Réglages iPhone → Notifications → IWasThere.',
                    'err',
                );
                this.#refreshUi();
                return;
            }
            if (perm !== 'granted') {
                this.#setStatus('Autorisation annulée.');
                this.#refreshUi();
                return;
            }

            this.#setStatus('Enregistrement de l\'abonnement…');
            await this.#subscribe();
            this.#setStatus('Notifications activées ✓', 'ok');
            this.#refreshUi();
        } catch (e) {
            console.warn('Push enable failed:', e);
            this.#setStatus('Erreur : ' + (e?.message || e), 'err');
        }
    }

    // ── Internal ─────────────────────────────────────────────────────────────

    async #subscribe() {
        const reg = await navigator.serviceWorker.ready;
        let sub = await reg.pushManager.getSubscription();
        if (sub) await sub.unsubscribe();

        sub = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: this.#b64(this.publicKeyValue),
        });

        const res = await fetch(this.subscribeUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(sub.toJSON()),
        });
        if (!res.ok) throw new Error(`Subscribe failed: ${res.status}`);
    }

    #refreshUi() {
        const perm = ('Notification' in window) ? Notification.permission : null;
        const supported = this.#supported();
        const inPwa = this.#isInPwa();
        const granted = perm === 'granted';

        if (this.hasCheckboxTarget) this.checkboxTarget.checked = granted;

        // Wrapper visible if not granted (notifications-page banner uses it).
        if (this.hasWrapperTarget) {
            this.wrapperTarget.classList.toggle('hidden', granted);
        }

        // The button is always rendered; its label/state mirrors reality.
        if (this.hasBtnTarget) {
            const btn = this.btnTarget;
            btn.disabled = false;
            btn.style.opacity = '';
            if (granted) {
                btn.classList.add('hidden');
            } else {
                btn.classList.remove('hidden');
                if (!supported) {
                    btn.textContent = 'Non supporté sur cet appareil';
                    btn.disabled = true;
                    btn.style.opacity = '0.6';
                } else if (this.#isIos() && !inPwa) {
                    btn.textContent = 'Ajoute à l\'écran d\'accueil';
                    btn.disabled = true;
                    btn.style.opacity = '0.6';
                } else if (perm === 'denied') {
                    btn.textContent = 'Bloquées — Réglages iPhone';
                    btn.disabled = true;
                    btn.style.opacity = '0.6';
                } else {
                    btn.textContent = 'Activer les notifications';
                }
            }
        }

        // Status line (when present) — explain the current situation.
        if (this.hasStatusTarget) {
            if (!supported) {
                this.#setStatus(
                    'Notifications non supportées sur cet appareil/navigateur.',
                    'warn',
                );
            } else if (this.#isIos() && !inPwa) {
                this.#setStatus(
                    'Sur iOS, ouvre l\'app depuis l\'icône installée pour activer le push.',
                    'warn',
                );
            } else if (perm === 'denied') {
                this.#setStatus(
                    'Bloquées. Réglages iPhone → Notifications → IWasThere → Autoriser.',
                    'err',
                );
            } else if (perm === 'granted') {
                this.#setStatus('Notifications activées ✓', 'ok');
            } else {
                this.#setStatus('Pas encore activées sur cet appareil.');
            }
        }

        if (granted && supported) this.#syncSubscription();
    }

    async #syncSubscription() {
        try {
            const reg = await navigator.serviceWorker.ready;
            const sub = await reg.pushManager.getSubscription();
            if (!sub) {
                await this.#subscribe();
                return;
            }
            await fetch(this.subscribeUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(sub.toJSON()),
            });
        } catch (e) {
            console.warn('Push sync failed:', e);
        }
    }

    #openOverlay() {
        if (document.getElementById('iwt-push-overlay')) return;
        if (!this.#supported() || !this.#isInPwa()) return;

        const overlay = document.createElement('div');
        overlay.id = 'iwt-push-overlay';
        overlay.style.cssText = `
            position: fixed; inset: 0; z-index: 100;
            background: rgba(0,0,0,0.6); backdrop-filter: blur(6px);
            display: flex; align-items: center; justify-content: center;
            padding: 1.25rem;
        `;
        overlay.innerHTML = `
            <div style="
                background: var(--bg-1, #16181D); color: var(--fg-1, #fff);
                border: 1px solid var(--border-default, rgba(255,255,255,0.08));
                border-radius: 1.25rem; padding: 1.75rem 1.5rem;
                max-width: 22rem; width: 100%; text-align: center;
                box-shadow: 0 24px 60px rgba(0,0,0,0.5);
            ">
                <div style="
                    width: 56px; height: 56px; margin: 0 auto 1rem;
                    border-radius: 16px; display:flex; align-items:center; justify-content:center;
                    background: rgba(61,220,151,0.12);
                ">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
                         stroke="#3DDC97" stroke-width="1.6"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10 21a2 2 0 0 0 4 0"/>
                    </svg>
                </div>
                <h2 style="font-size:1.05rem; font-weight:600; margin:0 0 .5rem">
                    Activer les notifications
                </h2>
                <p style="font-size:.85rem; line-height:1.4; color: var(--fg-3, #9aa0a6); margin:0 0 1.25rem">
                    Reçois les alertes pour tes amis et tes événements,
                    même quand l'app n'est pas ouverte.
                </p>
                <button id="iwt-push-accept" style="
                    display:block; width:100%; padding:.85rem 1rem;
                    background: var(--positive, #3DDC97); color:#0B0D10;
                    border: none; border-radius: 1rem;
                    font-weight:600; font-size:.9rem; cursor:pointer;
                ">Activer</button>
                <button id="iwt-push-later" style="
                    display:block; width:100%; padding:.7rem 1rem; margin-top:.5rem;
                    background: transparent; color: var(--fg-3, #9aa0a6);
                    border: none; font-size:.8rem; cursor:pointer;
                ">Plus tard</button>
            </div>
        `;
        document.body.appendChild(overlay);

        overlay.querySelector('#iwt-push-accept').addEventListener('click', async () => {
            overlay.remove();
            await this.enable();
        });
        overlay.querySelector('#iwt-push-later').addEventListener('click', () => {
            localStorage.setItem(LS_PROMPTED, '1');
            overlay.remove();
        });
    }

    #setStatus(text, kind = '') {
        if (!this.hasStatusTarget) return;
        const colors = {
            ok: 'var(--positive, #3DDC97)',
            err: 'var(--negative, #E89B8E)',
            warn: '#FBBF24',
            '': 'var(--fg-3, #9aa0a6)',
        };
        this.statusTarget.textContent = text;
        this.statusTarget.style.color = colors[kind] ?? colors[''];
    }

    #supported() {
        return 'Notification' in window
            && 'serviceWorker' in navigator
            && 'PushManager' in window;
    }

    #isIos() {
        const ua = navigator.userAgent || '';
        return /iP(hone|ad|od)/.test(ua)
            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }

    #isInPwa() {
        if (typeof window.matchMedia === 'function'
            && window.matchMedia('(display-mode: standalone)').matches) {
            return true;
        }
        // iOS-specific flag
        return navigator.standalone === true;
    }

    #b64(s) {
        const pad = '='.repeat((4 - s.length % 4) % 4);
        const raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
    }
}
