<?php

namespace MichaelDanielczok\Piklis\Templates;

use MichaelDanielczok\Piklis\Lists\Quantity;

/**
 * Ein Produkt einer Vorlage: Menge mit Einheit, Position und (nur auf
 * Wunsch beim Speichern) eine Notiz. Jedes Produkt steht höchstens einmal in
 * einer Vorlage.
 */
final class TemplateEntry
{
    public function __construct(
        public readonly string $id,
        public readonly string $productId,
        public readonly Quantity $quantity,
        public readonly string $note,
        public readonly string $sortKey
    ) {
    }

    /**
     * @return array{id: string, productId: string, quantity: array{amount: int|float, unit: string|null}, note: string, sortKey: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->productId,
            'quantity' => $this->quantity->toArray(),
            'note' => $this->note,
            'sortKey' => $this->sortKey
        ];
    }
}
