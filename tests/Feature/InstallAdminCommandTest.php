<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Admin provisioning via install command (PLAN.md Phase 2):
 * no default-password seeder; password >= 12 chars; audited.
 */
final class InstallAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_provisions_an_admin_account(): void
    {
        $exit = Artisan::call('sentinel:install-admin', [
            '--email' => 'root@example.test',
            '--name' => 'Root Admin',
            '--password' => 'a-very-long-passphrase',
        ]);

        $this->assertSame(0, $exit);

        $user = User::query()->where('email', 'root@example.test')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->isAdmin());
        $this->assertTrue($user->isActive());

        // Provisioning is audited (out-of-band auth event)
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->getKey(),
            'event' => 'auth.admin_provisioned',
        ]);
    }

    public function test_it_rejects_short_passwords(): void
    {
        $exit = Artisan::call('sentinel:install-admin', [
            '--email' => 'root@example.test',
            '--name' => 'Root Admin',
            '--password' => 'short12', // < 12 chars (SECURITY.md §2.3)
        ]);

        $this->assertSame(1, $exit);
        $this->assertDatabaseMissing('users', ['email' => 'root@example.test']);
    }

    public function test_it_refuses_duplicate_emails(): void
    {
        User::factory()->create(['email' => 'root@example.test']);

        $exit = Artisan::call('sentinel:install-admin', [
            '--email' => 'root@example.test',
            '--name' => 'Another',
            '--password' => 'a-very-long-passphrase',
        ]);

        $this->assertSame(1, $exit);
        $this->assertSame(1, User::query()->where('email', 'root@example.test')->count());
    }

    public function test_no_default_password_seeder_creates_admins(): void
    {
        // The database seeder must NOT create admin accounts with known
        // passwords (PLAN.md Phase 2 risk note).
        Artisan::call('db:seed', ['--force' => true]);

        $this->assertSame(
            0,
            User::query()->count(),
            'no admin may be seeded with a default password'
        );
    }
}
