<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPage;

use App\Models\StatusPage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

/**
 * No-SSRF / no-fetch contract (STATUS-PAGE.md §10.1, §12.4), per page.
 *
 * The status page performs no outbound request of any kind and accepts no
 * parameter that could induce one.
 */
final class NoSsrfTest extends StatusPageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function show(): string
    {
        return $this->defaultPage()->slug;
    }

    public function test_status_html_and_json_never_send_http(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPage::MODE_PUBLIC);

        $this->get(route('status.show', ['statusPage' => $this->show()]))->assertOk();
        $this->getJson(route('status.json', ['statusPage' => $this->show()]))->assertOk();

        Http::assertNothingSent();
    }

    public function test_hostile_query_parameters_are_ignored_and_send_nothing(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPage::MODE_PUBLIC);

        $hostile = [
            'url' => 'http://169.254.169.254/latest/meta-data/',
            'target' => 'http://127.0.0.1:6379/',
            'fetch' => 'http://internal-canary.local/',
            'proxy' => 'http://10.0.0.1/',
            'redirect' => 'http://localhost/',
            'file' => 'file:///etc/passwd',
        ];

        $response = $this->get(route('status.show', ['statusPage' => $this->show()] + $hostile));
        $response->assertOk();

        $json = $this->getJson(route('status.json', ['statusPage' => $this->show()] + $hostile));
        $json->assertOk();

        // The hostile parameters must not appear in the output.
        foreach ($hostile as $value) {
            $this->assertStringNotContainsString($value, (string) $response->getContent());
            $this->assertStringNotContainsString($value, (string) $json->getContent());
        }

        Http::assertNothingSent();
    }

    public function test_locked_and_private_modes_never_send_http(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);

        $this->setMode(StatusPage::MODE_PASSWORD_PROTECTED, [
            'password_hash' => Hash::make('correct-horse-battery'),
        ]);
        $this->get(route('status.show', ['statusPage' => $this->show()]))->assertOk();
        $this->getJson(route('status.json', ['statusPage' => $this->show()]));

        $this->setMode(StatusPage::MODE_PRIVATE);
        $this->get(route('status.show', ['statusPage' => $this->show()]))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_unlock_post_never_sends_http(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPage::MODE_PASSWORD_PROTECTED, [
            'password_hash' => Hash::make('correct-horse-battery'),
        ]);

        $slug = $this->show();
        $this->post(route('status.unlock', ['statusPage' => $slug]), ['password' => 'wrong-password-here']);
        $this->post(route('status.unlock', ['statusPage' => $slug]), ['password' => 'correct-horse-battery']);

        Http::assertNothingSent();
    }

    public function test_legacy_redirect_never_sends_http(): void
    {
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPage::MODE_PUBLIC);

        $this->get(route('status.legacy'));

        Http::assertNothingSent();
    }
}
