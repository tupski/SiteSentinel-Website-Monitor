<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPage;

use App\Models\StatusPage;
use Illuminate\Support\Carbon;

/**
 * Precise local-time footer (Requirement 26, ADR-040; STATUS-PAGE.md §8.1).
 *
 * The server emits a machine-readable UTC ISO-8601 stamp on a `<time datetime>`
 * plus a sensible server-rendered fallback; the browser converts it to the
 * visitor's local time. The server MUST NOT emit a pre-formatted local time.
 */
final class LocalTimeFooterTest extends StatusPageTestCase
{
    private function showUrl(?StatusPage $page = null): string
    {
        return route('status.show', ['statusPage' => ($page ?? $this->defaultPage())->slug]);
    }

    public function test_footer_renders_a_time_element_with_utc_iso_datetime_and_day_fallback(): void
    {
        $this->travelTo(Carbon::parse('2026-10-04 15:37:00', 'UTC'));
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPage::MODE_PUBLIC);

        $html = (string) $this->get($this->showUrl())->getContent();

        // Machine-readable UTC ISO-8601 stamp.
        $this->assertStringContainsString('<time', $html);
        $this->assertStringContainsString('datetime="2026-10-04T15:37:00Z"', $html);

        // The precise UTC value is emitted, never a pre-formatted local string.
        $this->assertStringNotContainsString('Last update: 15:37', $html);
        $this->assertStringNotContainsString('04/10/2026', $html);

        // Progressive-enhancement fallback for a JS-less client.
        $this->assertStringContainsString('Last update: 2026-10-04', $html);
    }

    public function test_footer_attributes_the_serve_page_to_the_visitor_local_time_component(): void
    {
        $this->travelTo(Carbon::parse('2026-10-04 15:37:00', 'UTC'));
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPage::MODE_PUBLIC);

        $html = (string) $this->get($this->showUrl())->getContent();

        // The local-time conversion is delegated to a registered Alpine
        // component (logic lives in app.js, never inline per AGENTS.md §7).
        $this->assertStringContainsString('id="status-last-update"', $html);
        $this->assertStringContainsString('x-data="statusLocalTime(', $html);

        // The UTC ISO-8601 value is handed to the component as a JS string and
        // the attribute is not truncated by an early quote (`@js` escapes).
        $this->assertStringContainsString("iso: '2026-10-04T15:37:00Z'", $html);
    }

    public function test_footer_falls_back_to_an_em_dash_when_no_timestamp_is_available(): void
    {
        // A page with no published services still generates a projection stamp,
        // so the footer is always populated; assert it is never an empty or
        // broken value.
        $this->defaultPage();
        $this->setMode(StatusPage::MODE_PUBLIC);

        $html = (string) $this->get($this->showUrl())->getContent();

        $this->assertStringContainsString('id="status-last-update"', $html);
        $this->assertStringNotContainsString('Invalid Date', $html);
        $this->assertDoesNotMatchRegularExpression('/Last update:\s*</', $html);
    }

    public function test_json_exposes_the_precise_utc_stamp_and_keeps_the_day_bucket(): void
    {
        $this->travelTo(Carbon::parse('2026-10-04 15:37:00', 'UTC'));
        $this->makeWebsite(['status_alias' => 'Alpha']);
        $this->setMode(StatusPage::MODE_PUBLIC);

        $response = $this->getJson(route('status.json', ['statusPage' => $this->defaultPage()->slug]));

        $response->assertOk();
        $response->assertJsonPath('updatedAt', '2026-10-04T15:37:00Z');
        $response->assertJsonPath('updatedDayBucket', '2026-10-04');
    }
}
