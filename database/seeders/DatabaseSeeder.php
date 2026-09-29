<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Deliberately does NOT create admin accounts (PLAN.md Phase 2:
     * "Seeding an Admin with a known default password is a security defect").
     * Admins are provisioned out of band via `sentinel:install-admin`.
     */
    public function run(): void
    {
        // No seeds at this phase. Admin provisioning: php artisan sentinel:install-admin
    }
}
