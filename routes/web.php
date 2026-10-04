<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\IncidentController;
use App\Http\Controllers\Admin\NotificationChannelController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\PushSubscriptionController;
use App\Http\Controllers\Admin\StatusPageController as AdminStatusPageController;
use App\Http\Controllers\Admin\StatusPageSettingController;
use App\Http\Controllers\Admin\WebsiteController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\StatusPageController;
use Illuminate\Support\Facades\Route;

// Public: health endpoint only (Phase 1 contract preserved).
Route::get('/health', HealthController::class)->name('health');

// Guest routes (login + password reset). Authenticated admins are bounced to the shell.
Route::middleware('guest')->group(function (): void {
    // Login lives at `/` per the canonical route map (PRD.md §22: `/` = login).
    Route::get('/', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/', [LoginController::class, 'login'])->middleware('throttle:60,1')->name('login.attempt');

    Route::get('/password-reset', [PasswordResetController::class, 'requestForm'])->name('password.request');
    Route::post('/password-reset', [PasswordResetController::class, 'sendResetLink'])->middleware('throttle:10,1')->name('password.email');
    Route::get('/password-reset/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
    Route::post('/password-reset/update', [PasswordResetController::class, 'reset'])->name('password.update');
});

// Authenticated actions
Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// Admin shell — every route here requires auth + active + admin (EnsureAdmin),
// enforced for ALL HTTP methods (AC-2-02 / SECURITY.md §3.2).
// `session.timeouts` enforces the SECURITY.md §2.5 idle (30 min) + absolute
// (8 h) windows before any admin action runs.
// NOTE: no registration route exists anywhere (AC-2-03).
Route::prefix('admin')->name('admin.')->middleware(['auth', 'session.timeouts', 'admin'])->group(function (): void {
    Route::get('/', AdminDashboardController::class)->name('dashboard');

    // Phase 3: monitored website CRUD
    Route::resource('websites', WebsiteController::class)->except(['show']);
    Route::post('websites/{website}/toggle', [WebsiteController::class, 'toggle'])->name('websites.toggle');

    // Plan S2: manual "run check" (queues only — never an inline probe) + bulk actions.
    // FR-24: the manual trigger is rate-limited (30/min per admin). Bulk routes use
    // fixed paths so `websites/bulk` can never be read as a {website} id.
    Route::post('websites/{website}/check', [WebsiteController::class, 'runCheck'])->middleware('throttle:30,1')->name('websites.check');
    Route::post('websites/bulk/enable', [WebsiteController::class, 'bulkEnable'])->name('websites.bulk.enable');
    Route::post('websites/bulk/disable', [WebsiteController::class, 'bulkDisable'])->name('websites.bulk.disable');
    Route::post('websites/bulk/delete', [WebsiteController::class, 'bulkDelete'])->name('websites.bulk.delete');

    // Phase 6: incident lifecycle
    Route::get('incidents', [IncidentController::class, 'index'])->name('incidents.index');
    Route::get('incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
    Route::post('incidents/{incident}/acknowledge', [IncidentController::class, 'acknowledge'])->name('incidents.acknowledge');
    Route::post('incidents/{incident}/resolve', [IncidentController::class, 'resolve'])->name('incidents.resolve');

    // Phase 7 / Plan S3: notification channels + delivery logs (admin only, CSRF via web group).
    Route::get('notifications', [NotificationChannelController::class, 'index'])->name('notifications.index');
    Route::get('notifications/create', [NotificationChannelController::class, 'create'])->name('notifications.create');
    Route::post('notifications', [NotificationChannelController::class, 'store'])->name('notifications.store');
    // Test-before-save: posts an unsaved channel config, sends via the provider
    // registry, and never persists a channel. Fixed path so it can never be read
    // as a {channel} id. Rate-limited + CSRF-protected (AGENTS.md §12).
    Route::post('notifications/test', [NotificationChannelController::class, 'test'])->middleware('throttle:30,1')->name('notifications.test');
    Route::get('notifications/{channel}/edit', [NotificationChannelController::class, 'edit'])->name('notifications.edit');
    Route::put('notifications/{channel}', [NotificationChannelController::class, 'update'])->name('notifications.update');
    Route::delete('notifications/{channel}', [NotificationChannelController::class, 'destroy'])->name('notifications.destroy');
    Route::post('notifications/{channel}/test-send', [NotificationChannelController::class, 'testSend'])->middleware('throttle:10,1')->name('notifications.test-send');

    Route::get('notification-logs', [NotificationChannelController::class, 'logs'])->name('notification-logs.index');

    // Phase 11 (ADR-032): Browser Push subscription lifecycle + test-send.
    // Auth + admin + session timeouts + CSRF via the group; `test` is
    // rate-limited. The VAPID private key and subscription material are never
    // echoed (SECURITY.md §4).
    Route::post('push/subscribe', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
    Route::delete('push/unsubscribe', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');
    Route::post('push/test', [PushSubscriptionController::class, 'test'])->middleware('throttle:30,1')->name('push.test');

    // Phase 5: admin profile (identity + password). Same auth + session + admin
    // gate as every other admin route (AC-2-02). Passwords are verified and
    // hashed server-side; the forms never re-render a password value.
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    // Phase 11 (ADR-031): multi-page CRUD. Delete is guarded in the controller
    // (never the last/default page; websites fall back via ON DELETE SET NULL).
    Route::resource('status-pages', AdminStatusPageController::class)->except(['show']);

    // Legacy Phase 8 singleton settings — retained for one release (DATABASE.md
    // §3.18). Editing still writes through to the default page.
    Route::get('status-settings', [StatusPageSettingController::class, 'edit'])->name('status-settings.edit');
    Route::put('status-settings', [StatusPageSettingController::class, 'update'])->name('status-settings.update');
});

// Public status pages (Phase 8 → Phase 11, ADR-031). Same gate for HTML + JSON.
// The page is route-model-bound by its unique slug; the legacy slugless
// `/status` 302-redirects to the default page.
Route::get('/status', [StatusPageController::class, 'legacy'])->name('status.legacy');
Route::get('/status.json', [StatusPageController::class, 'jsonLegacy'])->name('status.json-legacy');
// Register the `.json` variant before the bare-slug route so the literal
// suffix wins over a greedy `{statusPage}` match.
Route::get('/status/{statusPage}.json', [StatusPageController::class, 'json'])->name('status.json');
Route::get('/status/{statusPage}', [StatusPageController::class, 'show'])->name('status.show');
Route::post('/status/{statusPage}/unlock', [StatusPageController::class, 'unlock'])->middleware('throttle.status-unlock')->name('status.unlock');
Route::post('/status/{statusPage}/logout', [StatusPageController::class, 'logout'])->name('status.logout');
