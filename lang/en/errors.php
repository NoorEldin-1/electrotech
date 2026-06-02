<?php

declare(strict_types=1);

/**
 * User-facing domain error messages. Services throw with these keys so the
 * Filament notifications render localized, detailed messages instead of raw
 * English exception strings.
 */
return [
    'inventory' => [
        'insufficient_stock' => "Insufficient stock for ':item' in :warehouse. Available: :available, Requested: :requested.",
        'insufficient_transfer' => "Insufficient stock for ':item' to transfer from :warehouse. Available: :available, Requested: :requested.",
        'insufficient_hold' => "Insufficient available stock to hold ':item' in :warehouse. Available: :available, Requested: :requested.",
        'cannot_release' => "Cannot release :requested of ':item' in :warehouse. Currently on hold: :held.",
        'same_warehouse' => 'Source and destination warehouse must differ.',
        'positive_quantity' => 'Quantity must be greater than zero.',
    ],

    'voucher' => [
        'already_posted' => 'Voucher :number is already posted.',
        'no_lines' => 'Voucher :number has no items to post.',
    ],

    'journal' => [
        'already_posted' => 'Journal entry :number is already posted.',
        'no_lines' => 'Journal entry :number must have at least two lines.',
        'unbalanced' => 'Journal entry :number is not balanced. Debit: :debit, Credit: :credit.',
    ],

    'issue' => [
        'no_bom' => 'Work Order :number has no linked BOM, so an issue voucher cannot be created.',
    ],

    'delivery' => [
        'already_active' => 'Delivery voucher :number is already active.',
        'cancelled' => 'Delivery voucher :number is cancelled.',
        'no_lines' => 'Delivery voucher :number has no items to deliver.',
        'cannot_cancel_active' => 'Delivery voucher :number is active and cannot be cancelled.',
    ],

    'work_order' => [
        'cannot_start' => 'Work Order :number cannot be started. Current status: :status.',
        'no_bom' => 'Work Order :number has no linked BOM. Cannot issue materials.',
        'not_in_progress' => 'Work Order :number must be in progress to submit for QA.',
        'not_pending_qa' => 'Work Order :number is not pending QA review.',
        'qa_gate' => 'Work Order :number cannot be completed without QA approval. The QA gate is mandatory.',
        'not_in_qa_review' => 'Work Order :number must be in QA review to complete.',
    ],

    'purchase_order' => [
        'cancelled' => 'Cannot receive items for a cancelled purchase order.',
        'exceeds_ordered' => "Cannot receive :quantity of ':item'. It would exceed the ordered quantity of :ordered (already received: :received).",
    ],

    'operations' => [
        'illegal_transition' => "Illegal operation transition: the operation is ':current', expected one of [:allowed].",
        'claim_before_completion' => 'A financial claim can only be raised after supply and installation are complete.',
        'reserve_exceeds_available' => "Cannot reserve :quantity of ':item'. Only :available available in :warehouse.",
        'facility_exceeds_available' => "Cannot allocate :amount from ':facility'. Only :available available on the facility.",
    ],
];
