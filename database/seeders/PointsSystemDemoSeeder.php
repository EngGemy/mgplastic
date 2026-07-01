<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceDistributionItem;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\SystemLabel;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WithdrawalRequest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class PointsSystemDemoSeeder extends Seeder
{
    public function run(): void
    {
        $city = City::first();
        if (! $city) {
            $this->command?->warn('PointsSystemDemoSeeder: no city found — run CountrySeeder & CitySeeder first.');

            return;
        }

        // ── 1) مستخدمون سلسلة التوزيع ─────────────────────────────
        $superAdmin = User::firstOrCreate(
            ['email' => 'superadmin@mgplastic.com'],
            [
                'name' => 'مدير المصنع',
                'phone' => '0910000001',
                'password' => Hash::make('password'),
                'role' => 'super_admin',
                'city_id' => $city->id,
                'country_id' => $city->country_id,
                'is_approved' => true,
                'is_active' => true,
                'is_phone_verified' => true,
            ]
        );

        $wholesaler = User::firstOrCreate(
            ['email' => 'wholesaler@mgplastic.com'],
            [
                'name' => 'موزع الجملة — خالد',
                'phone' => '0910000002',
                'password' => Hash::make('password'),
                'role' => 'wholesale_distributor',
                'city_id' => $city->id,
                'country_id' => $city->country_id,
                'address' => 'طرابلس — شارع الجمهورية',
                'latitude' => 32.8872,
                'longitude' => 13.1913,
                'store_description' => 'متجر MG Plastic الرئيسي — موزع جملة',
                'is_approved' => true,
                'is_active' => true,
                'is_phone_verified' => true,
            ]
        );

        $retailer = User::firstOrCreate(
            ['email' => 'retailer@mgplastic.com'],
            [
                'name' => 'تاجر القطاعي — سالم',
                'phone' => '0910000003',
                'password' => Hash::make('password'),
                'role' => 'retail_trader',
                'parent_distributor_id' => $wholesaler->id,
                'city_id' => $city->id,
                'country_id' => $city->country_id,
                'address' => 'طرابلس — حي الأندلس',
                'latitude' => 32.8790,
                'longitude' => 13.1800,
                'is_approved' => true,
                'is_active' => true,
                'is_phone_verified' => true,
            ]
        );

        $plumber = User::firstOrCreate(
            ['email' => 'plumber.demo@mgplastic.com'],
            [
                'name' => 'سباك تجريبي — محمود',
                'phone' => '0910000004',
                'password' => Hash::make('password'),
                'role' => 'plumber',
                'parent_distributor_id' => $retailer->id,
                'city_id' => $city->id,
                'country_id' => $city->country_id,
                'is_approved' => true,
                'is_active' => true,
                'is_phone_verified' => true,
            ]
        );

        // ── 2) مثال على تخصيص مسمى (يظهر في Dashboard + Users) ───
        $plumberLabel = SystemLabel::where('key', 'plumber')->first();
        if ($plumberLabel && empty($plumberLabel->custom_value)) {
            $plumberLabel->update(['custom_value' => 'حرفي']);
            Cache::forget('system_label_plumber');
        }

        // ── 3) منتج بنقاط ───────────────────────────────────────────
        $product = Product::query()->first();
        if ($product) {
            $product->update(['points_per_unit' => 5]);
        }

        // ── 4) فاتورة POS جملة معتمدة + بند ───────────────────────
        $approvedInvoice = Invoice::firstOrCreate(
            ['serial_number' => 1],
            [
                'invoice_type' => 'wholesale_pos',
                'wholesale_distributor_id' => $wholesaler->id,
                'plumber_id' => null,
                'subtotal_cents' => 100000,
                'tax_cents' => 0,
                'total_cents' => 100000,
                'currency' => 'LYD',
                'number' => 'MG-J-'.now()->format('Y').'-000001',
                'attachment_path' => null,
                'status' => 'approved',
                'approved_at' => now(),
                'reviewed_by' => $superAdmin->id,
                'issued_by' => $superAdmin->id,
                'profit_percent' => null,
                'points_awarded' => 0,
            ]
        );

        if ($product) {
            InvoiceItem::firstOrCreate(
                ['invoice_id' => $approvedInvoice->id, 'product_id' => $product->id],
                [
                    'quantity' => 100,
                    'unit_price_cents' => 1000,
                    'points_per_unit' => 5,
                    'total_points' => 500,
                ]
            );
        }

        // ── 5) فاتورة معلّقة (badge أحمر في الوصول السريع) ────────
        Invoice::firstOrCreate(
            ['number' => 'INV-DEMO-PENDING'],
            [
                'plumber_id' => $plumber->id,
                'subtotal_cents' => 50000,
                'tax_cents' => 0,
                'total_cents' => 50000,
                'currency' => 'LYD',
                'attachment_path' => 'invoices/demo/pending.pdf',
                'status' => 'pending_review',
            ]
        );

        // ── 6) توزيع مسودة (badge أصفر) ─────────────────────────────
        if ($product) {
            $invoiceItem = InvoiceItem::where('invoice_id', $approvedInvoice->id)
                ->where('product_id', $product->id)
                ->first();

            if ($invoiceItem) {
                $draftDist = InvoiceDistribution::firstOrCreate(
                    [
                        'invoice_id' => $approvedInvoice->id,
                        'from_user_id' => $superAdmin->id,
                        'to_user_id' => $wholesaler->id,
                        'tier' => 1,
                        'status' => 'draft',
                    ],
                    ['parent_distribution_id' => null]
                );

                InvoiceDistributionItem::firstOrCreate(
                    [
                        'distribution_id' => $draftDist->id,
                        'invoice_item_id' => $invoiceItem->id,
                    ],
                    [
                        'quantity' => 50,
                        'points_value' => 250,
                    ]
                );
            }
        }

        // ── 7) طلب سحب معلّق (badge أصفر) ───────────────────────────
        $wallet = WalletAccount::firstOrCreate(
            ['owner_id' => $plumber->id, 'currency' => 'LYD'],
            ['balance_cents' => 0, 'balance_points' => 150]
        );

        WithdrawalRequest::firstOrCreate(
            [
                'plumber_id' => $plumber->id,
                'wallet_account_id' => $wallet->id,
                'amount_cents' => 5000,
                'status' => 'pending',
            ],
            [
                'method' => 'bank_transfer',
                'details' => ['iban' => 'LY00000000000000000000', 'name' => $plumber->name],
            ]
        );

        $this->command?->info('Points demo seeded. Login: superadmin@mgplastic.com / password');
        $this->command?->info('Try changing «سباك» → «حرفي» in الإعدادات → المسميات, then refresh Dashboard.');
    }
}
