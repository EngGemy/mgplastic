<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-shot: cancel stuck shipping orders that cannot be received (no supplier stock)
 * and previously could not be cancelled either.
 */
return new class extends Migration
{
    public function up(): void
    {
        $numbers = [
            'ORD-2026-000010',
            'ORD-2026-000011',
            'ORD-2026-000013',
            'ORD-2026-000014',
        ];

        $suffix = 'إلغاء تلقائي: تعذّر الاستلام لعدم كفاية المخزون (2026-10-17)';

        $rows = DB::table('orders')
            ->whereIn('order_number', $numbers)
            ->where('status', 'shipping')
            ->get(['id', 'note']);

        foreach ($rows as $row) {
            $note = trim((string) ($row->note ?? ''));
            $note = $note === '' ? $suffix : $note."\n".$suffix;

            DB::table('orders')->where('id', $row->id)->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'note' => $note,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Irreversible data fix — left intentionally empty.
    }
};
