<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(['email' => 'admin@salon.test'], [
            'name' => 'Chủ tiệm',
            'password' => 'password',
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ]);
    }
}
