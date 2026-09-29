<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 1 base migration suite (AC-1-01).
 *
 * Runs `migrate:fresh` on the test database and asserts every framework
 * table from DATABASE.md §8 group 1 exists with the frozen columns.
 *
 * Environment limitation: this suite runs on SQLite (local development).
 * Applying forward on a clean MySQL 8 (InnoDB, utf8mb4) is the production
 * target and is verified when a MySQL instance is available — not claimed here.
 */
final class BaseMigrationsTest extends TestCase
{
    public function test_base_migration_suite_applies_forward_on_a_clean_database(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        foreach (['users', 'password_reset_tokens', 'sessions', 'jobs', 'failed_jobs', 'cache', 'cache_locks'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "table {$table} must exist");
        }
    }

    public function test_users_table_matches_the_frozen_schema_from_database_md(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        $columns = Schema::getColumnListing('users');

        foreach (['id', 'name', 'email', 'password', 'role', 'is_active', 'email_verified_at', 'last_login_at'] as $column) {
            $this->assertContains($column, $columns, "users.{$column} is a frozen column (DATABASE.md §3.1)");
        }
    }

    public function test_password_reset_tokens_and_sessions_match_the_frozen_schema(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        $reset = Schema::getColumnListing('password_reset_tokens');
        foreach (['email', 'token', 'created_at'] as $column) {
            $this->assertContains($column, $reset, "password_reset_tokens.{$column} is a frozen column (DATABASE.md §3.2)");
        }

        $sessions = Schema::getColumnListing('sessions');
        foreach (['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity'] as $column) {
            $this->assertContains($column, $sessions, "sessions.{$column} is a frozen column (DATABASE.md §3.3)");
        }
    }
}
