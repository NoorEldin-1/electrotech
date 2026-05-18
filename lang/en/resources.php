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
            'financial_timeline' => 'Financial & Timeline',
            'description' => 'Description',
        ],

        'fields' => [
            'code' => 'Project Code',
            'name' => 'Project / Operation Name',
            'client_name' => 'Client Name',
            'consultant_name' => 'Consultant Name',
            'status' => 'Status',
            'estimated_budget' => 'Estimated Budget',
            'actual_cost' => 'Actual Cost',
            'start_date' => 'Start Date',
            'end_date' => 'End Date',
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
            'quantity' => 'Quantity',
            'source' => 'Source',
            'notes' => 'Notes',
            'performed_by' => 'By',
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
            'submit_qa' => 'Submit QA',
            'approve_qa' => 'Approve QA',
            'complete' => 'Complete',
        ],

        'notifications' => [
            'started' => 'Work Order started',
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
            'items' => 'Items',
            'boms' => 'Bills of Materials',
            'inventory' => 'Inventory Control',
            'transactions' => 'Inventory Transactions',
            'purchase_orders' => 'Purchase Orders',
            'work_orders' => 'Work Orders',
            'users' => 'Users Management',
            'roles' => 'Roles Management',
            'activity_log' => 'Activity Logs',
            'dashboard' => 'Dashboard',
            'attachments' => 'Attachments',
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
            'in_progress' => 'In Progress',
            'on_hold' => 'On Hold',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
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
    | Common / Shared Strings
    |--------------------------------------------------------------------------
    */
    'common' => [
        'no_data' => '—',
        'created_at' => 'Created At',
    ],
];
