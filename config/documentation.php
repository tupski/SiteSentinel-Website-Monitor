<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Documentation navigation
|--------------------------------------------------------------------------
|
| The in-app operator guide is rendered in a Laravel-framework-docs style
| shell (dedicated layout, grouped left sidebar, per-page "On this page" TOC
| and `#` anchors). This file is the single source of truth for the grouped
| sidebar: the docs index view derives both its navigation and its content
| anchors from it, and a regression test asserts the two never drift.
|
| Each entry documents a real, shipped capability only — the guide describes
| the product as deployed, never a roadmap. `group` labels mirror Laravel's
| docs grouping vocabulary (Prologue, Getting Started, The Basics, Digging
| Deeper, Security, …) applied to SiteSentinel.
|
| The `version` label is a static, presentational string (there is no semver
| released yet — the project tags `0.1.0` for the docs milestone). It drives
| the docs header version selector; it is deliberately NOT a persisted
| setting (see DECISIONS.md / CHANGELOG for the rationale).
|
*/

return [

    // Presentational version label shown in the docs header + selector.
    'version' => '0.1.x',

    // Canned version choices for the header dropdown. `current` marks the one
    // matching `version` above; the others are informational (older/newer
    // lines do not exist yet, so they are disabled placeholders — see view).
    'versions' => [
        ['label' => '0.1.x', 'current' => true],
        ['label' => 'main (unreleased)', 'current' => false],
    ],

    /*
     | Grouped navigation. `id` is the anchor slug used by `#heading` links and
     | the right-hand TOC; it MUST match the `doc-section` partial it points at.
     */
    'groups' => [
        [
            'label' => 'Prologue',
            'items' => [
                ['id' => 'release-notes', 'title' => 'Release notes'],
                ['id' => 'about', 'title' => 'About SiteSentinel'],
            ],
        ],
        [
            'label' => 'Getting Started',
            'items' => [
                ['id' => 'getting-started', 'title' => 'Getting started'],
                ['id' => 'telegram-bot-setup', 'title' => 'Telegram bot setup'],
                ['id' => 'troubleshooting', 'title' => 'Troubleshooting'],
            ],
        ],
        [
            'label' => 'The Basics',
            'items' => [
                ['id' => 'navigation', 'title' => 'Navigation'],
                ['id' => 'dashboard', 'title' => 'Dashboard'],
                ['id' => 'websites', 'title' => 'Websites'],
                ['id' => 'incidents', 'title' => 'Incidents'],
            ],
        ],
        [
            'label' => 'Digging Deeper',
            'items' => [
                ['id' => 'monitoring-engine', 'title' => 'Monitoring engine'],
                ['id' => 'detection-model', 'title' => 'Detection model'],
                ['id' => 'notifications', 'title' => 'Notifications'],
                ['id' => 'status-pages', 'title' => 'Status pages'],
            ],
        ],
        [
            'label' => 'Security',
            'items' => [
                ['id' => 'security-model', 'title' => 'Security model'],
            ],
        ],
        [
            'label' => 'Database & Operations',
            'items' => [
                ['id' => 'settings', 'title' => 'System settings'],
                ['id' => 'profile', 'title' => 'Profile & password'],
            ],
        ],
    ],

];
