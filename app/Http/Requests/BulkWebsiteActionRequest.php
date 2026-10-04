<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a bulk websites action payload (Plan S2 / ADR-034).
 *
 * The selection arrives as an array of website ids. The array is bounded so a
 * single request cannot enumerate unbounded ids, and each member is asserted to
 * be an integer. The route gate (`auth` + `admin`) already rejects non-admins;
 * per-id authorization happens in the controller against the visible set.
 */
final class BulkWebsiteActionRequest extends FormRequest
{
    /** Maximum number of ids accepted in one bulk request. */
    public const MAX_IDS = 100;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    /**
     * The validated, cast selection.
     *
     * @return list<int>
     */
    public function selectedIds(): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->validated('ids', []);

        return array_values(array_map('intval', $ids));
    }
}
