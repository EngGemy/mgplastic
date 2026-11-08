<?php

namespace App\Services\Ocr;

/**
 * Detect qty unit words on the paper and set qty_unit / qty_unit_source.
 */
class OcrQtyUnitResolver
{
    /** @var list<string> */
    protected array $boxWords = [
        'كرتونة', 'كرتونه', 'كرتون', 'صندوق', 'صناديق', 'علبة', 'علب', 'كارتون',
        'box', 'carton', 'cartons',
    ];

    /** @var list<string> */
    protected array $pieceWords = [
        'قطعة', 'قطعه', 'قطع', 'حبة', 'حبه', 'حبات', 'فرد', 'واحد',
        'دستة', 'دسته', 'دزينه', 'دزينة',
        'piece', 'pieces', 'pc', 'pcs',
    ];

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public function apply(array $line, ?int $piecesPerBox = null): array
    {
        $ppb = $piecesPerBox ?? (int) ($line['pieces_per_box'] ?? 0);
        $raw = mb_strtolower(trim((string) ($line['raw_text'] ?? $line['name'] ?? '')));

        $fromPaper = $this->detectFromText($raw);
        if ($fromPaper !== null) {
            $line['qty_unit'] = $fromPaper;
            $line['qty_unit_source'] = 'paper';
        } else {
            // Default: boxed catalog products → boxes; otherwise pieces.
            $line['qty_unit'] = $ppb > 1 ? 'box' : 'piece';
            $line['qty_unit_source'] = 'default';
        }

        $flags = array_values((array) ($line['needs_review'] ?? []));
        $flags = array_values(array_filter($flags, fn ($f) => $f !== 'unit_assumed'));
        if (($line['qty_unit_source'] ?? '') === 'default' && $ppb > 1) {
            $flags[] = 'unit_assumed';
        }
        $line['needs_review'] = array_values(array_unique($flags));

        return $line;
    }

    public function detectFromText(string $text): ?string
    {
        if ($text === '') {
            return null;
        }

        foreach ($this->boxWords as $w) {
            if (mb_strpos($text, mb_strtolower($w)) !== false) {
                return 'box';
            }
        }
        foreach ($this->pieceWords as $w) {
            if (mb_strpos($text, mb_strtolower($w)) !== false) {
                return 'piece';
            }
        }

        return null;
    }
}
