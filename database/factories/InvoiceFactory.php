<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'plumber_id' => User::factory()->state(['role' => 'plumber']),
            'subtotal_cents' => 100000,
            'tax_cents' => 0,
            'total_cents' => 100000,
            'currency' => 'LYD',
            'number' => 'INV-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
            'attachment_path' => 'invoices/test.pdf',
            'status' => 'pending_review',
        ];
    }
}
