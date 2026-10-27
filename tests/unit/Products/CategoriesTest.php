<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Products;

use MichaelDanielczok\Piklis\Products\Categories;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Kategorien wie in tests/fixtures/categories.json (dieselben prüft der JS-Test).
 */
class CategoriesTest extends TestCase
{
    public function testKeysMatchTheSharedFixture(): void
    {
        $json = (string)file_get_contents(__DIR__ . '/../../fixtures/categories.json');

        /** @var array{categories: list<string>} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($data['categories'], Categories::KEYS);
    }

    public function testValidation(): void
    {
        $this->assertTrue(Categories::isValid('coffee_tea'));
        $this->assertFalse(Categories::isValid('Obst'));
        $this->assertFalse(Categories::isValid(''));
    }
}
