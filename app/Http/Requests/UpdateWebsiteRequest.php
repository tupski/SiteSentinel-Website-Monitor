<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Form request for updating an existing monitored website.
 */
final class UpdateWebsiteRequest extends WebsiteRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'url' => ['sometimes', 'string', 'max:2048'],
        ]);
    }
}
