<?php

namespace MichaelDanielczok\Piklis\Products;

use DateTimeImmutable;

/**
 * Ein Produkt des globalen Katalogs (`pk_products`).
 *
 * Nur der Name ist Pflicht. `icon` null heißt allgemeines Ersatzicon,
 * `category` null heißt ohne Kategorie. Ausgeblendete Produkte bleiben
 * erhalten, damit alte Einkäufe sie weiter zeigen.
 */
final class Product
{
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
        public DateTimeImmutable $updatedAt
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
            $now
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

        if ($before === $this->editableFields()) {
            return false;
        }

        $this->touch($userId, $now);

        return true;
    }

    /**
     * Setzt Zuschnitt und Original (QUIQQER-Medien) oder entfernt beide (null).
     * Gibt zurück, ob sich etwas geändert hat.
     */
    public function setImage(?int $imageId, ?int $originalId, string $userId, DateTimeImmutable $now): bool
    {
        if ($this->imageId === $imageId && $this->imageOriginalId === $originalId) {
            return false;
        }

        $this->imageId = $imageId;
        $this->imageOriginalId = $imageId === null ? null : $originalId;
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
            'barcode' => $this->barcode,
            'imageUrl' => $image['small'] ?? null,
            'image' => $image,
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
            'packageSize' => $this->packageSize
        ];
    }
}
