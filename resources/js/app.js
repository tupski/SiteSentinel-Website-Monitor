// SiteSentinel frontend entry — Turbo (SPA-free navigation, ADR-002) + Alpine (local state only)
import '@hotwired/turbo';
import Alpine from 'alpinejs';

window.Alpine = Alpine;

/**
 * Theme store (ADR-033): Light / Dark / System.
 *
 * The resolved theme is applied to <html> by the no-FOUC bootstrap in the
 * <head>; this store keeps the control in sync, persists the *preference*
 * to localStorage AND a cookie, and follows prefers-color-scheme for "system".
 */
Alpine.store('theme', {
    mode: 'system',
    media: null,

    init() {
        this.mode = this.readPreference();
        this.apply();

        this.media = window.matchMedia('(prefers-color-scheme: dark)');
        this.media.addEventListener('change', () => {
            if (this.mode === 'system') {
                this.apply();
            }
        });
    },

    readPreference() {
        try {
            const stored = window.localStorage.getItem('theme');
            if (stored === 'light' || stored === 'dark' || stored === 'system') {
                return stored;
            }
        } catch (e) {}

        return 'system';
    },

    resolved() {
        if (this.mode === 'dark') {
            return 'dark';
        }
        if (this.mode === 'light') {
            return 'light';
        }

        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    },

    set(preference) {
        this.mode = preference;
        try {
            window.localStorage.setItem('theme', preference);
        } catch (e) {}
        document.cookie = 'theme=' + preference + ';path=/;max-age=31536000;SameSite=Lax' +
            (window.location.protocol === 'https:' ? ';Secure' : '');
        this.apply();
    },

    apply() {
        const dark = this.resolved() === 'dark';
        const root = document.documentElement;
        root.classList.toggle('dark', dark);
        root.setAttribute('data-theme', dark ? 'dark' : 'light');
        root.setAttribute('data-theme-preference', this.mode);
    },
});

/**
 * Reusable modal component (ADR-034).
 *
 * Defined here — NOT inline in a Blade `x-data` attribute — because the
 * focusable-element selector below contains double quotes
 * (`[tabindex]:not([tabindex="-1"])`). Inside an HTML attribute those quotes
 * would terminate the attribute early and hand Alpine a syntactically broken
 * expression. As a plain JS string in this module there is no quoting conflict.
 *
 * Local UI state only (AGENTS.md §2/§9): opening, focus management and the
 * focus trap. No data fetching, no business logic.
 */
Alpine.data('modal', () => ({
    open: false,
    trigger: null,
    previousFocus: null,

    init() {
        this.$watch('open', (isOpen) => (isOpen ? this.onOpen() : this.onClose()));
    },

    onOpen() {
        this.previousFocus = document.activeElement;
        this.$nextTick(() => {
            const panel = this.$refs.panel;
            const focusable = panel.querySelector(
                'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])'
            );
            if (focusable) focusable.focus();
            else panel.focus();
        });
    },

    onClose() {
        if (this.previousFocus && typeof this.previousFocus.focus === 'function') {
            this.previousFocus.focus();
        }
    },

    close() {
        this.open = false;
    },

    trap(event) {
        const panel = this.$refs.panel;
        const nodes = panel.querySelectorAll(
            'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])'
        );
        if (nodes.length === 0) return;

        const first = nodes[0];
        const last = nodes[nodes.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    },
}));

/**
 * Browser Push opt-in (NOTIFICATIONS.md §7.3, ADR-032).
 *
 * Registers the service worker, asks for Notification permission, subscribes
 * via the VAPID public key (read from a data attribute, never hard-coded), and
 * posts the subscription to the server. No secret is read here — only the
 * public key is exposed to this code.
 */
const urlBase64ToUint8Array = (base64String) => {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }

    return outputArray;
};

Alpine.data('pushOptin', (options = {}) => ({
    supported: false,
    subscribed: false,
    busy: false,
    error: '',
    vapidPublicKey: options.publicKey || '',
    subscribeUrl: options.subscribeUrl || '',
    unsubscribeUrl: options.unsubscribeUrl || '',
    csrfToken: options.csrfToken || '',

    init() {
        this.supported = 'serviceWorker' in navigator
            && 'PushManager' in window
            && 'Notification' in window
            && this.vapidPublicKey !== '';

        if (this.supported) {
            navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
            this.refreshState();
        }
    },

    async refreshState() {
        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            this.subscribed = !!subscription;
        } catch (e) {
            this.subscribed = false;
        }
    },

    async subscribe() {
        if (!this.supported || this.busy) {
            return;
        }

        this.busy = true;
        this.error = '';

        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                this.error = options.messages && options.messages.denied
                    ? options.messages.denied
                    : 'Notification permission was not granted.';
                return;
            }

            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(this.vapidPublicKey),
            });

            const payload = subscription.toJSON();
            const response = await fetch(this.subscribeUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                body: JSON.stringify({
                    endpoint: payload.endpoint,
                    keys: payload.keys,
                    user_agent: navigator.userAgent,
                }),
            });

            if (!response.ok) {
                throw new Error('subscribe failed');
            }

            this.subscribed = true;
        } catch (e) {
            this.error = options.messages && options.messages.failed
                ? options.messages.failed
                : 'Could not enable browser push.';
        } finally {
            this.busy = false;
        }
    },

    async unsubscribe() {
        if (!this.supported || this.busy) {
            return;
        }

        this.busy = true;
        this.error = '';

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();

            if (subscription) {
                const endpoint = subscription.endpoint;
                await subscription.unsubscribe();

                await fetch(this.unsubscribeUrl, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                    body: JSON.stringify({ endpoint }),
                });
            }

            this.subscribed = false;
        } catch (e) {
            this.error = options.messages && options.messages.failed
                ? options.messages.failed
                : 'Could not disable browser push.';
        } finally {
            this.busy = false;
        }
    },
}));

Alpine.start();
