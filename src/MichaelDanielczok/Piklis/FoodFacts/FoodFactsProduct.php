<?php

namespace MichaelDanielczok\Piklis\FoodFacts;

use DateTimeImmutable;
use DateTimeInterface;

use function array_filter;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function mb_strlen;
use function mb_strtolower;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function rtrim;
use function str_replace;
use function trim;

/**
 * Ein Treffer bei Open Food Facts in der Form der App: nur die Felder, die
 * sich übernehmen lassen, Texte auf Deutsch, sonst Englisch. Was Open Food
 * Facts nicht kennt, ist null; nichts wird ergänzt oder geraten.
 *
 * - Name: `product_name_de` · `product_name_en` · `product_name` (Namen sind
 *   oft sprachneutral, z. B. Marken). Höchstens 120 Zeichen.
 * - Marke: `brands`, doppelte Einträge entfernt.
 * - Packungsgröße: `PackageSize`.
 * - Zutaten: `ingredients_text_de` · `ingredients_text_en` ·
 *   `ingredients_text`, wenn die Hauptsprache Deutsch oder Englisch ist.
 * - Nährwerte: `Nutrition`.
 * - Bild: ausgewähltes Vorderseitenbild (`selected_images.front`), Deutsch ·
 *   Englisch · Hauptsprache · erstes; 400 px zur Vorschau, `full` zum
 *   Übernehmen.
 */
final class FoodFactsProduct
{
    public const LANGUAGES = ['de', 'en'];

    public const NAME_MAX_LENGTH = 120;
    public const BRAND_MAX_LENGTH = 120;
    public const INGREDIENTS_MAX_LENGTH = 5000;

    public const PRODUCT_PAGE = 'https://world.openfoodfacts.org/product/';

    private const IMAGE_PATTERN =
        '#^https://images\.openfoodfacts\.org/images/products/[0-9/]+/front_[a-z]{2,3}\.\d+\.400\.jpg$#';

    /**
     * @param array{per: string, servingSize: string|null, rows: list<array{key: string, unit: string, per100: float|null, perServing: float|null}>}|null $nutrition
     */
    public function __construct(
        public readonly string $barcode,
        public readonly ?string $name,
        public readonly ?string $nameLanguage,
        public readonly ?string $brand,
        public readonly ?string $packageSize,
        public readonly ?string $ingredients,
        public readonly ?string $ingredientsLanguage,
        public readonly ?array $nutrition,
        public readonly ?string $imageUrl,
        public readonly ?DateTimeImmutable $lastModified,
        public readonly DateTimeImmutable $fetchedAt
    ) {
    }

    /**
     * @param array<string, mixed> $product `product` aus der API-Antwort (oder dem Cache)
     */
    public static function fromApi(array $product, string $barcode, DateTimeImmutable $fetchedAt): self
    {
        $language = is_string($product['lang'] ?? null) ? $product['lang'] : '';
        [$name, $nameLanguage] = self::localized($product, 'product_name', $language, true);
        [$ingredients, $ingredientsLanguage] = self::localized($product, 'ingredients_text', $language, false);

        $lastModified = null;

        if (is_int($product['last_modified_t'] ?? null)) {
            $lastModified = (new DateTimeImmutable())->setTimestamp($product['last_modified_t']);
        }

        return new self(
            $barcode,
            self::limit($name, self::NAME_MAX_LENGTH),
            $name === null ? null : $nameLanguage,
            self::brand($product['brands'] ?? null),
            PackageSize::fromApi($product),
            self::limit($ingredients, self::INGREDIENTS_MAX_LENGTH),
            $ingredients === null ? null : $ingredientsLanguage,
            Nutrition::fromApi($product),
            self::frontImage($product, $language),
            $lastModified,
            $fetchedAt
        );
    }

    /**
     * Adresse des Bildes in voller Größe (zum Zuschneiden und Übernehmen).
     */
    public function imageFullUrl(): ?string
    {
        return $this->imageUrl === null ? null : str_replace('.400.jpg', '.full.jpg', $this->imageUrl);
    }

    public function productPage(): string
    {
        return self::PRODUCT_PAGE . $this->barcode;
    }

    /**
     * Sprache der übernommenen Texte (Zutaten vor Name).
     */
    public function language(): ?string
    {
        return $this->ingredientsLanguage ?? $this->nameLanguage;
    }

    /**
     * Welche Felder der Treffer hat (für die Auswahl in der App).
     *
     * @return list<string>
     */
    public function availableFields(): array
    {
        $fields = [
            'name' => $this->name,
            'brand' => $this->brand,
            'packageSize' => $this->packageSize,
            'ingredients' => $this->ingredients,
            'nutrition' => $this->nutrition,
            'image' => $this->imageUrl
        ];

        return array_keys(array_filter($fields, static fn(mixed $value): bool => $value !== null));
    }

    /**
     * Form für die API (ohne Bildadresse bei Open Food Facts: Das Bild holt
     * die App über den eigenen Server).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'barcode' => $this->barcode,
            'name' => $this->name,
            'brand' => $this->brand,
            'packageSize' => $this->packageSize,
            'ingredients' => $this->ingredients,
            'language' => $this->language(),
            'nutrition' => $this->nutrition,
            'hasImage' => $this->imageUrl !== null,
            'fields' => $this->availableFields(),
            'url' => $this->productPage(),
            'lastModified' => $this->lastModified?->format(DateTimeInterface::ATOM),
            'fetchedAt' => $this->fetchedAt->format(DateTimeInterface::ATOM)
        ];
    }

    /**
     * Text in Deutsch, sonst Englisch, sonst (wenn erlaubt oder die
     * Hauptsprache passt) das sprachlose Feld.
     *
     * @param array<string, mixed> $product
     * @return array{0: string|null, 1: string|null} Text und Sprache
     */
    private static function localized(array $product, string $field, string $language, bool $anyLanguage): array
    {
        foreach (self::LANGUAGES as $code) {
            $text = self::text($product[$field . '_' . $code] ?? null);

            if ($text !== null) {
                return [$text, $code];
            }
        }

        if (!$anyLanguage && !in_array($language, self::LANGUAGES, true)) {
            return [null, null];
        }

        $text = self::text($product[$field] ?? null);

        return [$text, $text === null || $language === '' ? null : $language];
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $text = trim(preg_replace('/[ \t]+/u', ' ', $value) ?? $value);

        return $text === '' ? null : $text;
    }

    private static function limit(?string $text, int $maxLength): ?string
    {
        if ($text === null || mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $maxLength - 1)) . '…';
    }

    /**
     * „Edeka, GUT&GÜNSTIG“ bleibt, „Milbona, Milbona“ wird „Milbona“.
     */
    private static function brand(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $parts = array_values(array_filter(
            array_map(static fn(string $part): string => trim($part), explode(',', $value)),
            static fn(string $part): bool => $part !== ''
        ));

        $seen = [];
        $brands = [];

        foreach ($parts as $part) {
            $key = mb_strtolower($part);

            if (in_array($key, $seen, true)) {
                continue;
            }

            $seen[] = $key;
            $brands[] = $part;
        }

        if ($brands === []) {
            return null;
        }

        return self::limit(implode(', ', array_unique($brands)), self::BRAND_MAX_LENGTH);
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function frontImage(array $product, string $language): ?string
    {
        $display = $product['selected_images']['front']['display'] ?? null;

        if (!is_array($display) || $display === []) {
            return null;
        }

        $order = [...self::LANGUAGES, $language, ...array_keys($display)];

        foreach ($order as $code) {
            $url = $display[$code] ?? null;

            if (is_string($url) && preg_match(self::IMAGE_PATTERN, $url) === 1) {
                return $url;
            }
        }

        return null;
    }
}
