<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Project Resource
    |--------------------------------------------------------------------------
    */
    'projects' => [
        'label' => 'Project',
        'plural_label' => 'Projects',
        'navigation_label' => 'Projects',

        'sections' => [
            'project_information' => 'Project Information',
            'technical_specifications' => 'Technical Specifications',
            'financial_timeline' => 'Financial & Timeline',
            'offers' => 'Offers',
            'offers_description' => 'Add a new financial / technical offer. The latest offer is what appears on the Tender list.',
            'offers_after_save_hint' => 'Save the project first, then add detailed offers (BOQ tables, VAT and terms) from the Offers tab.',
            'attachments' => 'Project Attachments',
            'attachments_description' => 'Upload each file under its category. Compressed files (RAR / ZIP) can\'t preview in the browser — use the download button to open them. Every department can view and download what it needs.',
            'description' => 'Description',
        ],

        'fields' => [
            'code' => 'Project Code',
            'name' => 'Project / Operation Name',
            'client_name' => 'Client Name',
            'customer' => 'Customer',
            'consultant_name' => 'Consultant Name',
            'engineer_name' => 'Engineer Name',
            'electric_current' => 'Electric Current',
            'model' => 'Model',
            'section_type' => 'Conductor Name',
            'poles_count' => 'Number of Poles',
            'quantity' => 'Quantity',
            'project_location' => 'Project Location',
            'arrival_method' => 'How the Operation Arrived',
            'status' => 'Status',
            'status_helper' => 'A new operation starts in Tender, so it appears under Tender Projects. "Draft" is a saved-but-not-yet-submitted operation. The status then moves Tender → In-Hand → Active Operation through the Action / Cancel buttons — it is not edited here.',
            'estimated_budget' => 'Estimated Budget',
            'actual_cost' => 'Actual Cost',
            'start_date' => 'Start Date',
            'end_date' => 'End Date',
            'end_date_helper' => 'Set automatically when the operation moves to Active Operations, if left empty.',
            'alarm_at' => 'Alarm Time',
            'alarm_note' => 'Alarm Note',
            'smb_status' => 'SMB Status',
            'smb_received_at' => 'SMB Received At',
            'acceptance_email_at' => 'Acceptance Email Date',
            'manager_approved_at' => 'Manager Approved At',
            'manager_approved_by' => 'Manager Approved By',
            'lost_reason' => 'Loss Reason',
            'lost_reason_note' => 'Loss Note',
            'winning_competitor' => 'Winning Competitor',
            'financial_amount' => 'Financial Amount',
            'submitted_at' => 'Submitted At',
            'offer_notes' => 'Offer Notes',
            'latest_offer' => 'Latest Offer',
            'no_offers_yet' => 'No offers recorded yet.',
            'description' => 'Project Description / Notes',
            'created_by' => 'Created By',
        ],

        'columns' => [
            'code' => 'Code',
            'name' => 'Name',
            'client_name' => 'Client',
            'status' => 'Status',
            'estimated_budget' => 'Budget',
            'start_date' => 'Start Date',
            'created_by' => 'Created By',
            'created_at' => 'Created At',
        ],

        'actions' => [
            'move_to_tender' => 'Send to Tender',
            'move_to_tender_modal_heading' => 'Send this operation to Tender Projects?',
            'move_to_tender_modal_description' => 'Once sent, the operation appears under Tender Projects with Action / Cancel / Alarm buttons.',
            'change_status' => 'Change Status',
            'change_status_modal_heading' => 'Change project status',
            'change_status_modal_description' => 'Admin override — bypasses the standard sales pipeline transitions. The change is recorded in the activity log.',
        ],

        'notifications' => [
            'moved_to_tender' => 'Operation sent to Tender Projects.',
            'move_to_tender_needs_offer_title' => 'No offer recorded',
            'move_to_tender_needs_offer_body' => 'Edit the project and add at least one offer in the Offers section before sending to Tender.',
            'status_changed' => 'Project status updated.',
        ],

        'relations' => [
            'activities' => [
                'title' => 'History',
                'columns' => [
                    'date' => 'When',
                    'source' => 'Source',
                    'event' => 'Event',
                    'changes' => 'Changed Fields',
                    'causer' => 'By',
                ],
                'sources' => [
                    'project' => 'Operation',
                    'offer' => 'Offer',
                    'payment' => 'Payment',
                    'installation' => 'Installation',
                    'delivery_voucher' => 'Delivery',
                    'delivery_minute' => 'Delivery Minute',
                ],
                'events' => [
                    'created' => 'Created',
                    'updated' => 'Updated',
                    'deleted' => 'Deleted',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Project Offers — BOQ documents (Slides 2, 7 & 8)
    |--------------------------------------------------------------------------
    */
    'project_offers' => [
        'title' => 'Offers',
        'label' => 'Offer',
        'plural_label' => 'Offers',

        'sections' => [
            'header' => 'Offer Details',
            'groups' => 'Offer Tables',
            'items' => 'Line Items',
        ],

        'fields' => [
            'quotation_number' => 'Quotation No.',
            'currency' => 'Currency',
            'vat_percentage' => 'VAT %',
            'show_vat' => 'Show VAT in the offer',
            'show_vat_helper' => 'When off, the offer total excludes value-added tax.',
            'installation_percentage' => 'Installation %',
            'show_installation' => 'Add installation to the offer',
            'show_installation_helper' => 'When on, installation is added as a percentage of the subtotal (like VAT). Some clients book installation as a separate contract line.',
            'installation_amount' => 'Installation',
            'submitted_at' => 'Submission Date',
            'group_label' => 'Table Title',
            'conductor_type' => 'Conductor',
            'description' => 'Description',
            'description_helper' => 'Review the full text before printing — check spelling and the pole count (3P / 4P), as it changes the price.',
            'unit' => 'Unit',
            'quantity' => 'Qty',
            'unit_price' => 'Unit Price',
            'line_total' => 'Total',
            'subtotal' => 'Subtotal',
            'tax_amount' => 'VAT',
            'grand_total' => 'Grand Total',
            'totals' => 'Totals',
            'terms' => 'Notes / Terms',
            'terms_helper' => 'Free-text notes shown under the table — payment terms, delivery dates, installation %, and other details.',
            'special_terms' => 'Special Terms (behind the tables)',
            'special_terms_helper' => 'Offer-specific notes printed directly under the tables — e.g. "if installation is requested, prices rise by 15%".',
            'general_terms' => 'General Terms (standard)',
            'general_terms_helper' => 'Standard terms, one point per line. Pre-filled from a template; add or remove points as needed. Printed as a numbered list.',
            'header_note' => 'Header Note (before tables)',
            'header_note_helper' => 'Intro line plus the DKC licence statement, printed before the tables. Pre-filled but editable per offer.',
        ],

        'defaults' => [
            'header_note' => "With reference to your request, we are pleased to submit our offer for the above project as per the following price schedule:\nThe busway system is manufactured under license from DKC – Italy.",
            'general_terms' => "Offer validity: one week.\nPrices are calculated based on the free dollar rate per egcurrency.com; the final price is set at the time of payment.\nDelivery within 4–6 weeks of receiving the supply order, the advance payment and the approved execution drawings.\nPayment: an advance payment with the balance due on delivery to our Alexandria warehouses.\nAccessories such as terminal units, elbows and joint packs are measured and priced as per the actual length.\nAll connections inside the panel are the panel supplier's responsibility.\nPrices are exclusive of value-added tax.",
        ],

        'columns' => [
            'version' => 'Ver.',
            'quotation_number' => 'Quotation No.',
            'grand_total' => 'Grand Total',
            'is_winning' => 'Winning',
            'submitted_at' => 'Submitted',
        ],

        'actions' => [
            'add_group' => 'Add table',
            'add_item' => 'Add line',
            'print' => 'Print',
            'print_en' => 'Print (English)',
            'print_ar' => 'Print (Arabic)',
        ],

        'pdf' => [
            'company_name' => 'Electrotech for Electrical Industries',
            'quotation' => 'Quotation',
            'quotation_no' => 'Quotation No.',
            'date' => 'Date',
            'project' => 'Project',
            'to' => 'To',
            'messrs' => 'Messrs.',
            'attention' => 'Attn. Eng.',
            'greeting' => 'Dear Sir, Greetings,',
            'conductor_type' => 'Conductor type',
            'item_no' => 'ITEM NO.',
            'description' => 'DESCRIPTION',
            'unit' => 'UNIT',
            'qty' => 'QTY',
            'unit_price' => 'UNIT PRICE',
            'total_price' => 'T. PRICE',
            'subtotal' => 'Subtotal',
            'taxes' => 'Taxes',
            'installation' => 'Installation',
            'grand_total' => 'Grand total',
            'offer_total_title' => 'Offer Total',
            'special_terms_title' => 'Special Terms',
            'terms_title' => 'Terms',
            'best_regards' => 'Best Regards',
            'sales_manager' => 'Sales Manager',
        ],
    ],

    'sales_alerts' => [
        'column' => 'Offer',
        'missing_offer_tooltip' => 'No priced offer recorded for this operation yet.',
        'notification_title' => 'Operations missing an offer',
        'notification_body' => ':count pipeline operation(s) have no priced offer yet.',
        'offer_attached_title' => 'Offer attached',
        'offer_attached_body' => 'Operation ":operation" now has :count offer(s) attached.',
        'submittal_title' => 'Submittal uploaded',
        'submittal_body' => 'A submittal was uploaded for operation ":operation" (:count file(s)).',
        'view_operation' => 'Open operation',
        'missing_offer_alert_title' => 'Tender operation with no offer',
        'missing_offer_alert_body' => 'Operation ":operation" has no priced offer yet. Add an offer.',
        'missing_smb_alert_title' => 'In-hand operation with no SMB',
        'missing_smb_alert_body' => 'Operation ":operation" has no SMB on file yet. Upload the SMB.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tender Projects (Sales pipeline — Slide 5)
    |--------------------------------------------------------------------------
    */
    'tender_projects' => [
        'label' => 'Tender Project',
        'plural_label' => 'Tender Projects',
        'navigation_label' => 'Tender Projects',

        'columns' => [
            'name' => 'Operation Name',
            'financial_offer' => 'Financial Offer',
            'alarm' => 'Alarm',
            'date' => 'Date',
        ],

        'fields' => [
            'smb_file' => 'SMB / Submittal File',
            'smb_file_helper' => 'Upload the SMB / Submittal document (the file itself, not a note). Optional — it can be added later from the project\'s Submittal section.',
            'lost_reason' => 'Loss Reason',
            'lost_reason_note' => 'Loss Note',
            'winning_competitor' => 'Winning Competitor',
            'alarm_at' => 'Alarm Time',
            'alarm_note' => 'Alarm Note',
        ],

        'actions' => [
            'action' => 'Action — Move to In-Hand',
            'action_modal_heading' => 'Move this operation to In-Hand?',
            'action_modal_description' => 'The client has accepted; SMB preparation begins. Upload the SMB / Submittal file (optional — you can add it later).',
            'cancel' => 'Cancel — Move to Lost',
            'cancel_modal_heading' => 'Cancel and move to Lost?',
            'set_alarm' => 'Set Alarm',
            'clear_alarm' => 'Clear Alarm',
        ],

        'notifications' => [
            'moved_to_inhand' => 'Operation moved to In-Hand.',
            'moved_to_lost' => 'Operation moved to Lost.',
            'alarm_set' => 'Alarm set.',
            'alarm_cleared' => 'Alarm cleared.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | In-Hand Projects (Sales pipeline — Slide 7)
    |--------------------------------------------------------------------------
    */
    'in_hand_projects' => [
        'label' => 'In-Hand Project',
        'plural_label' => 'In-Hand Projects',
        'navigation_label' => 'In-Hand Projects',

        'columns' => [
            'name' => 'Operation Name',
            'smb' => 'SMB',
            'acceptance_email_at' => 'Acceptance Email',
            'manager_approved' => 'Manager Approved',
            'alarm' => 'Alarm',
            'date' => 'Date',
        ],

        'smb_present_tooltip' => 'SMB / Submittal file is on record.',
        'smb_missing_tooltip' => 'No SMB / Submittal file yet — upload it from the project\'s Submittal section.',

        'fields' => [
            'acceptance_email_at' => 'Acceptance Email Date',
            'acceptance_file' => 'Customer Acceptance File',
            'acceptance_file_helper' => 'Attach the customer acceptance document (the file itself, not just a note).',
            'manager_approve_now' => 'Approve as Manager Now',
            'manager_approve_helper' => 'Visible only to users with manager-approve permission.',
            'lost_reason' => 'Loss Reason',
            'lost_reason_note' => 'Loss Note',
            'winning_competitor' => 'Winning Competitor',
            'alarm_at' => 'Alarm Time',
            'alarm_note' => 'Alarm Note',
        ],

        'actions' => [
            'action' => 'Action — Move to Active',
            'action_modal_heading' => 'Move this operation to Active Operations?',
            'action_modal_description' => 'Requires an acceptance email date and manager approval.',
            'manager_approve' => 'Manager Approve',
            'manager_approve_modal_heading' => 'Approve this operation as manager?',
            'cancel' => 'Cancel — Move to Lost',
            'cancel_modal_heading' => 'Cancel and move to Lost?',
            'set_alarm' => 'Set Alarm',
            'clear_alarm' => 'Clear Alarm',
        ],

        'notifications' => [
            'moved_to_active' => 'Operation moved to Active Operations.',
            'moved_to_lost' => 'Operation moved to Lost.',
            'manager_approved' => 'Manager approval recorded.',
            'alarm_set' => 'Alarm set.',
            'alarm_cleared' => 'Alarm cleared.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Active Projects (Sales pipeline — post-approval)
    |--------------------------------------------------------------------------
    */
    'active_projects' => [
        'label' => 'Active Operation',
        'plural_label' => 'Active Operations',
        'navigation_label' => 'Active Operations',

        'columns' => [
            'code' => 'Code',
            'name' => 'Operation Name',
            'client_name' => 'Client',
            'actual_cost' => 'Actual Cost',
            'start_date' => 'Start Date',
            'winning_offer' => 'Winning Offer',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Lost Projects (Sales pipeline — Slide 4)
    |--------------------------------------------------------------------------
    */
    'lost_projects' => [
        'label' => 'Lost Project',
        'plural_label' => 'Lost Projects',
        'navigation_label' => 'Lost List',

        'columns' => [
            'name' => 'Operation Name',
            'client_name' => 'Client',
            'lost_reason' => 'Loss Reason',
            'winning_competitor' => 'Winning Competitor',
            'date' => 'Date',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Item Resource
    |--------------------------------------------------------------------------
    */
    'items' => [
        'label' => 'Item',
        'plural_label' => 'Items',
        'navigation_label' => 'Items',

        'sections' => [
            'item_details' => 'Item Details',
        ],

        'fields' => [
            'sku' => 'SKU / Part Number',
            'name' => 'Name',
            'type' => 'Type',
            'unit' => 'Unit of Measure',
            'unit_cost' => 'Unit Cost',
            'minimum_stock' => 'Minimum Stock Level',
            'description' => 'Description',
        ],

        'columns' => [
            'sku' => 'SKU',
            'name' => 'Name',
            'type' => 'Type',
            'unit' => 'Unit',
            'unit_cost' => 'Unit Cost',
            'on_hand' => 'On Hand',
            'on_hold' => 'On Hold',
            'minimum_stock' => 'Min. Stock',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | BOM Resource
    |--------------------------------------------------------------------------
    */
    'boms' => [
        'label' => 'Bill of Materials',
        'plural_label' => 'Bills of Materials',
        'navigation_label' => 'Bills of Materials',

        'sections' => [
            'bom_details' => 'BOM Details',
            'bom_items' => 'BOM Items',
            'bom_items_description' => 'Add items required for this project. Include waste percentage as per technical specifications.',
        ],

        'fields' => [
            'project' => 'Project',
            'version' => 'Version',
            'status' => 'Status',
            'notes' => 'Notes',
            'item' => 'Item',
            'quantity' => 'Quantity',
            'waste_percentage' => 'Waste %',
        ],

        'columns' => [
            'project' => 'Project',
            'sales_stage' => 'Sales Stage',
            'version' => 'Version',
            'status' => 'Status',
            'items_count' => 'Items',
            'prepared_by' => 'Prepared By',
            'approved_by' => 'Approved By',
            'created_at' => 'Created At',
        ],

        'actions' => [
            'approve' => 'Approve',
            'add_bom_item' => 'Add BOM Item',
        ],

        'notifications' => [
            'approved' => 'BOM approved successfully',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Purchase Order Resource
    |--------------------------------------------------------------------------
    */
    'purchase_orders' => [
        'label' => 'Purchase Order',
        'plural_label' => 'Purchase Orders',
        'navigation_label' => 'Purchase Orders',

        'sections' => [
            'po_details' => 'Purchase Order Details',
            'line_items' => 'Line Items',
            'totals' => 'Totals',
            'attachment' => 'Purchase Order Scan',
        ],

        'fields' => [
            'po_number' => 'PO Number',
            'project' => 'Project',
            'supplier' => 'Supplier',
            'supplier_name' => 'Supplier Name',
            'supplier_contact' => 'Supplier Contact',
            'status' => 'Status',
            'subtotal' => 'Subtotal',
            'vat_amount' => 'VAT (:rate%)',
            'profit_tax_amount' => 'Profit Withholding (:rate%)',
            'total_amount' => 'Total',
            'expected_delivery_date' => 'Expected Delivery Date',
            'notes' => 'Notes',
            'item' => 'Item',
            'quantity' => 'Quantity',
            'unit_price' => 'Unit Price (EGP)',
            'received_quantity' => 'Received Quantity',
        ],

        'columns' => [
            'po_number' => 'PO #',
            'project' => 'Project',
            'sales_stage' => 'Sales Stage',
            'supplier_name' => 'Supplier',
            'status' => 'Status',
            'total_amount' => 'Total Amount',
            'expected_delivery' => 'Expected Delivery',
            'created_at' => 'Created At',
        ],

        'actions' => [
            'receive' => 'Receive Items',
            'receive_hint' => 'Enter the received quantities. An addition voucher (إذن إضافة) is created and posted automatically — stock and supplier account are updated and the order is closed by comparison.',
            'add_item' => 'Add Item',
            'approve' => 'Approve',
            'approve_confirm' => 'Approve this purchase order? It will be marked as sent and can no longer be edited as a draft.',
            'open_item' => 'Open item card',
            'print' => 'Print',
            'print_en' => 'Print (English)',
            'print_ar' => 'Print (Arabic)',
        ],

        'notifications' => [
            'received' => 'Items received successfully',
            'receive_failed' => 'Receiving failed',
            'no_quantities' => 'No quantities entered',
            'approved' => 'Purchase order approved and sent',
            'voucher_created' => 'Addition voucher :number created and posted.',
        ],

        'pdf' => [
            'company_name' => 'Electrotech for Electrical Industries',
            'title' => 'PURCHASE ORDER',
            'po_number' => 'PO No.',
            'date' => 'Date',
            'supplier' => 'Supplier',
            'tax_number' => 'Tax No.',
            'project' => 'Project',
            'status' => 'Status',
            'item_no' => '#',
            'item' => 'Item',
            'qty' => 'Qty',
            'unit_price' => 'Unit Price',
            'total_price' => 'Total',
            'subtotal' => 'Subtotal',
            'vat' => 'VAT (:rate%)',
            'profit_tax' => 'Profit Withholding (:rate%)',
            'total' => 'Total',
            'no_profit_tax_note' => 'This supplier is not subject to the 1% profit-withholding deduction.',
            'approved_by' => 'Approved by (Technical Office Manager)',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inventory Transaction Resource
    |--------------------------------------------------------------------------
    */
    'inventory_transactions' => [
        'label' => 'Stock Movement',
        'plural_label' => 'Stock Movements',
        'navigation_label' => 'Stock Movements',

        'columns' => [
            'date' => 'Date',
            'item' => 'Item',
            'sku' => 'SKU',
            'type' => 'Type',
            'warehouse' => 'Warehouse',
            'quantity' => 'Quantity',
            'unit_cost' => 'Unit Cost',
            'source' => 'Source',
            'notes' => 'Notes',
            'performed_by' => 'By',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Supplier Resource
    |--------------------------------------------------------------------------
    */
    'suppliers' => [
        'label' => 'Supplier',
        'plural_label' => 'Suppliers',
        'navigation_label' => 'Suppliers',

        'sections' => [
            'details' => 'Supplier Details',
            'documents' => 'Documents',
        ],

        'fields' => [
            'name' => 'Supplier Name',
            'contact_person' => 'Contact Person',
            'phone' => 'Phone',
            'email' => 'Email',
            'tax_number' => 'Tax Number',
            'profit_tax_exempt' => 'Exempt from 1% profit withholding',
            'profit_tax_exempt_helper' => 'When on, the 1% commercial/industrial profits tax is NOT deducted on this supplier\'s purchase orders. Attach the exemption document below.',
            'address' => 'Address',
            'notes' => 'Notes',
        ],

        'columns' => [
            'name' => 'Name',
            'contact_person' => 'Contact',
            'phone' => 'Phone',
            'email' => 'Email',
            'profit_tax_exempt' => '1% Exempt',
            'balance' => 'Balance',
            'created_at' => 'Created At',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer Resource
    |--------------------------------------------------------------------------
    */
    'customers' => [
        'label' => 'Customer',
        'plural_label' => 'Customers',
        'navigation_label' => 'Customers',

        'sections' => [
            'details' => 'Customer Details',
            'attachments' => 'Attachments',
        ],

        'fields' => [
            'name' => 'Customer Name',
            'contact_person' => 'Contact Person',
            'phone' => 'Phone',
            'email' => 'Email',
            'tax_number' => 'Tax Number',
            'address' => 'Address',
            'notes' => 'Notes',
        ],

        'columns' => [
            'name' => 'Name',
            'contact_person' => 'Contact',
            'phone' => 'Phone',
            'email' => 'Email',
            'balance' => 'Balance',
            'created_at' => 'Created At',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Addition Voucher Resource (إذن إضافة)
    |--------------------------------------------------------------------------
    */
    'addition_vouchers' => [
        'label' => 'Addition Voucher',
        'plural_label' => 'Addition Vouchers',
        'navigation_label' => 'Addition Vouchers',

        'sections' => [
            'details' => 'Voucher Details',
            'lines' => 'Received Items',
            'documents' => 'Voucher Scan',
        ],

        'fields' => [
            'voucher_number' => 'Voucher Number',
            'supplier' => 'Supplier',
            'supplier_name' => 'Supplier Name (unregistered)',
            'purchase_order' => 'Purchase Order',
            'voucher_date' => 'Date',
            'invoice_number' => 'Invoice Number',
            'invoice_value' => 'Invoice Value',
            'notes' => 'Notes',
            'lines' => 'Items',
            'item' => 'Item',
            'quantity' => 'Quantity',
            'unit_cost' => 'Unit Cost',
        ],

        'columns' => [
            'voucher_number' => 'Number',
            'supplier' => 'Supplier',
            'voucher_date' => 'Date',
            'invoice_number' => 'Invoice',
            'invoice_value' => 'Invoice Value',
            'status' => 'Status',
        ],

        'actions' => [
            'post' => 'Post',
            'post_confirm' => 'Posting adds the items to stock and credits the supplier account. This cannot be undone.',
        ],

        'notifications' => [
            'posted' => 'Addition voucher posted — stock and supplier account updated.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Issue Voucher Resource (إذن صرف)
    |--------------------------------------------------------------------------
    */
    'issue_vouchers' => [
        'label' => 'Issue Voucher',
        'plural_label' => 'Issue Vouchers',
        'navigation_label' => 'Issue Vouchers',

        'sections' => [
            'details' => 'Voucher Details',
            'lines' => 'Issued Items',
        ],

        'fields' => [
            'voucher_number' => 'Voucher Number',
            'work_order' => 'Work Order',
            'voucher_date' => 'Date',
            'notes' => 'Notes',
            'lines' => 'Items',
            'item' => 'Item',
            'quantity' => 'Quantity',
            'unit_cost' => 'Unit Cost',
        ],

        'columns' => [
            'voucher_number' => 'Number',
            'work_order' => 'Work Order',
            'voucher_date' => 'Date',
            'total_value' => 'Total Value',
            'status' => 'Status',
        ],

        'actions' => [
            'post' => 'Post',
            'post_confirm' => 'Posting moves the materials from raw stock into work-in-progress and loads their cost onto the operation. This cannot be undone.',
        ],

        'notifications' => [
            'posted' => 'Issue voucher posted — materials moved to work-in-progress.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Account Entry Resource (كشف الحساب)
    |--------------------------------------------------------------------------
    */
    'account_entries' => [
        'label' => 'Account Entry',
        'plural_label' => 'Account Ledger',
        'navigation_label' => 'Account Ledger',
        'statement' => 'Account Statement',

        'columns' => [
            'date' => 'Date',
            'party' => 'Account',
            'reference' => 'Reference',
            'operation' => 'Operation',
            'direction' => 'Type',
            'amount' => 'Amount',
            'running_balance' => 'Balance',
        ],

        'filters' => [
            'from' => 'From',
            'until' => 'Until',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Chart of Accounts Resource (شجرة الحسابات)
    |--------------------------------------------------------------------------
    */
    'accounts' => [
        'label' => 'Account',
        'plural_label' => 'Chart of Accounts',
        'navigation_label' => 'Chart of Accounts',

        'sections' => [
            'details' => 'Account Details',
            'opening' => 'Opening Balance',
        ],

        'fields' => [
            'code' => 'Code',
            'name' => 'Name',
            'name_en' => 'Name (English)',
            'type' => 'Type',
            'nature' => 'Nature',
            'currency' => 'Currency',
            'parent' => 'Parent Account',
            'is_active' => 'Active',
            'opening_balance' => 'Opening Balance',
            'opening_balance_hint' => 'Signed by the account nature (رصيد أول المدة).',
            'opening_balance_date' => 'Opening Balance Date',
            'notes' => 'Notes',
        ],

        'columns' => [
            'code' => 'Code',
            'name' => 'Account',
            'type' => 'Type',
            'nature' => 'Nature',
            'currency' => 'Currency',
            'opening_balance' => 'Opening Balance',
            'is_active' => 'Active',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Journal Entry Resource (قيود اليومية)
    |--------------------------------------------------------------------------
    */
    'journal_entries' => [
        'label' => 'Journal Entry',
        'plural_label' => 'Journal Entries',
        'navigation_label' => 'Journal Entries',

        'sections' => [
            'details' => 'Entry Details',
            'lines' => 'Entry Lines',
        ],

        'fields' => [
            'entry_number' => 'Entry No.',
            'document_type' => 'Document Type',
            'document_number' => 'Document No.',
            'entry_date' => 'Date',
            'description' => 'Description',
            'currency' => 'Currency',
            'notes' => 'Notes',
            'lines' => 'Lines',
            'account' => 'Account',
            'project' => 'Operation (cost center)',
            'direction' => 'Type',
            'amount' => 'Amount',
            'line_notes' => 'Notes',
            'totals' => 'Totals',
        ],

        'columns' => [
            'entry_number' => 'Entry No.',
            'document_type' => 'Document',
            'document_number' => 'Document No.',
            'entry_date' => 'Date',
            'description' => 'Description',
            'total_debit' => 'Debit',
            'total_credit' => 'Credit',
            'status' => 'Status',
        ],

        'filters' => [
            'from' => 'From',
            'until' => 'Until',
        ],

        'actions' => [
            'post' => 'Post',
            'post_confirm' => 'Posting reflects this entry in the ledgers and trial balance. It can no longer be edited.',
        ],

        'placeholders' => [
            'total_debit' => 'Total Debit',
            'total_credit' => 'Total Credit',
            'difference' => 'Difference (must be zero)',
        ],

        'notifications' => [
            'posted' => 'Journal entry posted.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | General Ledger (دفتر الأستاذ)
    |--------------------------------------------------------------------------
    */
    'general_ledger' => [
        'title' => 'Ledger',
        'line_label' => 'ledger entry',
        'lines_label' => 'ledger entries',
        'empty' => 'No posted ledger entries yet.',
        'opening_balance' => 'Opening Balance',
        'total' => 'Total',

        'columns' => [
            'date' => 'Date',
            'document_number' => 'Document No.',
            'document_type' => 'Document',
            'description' => 'Description',
            'debit' => 'Debit',
            'credit' => 'Credit',
            'balance' => 'Balance',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trial Balance (ميزان المراجعة)
    |--------------------------------------------------------------------------
    */
    'trial_balance' => [
        'label' => 'Trial Balance',
        'navigation_label' => 'Trial Balance',
        'title' => 'Trial Balance',
        'as_of' => 'As of date',
        'currency' => 'Currency',
        'totals' => 'Totals',
        'balanced' => 'The trial balance is balanced.',
        'unbalanced' => 'Warning: the trial balance does not balance.',
        'empty' => 'No accounts to display.',

        'columns' => [
            'row' => '#',
            'account' => 'Account',
            'debit' => 'Debit',
            'credit' => 'Credit',
            'balance' => 'Balance',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Operations Overview (الإدارة العامة — نظرة عامة على العمليات)
    |--------------------------------------------------------------------------
    */
    'operations_overview' => [
        'label' => 'Operations Overview',
        'plural_label' => 'Operations Overview',
        'navigation_label' => 'Operations Overview',
        'title' => 'Active Operations',
        'empty' => 'No active operations.',
        'filters' => [
            'search' => 'Search by name, code or client',
        ],
        'columns' => [
            'row' => '#',
            'operation' => 'Operation',
            'client' => 'Client',
            'estimated_budget' => 'Budget',
            'actual_cost' => 'Actual Cost',
            'usage' => 'Budget Used',
            'boms' => 'BOMs',
            'work_orders' => 'Work Orders',
            'purchase_orders' => 'Purchase Orders',
            'deliveries' => 'Deliveries',
            'status' => 'Status',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Operation Cost Center (الإدارة العامة — مركز تكلفة العملية)
    |--------------------------------------------------------------------------
    */
    'operations_cost' => [
        'label' => 'Operation Cost File',
        'plural_label' => 'Operation Cost Files',
        'navigation_label' => 'Operation Cost Center',
        'title' => 'Operation Cost Center',
        'select_operation' => 'Select operation',
        'empty' => 'Select an operation to view its cost center.',
        'cards' => [
            'estimated_budget' => 'Estimated Budget',
            'materials_cost' => 'Materials Cost',
            'ledger_expenses' => 'Ledger Expenses',
            'installation_expenses' => 'Installation Expenses',
            'purchases_reference' => 'Purchases (reference)',
            'total_cost' => 'Total Cost',
            'revenue' => 'Revenue (deliveries)',
            'received' => 'Received (cash)',
            'profit' => 'Profit',
            'margin' => 'Margin',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Operation Lifecycle actions & notifications (الإدارة العامة)
    |--------------------------------------------------------------------------
    */
    'operations' => [
        'actions' => [
            'complete' => 'Complete Operation',
            'hold' => 'Put On Hold',
            'resume' => 'Resume Operation',
        ],
        'notifications' => [
            'completed' => 'Operation marked as completed.',
            'held' => 'Operation put on hold.',
            'resumed' => 'Operation resumed.',
            'activated_title' => 'New active operation',
            'activated_body' => 'Operation :operation is now active and assigned to all departments.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stock Reservations (الإدارة العامة — حجز الكمية للعملية)
    |--------------------------------------------------------------------------
    */
    'stock_reservations' => [
        'label' => 'Stock Reservation',
        'plural_label' => 'Stock Reservations',
        'navigation_label' => 'Stock Reservations',
        'columns' => [
            'operation' => 'Operation',
            'item' => 'Item',
            'warehouse' => 'Warehouse',
            'quantity' => 'Quantity',
            'status' => 'Status',
            'released_at' => 'Released At',
        ],
        'fields' => [
            'operation' => 'Operation',
            'item' => 'Item',
            'warehouse' => 'Warehouse',
            'quantity' => 'Quantity',
            'notes' => 'Notes',
        ],
        'actions' => [
            'reserve' => 'Reserve Stock',
            'release' => 'Release',
        ],
        'notifications' => [
            'reserved' => 'Stock reserved for the operation.',
            'released' => 'Reservation released.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery Minutes (الإدارة العامة — محاضر التسليم)
    |--------------------------------------------------------------------------
    */
    'delivery_minutes' => [
        'label' => 'Delivery Minute',
        'plural_label' => 'Delivery Minutes',
        'navigation_label' => 'Delivery Minutes',
        'sections' => [
            'details' => 'Minute Details',
        ],
        'fields' => [
            'minute_number' => 'Minute No.',
            'minute_date' => 'Date',
            'operation' => 'Operation',
            'delivery_voucher' => 'Delivery Voucher',
            'customer' => 'Customer',
            'content' => 'Content',
        ],
        'columns' => [
            'minute_number' => 'Minute No.',
            'operation' => 'Operation',
            'customer' => 'Customer',
            'minute_date' => 'Date',
            'distributed' => 'Distributed',
        ],
        'actions' => [
            'distribute' => 'Distribute',
        ],
        'notifications' => [
            'distributed' => 'Delivery minute distributed to all departments.',
            'distributed_title' => 'Delivery minute',
            'distributed_body' => 'Delivery minute :number for operation :operation has been distributed.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Financial Claims (الإدارة العامة — المطالبة المالية)
    |--------------------------------------------------------------------------
    */
    'financial_claims' => [
        'label' => 'Financial Claim',
        'plural_label' => 'Financial Claims',
        'navigation_label' => 'Financial Claims',
        'sections' => [
            'details' => 'Claim Details',
        ],
        'fields' => [
            'claim_number' => 'Claim No.',
            'claim_date' => 'Date',
            'operation' => 'Operation',
            'customer' => 'Customer',
            'amount' => 'Amount',
            'description' => 'Description',
        ],
        'columns' => [
            'claim_number' => 'Claim No.',
            'operation' => 'Operation',
            'customer' => 'Customer',
            'amount' => 'Amount',
            'status' => 'Status',
            'claim_date' => 'Date',
        ],
        'actions' => [
            'submit' => 'Submit',
            'collect' => 'Collect',
        ],
        'notifications' => [
            'submitted' => 'Financial claim submitted.',
            'collected' => 'Financial claim collected.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Operation Payments (الإدارة العامة — الدفعات والمقبوضات)
    |--------------------------------------------------------------------------
    */
    'operation_payments' => [
        'label' => 'Operation Payment',
        'plural_label' => 'Operation Payments',
        'navigation_label' => 'Payments',
        'journal_description' => 'Operation payment :number',
        'sections' => [
            'details' => 'Payment Details',
        ],
        'fields' => [
            'payment_number' => 'Payment No.',
            'payment_date' => 'Date',
            'operation' => 'Operation',
            'customer' => 'Customer',
            'direction' => 'Direction',
            'method' => 'Method',
            'amount' => 'Amount',
            'currency' => 'Currency',
            'account' => 'Treasury / Bank account',
            'account_hint' => 'Required to auto-post a journal entry.',
            'counter_account' => 'Counter account',
            'financial_claim' => 'Financial Claim',
            'reference' => 'Reference',
            'notes' => 'Notes',
        ],
        'columns' => [
            'payment_number' => 'Payment No.',
            'operation' => 'Operation',
            'customer' => 'Customer',
            'direction' => 'Direction',
            'method' => 'Method',
            'amount' => 'Amount',
            'payment_date' => 'Date',
            'posted' => 'Posted to GL',
        ],
        'filters' => [
            'from' => 'From',
            'until' => 'Until',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Supply Orders File (ملف أوامر التوريد)
    |--------------------------------------------------------------------------
    */
    'supply_orders_file' => [
        'label' => 'Supply Orders File',
        'navigation_label' => 'Supply Orders File',
        'title' => 'Supply Orders File',
        'select_operation' => 'Select operation',
        'empty' => 'Select an operation to view its supply orders file.',
        'orders_heading' => 'Purchase / Supply Orders',
        'no_orders' => 'No purchase orders for this operation.',
        'summary' => [
            'revenue' => 'Revenue (deliveries)',
            'received' => 'Received',
            'paid' => 'Paid',
            'outstanding' => 'Outstanding',
        ],
        'columns' => [
            'po_number' => 'PO No.',
            'supplier' => 'Supplier',
            'status' => 'Status',
            'total' => 'Total',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Credit Facilities (الإدارة العامة — التسهيلات الائتمانية)
    |--------------------------------------------------------------------------
    */
    'credit_facilities' => [
        'label' => 'Credit Facility',
        'plural_label' => 'Credit Facilities',
        'navigation_label' => 'Credit Facilities',
        'sections' => [
            'details' => 'Facility Details',
        ],
        'fields' => [
            'name' => 'Name',
            'status' => 'Status',
            'account' => 'GL Account',
            'customer' => 'Customer',
            'limit_amount' => 'Limit',
            'currency' => 'Currency',
            'start_date' => 'Start Date',
            'end_date' => 'End Date',
            'notes' => 'Notes',
        ],
        'columns' => [
            'name' => 'Name',
            'account' => 'Account',
            'limit_amount' => 'Limit',
            'used_amount' => 'Used',
            'available_amount' => 'Available',
            'status' => 'Status',
        ],
    ],

    'facility_allocations' => [
        'label' => 'Allocation',
        'plural_label' => 'Operation Allocations',
        'fields' => [
            'operation' => 'Operation',
            'amount' => 'Allocated Amount',
            'notes' => 'Notes',
        ],
        'columns' => [
            'operation' => 'Operation',
            'amount' => 'Amount',
            'allocated_at' => 'Allocated At',
            'status' => 'Status',
        ],
        'actions' => [
            'allocate' => 'Allocate to Operation',
            'release' => 'Release',
        ],
        'notifications' => [
            'allocated' => 'Facility allocated to the operation.',
            'released' => 'Allocation released.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Installations (الإدارة العامة — مرحلة التركيب)
    |--------------------------------------------------------------------------
    */
    'installations' => [
        'label' => 'Installation',
        'plural_label' => 'Installations',
        'navigation_label' => 'Installations',
        'sections' => [
            'details' => 'Installation Details',
        ],
        'fields' => [
            'operation' => 'Operation',
            'delivery_voucher' => 'Delivery Voucher',
            'notes' => 'Notes',
        ],
        'columns' => [
            'operation' => 'Operation',
            'status' => 'Status',
            'started_at' => 'Started',
            'completed_at' => 'Completed',
        ],
        'actions' => [
            'start' => 'Start Installation',
            'complete' => 'Complete Installation',
        ],
        'notifications' => [
            'started' => 'Installation started.',
            'completed' => 'Installation completed.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Site Surveys (الإدارة العامة — معاينات الموقع)
    |--------------------------------------------------------------------------
    */
    'site_surveys' => [
        'label' => 'Site Survey',
        'plural_label' => 'Site Surveys',
        'navigation_label' => 'Site Surveys',
        'sections' => [
            'details' => 'Survey Details',
        ],
        'fields' => [
            'operation' => 'Operation',
            'survey_date' => 'Survey Date',
            'surveyed_by' => 'Surveyed By',
            'measurements' => 'Measurements',
            'notes' => 'Notes',
        ],
        'columns' => [
            'operation' => 'Operation',
            'survey_date' => 'Survey Date',
            'surveyed_by' => 'Surveyed By',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery Voucher Resource (إذن تسليم)
    |--------------------------------------------------------------------------
    */
    'delivery_vouchers' => [
        'label' => 'Delivery Voucher',
        'plural_label' => 'Delivery Vouchers',
        'navigation_label' => 'Delivery Vouchers',

        'sections' => [
            'details' => 'Voucher Details',
            'specs' => 'Technical Specifications',
            'lines' => 'Delivered Items',
        ],

        'fields' => [
            'voucher_number' => 'Voucher Number',
            'customer' => 'Customer',
            'project' => 'Operation / Project',
            'supply_order_number' => 'Supply Order No.',
            'voucher_date' => 'Date',
            'plates_count' => 'Number of Plates',
            'protection_degree' => 'Protection Degree',
            'insulation_voltage' => 'Insulation Voltage',
            'notes' => 'Notes',
            'lines' => 'Items',
            'item' => 'Item',
            'quantity' => 'Quantity',
            'unit_cost' => 'Unit Cost',
        ],

        'columns' => [
            'voucher_number' => 'Number',
            'customer' => 'Customer',
            'voucher_date' => 'Date',
            'technical' => 'Technical',
            'financial' => 'Financial',
            'total_value' => 'Total Value',
            'status' => 'Status',
        ],

        'actions' => [
            'approve_technical' => 'Technical Approval',
            'approve_financial' => 'Financial Approval',
            'cancel' => 'Cancel',
        ],

        'notifications' => [
            'approved' => 'Signature recorded. The voucher activates once both approvals are in.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Production Entry Resource (استخراج الفاقد)
    |--------------------------------------------------------------------------
    */
    'production_entries' => [
        'label' => 'Production Entry',
        'plural_label' => 'Production & Loss',
        'navigation_label' => 'Production & Loss',

        'columns' => [
            'work_order' => 'Work Order',
            'output_item' => 'Product',
            'entry_date' => 'Date',
            'planned_quantity' => 'Planned',
            'produced_quantity' => 'Produced',
            'scrap_quantity' => 'Loss',
            'scrap_percentage' => 'Loss %',
        ],

        'filters' => [
            'from' => 'From',
            'until' => 'Until',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Work Order Resource
    |--------------------------------------------------------------------------
    */
    'work_orders' => [
        'label' => 'Work Order',
        'plural_label' => 'Work Orders',
        'navigation_label' => 'Work Orders',

        'sections' => [
            'wo_details' => 'Work Order Details',
            'quantities_schedule' => 'Quantities & Schedule',
            'qa_gate' => 'QA Gate',
            'qa_gate_description' => 'Quality Assurance approval is mandatory before completion.',
            'description' => 'Description',
        ],

        'fields' => [
            'wo_number' => 'WO Number',
            'title' => 'Title',
            'project' => 'Project',
            'linked_bom' => 'Linked BOM',
            'output_item' => 'Finished Product',
            'output_item_helper' => 'The item produced into finished goods when this work order completes.',
            'status' => 'Status',
            'priority' => 'Priority',
            'assigned_to' => 'Assigned To',
            'planned_quantity' => 'Planned Quantity',
            'produced_quantity' => 'Produced Quantity',
            'waste_quantity' => 'Waste Quantity',
            'planned_start_date' => 'Planned Start Date',
            'planned_end_date' => 'Planned End Date',
            'qa_status' => 'QA Status',
            'qa_notes' => 'QA Notes',
            'description' => 'Description',
        ],

        'columns' => [
            'wo_number' => 'WO #',
            'title' => 'Title',
            'project' => 'Project',
            'status' => 'Status',
            'priority' => 'Priority',
            'planned' => 'Planned',
            'produced' => 'Produced',
            'estimated_cost' => 'Estimated Cost',
            'actual_cost' => 'Actual Cost',
            'cost_variance' => 'Cost Variance',
            'assigned_to' => 'Assigned To',
            'start_date' => 'Start',
        ],

        'actions' => [
            'start' => 'Start',
            'issue_materials' => 'Issue Materials',
            'submit_qa' => 'Submit QA',
            'approve_qa' => 'Approve QA',
            'complete' => 'Complete',
        ],

        'notifications' => [
            'started' => 'Work Order started',
            'issue_voucher_created' => 'Draft issue voucher created',
            'submitted_qa' => 'Submitted for QA',
            'qa_approved' => 'QA Approved',
            'completed' => 'Work Order completed',
            'failed' => 'Failed',
        ],

        'qa' => [
            'approved_by' => '✅ Approved by :name on :date',
            'pending' => '⏳ Pending QA Review',
        ],

        'priority_options' => [
            'low' => 'Low',
            'normal' => 'Normal',
            'high' => 'High',
            'urgent' => 'Urgent',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Resource
    |--------------------------------------------------------------------------
    */
    'users' => [
        'label' => 'User',
        'plural_label' => 'Users',
        'navigation_label' => 'Users',

        'sections' => [
            'account_information' => 'Account Information',
        ],

        'fields' => [
            'name' => 'Name',
            'email' => 'Email',
            'password' => 'Password',
            'password_helper_edit' => 'Leave blank to keep the current password.',
            'roles' => 'Role',
            'roles_helper' => 'Determines what the user can access in the system.',
        ],

        'columns' => [
            'name' => 'Name',
            'email' => 'Email',
            'role' => 'Role',
            'verified' => 'Verified',
            'joined' => 'Joined',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Role Resource
    |--------------------------------------------------------------------------
    */
    'roles' => [
        'label' => 'Role',
        'plural_label' => 'Roles',
        'navigation_label' => 'Roles & Permissions',

        'sections' => [
            'role_details' => 'Role Details',
            'permissions' => 'Permissions',
        ],

        'fields' => [
            'name' => 'Role Name',
            'permissions' => 'Attached Permissions',
            'permissions_helper' => 'Select the permissions this role should have.',
        ],

        'columns' => [
            'name' => 'Name',
            'permissions_count' => 'Permissions',
            'created_at' => 'Created At',
        ],

        'notifications' => [
            'admin_protected' => 'The Super Admin role cannot be modified or deleted.',
        ],

        'groups' => [
            'projects' => 'Projects Management',
            'project_offers' => 'Project Offers',
            'items' => 'Items',
            'boms' => 'Bills of Materials',
            'inventory' => 'Inventory Control',
            'transactions' => 'Inventory Transactions',
            'addition_vouchers' => 'Addition Vouchers',
            'issue_vouchers' => 'Issue Vouchers',
            'delivery_vouchers' => 'Delivery Vouchers',
            'production_entries' => 'Production & Loss',
            'scrap' => 'Scrap / Loss',
            'purchase_orders' => 'Purchase Orders',
            'suppliers' => 'Suppliers',
            'customers' => 'Customers',
            'supplier_statements' => 'Supplier Statements',
            'customer_statements' => 'Customer Statements',
            'accounts' => 'Chart of Accounts',
            'journal_entries' => 'Journal Entries',
            'general_ledger' => 'General Ledger',
            'trial_balance' => 'Trial Balance',
            'operations' => 'Operations (General Management)',
            'delivery_minutes' => 'Delivery Minutes',
            'financial_claims' => 'Financial Claims',
            'operation_payments' => 'Operation Payments',
            'supply_orders_file' => 'Supply Orders File',
            'credit_facilities' => 'Credit Facilities',
            'installations' => 'Installations',
            'site_surveys' => 'Site Surveys',
            'work_orders' => 'Work Orders',
            'users' => 'Users Management',
            'roles' => 'Roles Management',
            'activity_log' => 'Activity Logs',
            'dashboard' => 'Dashboard',
            'attachments' => 'Attachments',
        ],

        'permissions' => [
            'projects' => [
                'view' => 'View Projects',
                'create' => 'Create Project',
                'edit' => 'Edit Projects',
                'delete' => 'Delete Projects',
                'change_status' => 'Change Project Status',
                'move_to_tender' => 'Move Project to Tender',
                'move_to_inhand' => 'Move Project to In-Hand',
                'move_to_active' => 'Move Project to Active',
                'cancel_to_lost' => 'Cancel Project to Lost',
                'set_alarm' => 'Set Project Alarm',
                'manager_approve' => 'Manager Approve Project',
                'view_history' => 'View Operation History',
            ],
            'project_offers' => [
                'view' => 'View Project Offers',
                'create' => 'Create Project Offer',
                'edit' => 'Edit Project Offers',
                'delete' => 'Delete Project Offers',
                'print' => 'Print Offer',
            ],
            'attachments' => [
                'upload' => 'Upload Attachments',
                'download' => 'Download Attachments',
                'delete' => 'Delete Attachments',
            ],
            'items' => [
                'view' => 'View Items',
                'create' => 'Create Item',
                'edit' => 'Edit Items',
                'delete' => 'Delete Items',
            ],
            'boms' => [
                'view' => 'View BOMs',
                'create' => 'Create BOM',
                'edit' => 'Edit BOMs',
                'approve' => 'Approve BOMs',
                'delete' => 'Delete BOMs',
            ],
            'inventory' => [
                'view' => 'View Inventory',
                'manage' => 'Manage Inventory',
                'hold' => 'Hold Inventory',
                'release' => 'Release Inventory',
                'transfer' => 'Transfer Between Warehouses',
                'view_pricing' => 'View Pricing',
            ],
            'transactions' => [
                'view' => 'View Inventory Transactions',
            ],
            'addition_vouchers' => [
                'view' => 'View Addition Vouchers',
                'create' => 'Create Addition Voucher',
                'post' => 'Post Addition Voucher',
            ],
            'issue_vouchers' => [
                'view' => 'View Issue Vouchers',
                'create' => 'Create Issue Voucher',
                'post' => 'Post Issue Voucher',
            ],
            'delivery_vouchers' => [
                'view' => 'View Delivery Vouchers',
                'create' => 'Create Delivery Voucher',
                'approve_technical' => 'Technical Approval',
                'approve_financial' => 'Financial Approval',
                'cancel' => 'Cancel Delivery Voucher',
            ],
            'production_entries' => [
                'view' => 'View Production & Loss',
            ],
            'scrap' => [
                'view' => 'View Scrap / Loss',
            ],
            'purchase_orders' => [
                'view' => 'View Purchase Orders',
                'create' => 'Create Purchase Order',
                'edit' => 'Edit Purchase Orders',
                'approve' => 'Approve Purchase Orders',
                'receive' => 'Receive Purchase Orders',
                'print' => 'Print Purchase Orders',
                'delete' => 'Delete Purchase Orders',
            ],
            'suppliers' => [
                'view' => 'View Suppliers',
                'create' => 'Create Supplier',
                'edit' => 'Edit Suppliers',
                'delete' => 'Delete Suppliers',
            ],
            'customers' => [
                'view' => 'View Customers',
                'create' => 'Create Customer',
                'edit' => 'Edit Customers',
                'delete' => 'Delete Customers',
            ],
            'supplier_statements' => [
                'view' => 'View Supplier Statements',
            ],
            'customer_statements' => [
                'view' => 'View Customer Statements',
            ],
            'accounts' => [
                'view' => 'View Chart of Accounts',
                'create' => 'Create Account',
                'edit' => 'Edit Accounts',
                'delete' => 'Delete Accounts',
            ],
            'journal_entries' => [
                'view' => 'View Journal Entries',
                'create' => 'Create Journal Entry',
                'edit' => 'Edit Journal Entries',
                'post' => 'Post Journal Entries',
                'delete' => 'Delete Journal Entries',
            ],
            'general_ledger' => [
                'view' => 'View General Ledger',
            ],
            'trial_balance' => [
                'view' => 'View Trial Balance',
            ],
            'operations' => [
                'overview' => 'View Operations Overview',
                'view_cost' => 'View Operation Cost Center',
                'activate' => 'Activate Operation',
                'complete' => 'Complete Operation',
                'hold' => 'Put Operation On Hold',
                'reserve' => 'Reserve Stock for Operation',
            ],
            'delivery_minutes' => [
                'view' => 'View Delivery Minutes',
                'create' => 'Create Delivery Minute',
                'distribute' => 'Distribute Delivery Minute',
            ],
            'financial_claims' => [
                'view' => 'View Financial Claims',
                'create' => 'Create Financial Claim',
                'submit' => 'Submit Financial Claim',
                'collect' => 'Collect Financial Claim',
            ],
            'operation_payments' => [
                'view' => 'View Operation Payments',
                'record' => 'Record Operation Payment',
            ],
            'supply_orders_file' => [
                'view' => 'View Supply Orders File',
            ],
            'credit_facilities' => [
                'view' => 'View Credit Facilities',
                'manage' => 'Manage Credit Facilities',
            ],
            'installations' => [
                'view' => 'View Installations',
                'manage' => 'Manage Installations',
            ],
            'site_surveys' => [
                'view' => 'View Site Surveys',
                'manage' => 'Manage Site Surveys',
            ],
            'work_orders' => [
                'view' => 'View Work Orders',
                'create' => 'Create Work Order',
                'edit' => 'Edit Work Orders',
                'start' => 'Start Work Order',
                'submit_qa' => 'Submit to QA',
                'approve_qa' => 'Approve QA',
                'complete' => 'Complete Work Order',
                'delete' => 'Delete Work Orders',
            ],
            'users' => [
                'view' => 'View Users',
                'create' => 'Create User',
                'edit' => 'Edit Users',
                'delete' => 'Delete Users',
            ],
            'roles' => [
                'manage' => 'Manage Roles & Permissions',
            ],
            'activity_log' => [
                'view' => 'View Activity Logs',
            ],
            'dashboard' => [
                'view' => 'View Dashboard',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Activity Log Resource
    |--------------------------------------------------------------------------
    */
    'activities' => [
        'label' => 'Activity',
        'plural_label' => 'Activity Log',
        'navigation_label' => 'Activity Log',
        'system_causer' => 'System',
        'description_format' => ':subject was :event',

        'sections' => [
            'summary' => 'Activity Summary',
            'actor' => 'Actor & Subject',
            'changes' => 'Changed Fields',
            'properties' => 'Raw Properties',
        ],

        'columns' => [
            'timestamp' => 'When',
            'log_name' => 'Log',
            'event' => 'Event',
            'description' => 'Description',
            'subject' => 'Record',
            'subject_id' => 'Record ID',
            'causer' => 'Performed By',
            'causer_type' => 'Performer Type',
            'old_values' => 'Old Values',
            'new_values' => 'New Values',
            'batch' => 'Batch',
        ],

        'events' => [
            'created' => 'Created',
            'updated' => 'Updated',
            'deleted' => 'Deleted',
            'restored' => 'Restored',
        ],

        'log_names' => [
            'default' => 'System',
        ],

        'values' => [
            'true' => 'Yes',
            'false' => 'No',
        ],

        /*
        | Generic translation bucket for attribute names that appear in
        | the "Modified Fields" tables. Consulted as a fallback when the
        | per-model fields/columns translations don't cover the key.
        */
        'field_labels' => [
            'name' => 'Name',
            'code' => 'Code',
            'sku' => 'SKU',
            'type' => 'Type',
            'unit' => 'Unit',
            'unit_cost' => 'Unit Cost',
            'unit_price' => 'Unit Price',
            'quantity' => 'Quantity',
            'received_quantity' => 'Received Quantity',
            'produced_quantity' => 'Produced Quantity',
            'waste_quantity' => 'Waste Quantity',
            'planned_quantity' => 'Planned Quantity',
            'waste_percentage' => 'Waste %',
            'minimum_stock' => 'Minimum Stock',
            'status' => 'Status',
            'priority' => 'Priority',
            'version' => 'Version',
            'description' => 'Description',
            'notes' => 'Notes',
            'email' => 'Email',
            'estimated_budget' => 'Estimated Budget',
            'actual_cost' => 'Actual Cost',
            'total_amount' => 'Total Amount',
            'on_hand_quantity' => 'On-Hand Quantity',
            'on_hold_quantity' => 'On-Hold Quantity',
            'warehouse_type' => 'Warehouse',
            'reference_type' => 'Reference Type',
            'reference_id' => 'Reference ID',
            'file_name' => 'File Name',
            'file_type' => 'File Type',
            'file_size' => 'File Size',
            'category' => 'Category',
            'project_id' => 'Project',
            'item_id' => 'Item',
            'bom_id' => 'BOM',
            'purchase_order_id' => 'Purchase Order',
            'approved_by' => 'Approved By',
            'approved_at' => 'Approved At',
            'qa_approved_by' => 'QA Approved By',
            'qa_approved_at' => 'QA Approved At',
            'created_by' => 'Created By',
            'po_number' => 'PO Number',
            'wo_number' => 'WO Number',
        ],

        'filters' => [
            'date_range' => 'Date range',
            'from' => 'From',
            'until' => 'Until',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Enum Translations
    |--------------------------------------------------------------------------
    */
    'enums' => [
        'reservation_status' => [
            'active' => 'Active',
            'released' => 'Released',
        ],

        'claim_status' => [
            'draft' => 'Draft',
            'submitted' => 'Submitted',
            'collected' => 'Collected',
            'cancelled' => 'Cancelled',
        ],

        'payment_direction' => [
            'incoming' => 'Incoming (received)',
            'outgoing' => 'Outgoing (paid)',
        ],

        'payment_method' => [
            'cash' => 'Cash',
            'cheque' => 'Cheque',
            'bank_transfer' => 'Bank Transfer',
        ],

        'facility_status' => [
            'active' => 'Active',
            'expired' => 'Expired',
            'closed' => 'Closed',
        ],

        'installation_status' => [
            'pending' => 'Pending',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
        ],

        'bom_status' => [
            'draft' => 'Draft',
            'pending_approval' => 'Pending Approval',
            'approved' => 'Approved',
            'superseded' => 'Superseded',
        ],

        'project_status' => [
            'draft' => 'Draft',
            'pending_review' => 'Pending Review',
            'approved' => 'Approved',
            'tender' => 'Tender',
            'in_hand' => 'In-Hand',
            'in_progress' => 'Active Operation',
            'on_hold' => 'On Hold',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'lost' => 'Lost',
        ],

        'arrival_method' => [
            'direct_client' => 'Direct Client',
            'consultant_referral' => 'Consultant Referral',
            'tender_invitation' => 'Tender Invitation',
            'website' => 'Website',
            'other' => 'Other',
        ],

        'conductor_type' => [
            'copper' => 'Copper',
            'aluminum' => 'Aluminium',
            'bi_metal' => 'Bi-Metal',
        ],

        'attachment_category' => [
            'upload' => 'UPLOAD',
            'vendor_list' => 'VENDOR LIST',
            'drowing' => 'DROWING',
            'speces' => 'SPECES',
            'boq' => 'BOQ',
            'site_measurement' => 'SITE MEASUREMENT',
            'submittal' => 'SUBMITTAL (SMB)',
            'commercial_registry' => 'Commercial Registry',
            'tax_card' => 'Tax Card',
            'profit_tax_exemption' => '1% Withholding Exemption',
            'po_scan' => 'Purchase Order Scan',
            'addition_voucher_scan' => 'Addition Voucher Scan',
            'customer_acceptance' => 'Customer Acceptance',
            'customer_document' => 'Customer Files',
        ],

        'lost_reason' => [
            'high_price' => 'Prices too high',
            'not_approved_by_consultant' => 'Not approved by consultant',
            'electricity_company_approval' => 'Electricity company approval required',
            'payment_facilities' => 'Payment facilities',
            'other' => 'Other',
        ],

        'work_order_status' => [
            'pending' => 'Pending',
            'in_progress' => 'In Progress',
            'qa_review' => 'QA Review',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
        ],

        'purchase_order_status' => [
            'draft' => 'Draft',
            'submitted' => 'Submitted',
            'partially_received' => 'Partially Received',
            'received' => 'Received',
            'cancelled' => 'Cancelled',
        ],

        'item_type' => [
            'raw_material' => 'Raw Material',
            'finished_good' => 'Finished Good',
            'semi_finished' => 'Semi-Finished',
            'consumable' => 'Consumable',
        ],

        'transaction_type' => [
            'in' => 'Stock In',
            'out' => 'Stock Out',
            'hold' => 'Hold/Reserve',
            'release' => 'Release',
        ],

        'warehouse_type' => [
            'raw_materials' => 'Raw Materials',
            'work_in_progress' => 'Work In Progress',
            'finished_goods' => 'Finished Goods',
        ],

        'voucher_status' => [
            'draft' => 'Draft',
            'posted' => 'Posted',
        ],

        'account_direction' => [
            'debit' => 'Debit',
            'credit' => 'Credit',
        ],

        'account_type' => [
            'asset' => 'Asset',
            'liability' => 'Liability',
            'equity' => 'Equity',
            'revenue' => 'Revenue',
            'expense' => 'Expense',
        ],

        'document_type' => [
            'payment_order' => 'Payment Order',
            'supply_receipt' => 'Supply Receipt',
            'settlement' => 'Settlement Entry',
        ],

        'journal_status' => [
            'draft' => 'Draft',
            'posted' => 'Posted',
        ],

        'delivery_voucher_status' => [
            'draft' => 'Draft',
            'pending_approval' => 'Pending Approval',
            'active' => 'Active',
            'cancelled' => 'Cancelled',
        ],

        'unit_of_measure' => [
            'pcs' => 'Pieces (pcs)',
            'kg' => 'Kilograms (kg)',
            'm' => 'Meters (m)',
            'm2' => 'Square Meters (m²)',
            'liter' => 'Liters (L)',
            'set' => 'Sets',
            'roll' => 'Rolls',
            'sheet' => 'Sheets',
            'box' => 'Boxes',
            'ton' => 'Tons',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Conflicts (Offline-First)
    |--------------------------------------------------------------------------
    */
    'sync_conflicts' => [
        'label' => 'Sync Conflict',
        'plural_label' => 'Sync Conflicts',
        'navigation_label' => 'Sync Conflicts',

        'columns' => [
            'detected_at' => 'Detected',
            'operator' => 'Operator',
            'device' => 'Device',
            'type' => 'Type',
            'record' => 'Record',
            'reason' => 'Reason',
            'resolved' => 'Resolved',
        ],

        'fields' => [
            'uuid' => 'Conflict ID',
            'model_type' => 'Record Type',
            'record_uuid' => 'Record UUID',
            'reason' => 'Reason',
            'server_version' => 'Server Version',
            'client_base_version' => 'Client Base Version',
            'error_message' => 'Error Message',
            'client_payload' => 'Client payload (rejected)',
            'server_state' => 'Server state (authoritative)',
        ],

        'reasons' => [
            'version_stale' => 'Version stale',
            'illegal_transition' => 'Illegal transition',
            'insufficient_stock' => 'Insufficient stock',
            'validation_failed' => 'Validation failed',
            'fk_missing' => 'FK missing',
            'push_rejected' => 'Push rejected',
            'tombstoned' => 'Tombstoned on server',
        ],

        'actions' => [
            'resolve' => 'Mark resolved',
            'resolve_confirmation' => 'Mark this conflict as resolved? The server\'s state will be accepted as authoritative.',
        ],

        'filters' => [
            'reason' => 'Reason',
            'resolved' => 'Resolved',
            'resolved_true' => 'Resolved',
            'resolved_false' => 'Unresolved',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Common / Shared Strings
    |--------------------------------------------------------------------------
    */
    'common' => [
        'no_data' => '—',
        'created_at' => 'Created At',
        'auto_generated' => 'Auto-generated',
        'action_failed' => 'Action failed',
        'currency' => 'EGP',
    ],
];
