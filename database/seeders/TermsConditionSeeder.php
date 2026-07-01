<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TermsCondition;

class TermsConditionSeeder extends Seeder
{
    public function run(): void
    {
        TermsCondition::create([
            'slug' => 'mg-terms',
            'en' => [
                'title' => 'Terms and Conditions',
                'content' => 'These are the English terms and conditions for MG company mobile app.',
            ],
            'ar' => [
                'title' => 'الشروط والأحكام',
                'content' => 'هذه هي الشروط والأحكام باللغة العربية لتطبيق شركة MG.',
            ],
        ]);
    }
}
