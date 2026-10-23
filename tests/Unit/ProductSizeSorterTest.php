<?php

namespace Tests\Unit;

use App\Support\ProductSizeSorter;
use PHPUnit\Framework\TestCase;

class ProductSizeSorterTest extends TestCase
{
    public function test_parses_inch_and_mm_tags(): void
    {
        $this->assertSame(1.0, ProductSizeSorter::parseDetailed('1 بوصه')['value']);
        $this->assertSame(1.5, ProductSizeSorter::parseDetailed('1.5 بوصه')['value']);
        $this->assertSame(2.0, ProductSizeSorter::parseDetailed('2 بوصة')['value']);
        $this->assertSame(6.0, ProductSizeSorter::parseDetailed('6 بوصه')['value']);
        $this->assertSame(110.0, ProductSizeSorter::parseDetailed('110 مم')['value']);
        $this->assertSame('mm', ProductSizeSorter::parseDetailed('110 مم')['unit']);
        $this->assertSame(3.0, ProductSizeSorter::parseDetailed('كوع 90 - 3 بوصة MG')['value']);
    }

    public function test_tag_groups_sort_asc_by_number_in_tag(): void
    {
        $groups = [
            ['tag' => '110 مم', 'name' => '110 مم'],
            ['tag' => '6 بوصه', 'name' => '6 بوصه'],
            ['tag' => '1.5 بوصه', 'name' => '1.5 بوصه'],
            ['tag' => '2 بوصه', 'name' => '2 بوصه'],
            ['tag' => '4 بوصه', 'name' => '4 بوصه'],
            ['tag' => 'أخرى', 'name' => 'أخرى'],
            ['tag' => '1 بوصه', 'name' => '1 بوصه'],
        ];

        $sorted = ProductSizeSorter::sortTagGroupsByNumber($groups, 'asc');
        $names = array_column($sorted, 'tag');

        $this->assertSame(
            ['1 بوصه', '1.5 بوصه', '2 بوصه', '4 بوصه', '6 بوصه', '110 مم', 'أخرى'],
            $names,
        );
        $this->assertSame(0, $sorted[0]['sort_index']);
        $this->assertSame(6, $sorted[6]['sort_index']);
    }

    public function test_tag_groups_sort_desc(): void
    {
        $groups = [
            ['tag' => '1 بوصه'],
            ['tag' => '6 بوصه'],
            ['tag' => '110 مم'],
            ['tag' => 'أخرى'],
        ];

        $names = array_column(ProductSizeSorter::sortTagGroupsByNumber($groups, 'desc'), 'tag');

        $this->assertSame(['110 مم', '6 بوصه', '1 بوصه', 'أخرى'], $names);
    }

    public function test_excel_sheet_tag_set_sorts_numerically_not_lexicographically(): void
    {
        $groups = [
            ['tag' => '110 مم'],
            ['tag' => '1.5 بوصه'],
            ['tag' => '75 مم'],
            ['tag' => '2 بوصه'],
            ['tag' => '160 مم'],
            ['tag' => '1 بوصه'],
            ['tag' => '6 بوصه'],
            ['tag' => '90 مم'],
            ['tag' => '4 بوصه'],
            ['tag' => '3 بوصه'],
            ['tag' => '1.5 بوصة'], // variant spelling
        ];

        $asc = array_column(ProductSizeSorter::sortTagGroupsByNumber($groups, 'asc'), 'tag');

        // Lexicographic would put 110 before 2 — numeric must not.
        $this->assertSame('1 بوصه', $asc[0]);
        $this->assertContains($asc[1], ['1.5 بوصه', '1.5 بوصة']);
        $this->assertSame('2 بوصه', $asc[3]);
        $pos110 = array_search('110 مم', $asc, true);
        $pos2 = array_search('2 بوصه', $asc, true);
        $this->assertIsInt($pos110);
        $this->assertIsInt($pos2);
        $this->assertGreaterThan($pos2, $pos110);
    }
}
