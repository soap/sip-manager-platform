<?php

namespace App\Modules\Telephony\Http\Requests\Concerns;

use Illuminate\Foundation\Http\FormRequest;

/**
 * For PATCH of the API: the version the client last saw comes in the body or as If-Match (the
 * ETag of GET); without either the controller refuses the change with 428.
 *
 * @mixin FormRequest
 */
trait ExpectsVersion
{
    /**
     * From the body, else from If-Match ("3" or W/"3").
     */
    public function expectedVersion(): ?int
    {
        if ($this->has('version')) {
            return $this->integer('version');
        }

        $ifMatch = (string) $this->header('If-Match', '');

        return preg_match('/^(?:W\/)?"(\d+)"$/', trim($ifMatch), $matches) === 1 ? (int) $matches[1] : null;
    }
}
