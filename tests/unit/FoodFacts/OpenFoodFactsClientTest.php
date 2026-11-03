<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\FoodFacts;

use MichaelDanielczok\Piklis\FoodFacts\FoodFactsException;
use MichaelDanielczok\Piklis\FoodFacts\HttpResponse;
use MichaelDanielczok\Piklis\FoodFacts\OpenFoodFactsClient;
use MichaelDanielczok\Piklis\Tests\Unit\FoodFacts\Fixtures\FixtureHttpClient;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function str_contains;

/**
 * Abruf und Auswertung der API v3.4 mit gespeicherten Antworten von Open
 * Food Facts (vollständig, lückenhaft, mit Portionsangaben, nicht gefunden).
 */
class OpenFoodFactsClientTest extends TestCase
{
    public function testRequestUsesApiV34TheFieldListAndTheAppUserAgent(): void
    {
        $Http = (new FixtureHttpClient())->product('4311501489840', 'complete-4311501489840.json');

        (new OpenFoodFactsClient($Http))->fetch('4311501489840');

        $request = $Http->requests[0];

        $this->assertStringStartsWith(
            'https://world.openfoodfacts.org/api/v3.4/product/4311501489840?fields=code,lang,product_name,',
            $request['url']
        );
        $this->assertSame('Piklis/1.0 (private shopping list app)', $request['headers']['User-Agent']);
        $this->assertFalse(str_contains($request['headers']['User-Agent'], '@'), 'no e-mail address');
    }

    public function testCompleteAnswerIsReducedToTheRequestedFields(): void
    {
        $Http = (new FixtureHttpClient())->product('4311501489840', 'complete-4311501489840.json');

        $product = (new OpenFoodFactsClient($Http))->fetch('4311501489840');

        $this->assertNotNull($product);
        $this->assertSame('H-Vollmilch 3,5 % Fett', $product['product_name_de']);
        $this->assertArrayNotHasKey('nutriments_estimated', $product, 'estimated values are not stored');
        $this->assertSame(['front'], array_keys($product['selected_images']));
        $this->assertSame(['display'], array_keys($product['selected_images']['front']));
    }

    public function testNotFoundIsNull(): void
    {
        $Http = (new FixtureHttpClient())->product('4099999999019', 'not-found-4099999999019.json', 404);

        $this->assertNull((new OpenFoodFactsClient($Http))->fetch('4099999999019'));
    }

    public function testRedirectToAnotherDatabaseCountsAsNotFound(): void
    {
        $this->assertNull(OpenFoodFactsClient::parse(new HttpResponse(302, '')));
    }

    public function testOverloadPageIsUnavailable(): void
    {
        $this->expectException(FoodFactsException::class);
        $this->expectExceptionMessage('without JSON');

        OpenFoodFactsClient::parse(new HttpResponse(503, '<!DOCTYPE html><title>Page temporarily unavailable</title>'));
    }

    public function testFailureWithJsonIsUnavailable(): void
    {
        try {
            OpenFoodFactsClient::parse(new HttpResponse(500, '{"status":"failure","result":{"id":"internal"}}'));
            $this->fail('Expected FoodFactsException');
        } catch (FoodFactsException $Exception) {
            $this->assertSame('off_unavailable', $Exception->getErrorCode());
            $this->assertSame(503, $Exception->getHttpStatus());
        }
    }

    public function testImagesOnlyFromOpenFoodFactsFrontImages(): void
    {
        $Http = new FixtureHttpClient();
        $Client = new OpenFoodFactsClient($Http);

        foreach (
            [
                'https://example.com/front_de.4.400.jpg',
                'https://images.openfoodfacts.org/images/products/431/626/852/6463/ingredients_de.6.400.jpg',
                'http://images.openfoodfacts.org/images/products/431/626/852/6463/front_de.4.400.jpg'
            ] as $url
        ) {
            try {
                $Client->fetchImage($url);
                $this->fail('Expected FoodFactsException for ' . $url);
            } catch (FoodFactsException $Exception) {
                $this->assertSame('off_no_image', $Exception->getErrorCode());
            }
        }

        $this->assertSame([], $Http->requests, 'nothing was requested');
    }

    public function testImageMustBeAnImage(): void
    {
        $path = '/images/products/431/626/852/6463/front_de.4.full.jpg';
        $Http = (new FixtureHttpClient())
            ->respond($path, new HttpResponse(200, '<html></html>', 'text/html'));

        $this->expectException(FoodFactsException::class);

        (new OpenFoodFactsClient($Http))->fetchImage('https://images.openfoodfacts.org' . $path);
    }

    public function testImageIsReturnedWithItsType(): void
    {
        $path = '/images/products/431/626/852/6463/front_de.4.full.jpg';
        $Http = (new FixtureHttpClient())
            ->respond($path, new HttpResponse(200, "\xFF\xD8\xFF", 'image/jpeg'));

        $image = (new OpenFoodFactsClient($Http))->fetchImage('https://images.openfoodfacts.org' . $path);

        $this->assertSame('image/jpeg', $image['contentType']);
        $this->assertSame('Piklis/1.0 (private shopping list app)', $Http->requests[0]['headers']['User-Agent']);
    }
}
