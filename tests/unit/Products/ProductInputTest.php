<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Products;

use MichaelDanielczok\Piklis\Products\InvalidProductException;
use MichaelDanielczok\Piklis\Products\ProductInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Produktdaten: nur der Name ist Pflicht, leere optionale Felder werden null.
 */
class ProductInputTest extends TestCase
{
    public function testOnlyTheNameIsRequired(): void
    {
        $Input = ProductInput::forCreate(['name' => '  Gouda   jung  ']);

        $this->assertSame('Gouda jung', $Input->name);
        $this->assertNull($Input->category);
        $this->assertNull($Input->icon);
        $this->assertNull($Input->description);
        $this->assertNull($Input->packageSize);
    }

    public function testOptionalFields(): void
    {
        $Input = ProductInput::forCreate([
            'name' => 'Milch',
            'category' => 'dairy',
            'icon' => 'milch',
            'description' => ' Die mit dem blauen Deckel. ',
            'packageSize' => '1,5 l',
            'unknown' => 'ignored'
        ]);

        $this->assertSame('dairy', $Input->category);
        $this->assertSame('milch', $Input->icon);
        $this->assertSame('Die mit dem blauen Deckel.', $Input->description);
        $this->assertSame('1,5 l', $Input->packageSize);
        $this->assertFalse($Input->has('unknown'));
    }

    public function testEmptyValuesMeanNone(): void
    {
        $Input = ProductInput::forUpdate([
            'category' => '',
            'icon' => 'allgemein',
            'description' => '   ',
            'packageSize' => null
        ]);

        $this->assertTrue($Input->has('category'));
        $this->assertNull($Input->category);
        $this->assertNull($Input->icon);
        $this->assertNull($Input->description);
        $this->assertNull($Input->packageSize);
        $this->assertFalse($Input->has('name'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidCreates(): iterable
    {
        yield 'no name' => [[], 'name', 'required'];
        yield 'empty name' => [['name' => '   '], 'name', 'required'];
        yield 'name not a string' => [['name' => 5], 'name', 'required'];
        yield 'name too long' => [['name' => str_repeat('a', 121)], 'name', 'too_long'];
        yield 'unknown category' => [['name' => 'A', 'category' => 'Obst'], 'category', 'invalid'];
        yield 'invalid icon' => [['name' => 'A', 'icon' => '../x'], 'icon', 'invalid'];
        yield 'description too long' => [['name' => 'A', 'description' => str_repeat('a', 2001)], 'description', 'too_long'];
        yield 'package size too long' => [['name' => 'A', 'packageSize' => str_repeat('a', 51)], 'packageSize', 'too_long'];
        yield 'description not a string' => [['name' => 'A', 'description' => ['x']], 'description', 'invalid'];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('invalidCreates')]
    public function testInvalidInput(array $input, string $field, string $reason): void
    {
        try {
            ProductInput::forCreate($input);
            $this->fail('Expected InvalidProductException');
        } catch (InvalidProductException $Exception) {
            $this->assertSame($field, $Exception->getField());
            $this->assertSame($reason, $Exception->getReason());
        }
    }

    public function testUpdateNeedsAtLeastOneField(): void
    {
        $this->expectException(InvalidProductException::class);

        ProductInput::forUpdate(['unknown' => 1]);
    }

    public function testUpdateMayNotClearTheName(): void
    {
        $this->expectException(InvalidProductException::class);

        ProductInput::forUpdate(['name' => '']);
    }
}
