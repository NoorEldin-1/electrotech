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
            'attachments' => 'Project Attachments',
            'attachments_description' => 'Upload files under the appropriate category — UPLOAD, VENDOR LIST, DROWING, SPECES, BOQ.',
            'description' => 'Description',
        ],

        'fields' => [
            'code' => 'Project Code',
            'name' => 'Project / Operation Name',
            'client_name' => 'Client Name',
            'consultant_name' => 'Consultant Name',
            'engineer_name' => 'Engineer Name',
            'electric_current' => 'Electric Current',
            'model' => 'Model',
            'section_type' => 'Section Type',
            'poles_count' => 'Number of Poles',
            'quantity' => 'Quantity',
            'project_location' => 'Project Location',
            'arrival_method' => 'How the Operation Arrived',
            'status' => 'Status',
            'status_helper' => 'Status is driven by the Sales pipeline actions (Action / Cancel) — it is not edited directly.',
            'estimated_budget' => 'Estimated Budget',
            'actual_cost' => 'Actual Cost',
            'start_date' => 'Start Date',
            'end_date' => 'End Date',
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
            'technical_amount' => 'Technical Amount',
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
            'technical_offer' => 'Technical Offer',
            'alarm' => 'Alarm',
            'date' => 'Date',
        ],

        'fields' => [
            'smb_note' => 'SMB Note',
            'lost_reason' => 'Loss Reason',
            'lost_reason_note' => 'Loss Note',
            'winning_competitor' => 'Winning Competitor',
            'alarm_at' => 'Alarm Time',
            'alarm_note' => 'Alarm Note',
        ],

        'actions' => [
            'action' => 'Action — Move to In-Hand',
            'action_modal_heading' => 'Move this operation to In-Hand?',
            'action_modal_description' => 'The client has accepted; SMB preparation begins. You can leave an optional SMB note.',
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

        'fields' => [
            'acceptance_email_at' => 'Acceptance Email Date',
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
            'cancel' => 'Cancel — Move to Lost',
            'cancel_modal_heading' => 'Cancel and move to Lost?',
            'set_alarm' => 'Set Alarm',
            'clear_alarm' => 'Clear Alarm',
        ],

        'notifications' => [
            'moved_to_active' => 'Operation moved to Active Operations.',
            'moved_to_lost' => 'Operation moved to Lost.',
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
        ],

        'fields' => [
            'po_number' => 'PO Number',
            'project' => 'Project',
            'supplier_name' => 'Supplier Name',
            'supplier_contact' => 'Supplier Contact',
            'status' => 'Status',
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
            'add_item' => 'Add Item',
        ],

        'notifications' => [
            'received' => 'Items received successfully',
            'receive_failed' => 'Receiving failed',
            'no_quantities' => 'No quantities entered',
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
        ],

        'fields' => [
            'name' => 'Supplier Name',
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
    | Customer Resource
    |--------------------------------------------------------------------------
    */
    'customers' => [
        'label' => 'Customer',
        'plural_label' => 'Customers',
        'navigation_label' => 'Customers',

        'sections' => [
            'details' => 'Customer Details',
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
        ],

        'fields' => [
            'voucher_number' => 'Voucher Number',
            'supplier' => 'Supplier',
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
            ],
            'project_offers' => [
                'view' => 'View Project Offers',
                'create' => 'Create Project Offer',
                'edit' => 'Edit Project Offers',
                'delete' => 'Delete Project Offers',
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

        'attachment_category' => [
            'upload' => 'UPLOAD',
            'vendor_list' => 'VENDOR LIST',
            'drowing' => 'DROWING',
            'speces' => 'SPECES',
            'boq' => 'BOQ',
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
    ],
];
