<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Whether the account may authenticate and act (SECURITY.md §3.1).
     *
     * Note: `role` and `is_active` are deliberately NOT mass-assignable
     * (PHP attribute Fillable above); authorization state changes go
     * through explicit audited admin flows only.
     */
    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /**
     * Whether the account holds the admin role (SECURITY.md §3.1).
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * In-app notification centre rows for this admin (DATABASE.md §3.24, ADR-038).
     */
    public function adminNotifications(): HasMany
    {
        return $this->hasMany(AdminNotification::class);
    }

    /**
     * Unread in-app notification count for this admin (`read_at IS NULL`),
     * served by `idx_admin_notifications_user_read_created` (ADR-038).
     */
    public function unreadAdminNotificationsCount(): int
    {
        return $this->adminNotifications()->whereNull('read_at')->count();
    }
}
