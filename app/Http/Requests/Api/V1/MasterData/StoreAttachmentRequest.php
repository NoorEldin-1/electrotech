<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\MasterData;

use App\Enums\AttachmentCategory;
use App\Http\Api\AttachmentOwner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttachmentRequest extends FormRequest
{
    /**
     * Unlike every other write request in the API, this one cannot decide
     * authorization here: the gate is the *owner's* update policy, and the
     * owner is named in the body, so resolving it means running validation
     * first. The controller does the real check via `authorize('update',
     * $owner)` immediately after resolving.
     *
     * Returning true is therefore correct but only because the route is behind
     * `auth:sanctum` and the controller gates unconditionally. The
     * information-disclosure concern from Finding #4 does not apply: the rules
     * a caller could learn from a 422 here are the generic upload rules, not a
     * map of a business entity.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'owner_type' => ['required', 'string', Rule::in(AttachmentOwner::keys())],
            'owner_id' => ['required', 'integer', 'min:1'],
            'category' => ['required', Rule::enum(AttachmentCategory::class)],

            'file' => [
                'required',
                'file',

                // 20 MB. Site photos from a modern phone run to ~8 MB and a
                // scanned multi-page PO to ~15 MB; beyond that the upload is
                // more likely a mistake than a document, and it would sit on
                // a warehouse tablet's connection for minutes.
                'max:20480',

                // An extension allow-list, not a deny-list. A deny-list is a
                // guess about what is dangerous and is wrong the moment the
                // web server learns a new handler; an allow-list is a
                // statement about what this feature is for.
                'mimes:pdf,jpg,jpeg,png,webp,heic,doc,docx,xls,xlsx,csv,txt',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'owner_type.in' => 'owner_type must be one of: '.implode(', ', AttachmentOwner::keys()).'.',
        ];
    }
}
