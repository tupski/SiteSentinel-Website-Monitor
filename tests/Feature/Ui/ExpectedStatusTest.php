<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use App\Support\HttpStatusCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The expected-status select is backed by a canonical catalogue, and the
 * request rule is tightened to `in:` that set (Phase 11 UI task). The DB
 * column is unchanged.
 */
final class ExpectedStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Status Select Site',
            'url' => 'https://example.com',
            'check_interval_seconds' => 300,
            'timeout_seconds' => 10,
            'expected_status' => 200,
        ], $overrides);
    }

    public function test_catalogue_contains_the_required_codes(): void
    {
        foreach ([200, 201, 204, 301, 302, 307, 308, 400, 401, 403, 404, 410, 500, 502, 503] as $code) {
            $this->assertContains($code, HttpStatusCodes::allowed(), "catalogue must contain {$code}");
        }
    }

    public function test_every_allowed_code_is_accepted(): void
    {
        foreach (HttpStatusCodes::allowed() as $code) {
            $response = $this->actingAs($this->admin)
                ->from(route('admin.websites.create'))
                ->post(route('admin.websites.store'), $this->payload([
                    'name' => 'Allowed '.$code,
                    'url' => 'https://example.com/status-'.$code,
                    'expected_status' => $code,
                ]));

            $response->assertRedirect(route('admin.websites.index'));
            $response->assertSessionHasNoErrors();
            $this->assertDatabaseHas('websites', ['name' => 'Allowed '.$code, 'expected_status' => $code]);
        }
    }

    public function test_disallowed_status_is_rejected(): void
    {
        $response = $this->actingAs($this->admin)
            ->from(route('admin.websites.create'))
            ->post(route('admin.websites.store'), $this->payload([
                'expected_status' => 418, // teapot — deliberately not in the catalogue
            ]));

        $response->assertRedirect();
        $response->assertSessionHasErrors('expected_status');
        $this->assertDatabaseMissing('websites', ['name' => 'Status Select Site']);
    }

    public function test_form_renders_status_select_with_options(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.websites.create'));

        $response->assertOk();

        $html = (string) $response->getContent();
        // Rendered via x-ui.select, which emits the attributes before the tag
        // close; assert the element and its id/name contract independently.
        $this->assertStringContainsString('<select', $html);
        $this->assertStringContainsString('id="expected_status"', $html);
        $this->assertStringContainsString('name="expected_status"', $html);
        $this->assertStringContainsString('value="200"', $html);
        $this->assertStringContainsString('value="404"', $html);
        $this->assertStringContainsString('value="503"', $html);
    }
}
