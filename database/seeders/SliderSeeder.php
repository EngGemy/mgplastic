<?php

namespace Database\Seeders;

use App\Models\Slider;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SliderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Slider::create(['image' => 'sliders/slide1.jpg']);
        Slider::create(['image' => 'sliders/slide2.jpg']);
        Slider::create(['image' => 'sliders/slide3.jpg']);
    }
}
