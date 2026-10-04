<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Admin documentation (Phase 7).
 *
 * Renders the in-app operator guide. Read-only: no persistence, no
 * configuration is written from this screen. The content is static Blade
 * documenting only features that exist in this codebase.
 */
final class DocumentationController extends Controller
{
    public function index(): View
    {
        return view('admin.documentation.index');
    }
}
