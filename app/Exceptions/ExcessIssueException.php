<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * صرف كمية زائدة عن حاجة أمر التصنيع — raised when an issue voucher is posted
 * carrying more of an item than the work order's material plan still needs.
 *
 * It is a RuntimeException so every existing `catch (\RuntimeException)` around
 * a posting still behaves sensibly, but it carries the offending rows so the UI
 * can show the store keeper exactly which item is over by how much, and offer
 * the two ways out: go back and edit, or approve the excess with a reason.
 */
class ExcessIssueException extends \RuntimeException
{
    /**
     * @param  array<int, array{item_id:int, item_name:string, required:float, previously_issued:float, remaining:float, this_voucher:float, excess:float}>  $rows
     */
    public function __construct(
        public readonly array $rows,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : __('errors.issue.excess_quantity'));
    }
}
