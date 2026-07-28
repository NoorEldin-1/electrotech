<?php

declare(strict_types=1);

return [
    'groups' => [
        'general_management' => 'General Management',
        'sales_crm' => 'Sales & CRM',
        'technical_office' => 'Project Management Office',
        'warehouse' => 'Warehouse',
        'procurement' => 'Procurement',
        'manufacturing' => 'Manufacturing',
        'finance' => 'Finance',
        'system' => 'System',
    ],

    'user_menu' => [
        'language' => 'Language',
        'appearance' => 'Appearance',
        // The documentation page itself is Arabic-only by design; the trigger
        // is still labelled in both locales so the menu never mixes languages.
        'documentation' => 'Platform Guide',
        'documentation_hint' => 'Every screen and every button, explained',
        'documentation_badge' => 'New',
        'logout_confirm_heading' => 'Sign out?',
        'logout_confirm_description' => 'You will be returned to the login screen and will need to sign in again to continue.',
        'logout_confirm_button' => 'Sign out',
        'cancel' => 'Cancel',
    ],
];
