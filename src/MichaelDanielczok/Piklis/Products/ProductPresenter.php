<?php

namespace MichaelDanielczok\Piklis\Products;

use MichaelDanielczok\Piklis\Images\ImageMedia;
use MichaelDanielczok\Piklis\Images\ProductImageUrls;

use function array_map;

/**
 * Produkte für die API mit ihren Bildadressen (`Product::toArray()` mit
 * `Images\ProductImageUrls`). Ohne Ablage (Tests) sind alle Bilder null.
 */
final class ProductPresenter
{
    public function __construct(private readonly ?ImageMedia $Media = null)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function one(Product $Product): array
    {
        return $this->many([$Product])[0];
    }

    /**
     * @param list<Product> $products
     * @return list<array<string, mixed>>
     */
    public function many(array $products): array
    {
        if ($this->Media === null) {
            return array_map(static fn(Product $Product): array => $Product->toArray(), $products);
        }

        $Urls = ProductImageUrls::load($this->Media, $products);

        return array_map(static fn(Product $Product): array => $Product->toArray($Urls->of($Product)), $products);
    }
}
