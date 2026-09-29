<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests perform multi-request flows (login -> logout, form -> submit).
        // The array session driver does not persist the CSRF token across these
        // requests, so use the database driver for feature tests. The database
        // schema (sessions table) is created by migrations/RefreshDatabase.
        config(['session.driver' => 'database']);
    }
}
