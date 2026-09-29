<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Phase 1: only the health endpoint is public beyond the placeholder root
// (PLAN.md Phase 1 security note: "No public route may be created in this
// phase beyond the health endpoint; health must not expose secrets").
Route::get('/health', HealthController::class)->name('health');

Route::get('/', function () {
    return view('welcome');
});
