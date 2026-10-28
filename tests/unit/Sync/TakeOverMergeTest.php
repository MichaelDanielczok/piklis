<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Sync;

use MichaelDanielczok\Piklis\Lists\Quantity;
use MichaelDanielczok\Piklis\Sync\Operation;
use MichaelDanielczok\Piklis\Sync\TakeOverMerge;
use MichaelDanielczok\Piklis\Sync\UnitMismatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function json_decode;
use function mb_strlen;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

/**
 * Zusammenführen beim Übernehmen in eine andere Liste; dieselben Fälle prüft
 * tests/js/takeOver.test.js.
 */
class TakeOverMergeTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function mergeCases(): iterable
    {
        $json = (string)file_get_contents(__DIR__ . '/../../fixtures/take-over.json');
        $fixture = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        foreach ($fixture['merge'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('mergeCases')]
    public function testMergeMatchesFixture(array $case): void
    {
        $merged = TakeOverMerge::merge(
            Quantity::fromArray($case['existing']['quantity']),
            $case['existing']['note'],
            Quantity::fromArray($case['incoming']['quantity']),
            $case['incoming']['note'],
            UnitMismatch::from($case['mode']),
            $case['text']
        );

        $this->assertEquals($case['expected']['quantity'], $merged['quantity']->toArray());
        $this->assertSame($case['expected']['note'], $merged['note']);
        $this->assertSame($case['expected']['result'], $merged['result']);
    }

    public function testMergedNoteStaysWithinTheLimit(): void
    {
        $long = str_repeat('a', Operation::NOTE_MAX_LENGTH - 10);
        $note = TakeOverMerge::mergeNotes($long, 'für den Kuchen');

        $this->assertLessThanOrEqual(Operation::NOTE_MAX_LENGTH, mb_strlen($note));
        $this->assertStringStartsWith($long . ' · f', $note);
    }

    public function testPlusOnlyForTheSameUnit(): void
    {
        $this->assertNull(Quantity::create(1, 'pack')->plus(Quantity::create(1, null)));
        $this->assertSame(['amount' => 2, 'unit' => 'pack'], Quantity::create(1, 'pack')->plus(Quantity::create(1, 'pack'))?->toArray());
    }
}
