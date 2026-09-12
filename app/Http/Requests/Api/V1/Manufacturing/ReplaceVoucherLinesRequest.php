<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Manufacturing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Replace the lines of a draft issue or return voucher.
 *
 * One request class serves both documents because the payload is identical and
 * the authorization is the same question asked of a different model — the
 * route's own bound model answers it, so nothing here needs to know which one
 * it is.
 *
 * Quantity may be zero, which matters most on a RETURN voucher: a draft is
 * pre-filled with everything the order was issued, and the warehouse sets
 * amounts only on the lines that actually came back rather than deleting the
 * rest. Posting ignores zero lines. An issue voucher carrying nothing but zero
 * lines is refused at posting time, by the service, where the rule belongs.
 */
class ReplaceVoucherLinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->voucher()) ?? false;
    }

    public function rules(): array
    {
        return [
            'lines' => ['present', 'array', 'max:500'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->whereNull('deleted_at')],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * Whichever voucher this route binds — `{issue_voucher}` or
     * `{return_voucher}`.
     */
    private function voucher(): ?object
    {
        return $this->route('issue_voucher') ?? $this->route('return_voucher');
    }
}
