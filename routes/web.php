<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\IncidentController;
use App\Http\Controllers\Admin\NotificationChannelController;
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
// NOTE: no registration route exists anywhere (AC-2-03).
Route::prefix('admin')->name('admin.')->middleware(['auth', 'admin'])->group(function (): void {
    Route::get('/', AdminDashboardController::class)->name('dashboard');

    // Phase 3: monitored website CRUD
    Route::resource('websites', WebsiteController::class)->except(['show']);
    Route::post('websites/{website}/toggle', [WebsiteController::class, 'toggle'])->name('websites.toggle');

    // Phase 6: incident lifecycle
    Route::get('incidents', [IncidentController::class, 'index'])->name('incidents.index');
    Route::get('incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
    Route::post('incidents/{incident}/acknowledge', [IncidentController::class, 'acknowledge'])->name('incidents.acknowledge');
    Route::post('incidents/{incident}/resolve', [IncidentController::class, 'resolve'])->name('incidents.resolve');

    // Phase 7: notification channels + delivery logs (admin only, CSRF via web group).
    Route::get('notifications', [NotificationChannelController::class, 'index'])->name('notifications.index');
    Route::get('notifications/create', [NotificationChannelController::class, 'create'])->name('notifications.create');
    Route::post('notifications', [NotificationChannelController::class, 'store'])->name('notifications.store');
    Route::get('notifications/{channel}/edit', [NotificationChannelController::class, 'edit'])->name('notifications.edit');
    Route::put('notifications/{channel}', [NotificationChannelController::class, 'update'])->name('notifications.update');
    Route::delete('notifications/{channel}', [NotificationChannelController::class, 'destroy'])->name('notifications.destroy');
    Route::post('notifications/{channel}/test-send', [NotificationChannelController::class, 'testSend'])->middleware('throttle:10,1')->name('notifications.test-send');

    Route::get('notification-logs', [NotificationChannelController::class, 'logs'])->name('notification-logs.index');

    Route::get('status-settings', [StatusPageSettingController::class, 'edit'])->name('status-settings.edit');
    Route::put('status-settings', [StatusPageSettingController::class, 'update'])->name('status-settings.update');
});

// Public status page (Phase 8). Same gate for HTML + JSON.
Route::get('/status', [StatusPageController::class, 'show'])->name('status.show');
Route::get('/status.json', [StatusPageController::class, 'json'])->name('status.json');
Route::post('/status/unlock', [StatusPageController::class, 'unlock'])->middleware('throttle.status-unlock')->name('status.unlock');
Route::post('/status/logout', [StatusPageController::class, 'logout'])->name('status.logout');
