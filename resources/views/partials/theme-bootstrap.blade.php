<?php
/**
 * No-FOUC theme bootstrap (ADR-033). Inlined in <head> so the resolved theme is
 * applied to <html> before first paint. Reads the cookie first (server-renderable),
 * then localStorage, otherwise follows the OS preference.
 */
?>
<script>
    (function () {
        try {
            var match = document.cookie.match(/(?:^|; )theme=([^;]+)/);
            var cookie = match ? decodeURIComponent(match[1]) : null;
            var stored = null;
            try { stored = window.localStorage.getItem('theme'); } catch (e) {}
            var pref = cookie || stored || 'system';
            if (pref !== 'light' && pref !== 'dark' && pref !== 'system') pref = 'system';
            var dark = pref === 'dark' ||
                (pref === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            var root = document.documentElement;
            root.classList.toggle('dark', dark);
            root.setAttribute('data-theme', dark ? 'dark' : 'light');
            root.setAttribute('data-theme-preference', pref);
        } catch (e) {}
    })();
</script>
