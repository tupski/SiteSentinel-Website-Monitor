<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Illuminate\Console\Command;

/**
 * Provision an admin account out of band (PLAN.md Phase 2).
 *
 * No default password, no public registration route (SECURITY.md §2.3,
 * PRD.md §15.3). The password is supplied interactively (hidden prompt) or
 * via --password for scripted provisioning, and must be ≥ 12 chars.
 */
final class InstallAdminCommand extends Command
{
    protected $signature = 'sentinel:install-admin
                            {--email= : Admin email address}
                            {--name= : Admin display name}
                            {--password= : Admin password (avoid; prefer the interactive prompt)}';

    protected $description = 'Provision the initial admin account (no public registration exists)';

    public function handle(): int
    {
        $email = mb_strtolower((string) ($this->option('email') ?: $this->ask('Admin email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email address.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error("An account with email {$email} already exists.");

            return self::FAILURE;
        }

        $name = (string) ($this->option('name') ?: $this->ask('Admin display name'));

        /** @var string|null $password */
        $password = $this->option('password');

        if ($password === null || $password === '') {
            $password = $this->secret('Admin password (min 12 chars, input hidden)');
        }

        $minLen = max(12, (int) settings(SettingsRepository::AUTH_MIN_PASSWORD_LENGTH));

        if (mb_strlen($password) < $minLen) {
            $this->error("Password must be at least {$minLen} characters (SECURITY.md §2.3).");

            return self::FAILURE;
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password, // hashed cast on the model
            'role' => 'admin',
            'is_active' => true,
        ]);

        // Command context has no HTTP request; the audit trail always carries
        // the provisioning event (AC-2-06 scope includes out-of-band actions).
        AuditLog::create([
            'user_id' => $user->getKey(),
            'event' => 'auth.admin_provisioned',
            'subject_type' => $user::class,
            'subject_id' => $user->getKey(),
            'ip_address' => null,
            'user_agent' => 'artisan:sentinel:install-admin',
            'created_at' => now(),
        ]);

        $this->info("Admin {$email} provisioned.");

        return self::SUCCESS;
    }
}
