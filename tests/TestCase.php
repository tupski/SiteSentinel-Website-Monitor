<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // The suite-wide session driver comes from phpunit.xml (SESSION_DRIVER=array):
    // it is tableless and needs no schema, so tests that do not migrate the
    // database still boot the HTTP stack cleanly. Tests that genuinely exercise
    // the server-side session row (e.g. LogoutAndResetTest, SessionTimeoutTest)
    // opt in per-test with `config(['session.driver' => 'database'])` after
    // RefreshDatabase has created the `sessions` table.
}
