<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $city = City::first();

        if (! $city) {
            return;
        }

        User::firstOrCreate(
            ['email' => 'superadmin@mgplastic.com'],
            [
                'name' => 'مدير المصنع',
                'password' => Hash::make('password'),
                'role' => 'super_admin',
                'phone' => '0910000001',
                'city_id' => $city->id,
                'country_id' => $city->country_id,
                'is_approved' => true,
                'is_active' => true,
                'is_phone_verified' => true,
            ]
        );

        User::firstOrCreate(
            ['email' => 'johndoe@example.com'],
            [
                'name' => 'John Doe',
                'password' => Hash::make('password'),
                'role' => 'user',
                'phone' => '0100000001',
                'city_id' => $city->id,
                'country_id' => $city->country_id,
            ]
        );

        User::firstOrCreate(
            ['email' => 'ahmedplumber@example.com'],
            [
                'name' => 'Ahmed Plumber',
                'password' => Hash::make('password'),
                'role' => 'plumber',
                'phone' => '0100000002',
                'city_id' => $city->id,
                'country_id' => $city->country_id,
            ]
        );

        User::firstOrCreate(
            ['email' => 'aliplumber@example.com'],
            [
                'name' => 'Ali Plumber',
                'password' => Hash::make('password'),
                'role' => 'plumber',
                'phone' => '0100000003',
                'city_id' => $city->id,
                'country_id' => $city->country_id,
            ]
        );
    }
}
