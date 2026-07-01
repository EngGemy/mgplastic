<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\PlumberStore;
use App\Models\City;

class PlumberStoreSeeder extends Seeder
{
    public function run(): void
    {
        $city = City::query()->first(); // Use the first available city
        if (! $city) {
            $this->command?->warn('No cities found. Seed cities first.');
            return;
        }

        // 1) Create 3 vendor users
        $vendorsData = [
            [
                'name'       => 'Vendor One',
                'phone'      => '+201000000101',
                'email'      => 'vendor1@example.com',
            ],
            [
                'name'       => 'Vendor Two',
                'phone'      => '+201000000102',
                'email'      => 'vendor2@example.com',
            ],
            [
                'name'       => 'Vendor Three',
                'phone'      => '+201000000103',
                'email'      => 'vendor3@example.com',
            ],
        ];

        $vendors = [];
        foreach ($vendorsData as $data) {
            // Avoid duplicate phones on re-seed
            $vendor = User::firstOrCreate(
                ['phone' => $data['phone']],
                [
                    'name'        => $data['name'],
                    'email'       => $data['email'],
                    'role'        => User::ROLE_VENDOR ?? 'vendor',
                    'country_id'  => $city->country_id, // align with the city we’ll use
                    'city_id'     => $city->id,
                    'password'    => Hash::make('password'), // default password for dev
                    'remember_token' => Str::random(10),
                    'is_phone_verified' => true,
                ]
            );

            $vendors[] = $vendor;
            $this->command?->info("Vendor created: {$vendor->name} ({$vendor->phone})");
        }

        // 2) Create stores belonging to the first vendor (you can add more per vendor)
        $vendor = $vendors[0] ?? null;
        if (! $vendor) {
            $this->command?->warn('No vendor created. Aborting store seeding.');
            return;
        }

        try {
            // Example store #1
            $store1 = PlumberStore::create([
                'vendor_id'      => $vendor->id,      // << IMPORTANT: owner vendor
                'city_id'        => $city->id,
                'address'        => '123 Cairo Street',
                'available_date' => now()->toDateString(),
                'available_time' => now()->format('H:i'),
                'phone'          => '0100000000',
                'image'          => 'plumber_stores/store1.jpg', // ensure file exists if you plan to show it
                'latitude'       => 30.0444,
                'longitude'      => 31.2357,

                // Translations
                'en' => [
                    'name'        => 'MG Plumbing Store',
                    'description' => 'We sell plumbing tools and accessories',
                ],
                'ar' => [
                    'name'        => 'متجر MG للسباكة',
                    'description' => 'نبيع أدوات السباكة والإكسسوارات',
                ],
            ]);

            // Example store #2 (optional) for diversity
            $store2 = PlumberStore::create([
                'vendor_id'      => $vendor->id,
                'city_id'        => $city->id,
                'address'        => '456 Giza Avenue',
                'available_date' => now()->toDateString(),
                'available_time' => now()->format('H:i'),
                'phone'          => '0100000001',
                'image'          => null,
                'latitude'       => 29.9870,
                'longitude'      => 31.2118,

                'en' => [
                    'name'        => 'Delta Pipe Supplies',
                    'description' => 'Pipes, fittings, and professional support',
                ],
                'ar' => [
                    'name'        => 'دلتا لمستلزمات المواسير',
                    'description' => 'مواسير ووصلات ودعم فني',
                ],
            ]);

            $this->command?->info("Plumber stores created: #{$store1->id}, #{$store2->id}");
        } catch (\Throwable $e) {
            $this->command?->error("Error creating stores: {$e->getMessage()}");
        }
    }
}
