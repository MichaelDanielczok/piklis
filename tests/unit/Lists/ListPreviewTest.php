<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Lists;

use MichaelDanielczok\Piklis\Lists\ListPreview;
use PHPUnit\Framework\TestCase;

/**
 * Vorschau der Listenübersicht: erste offene Einträge je Liste.
 */
class ListPreviewTest extends TestCase
{
    /**
     * @return array{list_id: string, id: string, product_id: string, sort_key: string}
     */
    private static function row(string $listId, string $id, string $productId, string $sortKey): array
    {
        return [
            'list_id' => $listId,
            'id' => $id,
            'product_id' => $productId,
            'sort_key' => $sortKey
        ];
    }

    public function testOrdersBySortKeyPerList(): void
    {
        $rows = [
            self::row('lidl', 'e3', 'butter', 'k'),
            self::row('rewe', 'e9', 'tea', 'i'),
            self::row('lidl', 'e1', 'milk', 'i'),
            self::row('lidl', 'e2', 'bread', 'j')
        ];

        $this->assertSame(
            [
                'lidl' => ['milk', 'bread', 'butter'],
                'rewe' => ['tea']
            ],
            ListPreview::firstProducts($rows)
        );
    }

    public function testLimitsProductsPerList(): void
    {
        $rows = [
            self::row('lidl', 'e1', 'p1', 'i'),
            self::row('lidl', 'e2', 'p2', 'j'),
            self::row('lidl', 'e3', 'p3', 'k'),
            self::row('lidl', 'e4', 'p4', 'l'),
            self::row('lidl', 'e5', 'p5', 'm')
        ];

        $this->assertSame(['lidl' => ['p1', 'p2']], ListPreview::firstProducts($rows, 2));
        $this->assertCount(ListPreview::SIZE, ListPreview::firstProducts($rows)['lidl']);
    }

    public function testEqualKeysSortByEntryId(): void
    {
        $rows = [
            self::row('lidl', 'b', 'second', 'i'),
            self::row('lidl', 'a', 'first', 'i')
        ];

        $this->assertSame(['lidl' => ['first', 'second']], ListPreview::firstProducts($rows));
    }

    public function testComparesAsPlainStrings(): void
    {
        // Basis 36: Ziffern vor Buchstaben, wie beim Vergleich der Schlüssel im Code
        $rows = [
            self::row('lidl', 'e1', 'later', 'ii'),
            self::row('lidl', 'e2', 'earlier', 'i1')
        ];

        $this->assertSame(['lidl' => ['earlier', 'later']], ListPreview::firstProducts($rows));
    }

    public function testEmptyInput(): void
    {
        $this->assertSame([], ListPreview::firstProducts([]));
    }
}
