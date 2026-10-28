<?php

namespace MichaelDanielczok\Piklis\Products;

use function in_array;

/**
 * Feste Kategorien des Katalogs in der Reihenfolge der Auswahl. Namen stehen
 * im Frontend-i18n (`categories.<key>`), nicht hier.
 *
 * Dieselbe Liste steht in `frontend/products/categories.js`; beide Tests prüfen
 * sie gegen `tests/fixtures/categories.json`. Die Farben je Kategorie sind
 * reine Gestaltung und stehen nur als Tokens in `frontend/styles/tokens.css`
 * (Attribut `data-category`), nicht in der Datenbank.
 */
final class Categories
{
    public const KEYS = [
        'fruit',
        'vegetables',
        'bread',
        'dairy',
        'cheese',
        'meat',
        'fish',
        'grains',
        'canned',
        'frozen',
        'sweets',
        'drinks',
        'coffee_tea',
        'baking',
        'drugstore',
        'household',
        'baby',
        'pets',
        'other'
    ];

    public static function isValid(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }
}
