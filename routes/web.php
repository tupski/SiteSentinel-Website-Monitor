<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\WebsiteController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\HealthController;
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
});
