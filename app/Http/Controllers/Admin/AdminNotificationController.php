<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use App\Models\User;
use App\Services\Notifications\AdminNotificationService;
use App\Support\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * In-app admin notification centre data + mark-read API (Phase G, ADR-038,
 * NOTIFICATIONS.md §15).
 *
 * Phase H owns the bell/dropdown/full-page UI. This controller exposes only the
 * minimal, admin-only data + mutation surface the UI consumes, always scoped to
 * the **authenticated admin's own rows** (ownership is enforced here; the project
 * uses no policies — authorization lives in the controller and is proven by the
 * IDOR/authz feature tests).
 *
 * Response shape is dual-mode and consistent across all actions: JSON for
 * AJAX/Turbo (`expectsJson()`), otherwise a redirect back with a flash so the
 * endpoints degrade gracefully for a non-JS form post.
 */
final class AdminNotificationController extends Controller
{
    /** Default page size for the list endpoint (Phase H may pass ?per_page). */
    private const DEFAULT_PER_PAGE = 10;

    private const MAX_PER_PAGE = 50;

    public function __construct(
        private readonly AdminNotificationService $service,
    ) {}

    /**
     * `GET /admin/notifications/in-app` — recent notifications + unread count.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $perPage = $this->perPage($request);

        $query = $user->adminNotifications()->orderByDesc('created_at')->orderByDesc('id');

        $total = (clone $query)->count();

        $notifications = $query
            ->limit($perPage)
            ->get()
            ->map(fn (AdminNotification $notification): array => $this->serialize($notification))
            ->all();

        return response()->json([
            'data' => $notifications,
            'unread_count' => $this->service->unreadCount($user->getKey()),
            'meta' => [
                'per_page' => $perPage,
                'returned' => count($notifications),
                'total' => $total,
            ],
        ]);
    }

    /**
     * `GET /admin/notifications/in-app/page` — full-page, server-rendered list
     * (Requirement 28 / Phase H). Distinct from the outbound-channel
     * `admin.notifications.index` page; it reuses the SAME per-admin query the
     * JSON `index` action serves, so the Alpine-enhanced page and the JSON
     * source never diverge.
     *
     * Filtering by `status` (`unread`/`read`/`all`) and `type` is applied with
     * a whitelist; pagination reuses the shared `x-per-page` conventions.
     * The view is complete without JavaScript (AGENTS.md §7).
     */
    public function page(Request $request): View
    {
        $user = $this->user($request);

        // A plain Eloquent Builder (not the relation) so the shared per-page
        // helper can clone/count it; ownership is the explicit `user_id` scope.
        $query = AdminNotification::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $status = (string) $request->query('status', 'all');
        if ($status === 'unread') {
            $query->whereNull('read_at');
        } elseif ($status === 'read') {
            $query->whereNotNull('read_at');
        } else {
            $status = 'all';
        }

        $type = (string) $request->query('type', '');
        if ($type !== '' && in_array($type, AdminNotification::types(), true)) {
            $query->where('type', $type);
        } else {
            $type = '';
        }

        $notifications = $query
            ->paginate(PerPage::sizeFor($query, $request))
            ->withQueryString();

        // Defence-in-depth for the view: expose `safe_link` (the row's link_url
        // only when it is still a valid internal relative path) so the Blade
        // template never has to trust the raw payload or re-implement the rule.
        $notifications->through(function (AdminNotification $notification): AdminNotification {
            $notification->setAttribute(
                'safe_link',
                AdminNotificationService::isValidInternalPath((string) $notification->link_url)
                    ? $notification->link_url
                    : null,
            );

            return $notification;
        });

        return view('admin.notifications.in-app.index', [
            'notifications' => $notifications,
            'unreadCount' => $this->service->unreadCount($user->getKey()),
            'status' => $status,
            'type' => $type,
            'types' => AdminNotification::types(),
        ]);
    }

    /**
     * `GET /admin/notifications/in-app/unread-count` — cheap polling endpoint.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'unread_count' => $this->service->unreadCount($user->getKey()),
        ]);
    }

    /**
     * `POST /admin/notifications/in-app/{adminNotification}/read` — mark one read.
     *
     * The row is resolved through the authenticated admin's own relation, so
     * another admin's notification yields a 404 (ownership enforced; no IDOR).
     */
    public function markRead(Request $request, int $adminNotification): JsonResponse|RedirectResponse
    {
        $user = $this->user($request);

        $notification = $user->adminNotifications()->findOrFail($adminNotification);

        $notification->markAsRead();

        return $this->respond($request, __('Notification marked as read.'), $user);
    }

    /**
     * `POST /admin/notifications/in-app/read-all` — mark all of the
     * authenticated admin's unread rows read (never another admin's).
     */
    public function markAllRead(Request $request): JsonResponse|RedirectResponse
    {
        $user = $this->user($request);

        $affected = $user->adminNotifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->respond($request, __('All notifications marked as read.'), $user, $affected);
    }

    /*
    |----------------------------------------------------------------------
    | Internals
    |----------------------------------------------------------------------
    */

    /**
     * Dual-mode response: JSON for AJAX/Turbo (with the updated unread count),
     * otherwise a redirect back with a flash (graceful non-JS form post).
     */
    private function respond(Request $request, string $message, User $user, ?int $affected = null): JsonResponse|RedirectResponse
    {
        $unread = $this->service->unreadCount($user->getKey());

        if ($request->expectsJson()) {
            $payload = [
                'ok' => true,
                'message' => $message,
                'unread_count' => $unread,
            ];

            if ($affected !== null) {
                $payload['affected'] = $affected;
            }

            return response()->json($payload);
        }

        return back()->with('status', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(AdminNotification $notification): array
    {
        return [
            'id' => (int) $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'body' => $notification->body,
            'severity' => $notification->severity,
            'link_url' => $notification->link_url,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }

    private function perPage(Request $request): int
    {
        $raw = $request->query('per_page');

        if (! is_numeric($raw)) {
            return self::DEFAULT_PER_PAGE;
        }

        return max(1, min(self::MAX_PER_PAGE, (int) $raw));
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
