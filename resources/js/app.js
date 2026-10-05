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
 * Icon-only theme menu (ADR-033).
 *
 * Defined here — not inline in a Blade `x-data` attribute — for the same
 * quoting reason as the modal below, and because the roving-focus and
 * focus-trap logic does not belong in markup. Reads/writes only the global
 * `theme` store (localStorage key unchanged) and manages local UI state.
 * Uses a `role="menu"` / `role="menuitemradio"` pattern.
 */
Alpine.data('themeMenu', () => ({
    open: false,
    current: 0,
    items: ['light', 'dark', 'system'],

    init() {
        this.current = Math.max(0, this.items.indexOf(this.$store.theme.mode));
    },

    modeLabel() {
        return { light: 'Light', dark: 'Dark', system: 'System' }[this.$store.theme.mode] || 'System';
    },

    openMenu(focusIndex) {
        this.current = focusIndex ?? Math.max(0, this.items.indexOf(this.$store.theme.mode));
        this.open = true;
        this.$nextTick(() => this.focusCurrent());
    },

    closeMenu(returnFocus = false) {
        this.open = false;
        if (returnFocus) {
            this.$refs.trigger.focus();
        }
    },

    toggle() {
        this.open ? this.closeMenu(true) : this.openMenu();
    },

    focusCurrent() {
        const nodes = this.$refs.menu.querySelectorAll('[data-theme-option]');
        if (nodes[this.current]) {
            nodes[this.current].focus();
        }
    },

    move(delta) {
        const count = this.items.length;
        this.current = (this.current + delta + count) % count;
        this.focusCurrent();
    },

    select(mode) {
        this.$store.theme.set(mode);
        this.closeMenu(true);
    },

    trap(event) {
        const nodes = this.$refs.menu.querySelectorAll('[data-theme-option]');
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

    onTriggerKeydown(event) {
        if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
            event.preventDefault();
            this.openMenu();
        }
    },
}));

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

/**
 * Admin shell — collapsible sidebar + mobile off-canvas drawer (Phase 4).
 *
 * Local UI state only: collapse/expand, the mobile drawer, and its focus
 * trap. The collapse preference is persisted to localStorage under
 * `sentinel.sidebar` in the same spirit as the theme store's `theme` key, so
 * it survives Turbo navigation and full reloads. No data fetching.
 *
 * The desktop collapse and the mobile drawer are independent: `collapsed`
 * only affects the fixed sidebar at `md` and up (via the `is-collapsed`
 * class on the root), while `mobileOpen` only affects the off-canvas drawer
 * below `md`.
 */
Alpine.data('sidebar', () => ({
    collapsed: false,
    mobileOpen: false,
    previousFocus: null,

    init() {
        try {
            this.collapsed = window.localStorage.getItem('sentinel.sidebar') === 'collapsed';
        } catch (e) {
            this.collapsed = false;
        }
    },

    toggleCollapse() {
        this.collapsed = !this.collapsed;
        try {
            window.localStorage.setItem('sentinel.sidebar', this.collapsed ? 'collapsed' : 'expanded');
        } catch (e) {}
    },

    collapseLabel() {
        return this.collapsed ? 'Expand sidebar' : 'Collapse sidebar';
    },

    openDrawer() {
        this.previousFocus = document.activeElement;
        this.mobileOpen = true;
        this.$nextTick(() => {
            const drawer = this.$refs.drawer;
            if (!drawer) return;
            const focusable = drawer.querySelector(
                'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])'
            );
            if (focusable) focusable.focus();
            else drawer.focus();
        });
    },

    closeDrawer(returnFocus = true) {
        this.mobileOpen = false;
        if (returnFocus && this.previousFocus && typeof this.previousFocus.focus === 'function') {
            this.previousFocus.focus();
        }
    },

    trap(event) {
        const drawer = this.$refs.drawer;
        if (!drawer) return;
        const nodes = drawer.querySelectorAll(
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
 * Profile dropdown (Phase 4). Replaces the bare logout button in the top bar.
 *
 * The trigger is the initials avatar; the menu carries identity plus the
 * profile/settings links and the real POST logout form. Uses the same
 * roving-focus + outside-click/Escape pattern as `themeMenu`, but with an
 * arbitrary number of `[data-profile-item]` nodes so the logout submit
 * button participates. Local UI state only.
 */
Alpine.data('profileMenu', () => ({
    open: false,
    current: 0,

    openMenu() {
        this.open = true;
        this.$nextTick(() => this.focusCurrent());
    },

    closeMenu(returnFocus = false) {
        this.open = false;
        if (returnFocus) {
            this.$refs.trigger.focus();
        }
    },

    toggle() {
        this.open ? this.closeMenu(true) : this.openMenu();
    },

    items() {
        return this.$refs.menu
            ? Array.from(this.$refs.menu.querySelectorAll('[data-profile-item]'))
            : [];
    },

    focusCurrent() {
        const nodes = this.items();
        if (nodes.length === 0) return;
        this.current = Math.max(0, Math.min(this.current, nodes.length - 1));
        nodes[this.current].focus();
    },

    move(delta) {
        const nodes = this.items();
        if (nodes.length === 0) return;
        this.current = (this.current + delta + nodes.length) % nodes.length;
        this.focusCurrent();
    },

    trap(event) {
        const nodes = this.items();
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

    onTriggerKeydown(event) {
        if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
            event.preventDefault();
            this.openMenu();
        }
    },
}));

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

/**
 * Dismissible flash message (Requirement 22).
 *
 * Owns only the `visible` boolean behind `x-ui.alert`'s dismissible mode:
 * `x-show` on the alert root tears the whole node (and its `mb-*` margin) out
 * of the flow with an Alpine transition, so no empty container is left behind.
 * There is no auto-dismiss timer — the user closes the message explicitly.
 * Defined here (not inline in Blade) per the project's Alpine-component rule.
 */
Alpine.data('flashMessage', () => ({
    visible: true,

    dismiss() {
        this.visible = false;
    },
}));

/**
 * Server-rendered SVG chart hover tooltip (ADR-037).
 *
 * The chart itself is static, server-rendered inline SVG; this component only
 * positions a styled tooltip when a bar is hovered. It owns local UI state
 * only — no data fetching, no business logic. A native `<title>` on every bar
 * already provides the same information without JavaScript (progressive
 * enhancement), so this component is purely cosmetic.
 *
 * `show()` receives the already-safe display strings from the Blade component
 * (never raw values), so no formatting or interpretation happens here.
 */
Alpine.data('chartTooltip', () => ({
    visible: false,
    label: '',
    value: '',
    detail: '',
    x: 0,
    y: 0,

    show(event, label, value, detail) {
        const rect = this.$root.getBoundingClientRect();
        const target = event.currentTarget.getBoundingClientRect();

        // Position relative to the chart root, above the hovered bar.
        this.x = target.left - rect.left + (target.width / 2);
        this.y = target.top - rect.top;
        this.label = label || '';
        this.value = value || '';
        this.detail = detail || '';
        this.visible = true;
    },

    hide() {
        this.visible = false;
    },
}));

/**
 * Precise local-time footer (Requirement 26, ADR-040).
 *
 * The server emits the projection stamp as UTC ISO-8601 on a `<time datetime>`;
 * this component renders it in the VISITOR'S own timezone/locale as
 * `Last update: H:i dd/mm/yyyy`. Missing/invalid stamps fall back to the
 * server-rendered day-level text (its textContent), never `Invalid Date`.
 */
Alpine.data('statusLocalTime', (options = {}) => ({
    iso: options.iso || '',
    display: '',

    init() {
        this.render();
    },

    render() {
        const text = this.format(this.iso);

        if (text !== null) {
            this.display = 'Last update: ' + text;
        }
    },

    /**
     * Format an ISO-8601 instant in the visitor's locale as `H:i dd/mm/yyyy`,
     * or null when the input is absent/unparseable.
     */
    format(iso) {
        if (! iso) {
            return null;
        }

        const date = new Date(iso);
        if (Number.isNaN(date.getTime())) {
            return null;
        }

        let parts;
        try {
            parts = new Intl.DateTimeFormat(undefined, {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                hour12: false,
            }).formatToParts(date);
        } catch (e) {
            return null;
        }

        const pick = (type) => {
            const part = parts.find((p) => p.type === type);
            return part ? part.value : null;
        };

        let hour = pick('hour');
        const minute = pick('minute');
        const day = pick('day');
        const month = pick('month');
        const year = pick('year');

        // `hour12: false` may yield "24" for midnight in some ICU builds.
        if (hour === '24') {
            hour = '00';
        }

        if (hour && minute && day && month && year) {
            return hour + ':' + minute + ' ' + day + '/' + month + '/' + year;
        }

        return null;
    },
}));

/**
 * Auto-refresh control (Requirement 25, ADR-039).
 *
 * Progressively enhances the server-rendered status page by periodically
 * re-fetching the EXISTING public projection route (`status.json`) — no new
 * endpoint, no real-time infrastructure, and never an outbound probe (the JSON
 * endpoint serves the cached projection only). A successful fetch re-renders
 * the banner + service region in place and updates the footer timestamp.
 *
 * The countdown is computed from a target timestamp (not a naive decrement) so
 * it cannot drift; requests never overlap (in-flight guard); the timer pauses
 * while the document is hidden and resumes on return; timers/listeners are
 * removed in `destroy()` (Alpine's hook for node teardown, including Turbo
 * navigations). The visitor's toggle + interval persist in localStorage.
 */
Alpine.data('statusRefresh', (options = {}) => ({
    jsonUrl: options.jsonUrl || '',
    allowedIntervals: options.allowedIntervals || [60, 300, 600, 1800, 3600],
    defaults: options.defaults || { enabled: false, interval: 60 },
    storageKey: options.storageKey || 'sentinel.status.refresh',
    messages: options.messages || {},

    enabled: false,
    interval: 60,
    loading: false,
    remaining: 0,
    target: 0,
    ticker: null,
    visibilityHandler: null,

    init() {
        this.restore();

        this.visibilityHandler = () => this.onVisibilityChange();
        document.addEventListener('visibilitychange', this.visibilityHandler);

        this.$watch('enabled', (value) => {
            if (value) {
                this.start();
            } else {
                this.stop();
            }
        });

        if (this.enabled) {
            this.start();
        }
    },

    destroy() {
        this.clearTicker();

        if (this.visibilityHandler !== null) {
            document.removeEventListener('visibilitychange', this.visibilityHandler);
            this.visibilityHandler = null;
        }
    },

    toggle(value) {
        this.enabled = Boolean(value);
        this.persist();
    },

    /**
     * Interval changed: persist and reset the countdown to the new cadence.
     */
    applyInterval() {
        const value = Number(this.interval);

        if (this.allowedIntervals.includes(value)) {
            this.interval = value;
        } else {
            this.interval = this.defaults.interval || 60;
        }

        this.persist();

        if (this.enabled) {
            this.start();
        }
    },

    start() {
        this.schedule();
        this.ensureTicker();
        this.tick();
    },

    stop() {
        this.clearTicker();
        this.remaining = 0;
        this.target = 0;
    },

    /**
     * Arm the next refresh for `interval` seconds from now.
     */
    schedule() {
        this.target = Date.now() + this.interval * 1000;
        this.remaining = this.interval * 1000;
    },

    ensureTicker() {
        if (this.ticker !== null) {
            return;
        }

        this.ticker = window.setInterval(() => this.tick(), 1000);
    },

    clearTicker() {
        if (this.ticker !== null) {
            window.clearInterval(this.ticker);
            this.ticker = null;
        }
    },

    tick() {
        if (! this.enabled) {
            return;
        }

        if (this.isHidden()) {
            // Paused while hidden; the deadline is re-armed on return.
            return;
        }

        const msLeft = this.target - Date.now();

        if (msLeft <= 0) {
            this.refresh();
            return;
        }

        this.remaining = msLeft;
    },

    onVisibilityChange() {
        if (! this.enabled) {
            return;
        }

        if (this.isHidden()) {
            this.clearTicker();
            return;
        }

        // Returning to a visible tab: re-arm from now so a long background
        // period cannot trigger an immediate burst, then resume ticking.
        this.schedule();
        this.ensureTicker();
        this.tick();
    },

    isHidden() {
        return document.visibilityState === 'hidden';
    },

    refreshNow() {
        this.refresh();
    },

    /**
     * Fetch the existing public JSON projection and re-render the status
     * region. Overlapping requests are prevented with the `loading` flag.
     */
    refresh() {
        if (this.loading) {
            return;
        }

        if (! this.isHidden()) {
            this.ensureTicker();
        }

        this.loading = true;

        window
            .fetch(this.jsonUrl, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
            .then((response) => {
                if (! response.ok) {
                    throw new Error('status ' + response.status);
                }

                return response.json();
            })
            .then((data) => {
                this.applyPayload(data);
            })
            .catch(() => {
                // A failed refresh is non-fatal: the server-rendered page stays
                // intact and the countdown simply restarts.
            })
            .finally(() => {
                this.loading = false;
                this.restartAfterRefresh();
            });
    },

    restartAfterRefresh() {
        if (this.enabled) {
            this.schedule();

            // Do not spin the ticker up again while the tab is hidden; the
            // `visibilitychange` handler resumes it on return.
            if (! this.isHidden()) {
                this.ensureTicker();
            }
        }
    },

    /**
     * Apply the public DTO to the visible region without a full page load.
     */
    applyPayload(data) {
        if (data === null || typeof data !== 'object') {
            return;
        }

        const banner = document.getElementById('status-banner');
        if (banner && typeof data.banner === 'string') {
            banner.textContent = data.banner;
        }

        const list = document.getElementById('status-services');
        if (list && Array.isArray(data.services)) {
            this.updateServices(list, data.services);
        }

        this.updateTimestamp(document.getElementById('status-last-update'), data.updatedAt);
    },

    /**
     * Update the live text of each server-rendered service card IN PLACE.
     *
     * The cards are rendered server-side; a refresh only needs to refresh the
     * coarse label, the sample-based uptime figure and the day bucket.
     * Rebuilding the nodes would discard the `chartTooltip` instances on the
     * page-level response-time chart, so this mutates the existing DOM only.
     * The response-time chart itself is re-rendered on the next full page load
     * (a reload, or Turbo navigation) rather than mutated here. A service with
     * no matching card is skipped.
     */
    updateServices(list, services) {
        services.forEach((service) => {
            const index = service.opaqueIndex;
            if (typeof index !== 'string' || index === '') {
                return;
            }

            const card = list.querySelector('[data-status-service="' + index + '"]');
            if (!card) {
                return;
            }

            const label = card.querySelector('[data-service-label]');
            if (label && typeof service.publicLabel === 'string') {
                label.textContent = service.publicLabel;
            }

            const uptime = card.querySelector('[data-service-uptime]');
            if (uptime) {
                if (service.uptime && service.uptime.available === true) {
                    uptime.textContent = this.uptimeText(service.uptime);
                } else {
                    uptime.textContent = 'No availability data yet';
                }
            }

            const bucket = card.querySelector('[data-service-bucket]');
            if (bucket && typeof service.dayBucket === 'string') {
                bucket.textContent = 'Updated ' + service.dayBucket;
            }
        });
    },

    /**
     * Format the public-safe uptime figure (`100.00% · 0 failed checks`). The
     * exact response time is never part of the public projection.
     */
    uptimeText(uptime) {
        const percent = typeof uptime.percent === 'number' ? uptime.percent : 0;
        const down = typeof uptime.down === 'number' ? uptime.down : 0;

        return percent.toFixed(2) + '% \u00b7 ' + down + ' failed checks';
    },

    /**
     * Update the footer `<time>` element: the machine-readable `datetime`
     * attribute stays UTC ISO-8601, the visible text re-renders in local time.
     */
    updateTimestamp(element, iso) {
        if (! element || typeof iso !== 'string' || iso === '') {
            return;
        }

        const formatted = this.formatInstant(iso);
        if (formatted === null) {
            return;
        }

        element.setAttribute('datetime', iso);
        element.textContent = 'Last update: ' + formatted;
    },

    formatInstant(iso) {
        const date = new Date(iso);
        if (Number.isNaN(date.getTime())) {
            return null;
        }

        try {
            const parts = new Intl.DateTimeFormat(undefined, {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                hour12: false,
            }).formatToParts(date);

            const pick = (type) => {
                const part = parts.find((p) => p.type === type);
                return part ? part.value : null;
            };

            let hour = pick('hour');
            const minute = pick('minute');
            const day = pick('day');
            const month = pick('month');
            const year = pick('year');

            if (hour === '24') {
                hour = '00';
            }

            if (hour && minute && day && month && year) {
                return hour + ':' + minute + ' ' + day + '/' + month + '/' + year;
            }
        } catch (e) {
            return null;
        }

        return null;
    },

    get countdownDisplay() {
        if (! this.enabled) {
            return '';
        }

        if (this.isHidden()) {
            return this.messages.paused || 'Paused';
        }

        const total = Math.max(0, Math.ceil(this.remaining / 1000));
        const minutes = String(Math.floor(total / 60)).padStart(2, '0');
        const seconds = String(total % 60).padStart(2, '0');

        return (this.messages.nextRefreshIn || 'Next refresh in:') + ' ' + minutes + ':' + seconds;
    },

    restore() {
        let saved = null;
        try {
            const raw = window.localStorage.getItem(this.storageKey);
            saved = raw ? JSON.parse(raw) : null;
        } catch (e) {
            saved = null;
        }

        if (saved && typeof saved === 'object') {
            this.enabled = saved.enabled === true;

            const value = Number(saved.interval);
            this.interval = this.allowedIntervals.includes(value)
                ? value
                : (this.defaults.interval || 60);
        } else {
            this.enabled = this.defaults.enabled === true;
            this.interval = this.defaults.interval || 60;
        }
    },

    persist() {
        try {
            window.localStorage.setItem(this.storageKey, JSON.stringify({
                enabled: this.enabled,
                interval: this.interval,
            }));
        } catch (e) {
            // Private mode / storage disabled: state still works for this page.
        }
    },
}));

/**
 * In-app notification centre — bell + dropdown (Requirement 28 / Phase H,
 * ADR-038, NOTIFICATIONS.md §15).
 *
 * A thin client over the EXISTING Phase G JSON endpoints
 * (`admin.notifications.in-app.*`). It owns only local UI state (open/closed,
 * the fetched rows, the unread count) and never generates notifications —
 * generation is backend (ADR-038). It is NOT real-time: it polls the cheap
 * unread-count endpoint on a fixed interval and (lazily) loads the recent list
 * only when the panel is first opened.
 *
 * Defined here — never inline in a Blade `x-data` attribute — per AGENTS.md §7.
 * Non-overlapping requests are guarded with in-flight flags; polling pauses
 * while the document is hidden and resumes on return; the interval and the
 * visibility listener are torn down in `destroy()` (Alpine's node-teardown
 * hook, which also fires on Turbo navigations).
 */
Alpine.data('notificationCenter', (options = {}) => ({
    indexUrl: options.indexUrl || '',
    unreadCountUrl: options.unreadCountUrl || '',
    readAllUrl: options.readAllUrl || '',
    markReadUrlTemplate: options.markReadUrlTemplate || '',
    fullPageUrl: options.fullPageUrl || '',
    csrfToken: options.csrfToken || '',
    pollInterval: options.pollInterval || 60000,
    messages: options.messages || {},

    open: false,
    unreadCount: 0,
    items: [],
    loading: false,
    error: false,
    busy: false,
    loadedOnce: false,
    previousFocus: null,
    pollTimer: null,
    visibilityHandler: null,
    countInFlight: false,
    listInFlight: false,

    init() {
        this.pollTimer = window.setInterval(() => this.poll(), this.pollInterval);

        this.visibilityHandler = () => this.onVisibilityChange();
        document.addEventListener('visibilitychange', this.visibilityHandler);

        // Seed the badge without opening the panel (one cheap count request).
        this.poll();
    },

    destroy() {
        if (this.pollTimer !== null) {
            window.clearInterval(this.pollTimer);
            this.pollTimer = null;
        }

        if (this.visibilityHandler !== null) {
            document.removeEventListener('visibilitychange', this.visibilityHandler);
            this.visibilityHandler = null;
        }
    },

    openMenu() {
        this.previousFocus = document.activeElement;
        this.open = true;
        this.load();
    },

    closeMenu(returnFocus = false) {
        this.open = false;

        if (returnFocus) {
            this.$refs.trigger.focus();
        }
    },

    toggle() {
        this.open ? this.closeMenu(true) : this.openMenu();
    },

    onTriggerKeydown(event) {
        if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
            event.preventDefault();
            this.openMenu();
        }
    },

    onVisibilityChange() {
        if (document.visibilityState === 'hidden') {
            if (this.pollTimer !== null) {
                window.clearInterval(this.pollTimer);
                this.pollTimer = null;
            }

            return;
        }

        if (this.pollTimer === null) {
            this.pollTimer = window.setInterval(() => this.poll(), this.pollInterval);
        }

        this.poll();
    },

    poll() {
        if (document.visibilityState === 'hidden') {
            return;
        }

        this.refreshCount();
    },

    /**
     * Cheap unread-count poll. Non-overlapping (in-flight guard); a failure is
     * non-fatal and the next tick retries.
     */
    refreshCount() {
        if (this.countInFlight) {
            return;
        }

        this.countInFlight = true;

        window
            .fetch(this.unreadCountUrl, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error('count'))))
            .then((data) => this.applyCount(data ? data.unread_count : undefined))
            .catch(() => {
                // Non-fatal: the server-rendered shell stays intact.
            })
            .finally(() => {
                this.countInFlight = false;
            });
    },

    /**
     * Load the recent list (lazily, on first open). Non-overlapping.
     */
    load() {
        if (this.listInFlight) {
            return;
        }

        this.listInFlight = true;
        this.loading = true;
        this.error = false;

        window
            .fetch(this.indexUrl, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
            .then((response) => {
                if (! response.ok) {
                    throw new Error('list');
                }

                return response.json();
            })
            .then((data) => {
                this.items = this.mapItems(Array.isArray(data.data) ? data.data : []);
                this.applyCount(data.unread_count);
                this.loadedOnce = true;
            })
            .catch(() => {
                this.error = true;
            })
            .finally(() => {
                this.loading = false;
                this.listInFlight = false;
            });
    },

    /**
     * Map the documented JSON contract to display rows. `link_url` is only
     * accepted when it is a valid internal relative path (never an external
     * URL), so a tampered payload cannot become an open-redirect surface.
     */
    mapItems(rows) {
        return rows.map((row) => ({
            id: row.id,
            title: typeof row.title === 'string' ? row.title : '',
            body: typeof row.body === 'string' ? row.body : '',
            read: row.read_at !== null && row.read_at !== undefined,
            link: this.safeLink(row.link_url),
            time: this.relativeTime(row.created_at),
            icon: this.iconFor(row.severity),
            iconColor: this.iconColorFor(row.severity),
        }));
    },

    iconFor(severity) {
        return {
            success: 'check-circle',
            warning: 'exclamation-triangle',
            danger: 'x-circle',
        }[severity] || 'bell';
    },

    iconColorFor(severity) {
        return {
            info: 'text-info',
            success: 'text-success',
            warning: 'text-warning',
            danger: 'text-danger',
        }[severity] || 'text-text-subtle';
    },

    safeLink(url) {
        if (typeof url !== 'string' || url === '') {
            return null;
        }

        if (url[0] !== '/' || url.startsWith('//') || url.includes('\\') || url.includes('://')) {
            return null;
        }

        return url;
    },

    relativeTime(iso) {
        if (typeof iso !== 'string' || iso === '') {
            return '';
        }

        const date = new Date(iso);
        if (Number.isNaN(date.getTime())) {
            return '';
        }

        const seconds = Math.floor((Date.now() - date.getTime()) / 1000);

        if (seconds < 60) {
            return 'just now';
        }

        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) {
            return minutes + 'm ago';
        }

        const hours = Math.floor(minutes / 60);
        if (hours < 24) {
            return hours + 'h ago';
        }

        const days = Math.floor(hours / 24);
        if (days < 7) {
            return days + 'd ago';
        }

        return date.toLocaleDateString();
    },

    /**
     * Mark one row read. On success the count is updated IMMEDIATELY from the
     * server response (never assumed); on failure the row is left untouched and
     * the error state is shown honestly.
     */
    async markRead(id) {
        if (this.busy) {
            return;
        }

        const item = this.items.find((entry) => entry.id === id);
        if (! item || item.read) {
            return;
        }

        this.busy = true;
        this.error = false;

        try {
            const response = await window.fetch(this.markReadUrl(id), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                credentials: 'same-origin',
            });

            if (! response.ok) {
                throw new Error('mark-read');
            }

            const data = await response.json();
            item.read = true;
            this.applyCount(data.unread_count);
        } catch (e) {
            this.error = true;
        } finally {
            this.busy = false;
        }
    },

    async markAllRead() {
        if (this.busy || this.unreadCount === 0) {
            return;
        }

        this.busy = true;
        this.error = false;

        try {
            const response = await window.fetch(this.readAllUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                credentials: 'same-origin',
            });

            if (! response.ok) {
                throw new Error('read-all');
            }

            const data = await response.json();
            this.items.forEach((entry) => {
                entry.read = true;
            });
            this.applyCount(data.unread_count);
        } catch (e) {
            this.error = true;
        } finally {
            this.busy = false;
        }
    },

    applyCount(value) {
        if (typeof value === 'number') {
            this.unreadCount = value;
        }
    },

    markReadUrl(id) {
        return this.markReadUrlTemplate.replace('__ID__', String(id));
    },

    get badgeText() {
        return this.unreadCount > 99 ? '99+' : String(this.unreadCount);
    },

    get countLabel() {
        return this.unreadCount + ' ' + (this.messages.unread || 'unread');
    },
}));

/**
 * Full-page notification list enhancement (Requirement 28 / Phase H, ADR-038).
 *
 * The page is fully server-rendered and correct without JavaScript; this
 * component only upgrades the "Mark as read" / "Mark all as read" controls to
 * the EXISTING Phase G JSON endpoints and updates the visible unread count in
 * place. A failed request surfaces an honest inline error and never pretends
 * success.
 */
Alpine.data('notificationList', (options = {}) => ({
    readAllUrl: options.readAllUrl || '',
    csrfToken: options.csrfToken || '',
    messages: options.messages || {},
    busy: false,

    async markRead(event) {
        if (this.busy) {
            return;
        }

        const button = event.currentTarget;
        const url = button.dataset.url;
        if (! url) {
            return;
        }

        this.busy = true;
        this.clearError();

        try {
            const response = await window.fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                credentials: 'same-origin',
            });

            if (! response.ok) {
                throw new Error('mark-read');
            }

            const data = await response.json();
            this.markRowRead(button.closest('[data-notification-row]'));
            button.remove();
            this.updateCount(data.unread_count);
        } catch (e) {
            this.showError(this.messages.failed);
        } finally {
            this.busy = false;
        }
    },

    async markAllRead() {
        if (this.busy) {
            return;
        }

        this.busy = true;
        this.clearError();

        try {
            const response = await window.fetch(this.readAllUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                credentials: 'same-origin',
            });

            if (! response.ok) {
                throw new Error('read-all');
            }

            const data = await response.json();

            this.$root.querySelectorAll('[data-notification-row]').forEach((row) => this.markRowRead(row));
            this.$root.querySelectorAll('[data-notification-read]').forEach((el) => el.remove());

            this.updateCount(data.unread_count);
        } catch (e) {
            this.showError(this.messages.failedAll);
        } finally {
            this.busy = false;
        }
    },

    markRowRead(row) {
        if (! row) {
            return;
        }

        row.dataset.read = 'true';
        row.classList.remove('bg-info-muted/40');

        const unread = row.querySelector('[data-notification-status="unread"]');
        const read = row.querySelector('[data-notification-status="read"]');

        if (unread) {
            unread.classList.add('hidden');
        }

        if (read) {
            read.classList.remove('hidden');
        }
    },

    updateCount(value) {
        if (typeof value !== 'number') {
            return;
        }

        this.$root.querySelectorAll('[data-notification-list-count]').forEach((el) => {
            el.textContent = value + ' ' + (this.messages.unread || 'unread');
        });
    },

    showError(message) {
        const box = this.$root.querySelector('[data-notification-list-error]');
        if (box) {
            box.textContent = message || '';
            box.classList.remove('hidden');
        }
    },

    clearError() {
        const box = this.$root.querySelector('[data-notification-list-error]');
        if (box) {
            box.textContent = '';
            box.classList.add('hidden');
        }
    },
}));

/**
 * Documentation shell (Laravel-framework-docs style).
 *
 * Owns three concerns for the dedicated docs page
 * (resources/views/components/docs-layout.blade.php):
 *
 *  1. Client-side SEARCH — filters both the grouped sidebar links and the
 *     "On this page" TOC entries, and hides content `<section data-doc-section>`
 *     blocks whose `data-search` haystack does not match. Purely local: no
 *     network round-trip (server search is explicitly out of scope).
 *  2. SCROLL-SPY — highlights the current section in the sidebar + TOC using an
 *     IntersectionObserver, falling back to a scroll handler when unsupported.
 *  3. Mobile DRAWER — off-canvas sidebar + TOC with focus trap and Escape,
 *     mirroring the admin `sidebar` component.
 *
 * All state is local UI; nothing is persisted.
 */
Alpine.data('docsShell', () => ({
    q: '',
    visibleCount: 0,
    sidebarOpen: false,
    previousFocus: null,
    active: '',
    observer: null,
    sections: [],

    init() {
        // Resolve which section ids exist on this page, once.
        this.sections = Array.from(document.querySelectorAll('[data-doc-section]')).map(
            (el) => el.id
        );

        this.$watch('q', () => this.applyFilter());
        this.applyFilter();
        this.spy();

        // Keep the hash link in sync without fighting smooth scrolling.
        window.addEventListener('hashchange', () => this.onHash());
        this.onHash();
    },

    // --- Search -----------------------------------------------------------

    matches(haystack) {
        const q = this.q.trim().toLowerCase();
        if (q === '') return true;
        return (haystack || '').toLowerCase().includes(q);
    },

    applyFilter() {
        let visible = 0;

        document.querySelectorAll('[data-doc-section]').forEach((section) => {
            const hit = this.matches(section.dataset.search || '');
            section.style.display = hit ? '' : 'none';
            if (hit) visible += 1;
        });

        document.querySelectorAll('[data-docs-nav-link], [data-docs-toc-link]').forEach((link) => {
            const hit = this.matches(link.dataset.search || '');
            link.style.display = hit ? '' : 'none';
            const li = link.closest('li');
            if (li) li.style.display = hit ? '' : 'none';
        });

        this.visibleCount = visible;
    },

    // --- Scroll spy -------------------------------------------------------

    spy() {
        if (this.sections.length === 0) return;

        if ('IntersectionObserver' in window) {
            this.observer = new IntersectionObserver(
                (entries) => {
                    // Pick the topmost intersecting section.
                    const visible = entries.filter((e) => e.isIntersecting);
                    if (visible.length === 0) return;
                    this.setActive(visible[0].target.id);
                },
                { rootMargin: '-80px 0px -70% 0px', threshold: 0 }
            );

            this.sections.forEach((id) => {
                const el = document.getElementById(id);
                if (el) this.observer.observe(el);
            });
            return;
        }

        // Fallback: passive scroll handler.
        window.addEventListener('scroll', () => this.onScroll(), { passive: true });
        this.onScroll();
    },

    onScroll() {
        for (const id of this.sections) {
            const el = document.getElementById(id);
            if (!el) continue;
            if (el.getBoundingClientRect().top <= 96) {
                this.setActive(id);
            }
        }
    },

    setActive(id) {
        if (this.active === id) return;
        this.active = id;

        document.querySelectorAll('[data-docs-nav-link], [data-docs-toc-link]').forEach((link) => {
            const isActive = link.dataset.target === id;
            link.classList.toggle('is-active', isActive);
            if (isActive) {
                link.setAttribute('aria-current', 'true');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    },

    onHash() {
        const id = (window.location.hash || '').replace('#', '');
        if (id && this.sections.includes(id)) {
            this.setActive(id);
        }
    },

    // --- Drawer -----------------------------------------------------------

    openDrawer() {
        this.previousFocus = document.activeElement;
        this.sidebarOpen = true;
        this.$nextTick(() => {
            const drawer = this.$refs.drawer;
            if (!drawer) return;
            const focusable = drawer.querySelector(
                'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])'
            );
            if (focusable) focusable.focus();
            else drawer.focus();
        });
    },

    closeDrawer(returnFocus = true) {
        this.sidebarOpen = false;
        if (returnFocus && this.previousFocus && typeof this.previousFocus.focus === 'function') {
            this.previousFocus.focus();
        }
    },

    trap(event) {
        const drawer = this.$refs.drawer;
        if (!drawer) return;
        const nodes = drawer.querySelectorAll(
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

Alpine.start();
