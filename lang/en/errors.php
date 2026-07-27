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
        'no_materials' => 'Work Order :number has no materials or linked BOM, so an issue voucher cannot be created.',
    ],

    'delivery' => [
        'already_active' => 'Delivery voucher :number is already active.',
        'cancelled' => 'Delivery voucher :number is cancelled.',
        'no_lines' => 'Delivery voucher :number has no items to deliver.',
        'cannot_cancel_active' => 'Delivery voucher :number is active and cannot be cancelled.',
    ],

    'sales_invoice' => [
        'voucher_not_active' => 'Delivery voucher :number is not delivered yet. Only activated vouchers can be invoiced.',
        'exceeds_voucher_value' => 'The invoice exceeds the delivered value of voucher :number. Remaining to invoice: :remaining EGP.',
    ],

    'purchase_invoice' => [
        'invalid_value' => 'The invoice value cannot be negative.',
        'not_posted' => 'Addition voucher :number is not posted yet. Only posted receipts can be closed without an invoice.',
        'already_invoiced' => 'Addition voucher :number already carries invoice :invoice. Remove the invoice number before closing it.',
        'reason_required' => 'A closure reason is required — the file must say why this receipt will never be invoiced.',
    ],

    'work_order' => [
        'cannot_approve_order' => 'Work Order :number cannot be approved. It is not a draft. Current status: :status.',
        'cannot_start' => 'Work Order :number cannot be started. Current status: :status.',
        'no_bom' => 'Work Order :number has no linked BOM. Cannot issue materials.',
        'not_in_progress' => 'Work Order :number must be in progress to submit for QA.',
        'not_pending_qa' => 'Work Order :number is not pending QA review.',
        'qa_gate' => 'Work Order :number cannot be completed without QA approval. The QA gate is mandatory.',
        'not_in_qa_review' => 'Work Order :number must be in QA review to complete.',
        'cannot_finish_manufacturing' => 'Manufacturing for Work Order :number cannot be finished. It must be started first. Current status: :status.',
        'no_output_item' => 'Work Order :number has no finished product selected, so standard materials cannot be fetched.',
        'no_standard_bom' => 'No approved standard BOM exists for ":item". Define its standard recipe first, or enter the materials manually.',
    ],

    'quality_sheet' => [
        'already_approved' => 'Quality sheet :number is already approved and cannot be changed.',
        'not_filled' => 'Quality sheet :number must be filled by QA before it can be approved.',
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
