<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3 website CRUD tests (AC-3-01, AC-3-03, AC-3-04, AC-3-05, AC-3-06).
 */
final class WebsiteCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    public function test_admin_can_create_a_website(): void
    {
        $response = $this->actingAs($this->admin)
            ->from(route('admin.websites.create'))
            ->post(route('admin.websites.store'), [
                'name' => 'Example Site',
                'url' => 'https://example.com',
                'check_interval_seconds' => 300,
                'timeout_seconds' => 10,
                'expected_status' => 200,
            ]);

        $response->assertRedirect(route('admin.websites.index'));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('websites', [
            'name' => 'Example Site',
            'url' => 'https://example.com/',
            'scheme' => 'https',
            'host' => 'example.com',
            'is_active' => true,
        ]);
    }

    public function test_create_rejects_an_ssrf_url(): void
    {
        $response = $this->actingAs($this->admin)
            ->from(route('admin.websites.create'))
            ->post(route('admin.websites.store'), [
                'name' => 'Bad',
                'url' => 'http://127.0.0.1',
                'check_interval_seconds' => 300,
                'timeout_seconds' => 10,
                'expected_status' => 200,
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('url');
        $this->assertDatabaseMissing('websites', ['name' => 'Bad']);
    }

    public function test_admin_can_edit_a_website(): void
    {
        $website = Website::factory()->create();

        $response = $this->actingAs($this->admin)
            ->from(route('admin.websites.edit', $website))
            ->put(route('admin.websites.update', $website), [
                'name' => 'Renamed',
                'url' => 'https://example.org',
                'check_interval_seconds' => 600,
                'timeout_seconds' => 15,
                'expected_status' => 200,
            ]);

        $response->assertRedirect(route('admin.websites.index'));
        $website->refresh();
        $this->assertSame('Renamed', $website->name);
        $this->assertSame('https://example.org/', $website->url);
        $this->assertSame('example.org', $website->host);
    }

    public function test_admin_can_delete_a_website(): void
    {
        $website = Website::factory()->create();

        $response = $this->actingAs($this->admin)
            ->from(route('admin.websites.index'))
            ->delete(route('admin.websites.destroy', $website));

        $response->assertRedirect(route('admin.websites.index'));
        $this->assertSoftDeleted($website);
    }

    public function test_admin_can_toggle_website_active_state(): void
    {
        $website = Website::factory()->create(['is_active' => true]);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.websites.toggle', $website));

        $response->assertRedirect(route('admin.websites.index'));
        $this->assertFalse($website->fresh()->is_active);

        $this->actingAs($this->admin)
            ->post(route('admin.websites.toggle', $website));

        $this->assertTrue($website->fresh()->is_active);
    }

    public function test_list_shows_separate_availability_security_and_last_check_columns(): void
    {
        Website::factory()->create([
            'name' => 'Listed',
            'status_availability' => 'UP',
            'status_security' => 'OK',
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.websites.index'));

        $response->assertOk();
        $html = (string) $response->getContent();
        $this->assertStringContainsString('Listed', $html);
        $this->assertStringContainsString('UP', $html);
        $this->assertStringContainsString('OK', $html);
    }

    public function test_unauthenticated_user_cannot_access_crud(): void
    {
        $this->get(route('admin.websites.index'))->assertRedirect(route('login'));
        $this->post(route('admin.websites.store'), [])->assertRedirect(route('login'));
    }

    public function test_crud_events_are_audited(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.websites.store'), [
            'name' => 'Audited',
            'url' => 'https://example.com',
            'check_interval_seconds' => 300,
            'timeout_seconds' => 10,
            'expected_status' => 200,
        ]);

        $response->assertRedirect(route('admin.websites.index'));

        $this->assertDatabaseHas('audit_logs', ['event' => 'website.created']);
    }
}
