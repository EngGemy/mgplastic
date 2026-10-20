<?php

namespace App\Console\Commands;

use App\Services\Testimonials\TestimonialGeneratorService;
use Illuminate\Console\Command;

class TestimonialsGenerateCommand extends Command
{
    protected $signature = 'testimonials:generate {--count= : Number of testimonials to create}';

    protected $description = 'Generate daily MG product testimonials via Gemini (with template fallback)';

    public function handle(TestimonialGeneratorService $generator): int
    {
        if (! config('testimonials.enabled', true) || ! config('testimonials.daily_generate', true)) {
            $this->warn('Testimonials generation disabled');

            return self::SUCCESS;
        }

        $count = $this->option('count') !== null ? (int) $this->option('count') : null;
        $rows = $generator->generateDaily($count);
        $this->info('Created '.count($rows).' testimonial(s)');
        foreach ($rows as $t) {
            $this->line(' · '.$t->name_ar.' — '.$t->source.' — '.$t->product_hint);
        }

        return self::SUCCESS;
    }
}
