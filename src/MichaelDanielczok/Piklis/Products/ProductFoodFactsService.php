<?php

namespace MichaelDanielczok\Piklis\Products;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\FoodFacts\FoodFactsException;
use MichaelDanielczok\Piklis\FoodFacts\FoodFactsLookup;
use MichaelDanielczok\Piklis\FoodFacts\InvalidBarcodeException;

use function array_values;
use function array_unique;
use function in_array;
use function is_array;
use function is_string;

/**
 * Produkt mit Open Food Facts ergänzen oder die Verknüpfung lösen, mit den
 * Rechten des Bearbeitens (`ProductPermissions::canEdit`).
 *
 * Die Werte kommen immer aus dem Cache des Servers (`FoodFactsLookup`),
 * nie aus der App: Die App wählt nur, welche Felder sie übernehmen will.
 * Das Bild lädt die App danach über `POST /products/{id}/image` mit
 * `source=openfoodfacts` hoch (derselbe Weg wie eigene Fotos).
 */
final class ProductFoodFactsService
{
    public function __construct(
        private readonly ProductStore $Store,
        private readonly FoodFactsLookup $Lookup
    ) {
    }

    /**
     * @param mixed $fields Liste aus `Product::FOOD_FACTS_FIELDS`, mindestens eins
     * @throws ProductException
     * @throws InvalidProductException
     * @throws InvalidBarcodeException
     * @throws FoodFactsException
     */
    public function apply(
        string $productId,
        mixed $barcode,
        mixed $fields,
        string $userId,
        bool $isManager,
        ?DateTimeImmutable $now = null
    ): Product {
        $Product = $this->loadEditable($productId, $userId, $isManager);
        $fields = self::fields($fields);
        $now = $now ?? new DateTimeImmutable();

        $Off = $this->Lookup->lookup($barcode, false, $now);

        if ($Product->applyFoodFacts($Off, $fields, $userId, $now)) {
            $this->Store->update($Product);
        }

        return $Product;
    }

    /**
     * @throws ProductException
     */
    public function remove(
        string $productId,
        string $userId,
        bool $isManager,
        ?DateTimeImmutable $now = null
    ): Product {
        $Product = $this->loadEditable($productId, $userId, $isManager);

        if ($Product->removeFoodFacts($userId, $now ?? new DateTimeImmutable())) {
            $this->Store->update($Product);
        }

        return $Product;
    }

    /**
     * @throws ProductException
     */
    private function loadEditable(string $productId, string $userId, bool $isManager): Product
    {
        $Product = (new ProductService($this->Store))->loadVisible($productId, $userId);

        if (!ProductPermissions::canEdit($Product, $userId, $isManager)) {
            throw ProductException::forbidden('Only the creator or a product maintainer may edit this product.');
        }

        return $Product;
    }

    /**
     * @return list<string>
     * @throws InvalidProductException
     */
    private static function fields(mixed $fields): array
    {
        if (!is_array($fields) || $fields === []) {
            throw new InvalidProductException('fields', 'required');
        }

        foreach ($fields as $field) {
            if (!is_string($field) || !in_array($field, Product::FOOD_FACTS_FIELDS, true)) {
                throw new InvalidProductException('fields', 'invalid');
            }
        }

        /** @var list<string> $unique */
        $unique = array_values(array_unique($fields));

        return $unique;
    }
}
