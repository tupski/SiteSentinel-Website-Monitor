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

        // Requirement 21 / B3 — the association column is present alongside them.
        $this->assertStringContainsString('Status page', $html);
        $this->assertStringContainsString('Not linked', $html);
    }

    public function test_row_toggle_is_a_post_form_preserving_csrf_confirmation_behaviour(): void
    {
        $website = Website::factory()->create(['name' => 'Toggler', 'is_active' => true]);

        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.websites.index'))
            ->assertOk()
            ->getContent();

        // B1 preserves the existing server-side action: a POST form (CSRF-protected)
        // rather than an Alpine/inline handler — confirmation/error flow unchanged.
        $this->assertStringContainsString('action="'.route('admin.websites.toggle', $website).'"', $html);
        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('name="_token"', $html);
    }

    public function test_unauthenticated_user_cannot_access_crud(): void
    {
        $this->get(route('admin.websites.index'))->assertRedirect(route('login'));
        $this->post(route('admin.websites.store'), [])->assertRedirect(route('login'));
    }

    /**
     * Requirement 31: `?status=UP` filters the ACTUAL query to operational
     * websites (`status_availability === 'UP'`), independent of `is_active`.
     */
    public function test_status_up_filter_returns_only_operational_websites(): void
    {
        Website::factory()->create(['name' => 'Up Active', 'is_active' => true, 'status_availability' => 'UP']);
        Website::factory()->create(['name' => 'Up Inactive', 'is_active' => false, 'status_availability' => 'UP']);
        Website::factory()->create(['name' => 'Down Active', 'is_active' => true, 'status_availability' => 'DOWN']);
        Website::factory()->create(['name' => 'Unknown', 'is_active' => true, 'status_availability' => null]);

        $response = $this->actingAs($this->admin)->get(route('admin.websites.index', ['status' => 'UP']));

        $response->assertOk();
        $html = (string) $response->getContent();

        $this->assertStringContainsString('Up Active', $html);
        $this->assertStringContainsString('Up Inactive', $html, 'UP is availability, not is_active');
        $this->assertStringNotContainsString('Down Active', $html);
        $this->assertStringNotContainsString('>Unknown<', $html);
    }

    /** Requirement 31: an invalid `status` value is ignored (whitelisted). */
    public function test_status_filter_ignores_invalid_values(): void
    {
        Website::factory()->create(['name' => 'Visible Site', 'status_availability' => 'DOWN']);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.websites.index', ['status' => 'DROP TABLE websites']));

        $response->assertOk();
        // Unfiltered fallback: the DOWN row is still listed, and no filter notice shows.
        $response->assertSee('Visible Site');
        $this->assertStringNotContainsString('Clear filter', (string) $response->getContent());
    }

    /** Requirement 31: the active filter is shown with a one-click clear link. */
    public function test_status_filter_is_shown_and_removable(): void
    {
        Website::factory()->create(['status_availability' => 'UP']);

        $response = $this->actingAs($this->admin)->get(route('admin.websites.index', ['status' => 'UP']));

        $response->assertOk();
        $response->assertSee('Showing:');
        $response->assertSee('Operational');
        $response->assertSee('Clear filter');
        $response->assertSee('href="'.route('admin.websites.index').'"', false);
    }

    /** Requirement 31: the filter survives pagination links and `per_page`. */
    public function test_status_filter_works_with_per_page_and_pagination(): void
    {
        foreach (range(1, 12) as $i) {
            Website::factory()->create(['name' => "Up {$i}", 'status_availability' => 'UP']);
        }
        Website::factory()->create(['name' => 'Down Only', 'status_availability' => 'DOWN']);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.websites.index', ['status' => 'UP', 'per_page' => 10]));

        $response->assertOk();
        $rows = $response->getOriginalContent()->getData()['websites'];

        // Only the 12 UP rows are paginated (the DOWN row is excluded).
        $this->assertSame(12, $rows->total());
        $this->assertSame(10, $rows->perPage());
        $this->assertStringNotContainsString('Down Only', (string) $response->getContent());

        // Pagination links keep the active filter (withQueryString), so page 2 stays filtered.
        $html = (string) $response->getContent();
        $this->assertStringContainsString('status=UP', $html);
        $this->assertStringContainsString('per_page=10', $html);
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
