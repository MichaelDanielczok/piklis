<?php

namespace MichaelDanielczok\Piklis\Products;

use function array_key_exists;
use function in_array;
use function is_string;
use function mb_strlen;
use function preg_match;
use function preg_replace;
use function trim;

/**
 * Geprüfte Produktdaten aus der App. Beim Anlegen ist nur `name` Pflicht,
 * beim Bearbeiten zählen nur die gesendeten Felder.
 *
 * - `name`: 1–120 Zeichen, Leerraum zusammengefasst.
 * - `category`: Schlüssel aus `Categories::KEYS`, null oder leer = ohne.
 * - `icon`: Schlüssel eines Ersatzicons (`a-z`, `0-9`, `-`), null, leer oder
 *   `allgemein` = allgemeines Icon (gespeichert als null). Welche Icons es
 *   gibt, weiß nur das Frontend; ein unbekanntes zeigt es als allgemein.
 * - `description`: höchstens 2000 Zeichen, leer = null.
 * - `packageSize`: höchstens 50 Zeichen („1,5 l“), leer = null.
 */
final class ProductInput
{
    public const NAME_MAX_LENGTH = 120;

    public const DESCRIPTION_MAX_LENGTH = 2000;

    public const PACKAGE_SIZE_MAX_LENGTH = 50;

    public const DEFAULT_ICON = 'allgemein';

    private const FIELDS = ['name', 'category', 'icon', 'description', 'packageSize'];

    private const ICON_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    public ?string $name = null;

    public ?string $category = null;

    public ?string $icon = null;

    public ?string $description = null;

    public ?string $packageSize = null;

    /** @var list<string> */
    private array $present = [];

    private function __construct()
    {
    }

    /**
     * @param array<array-key, mixed> $input
     * @throws InvalidProductException
     */
    public static function forCreate(array $input): self
    {
        $Input = self::fromArray($input);

        if (!$Input->has('name')) {
            throw new InvalidProductException('name', 'required');
        }

        return $Input;
    }

    /**
     * @param array<array-key, mixed> $input
     * @throws InvalidProductException
     */
    public static function forUpdate(array $input): self
    {
        $Input = self::fromArray($input);

        if ($Input->present === []) {
            throw new InvalidProductException('fields', 'required');
        }

        return $Input;
    }

    public function has(string $field): bool
    {
        return in_array($field, $this->present, true);
    }

    /**
     * @param array<array-key, mixed> $input
     * @throws InvalidProductException
     */
    private static function fromArray(array $input): self
    {
        $Input = new self();

        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                continue;
            }

            $value = $input[$field];
            $Input->present[] = $field;

            switch ($field) {
                case 'name':
                    $Input->name = self::name($value);
                    break;

                case 'category':
                    $Input->category = self::category($value);
                    break;

                case 'icon':
                    $Input->icon = self::icon($value);
                    break;

                case 'description':
                    $Input->description = self::optionalText($value, 'description', self::DESCRIPTION_MAX_LENGTH);
                    break;

                case 'packageSize':
                    $Input->packageSize = self::optionalText($value, 'packageSize', self::PACKAGE_SIZE_MAX_LENGTH);
                    break;
            }
        }

        return $Input;
    }

    /**
     * @throws InvalidProductException
     */
    private static function name(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidProductException('name', 'required');
        }

        $name = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        if ($name === '') {
            throw new InvalidProductException('name', 'required');
        }

        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new InvalidProductException('name', 'too_long');
        }

        return $name;
    }

    /**
     * @throws InvalidProductException
     */
    private static function category(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || !Categories::isValid($value)) {
            throw new InvalidProductException('category', 'invalid');
        }

        return $value;
    }

    /**
     * @throws InvalidProductException
     */
    private static function icon(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === self::DEFAULT_ICON) {
            return null;
        }

        if (!is_string($value) || preg_match(self::ICON_PATTERN, $value) !== 1) {
            throw new InvalidProductException('icon', 'invalid');
        }

        return $value;
    }

    /**
     * @throws InvalidProductException
     */
    private static function optionalText(mixed $value, string $field, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidProductException($field, 'invalid');
        }

        $text = trim($value);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $maxLength) {
            throw new InvalidProductException($field, 'too_long');
        }

        return $text;
    }
}
