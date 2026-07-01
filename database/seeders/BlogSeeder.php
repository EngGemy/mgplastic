<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\BlogCategory;
use App\Models\Blog;

class BlogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // 1) Ensure an admin exists
            $admin = User::where('role', 'admin')->first();
            if (! $admin) {
                $admin = User::create([
                    'name'     => 'Admin',
                    'email'    => 'admin@example.com',
                    'phone'    => '01000000000',
                    'role'     => 'admin',
                    'password' => Hash::make('password'),
                ]);
                $this->command?->warn('No admin found. Created default admin: admin@example.com / password');
            }

            // 2) Ensure categories exist
            if (BlogCategory::count() === 0) {
                $defaultCats = ['General', 'Tips', 'News', 'How-To'];
                foreach ($defaultCats as $c) {
                    BlogCategory::firstOrCreate(['name' => $c]);
                }
                $this->command?->info('Created default blog categories.');
            }

            // 3) Create one blog per category if not exists
            $created = 0;
            foreach (BlogCategory::all() as $category) {
                // Avoid making duplicates: check if an approved blog for this category by this admin already exists
                $exists = Blog::where('category_id', $category->id)
                    ->where('user_id', $admin->id)
                    ->where('status', 'approved')
                    ->exists();

                if (! $exists) {
                    Blog::create([
                        'category_id' => $category->id,
                        'user_id'     => $admin->id,
                        'title'       => "Sample blog in {$category->name}",
                        'description' => "This is a sample blog post for category {$category->name}.",
                        'image'       => null,
                        'status'      => 'approved',
                    ]);
                    $created++;
                }
            }

            $this->command?->info("BlogSeeder: created {$created} blog(s).");
        });
    }
}
