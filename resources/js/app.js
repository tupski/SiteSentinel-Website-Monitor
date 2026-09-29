// SiteSentinel frontend entry — Turbo (SPA-free navigation, ADR-002) + Alpine (local state only)
import '@hotwired/turbo';
import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();
