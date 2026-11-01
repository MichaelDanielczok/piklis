<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Templates;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Rest\TemplatesEndpoint;
use MichaelDanielczok\Piklis\Sync\ListRole;
use MichaelDanielczok\Piklis\Templates\TemplateEntry;
use MichaelDanielczok\Piklis\Templates\TemplateException;
use MichaelDanielczok\Piklis\Templates\TemplateService;
use MichaelDanielczok\Piklis\Templates\TemplateState;
use MichaelDanielczok\Piklis\Tests\Unit\Templates\Fixtures\InMemoryTemplateStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function str_repeat;

/**
 * Vorlagen (Konzept „Vorlagen“): anlegen, ganz speichern mit Revision,
 * gleichzeitiges Speichern, Rechte im Haushalt, verschieben, kopieren,
 * löschen.
 */
class TemplateServiceTest extends TestCase
{
    private const MICHAEL = 'user-michael';
    private const EWA = 'user-ewa';
    private const TOMASZ = 'user-tomasz';
    private const HOUSEHOLD = '22222222-2222-4222-8222-222222222222';
    private const TEMPLATE_ID = '33333333-3333-4333-8333-333333333333';
    private const COPY_ID = '44444444-4444-4444-8444-444444444444';
    private const MILK = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const BREAD = 'aaaaaaaa-0000-4000-8000-000000000002';
    private const EGGS = 'aaaaaaaa-0000-4000-8000-000000000003';
    private const UNKNOWN = 'aaaaaaaa-0000-4000-8000-000000000099';

    private InMemoryTemplateStore $Store;

    private TemplateService $Service;

    protected function setUp(): void
    {
        $this->Store = new InMemoryTemplateStore();
        $this->Store->householdMembers[self::HOUSEHOLD] = [self::MICHAEL, self::EWA];
        $this->Store->products = [self::MILK, self::BREAD, self::EGGS];
        $this->Service = new TemplateService($this->Store);
    }

    public function testCreatesPersonalTemplateInTheSentOrder(): void
    {
        $result = $this->createLidl(null);
        $Template = $result['template'];

        $this->assertSame('created', $result['status']);
        $this->assertSame('Wocheneinkauf Lidl', $Template->name);
        $this->assertSame(self::MICHAEL, $Template->ownerUser);
        $this->assertNull($Template->householdId);
        $this->assertSame(1, $Template->revision);
        $this->assertSame([self::BREAD, self::MILK, self::EGGS], $this->productsOf($Template));
        $this->assertSame(['amount' => 2, 'unit' => 'pack'], $Template->entries()[1]->quantity->toArray());
        $this->assertSame('', $Template->entries()[0]->note);
        $this->assertSame('die große', $Template->entries()[2]->note);
    }

    public function testCreatingTheSameIdAgainIsUnchanged(): void
    {
        $this->createLidl(null);
        $again = $this->createLidl(null);

        $this->assertSame('unchanged', $again['status']);
        $this->assertSame(1, $this->Store->writes);
    }

    public function testSomebodyElsesIdIsRejected(): void
    {
        $this->createLidl(null);

        $this->expectError('template_exists', 409, fn() => $this->Service->create(
            ['id' => self::TEMPLATE_ID, 'name' => 'Grillen', 'entries' => []],
            self::TOMASZ
        ));
    }

    public function testHouseholdTemplateOnlyForTheOwnHousehold(): void
    {
        $this->expectError('forbidden', 403, fn() => $this->Service->create(
            ['id' => self::TEMPLATE_ID, 'name' => 'Grillen', 'householdId' => self::HOUSEHOLD, 'entries' => []],
            self::TOMASZ
        ));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'no name' => [['name' => '  '], 'invalid_template', 'name'];
        yield 'long name' => [['name' => str_repeat('x', 101)], 'invalid_template', 'name'];
        yield 'entries not a list' => [['entries' => ['a' => 1]], 'invalid_template', 'entries'];
        yield 'twice the same product' => [
            ['entries' => [['productId' => self::MILK], ['productId' => self::MILK]]],
            'invalid_template',
            'entries'
        ];
        yield 'bad quantity' => [
            ['entries' => [['productId' => self::MILK, 'quantity' => ['amount' => 1.5, 'unit' => 'pack']]]],
            'invalid_template',
            'entries'
        ];
        yield 'unknown product' => [
            ['entries' => [['productId' => self::UNKNOWN]]],
            'unknown_product',
            self::UNKNOWN
        ];
        yield 'no id' => [['id' => 'x'], 'invalid_request', 'id'];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidInput(array $input, string $code, string $field): void
    {
        $input += ['id' => self::TEMPLATE_ID, 'name' => 'Grillen', 'entries' => []];

        try {
            $this->Service->create($input, self::MICHAEL);
            $this->fail('Expected ' . $code);
        } catch (TemplateException $Exception) {
            $this->assertSame($code, $Exception->getErrorCode());
            $this->assertSame($field, $Exception->getField());
        }

        $this->assertSame([], $this->Store->templates);
    }

    public function testHouseholdMemberSeesAndSavesTheHouseholdTemplate(): void
    {
        $this->createLidl(self::HOUSEHOLD);

        $Template = $this->Service->get(self::TEMPLATE_ID, self::EWA);
        $this->assertSame(ListRole::Member, TemplateService::roleOf($Template, self::EWA, self::HOUSEHOLD));

        $milkId = $Template->entries()[1]->id;
        $Saved = $this->Service->save(
            self::TEMPLATE_ID,
            [
                'name' => 'Wocheneinkauf Lidl',
                'entries' => [
                    ['productId' => self::MILK, 'quantity' => ['amount' => 3, 'unit' => 'pack']],
                    ['productId' => self::BREAD]
                ]
            ],
            1,
            self::EWA,
            new DateTimeImmutable('2026-11-01 10:00:00')
        );

        $this->assertSame(2, $Saved->revision);
        $this->assertSame(self::EWA, $Saved->updatedBy);
        $this->assertSame([self::MILK, self::BREAD], $this->productsOf($Saved));
        $this->assertSame($milkId, $Saved->entries()[0]->id, 'Same product keeps its entry id.');
        $this->assertSame(3, $Saved->entries()[0]->quantity->amount());
        $this->assertSame([self::MILK, self::BREAD], $this->productsOf($this->Store->templates[self::TEMPLATE_ID]));
    }

    public function testConcurrentSaveIsRejectedWithTheCurrentState(): void
    {
        $this->createLidl(self::HOUSEHOLD);

        $this->Service->save(self::TEMPLATE_ID, ['name' => 'Lidl', 'entries' => [['productId' => self::MILK]]], 1, self::EWA);

        try {
            $this->Service->save(
                self::TEMPLATE_ID,
                ['name' => 'Lidl groß', 'entries' => [['productId' => self::BREAD]]],
                1,
                self::MICHAEL
            );
            $this->fail('Expected template_changed');
        } catch (TemplateException $Exception) {
            $this->assertSame('template_changed', $Exception->getErrorCode());
            $this->assertSame(409, $Exception->getHttpStatus());
            $this->assertSame('Lidl', $Exception->getCurrent()?->name);
            $this->assertSame(2, $Exception->getCurrent()->revision);
        }

        $this->assertSame([self::MILK], $this->productsOf($this->Store->templates[self::TEMPLATE_ID]));
    }

    public function testSaveWithoutRevisionIsRejected(): void
    {
        $this->createLidl(null);

        $this->expectError('precondition_required', 428, fn() => $this->Service->save(
            self::TEMPLATE_ID,
            ['name' => 'Lidl', 'entries' => []],
            null,
            self::MICHAEL
        ));
    }

    public function testOthersHaveNoAccess(): void
    {
        $this->createLidl(null);

        // Ewa ist im selben Haushalt, die Vorlage ist aber persönlich
        $this->expectError('no_access', 403, fn() => $this->Service->get(self::TEMPLATE_ID, self::EWA));
    }

    public function testFormerMemberLosesAccess(): void
    {
        $this->createLidl(self::HOUSEHOLD);
        $this->Store->householdMembers[self::HOUSEHOLD] = [self::EWA];

        $this->expectError('no_access', 403, fn() => $this->Service->get(self::TEMPLATE_ID, self::MICHAEL));
    }

    public function testDeleteLeavesATombstone(): void
    {
        $this->createLidl(self::HOUSEHOLD);
        $this->Service->delete(self::TEMPLATE_ID, self::EWA);

        $this->assertTrue($this->Store->templates[self::TEMPLATE_ID]->isDeleted());
        $this->assertSame(self::EWA, $this->Store->templates[self::TEMPLATE_ID]->deletedBy);
        $this->expectError('template_deleted', 410, fn() => $this->Service->save(
            self::TEMPLATE_ID,
            ['name' => 'Lidl', 'entries' => []],
            1,
            self::MICHAEL
        ));
    }

    public function testOwnerMovesPersonalTemplateIntoTheHousehold(): void
    {
        $this->createLidl(null);

        $Moved = $this->Service->moveToHousehold(self::TEMPLATE_ID, self::MICHAEL);

        $this->assertSame(self::HOUSEHOLD, $Moved->householdId);
        $this->assertNull($Moved->ownerUser);
        $this->assertSame(2, $Moved->revision);
        $this->assertSame(self::TEMPLATE_ID, $this->Service->get(self::TEMPLATE_ID, self::EWA)->id);
    }

    public function testMoveNeedsAHouseholdAndTheOwner(): void
    {
        $this->createLidl(self::HOUSEHOLD);
        $this->expectError('forbidden', 403, fn() => $this->Service->moveToHousehold(self::TEMPLATE_ID, self::EWA));

        $this->Store->householdMembers = [];
        $this->Store->templates = [];
        $this->createLidl(null);
        $this->expectError('no_household', 409, fn() => $this->Service->moveToHousehold(self::TEMPLATE_ID, self::MICHAEL));
    }

    public function testCopyIntoTheHouseholdKeepsThePersonalOriginal(): void
    {
        $this->createLidl(null);

        $result = $this->Service->copy(self::TEMPLATE_ID, self::COPY_ID, 'household', self::MICHAEL);
        $Copy = $result['template'];

        $this->assertSame('created', $result['status']);
        $this->assertSame(self::HOUSEHOLD, $Copy->householdId);
        $this->assertSame([self::BREAD, self::MILK, self::EGGS], $this->productsOf($Copy));
        $this->assertSame('die große', $Copy->entries()[2]->note);
        $this->assertNotSame(
            $this->Store->templates[self::TEMPLATE_ID]->entries()[0]->id,
            $Copy->entries()[0]->id
        );
        $this->assertSame(self::MICHAEL, $this->Store->templates[self::TEMPLATE_ID]->ownerUser);

        $again = $this->Service->copy(self::TEMPLATE_ID, self::COPY_ID, 'household', self::MICHAEL);
        $this->assertSame('unchanged', $again['status']);
    }

    public function testMemberTakesAPersonalCopyOfAHouseholdTemplate(): void
    {
        $this->createLidl(self::HOUSEHOLD);

        $Copy = $this->Service->copy(self::TEMPLATE_ID, self::COPY_ID, 'personal', self::EWA)['template'];

        $this->assertSame(self::EWA, $Copy->ownerUser);
        $this->assertNull($Copy->householdId);

        // nach dem Austritt bleibt die Kopie bei ihr
        $this->Store->householdMembers[self::HOUSEHOLD] = [self::MICHAEL];
        $this->assertCount(3, $this->Service->get(self::COPY_ID, self::EWA)->entries());
        $this->expectError('no_access', 403, fn() => $this->Service->get(self::TEMPLATE_ID, self::EWA));
    }

    public function testWrongCopyDirectionsAreRejected(): void
    {
        $this->createLidl(null);
        $this->expectError('invalid_request', 400, fn() => $this->Service->copy(
            self::TEMPLATE_ID,
            self::COPY_ID,
            'personal',
            self::MICHAEL
        ));
    }

    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function ifMatchHeaders(): iterable
    {
        yield 'quoted' => ['"3"', 3];
        yield 'plain' => ['12', 12];
        yield 'weak' => ['W/"7"', 7];
        yield 'missing' => ['', null];
        yield 'not a number' => ['"abc"', null];
        yield 'list' => ['"1", "2"', null];
    }

    #[DataProvider('ifMatchHeaders')]
    public function testReadsTheRevisionFromIfMatch(string $header, ?int $expected): void
    {
        $this->assertSame($expected, TemplatesEndpoint::ifMatch($header));
    }

    /**
     * @return array{status: string, template: TemplateState}
     */
    private function createLidl(?string $householdId): array
    {
        return $this->Service->create(
            [
                'id' => self::TEMPLATE_ID,
                'name' => ' Wocheneinkauf Lidl ',
                'householdId' => $householdId,
                'entries' => [
                    ['productId' => self::BREAD],
                    ['productId' => self::MILK, 'quantity' => ['amount' => 2, 'unit' => 'pack']],
                    ['productId' => self::EGGS, 'quantity' => ['amount' => 10, 'unit' => null], 'note' => ' die große ']
                ]
            ],
            self::MICHAEL,
            new DateTimeImmutable('2026-10-31 09:00:00')
        );
    }

    /**
     * @return list<string>
     */
    private function productsOf(TemplateState $Template): array
    {
        return array_map(static fn(TemplateEntry $Entry): string => $Entry->productId, $Template->entries());
    }

    /**
     * @param callable(): mixed $action
     */
    private function expectError(string $code, int $status, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected ' . $code);
        } catch (TemplateException $Exception) {
            $this->assertSame($code, $Exception->getErrorCode());
            $this->assertSame($status, $Exception->getHttpStatus());
        }
    }
}
