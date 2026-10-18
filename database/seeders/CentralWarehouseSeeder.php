<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\NetworkCodeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CentralWarehouseSeeder extends Seeder
{
    public function run(): void
    {
        // Dedicated phone — must NOT collide with super_admin (0910000001 in UserSeeder).
        $phone = '0910000091';
        $email = 'warehouse@mg-plastic.com';

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => 'مخزن الدعوة',
                'brand_name' => 'مخزن الدعوة',
                'phone' => $phone,
                'password' => Hash::make('Warehouse@2026'),
                'role' => 'wholesale_distributor',
                'is_central_warehouse' => true,
                'is_approved' => true,
                'approved_at' => now(),
                'is_active' => true,
                'is_phone_verified' => true,
                'deactivated_at' => null,
                'store_description' => 'المخزن المركزي — يستقبل طلبيات الجملة والقطاعي، يحدّث المخزون، ويطلب من المصنع مباشرة.',
                'short_description' => 'مخزن الدعوة المركزي لشبكة MG Plastic',
            ],
        );

        // Never leave a stolen phone on a former admin account if an old seeder clobbered it.
        User::query()
            ->where('phone', '0910000001')
            ->where('role', 'wholesale_distributor')
            ->where('email', $email)
            ->whereKey($user->id)
            ->update(['phone' => $phone]);

        // Also promote any existing "مخزن الدعوة" account created manually (do not touch admins).
        User::query()
            ->where(function ($q) {
                $q->where('brand_name', 'مخزن الدعوة')
                    ->orWhere('name', 'مخزن الدعوة')
                    ->orWhere('email', 'warehouse@mg-plastic.com');
            })
            ->where('role', 'wholesale_distributor')
            ->update([
                'is_central_warehouse' => true,
                'is_approved' => true,
                'is_active' => true,
            ]);

        app(NetworkCodeService::class)->ensure($user);

        $this->command?->info('مخزن الدعوة جاهز');
        $this->command?->line('  الدخول: /warehouse');
        $this->command?->line("  الهاتف: {$phone}");
        $this->command?->line("  البريد: {$email}");
        $this->command?->line('  كلمة المرور: Warehouse@2026');
        $this->command?->line('  الرقم الموحّد: '.$user->fresh()->network_code);
    }
}
