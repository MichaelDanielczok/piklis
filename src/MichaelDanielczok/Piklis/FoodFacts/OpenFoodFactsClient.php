<?php

namespace MichaelDanielczok\Piklis\FoodFacts;

use JsonException;

use function array_intersect_key;
use function array_flip;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function preg_match;
use function str_starts_with;
use function strtolower;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Lesender Zugriff auf Open Food Facts, nur vom Server aus.
 *
 * - API v3.4, fest eingestellt: v3.5 ändert die Nährwerte grundlegend und ist
 *   laut Änderungsprotokoll noch in Arbeit; ältere Versionen liefert Open
 *   Food Facts in ihrer alten Form weiter.
 * - Ohne Konto und API-Key, mit App-Kennung im User-Agent (verlangt von
 *   Open Food Facts; ohne Namen oder E-Mail-Adresse einer Person).
 * - Nur die gebrauchten Felder (`fields`), Antwort vor dem Speichern
 *   verkleinert (`compact()`).
 * - Abruflimit von Open Food Facts: 15 Produktabrufe je Minute und IP;
 *   die Bremse davor sitzt in `FoodFactsLookup`.
 */
final class OpenFoodFactsClient
{
    public const API = 'https://world.openfoodfacts.org/api/v3.4/product/';

    public const USER_AGENT = 'Piklis/1.0 (private shopping list app)';

    public const FIELDS = [
        'code',
        'lang',
        'product_name',
        'product_name_de',
        'product_name_en',
        'brands',
        'quantity',
        'product_quantity',
        'product_quantity_unit',
        'serving_size',
        'nutrition_data_per',
        'no_nutrition_data',
        'nutriments',
        'ingredients_text',
        'ingredients_text_de',
        'ingredients_text_en',
        'selected_images',
        'last_modified_t'
    ];

    private const MAX_JSON_BYTES = 2 * 1024 * 1024;
    public const MAX_IMAGE_BYTES = 10 * 1024 * 1024;

    private const IMAGE_PATTERN =
        '#^https://images\.openfoodfacts\.org/images/products/[0-9/]+/front_[a-z]{2,3}\.\d+\.(400|full)\.jpg$#';

    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly HttpClient $Http)
    {
    }

    public static function productUrl(string $barcode): string
    {
        return self::API . $barcode . '?fields=' . implode(',', self::FIELDS);
    }

    /**
     * Produktdaten (verkleinert) oder null, wenn Open Food Facts den Barcode
     * nicht kennt.
     *
     * @return array<string, mixed>|null
     * @throws FoodFactsException `off_unavailable`
     */
    public function fetch(string $barcode): ?array
    {
        $Response = $this->Http->get(self::productUrl($barcode), self::headers('application/json'), self::MAX_JSON_BYTES);

        return self::parse($Response);
    }

    /**
     * Bild von Open Food Facts (nur Vorderseitenbilder auf
     * images.openfoodfacts.org).
     *
     * @return array{body: string, contentType: string}
     * @throws FoodFactsException
     */
    public function fetchImage(string $url): array
    {
        if (preg_match(self::IMAGE_PATTERN, $url) !== 1) {
            throw FoodFactsException::noImage();
        }

        $Response = $this->Http->get($url, self::headers('image/*'), self::MAX_IMAGE_BYTES);

        if ($Response->status === 404) {
            throw FoodFactsException::noImage();
        }

        if ($Response->status !== 200) {
            throw FoodFactsException::unavailable('Image request answered ' . $Response->status . '.');
        }

        $contentType = strtolower(trim(explode(';', $Response->contentType)[0]));

        if (!in_array($contentType, self::IMAGE_TYPES, true) || $Response->body === '') {
            throw FoodFactsException::unavailable('The image has an unexpected type.');
        }

        return [
            'body' => $Response->body,
            'contentType' => $contentType
        ];
    }

    /**
     * Antwort der API v3 auswerten:
     * - 200 mit `status` `success` oder `success_with_warnings`: Produkt.
     * - 404 mit `result.id` `product_not_found`: null.
     * - Weiterleitung (Produkt einer anderen Datenbank, z. B. Kosmetik): null.
     * - alles andere (429, 5xx, kaputtes JSON): `off_unavailable`.
     *
     * @return array<string, mixed>|null
     * @throws FoodFactsException
     */
    public static function parse(HttpResponse $Response): ?array
    {
        if ($Response->status >= 300 && $Response->status < 400) {
            return null;
        }

        try {
            $data = json_decode($Response->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw FoodFactsException::unavailable('Answer ' . $Response->status . ' without JSON.');
        }

        if (!is_array($data)) {
            throw FoodFactsException::unavailable('Unexpected answer.');
        }

        $status = is_string($data['status'] ?? null) ? $data['status'] : '';
        $result = is_string($data['result']['id'] ?? null) ? $data['result']['id'] : '';

        if ($Response->status === 404 && $result === 'product_not_found') {
            return null;
        }

        if ($Response->status !== 200 || !str_starts_with($status, 'success') || !is_array($data['product'] ?? null)) {
            throw FoodFactsException::unavailable('Answer ' . $Response->status . ' (' . $result . ').');
        }

        return self::compact($data['product']);
    }

    /**
     * Nur die angefragten Felder; von den ausgewählten Bildern nur die
     * Vorderseite in 400 px.
     *
     * @param array<array-key, mixed> $product
     * @return array<string, mixed>
     */
    public static function compact(array $product): array
    {
        /** @var array<string, mixed> $compact */
        $compact = array_intersect_key($product, array_flip(self::FIELDS));
        $front = $product['selected_images']['front']['display'] ?? null;

        unset($compact['selected_images']);

        if (is_array($front) && $front !== []) {
            $compact['selected_images'] = [
                'front' => [
                    'display' => $front
                ]
            ];
        }

        return $compact;
    }

    /**
     * @return array<string, string>
     */
    private static function headers(string $accept): array
    {
        return [
            'User-Agent' => self::USER_AGENT,
            'Accept' => $accept
        ];
    }
}
