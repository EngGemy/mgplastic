<?php

namespace App\Services\Ocr;

/**
 * Single primary UI action for a draft line — app should not re-derive this.
 */
class OcrLineUiAction
{
    /**
     * @param  array<string, mixed>  $line
     * @return 'none'|'confirm_understanding'|'choose_family'|'pick_candidate'|'fix_qty'
     */
    public function for(array $line): string
    {
        if (! empty($line['approved']) || in_array(($line['review_status'] ?? ''), ['approved', 'added'], true)) {
            return 'none';
        }

        $flags = (array) ($line['needs_review'] ?? []);
        $hasFamilyChoice = in_array('ambiguous_family', $flags, true)
            || in_array('unknown_word', $flags, true)
            || ! empty($line['family_candidates'])
            || ! empty($line['family_chips']);

        if ($hasFamilyChoice) {
            return 'choose_family';
        }

        if (in_array('unconfirmed_term', $flags, true) && ! empty($line['understanding'])) {
            return 'confirm_understanding';
        }

        $candidates = (array) ($line['candidates'] ?? []);
        if (
            (in_array('needs_choice', $flags, true) || empty($line['matched_product_id']))
            && $candidates !== []
        ) {
            return 'pick_candidate';
        }

        if (
            in_array('unit_assumed', $flags, true)
            || in_array('qty', $flags, true)
            || in_array('qty_missing', $flags, true)
            || ! isset($line['qty'])
            || (float) ($line['qty'] ?? 0) < 1
        ) {
            return 'fix_qty';
        }

        return 'none';
    }
}
