<?php

namespace MichaelDanielczok\Piklis\Products;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\FoodFacts\FoodFactsProduct;

use function in_array;
use function serialize;

/**
 * Ein Produkt des globalen Katalogs (`pk_products`).
 *
 * Nur der Name ist Pflicht. `icon` null heißt allgemeines Ersatzicon,
 * `category` null heißt ohne Kategorie. Ausgeblendete Produkte bleiben
 * erhalten, damit alte Einkäufe sie weiter zeigen.
 *
 * Open Food Facts (Schritt 14): `barcode` verknüpft das Produkt,
 * `foodFacts` hält Abrufdatum, Zutaten und Nährwerte, `imageSource`
 * `openfoodfacts` markiert ein von dort übernommenes Bild (Lizenzhinweis).
 *
 * Listenartikel (Schritt 18): `listItem` markiert ein Produkt, das nicht im
 * Katalog steht. Es erscheint nie in Katalog, Suche und Vorschlägen und ist
 * nur für Personen mit Zugriff auf eine Liste oder Vorlage sichtbar, die es
 * nutzt (`ProductStore::canAccessListItem`).
 */
final class Product
{
    public const IMAGE_SOURCE_FOOD_FACTS = 'openfoodfacts';

    /** Felder, die sich aus Open Food Facts übernehmen lassen; `image` lädt die App selbst hoch. */
    public const FOOD_FACTS_FIELDS = ['name', 'brand', 'packageSize', 'ingredients', 'nutrition', 'image'];

    public function __construct(
        public readonly string $id,
        public string $name,
        public ?string $category,
        public ?string $icon,
        public ?string $description,
        public ?string $packageSize,
        public ?string $barcode,
        public ?int $imageId,
        public ?int $imageOriginalId,
        public bool $hidden,
        public ?DateTimeImmutable $hiddenAt,
        public ?string $hiddenBy,
        public readonly string $createdBy,
        public readonly DateTimeImmutable $createdAt,
        public string $updatedBy,
        public DateTimeImmutable $updatedAt,
        public ?string $brand = null,
        public ?FoodFactsData $foodFacts = null,
        public ?string $imageSource = null,
        public bool $listItem = false
    ) {
    }

    public static function create(
        string $id,
        ProductInput $Input,
        string $userId,
        DateTimeImmutable $now
    ): self {
        return new self(
            $id,
            (string)$Input->name,
            $Input->category,
            $Input->icon,
            $Input->description,
            $Input->packageSize,
            null,
            null,
            null,
            false,
            null,
            null,
            $userId,
            $now,
            $userId,
            $now,
            $Input->brand,
            null,
            null,
            $Input->listItem
        );
    }

    public function searchName(): string
    {
        return SearchText::normalize($this->name);
    }

    /**
     * Übernimmt die gesendeten Felder. Gibt zurück, ob sich etwas geändert hat.
     */
    public function apply(ProductInput $Input, string $userId, DateTimeImmutable $now): bool
    {
        $before = $this->editableFields();

        if ($Input->has('name')) {
            $this->name = (string)$Input->name;
        }

        if ($Input->has('category')) {
            $this->category = $Input->category;
        }

        if ($Input->has('icon')) {
            $this->icon = $Input->icon;
        }

        if ($Input->has('description')) {
            $this->description = $Input->description;
        }

        if ($Input->has('packageSize')) {
            $this->packageSize = $Input->packageSize;
        }

        if ($Input->has('brand')) {
            $this->brand = $Input->brand;
        }

        if ($before === $this->editableFields()) {
            return false;
        }

        $this->touch($userId, $now);

        return true;
    }

    /**
     * Setzt Zuschnitt und Original (QUIQQER-Medien) oder entfernt beide (null).
     * `$source` ist die Herkunft (`openfoodfacts`, null = eigenes Foto).
     * Gibt zurück, ob sich etwas geändert hat.
     */
    public function setImage(
        ?int $imageId,
        ?int $originalId,
        string $userId,
        DateTimeImmutable $now,
        ?string $source = null
    ): bool {
        $source = $imageId === null ? null : $source;

        if (
            $this->imageId === $imageId
            && $this->imageOriginalId === $originalId
            && $this->imageSource === $source
        ) {
            return false;
        }

        $this->imageId = $imageId;
        $this->imageOriginalId = $imageId === null ? null : $originalId;
        $this->imageSource = $source;
        $this->touch($userId, $now);

        return true;
    }

    /**
     * Übernimmt die gewählten Angaben eines Treffers bei Open Food Facts und
     * verknüpft das Produkt mit dessen Barcode. Was Open Food Facts nicht
     * kennt, bleibt, wie es ist; nicht gewählte Felder ebenso (eigene Namen
     * werden nur auf ausdrücklichen Wunsch ersetzt). Die Beschreibung
     * (eigene Hinweise) wird nie berührt. Gibt zurück, ob sich etwas
     * geändert hat.
     *
     * @param list<string> $fields aus `FOOD_FACTS_FIELDS`
     */
    public function applyFoodFacts(
        FoodFactsProduct $Off,
        array $fields,
        string $userId,
        DateTimeImmutable $now
    ): bool {
        $before = $this->foodFactsFields();

        if (in_array('name', $fields, true) && $Off->name !== null) {
            $this->name = $Off->name;
        }

        if (in_array('brand', $fields, true) && $Off->brand !== null) {
            $this->brand = $Off->brand;
        }

        if (in_array('packageSize', $fields, true) && $Off->packageSize !== null) {
            $this->packageSize = $Off->packageSize;
        }

        $ingredients = $this->foodFacts?->ingredients;
        $language = $this->foodFacts?->language;
        $nutrition = $this->foodFacts?->nutrition;

        if (in_array('ingredients', $fields, true) && $Off->ingredients !== null) {
            $ingredients = $Off->ingredients;
            $language = $Off->ingredientsLanguage;
        }

        if (in_array('nutrition', $fields, true) && $Off->nutrition !== null) {
            $nutrition = $Off->nutrition;
        }

        $this->barcode = $Off->barcode;
        $this->foodFacts = new FoodFactsData($Off->fetchedAt, $language, $ingredients, $nutrition);

        if ($before === $this->foodFactsFields()) {
            return false;
        }

        $this->touch($userId, $now);

        return true;
    }

    /**
     * Löst die Verknüpfung: Barcode, Zutaten und Nährwerte weg. Übernommene
     * Namen, Marke, Packungsgröße und Bild bleiben (sie gehören jetzt zum
     * Produkt; ein Bild behält seinen Lizenzhinweis).
     */
    public function removeFoodFacts(string $userId, DateTimeImmutable $now): bool
    {
        if ($this->barcode === null && $this->foodFacts === null) {
            return false;
        }

        $this->barcode = null;
        $this->foodFacts = null;
        $this->touch($userId, $now);

        return true;
    }

    /**
     * Gibt zurück, ob sich etwas geändert hat.
     */
    public function setHidden(bool $hidden, string $userId, DateTimeImmutable $now): bool
    {
        if ($this->hidden === $hidden) {
            return false;
        }

        $this->hidden = $hidden;
        $this->hiddenAt = $hidden ? $now : null;
        $this->hiddenBy = $hidden ? $userId : null;
        $this->touch($userId, $now);

        return true;
    }

    /**
     * Listenartikel in den Katalog aufnehmen: nur die Markierung ändert sich,
     * ab jetzt gelten die normalen Pflegerechte. Gibt zurück, ob sich etwas
     * geändert hat.
     */
    public function addToCatalog(string $userId, DateTimeImmutable $now): bool
    {
        if (!$this->listItem) {
            return false;
        }

        $this->listItem = false;
        $this->touch($userId, $now);

        return true;
    }

    /**
     * Form für die API (camelCase). `image` sind die Bildadressen aus
     * `Images\ProductImageUrls` (null ohne Bild), `imageUrl` die kleine Größe
     * für Listen und Kacheln.
     *
     * @param array{small: string, medium: string, large: string, original: string|null}|null $image
     * @return array<string, mixed>
     */
    public function toArray(?array $image = null): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'icon' => $this->icon,
            'description' => $this->description,
            'packageSize' => $this->packageSize,
            'brand' => $this->brand,
            'barcode' => $this->barcode,
            'imageUrl' => $image['small'] ?? null,
            'image' => $image,
            'imageSource' => $this->imageSource,
            'foodFacts' => $this->foodFacts?->toArray($this->barcode),
            'listItem' => $this->listItem,
            'hidden' => $this->hidden,
            'hiddenAt' => $this->hiddenAt?->format(DATE_ATOM),
            'createdBy' => $this->createdBy,
            'createdAt' => $this->createdAt->format(DATE_ATOM),
            'updatedAt' => $this->updatedAt->format(DATE_ATOM)
        ];
    }

    private function touch(string $userId, DateTimeImmutable $now): void
    {
        $this->updatedBy = $userId;
        $this->updatedAt = $now;
    }

    /**
     * @return array<string, string|null>
     */
    private function editableFields(): array
    {
        return [
            'name' => $this->name,
            'category' => $this->category,
            'icon' => $this->icon,
            'description' => $this->description,
            'packageSize' => $this->packageSize,
            'brand' => $this->brand
        ];
    }

    private function foodFactsFields(): string
    {
        return serialize([
            $this->name,
            $this->brand,
            $this->packageSize,
            $this->barcode,
            $this->foodFacts
        ]);
    }
}
