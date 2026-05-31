<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name' => 'System Administrator',
                'email' => 'admin@electrotech.com',
                'role' => 'Admin',
            ],
            [
                'name' => 'Ahmed Hassan (Sales)',
                'email' => 'sales@electrotech.com',
                'role' => 'Sales',
            ],
            [
                'name' => 'Mona Saeed (Sales Manager)',
                'email' => 'sales.manager@electrotech.com',
                'role' => 'Sales_Manager',
            ],
            [
                'name' => 'Eng. Mohamed Ali (Technical)',
                'email' => 'technical@electrotech.com',
                'role' => 'Technical_Office',
            ],
            [
                'name' => 'Sara Ibrahim (Procurement)',
                'email' => 'procurement@electrotech.com',
                'role' => 'Procurement',
            ],
            [
                'name' => 'Eng. Khaled Omar (Factory)',
                'email' => 'factory@electrotech.com',
                'role' => 'Factory_Manager',
            ],
            [
                'name' => 'Youssef Mahmoud (Warehouse)',
                'email' => 'warehouse@electrotech.com',
                'role' => 'Warehouse_Manager',
            ],
        ];

        foreach ($users as $userData) {
            $user = User::firstOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );

            $user->assignRole($userData['role']);
        }
    }
}
