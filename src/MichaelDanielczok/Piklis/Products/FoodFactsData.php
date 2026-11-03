<?php

namespace MichaelDanielczok\Piklis\Products;

use DateTimeImmutable;
use DateTimeInterface;
use MichaelDanielczok\Piklis\FoodFacts\FoodFactsProduct;

/**
 * Am Produkt gespeicherte Angaben aus Open Food Facts (Spalten
 * `off_fetched_at`, `off_language`, `off_data`): Abrufdatum, Sprache der
 * Zutaten und die übernommenen Zutaten und Nährwerte (je null, wenn nicht
 * übernommen oder bei Open Food Facts nicht vorhanden). Name, Marke,
 * Packungsgröße und Bild stehen in den normalen Feldern des Produkts.
 */
final class FoodFactsData
{
    /**
     * @param array<string, mixed>|null $nutrition Form aus `FoodFacts\Nutrition`
     */
    public function __construct(
        public readonly DateTimeImmutable $fetchedAt,
        public readonly ?string $language,
        public readonly ?string $ingredients,
        public readonly ?array $nutrition
    ) {
    }

    /**
     * Inhalt der Spalte `off_data`.
     *
     * @return array{ingredients: string|null, nutrition: array<string, mixed>|null}
     */
    public function data(): array
    {
        return [
            'ingredients' => $this->ingredients,
            'nutrition' => $this->nutrition
        ];
    }

    /**
     * Form für die API (`product.foodFacts`).
     *
     * @return array<string, mixed>
     */
    public function toArray(?string $barcode): array
    {
        return [
            'fetchedAt' => $this->fetchedAt->format(DateTimeInterface::ATOM),
            'language' => $this->language,
            'ingredients' => $this->ingredients,
            'nutrition' => $this->nutrition,
            'url' => $barcode === null ? null : FoodFactsProduct::PRODUCT_PAGE . $barcode
        ];
    }
}
