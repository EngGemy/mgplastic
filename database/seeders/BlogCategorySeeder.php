<?php


namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\BlogCategory;

class BlogCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            'Plumbing Tips',
            'DIY Home Repairs',
            'Product Reviews',
            'Industry News',
            'Events & Workshops',
        ];

        foreach ($categories as $category) {
            BlogCategory::create(['name' => $category]);
        }
    }
}
