<?php

namespace MichaelDanielczok\Piklis\Sync;

use MichaelDanielczok\Piklis\Products\InvalidProductException;
use MichaelDanielczok\Piklis\Products\ProductException;
use MichaelDanielczok\Piklis\Products\ProductPresenter;
use MichaelDanielczok\Piklis\Products\ProductService;

use function is_array;
use function is_string;

/**
 * Operation `product.create` in `POST /ops`: ein offline angelegtes Produkt,
 * das die Warteschlange vor dem `entry.add` schickt, das es benutzt.
 *
 * Form: `{ "id": "<op>", "type": "product.create", "productId": "<uuid>",
 * "product": { "name": "…", "category": …, "icon": …, "description": …,
 * "packageSize": … } }`. Ohne `listId`; idempotent über die Produkt-ID
 * (nicht über das Änderungsprotokoll der Listen): dieselbe ID vom selben
 * Benutzer gibt `unchanged` mit dem vorhandenen Produkt.
 *
 * Abgelehnt: `invalid_operation` (Form, Name fehlt …), `product_exists`
 * (ID gehört zum Produkt eines anderen Benutzers).
 */
final class ProductOperation
{
    public const TYPE = 'product.create';

    private ProductPresenter $Presenter;

    /**
     * @param ProductPresenter|null $Presenter mit Bildadressen (ohne: Bilder null)
     */
    public function __construct(
        private readonly ProductService $Service,
        ?ProductPresenter $Presenter = null
    ) {
        $this->Presenter = $Presenter ?? new ProductPresenter();
    }

    public static function isProductOperation(mixed $raw): bool
    {
        return is_array($raw) && ($raw['type'] ?? null) === self::TYPE;
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    public function process(array $raw, string $userId): OperationResult
    {
        $operationId = is_string($raw['id'] ?? null) ? $raw['id'] : '';
        $product = $raw['product'] ?? null;

        if ($operationId === '' || !is_array($product)) {
            return OperationResult::rejected($operationId, 'invalid_operation', 'product.create needs id and product.');
        }

        try {
            $result = $this->Service->create($raw['productId'] ?? null, $product, $userId);
        } catch (InvalidProductException $Exception) {
            return OperationResult::rejected($operationId, 'invalid_operation', $Exception->getMessage());
        } catch (ProductException $Exception) {
            return OperationResult::rejected($operationId, $Exception->getErrorCode(), $Exception->getMessage());
        }

        return OperationResult::product(
            $operationId,
            $result['status'] === ProductService::CREATED,
            $this->Presenter->one($result['product'])
        );
    }
}
