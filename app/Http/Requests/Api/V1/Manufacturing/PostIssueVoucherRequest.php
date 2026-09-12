<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Manufacturing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Post an issue voucher — the moment the material leaves the store.
 *
 * `allow_excess` is the second half of a two-step conversation, not a flag to
 * set by default. The first post of a voucher that goes over the work order's
 * remaining requirement is refused with `422 issue_excess_requires_approval`
 * and the offending rows in `details.excess`. A client shows those rows, and
 * only if the user holds `issue_vouchers.approve_excess` does it offer to
 * retry with `allow_excess: true` and a written reason — both of which are
 * stamped on the document.
 *
 * The reason is required *when* the excess is approved: an unexplained
 * override is indistinguishable from a typo six months later.
 */
class PostIssueVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('post', $this->route('issue_voucher')) ?? false;
    }

    public function rules(): array
    {
        return [
            'allow_excess' => ['nullable', 'boolean'],
            'excess_reason' => ['required_if_accepted:allow_excess', 'nullable', 'string', 'max:2000'],
        ];
    }
}
