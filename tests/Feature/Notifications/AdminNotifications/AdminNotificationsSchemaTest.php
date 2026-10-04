<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications\AdminNotifications;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `admin_notifications` schema (DATABASE.md §3.24, ADR-038).
 *
 * Mirrors the BaseMigrationsTest idiom: run `migrate:fresh` and assert the
 * frozen columns and named indexes exist. Environment limitation: this runs on
 * SQLite locally; applying forward on a clean MySQL 8 (InnoDB, utf8mb4) is the
 * production target (verified when a MySQL instance is available).
 */
final class AdminNotificationsSchemaTest extends TestCase
{
    public function test_admin_notifications_table_exists_with_the_frozen_columns(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        $this->assertTrue(Schema::hasTable('admin_notifications'), 'table admin_notifications must exist');

        $columns = Schema::getColumnListing('admin_notifications');

        foreach ([
            'id',
            'user_id',
            'type',
            'title',
            'body',
            'severity',
            'link_url',
            'read_at',
            'dedupe_key',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertContains($column, $columns, "admin_notifications.{$column} is a frozen column (DATABASE.md §3.24)");
        }
    }

    public function test_admin_notifications_indexes_are_named_as_documented(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        $indexes = collect(Schema::getIndexes('admin_notifications'))->pluck('name')->all();

        $this->assertContains('uq_admin_notifications_dedupe_key', $indexes);
        $this->assertContains('idx_admin_notifications_user_read_created', $indexes);
    }
}
