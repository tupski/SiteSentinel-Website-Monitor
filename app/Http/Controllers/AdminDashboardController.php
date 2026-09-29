<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * Admin shell dashboard placeholder (PLAN.md Phase 2, AC-2-07).
 *
 * Renders two clearly separated areas — availability and security — as the
 * structural placeholder for later phases. No monitoring data yet.
 */
final class AdminDashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard');
    }
}
