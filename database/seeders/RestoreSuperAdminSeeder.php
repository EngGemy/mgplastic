<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Ensures a working super_admin after CentralWarehouseSeeder historically
 * clobbered phone 0910000001 / the admin row via updateOrCreate(phone).
 */
class RestoreSuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'superadmin@mgplastic.com';
        $phone = '0910000001';

        // If warehouse still owns the admin phone, move warehouse off it first.
        User::query()
            ->where('phone', $phone)
            ->where(function ($q) {
                $q->where('is_central_warehouse', true)
                    ->orWhere('email', 'warehouse@mg-plastic.com')
                    ->orWhere('role', 'wholesale_distributor');
            })
            ->where('role', '!=', 'super_admin')
            ->update(['phone' => '0910000091']);

        $admin = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => 'مدير المصنع',
                'phone' => $phone,
                'password' => Hash::make('password'),
                'role' => 'super_admin',
                'is_central_warehouse' => false,
                'is_approved' => true,
                'is_active' => true,
                'is_phone_verified' => true,
                'deactivated_at' => null,
                'brand_name' => null,
            ],
        );

        // Force role even if the row already existed as warehouse.
        $admin->forceFill([
            'role' => 'super_admin',
            'is_central_warehouse' => false,
            'is_approved' => true,
            'is_active' => true,
            'phone' => $phone,
            'password' => Hash::make('password'),
        ])->save();

        $this->command?->info('Super admin restored');
        $this->command?->line("  email: {$email}");
        $this->command?->line("  phone: {$phone}");
        $this->command?->line('  password: password');
        $this->command?->line('  panel: /'.trim((string) config('portal.admin_path', 'admin'), '/'));
    }
}
