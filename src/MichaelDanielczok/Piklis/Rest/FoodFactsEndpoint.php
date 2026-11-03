<?php

namespace MichaelDanielczok\Piklis\Rest;

use Doctrine\DBAL\Exception as DBALException;
use JsonException;
use MichaelDanielczok\Piklis\FoodFacts\CurlHttpClient;
use MichaelDanielczok\Piklis\FoodFacts\FoodFactsCacheRepository;
use MichaelDanielczok\Piklis\FoodFacts\FoodFactsException;
use MichaelDanielczok\Piklis\FoodFacts\FoodFactsLookup;
use MichaelDanielczok\Piklis\FoodFacts\InvalidBarcodeException;
use MichaelDanielczok\Piklis\FoodFacts\OpenFoodFactsClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;

/**
 * Open Food Facts nachschlagen (nur Lesen, nur vom Server aus, mit Cache).
 *
 * | Methode | Pfad | Antwort |
 * | --- | --- | --- |
 * | GET | /openfoodfacts/{barcode}?refresh=1 | `{ result }` (`FoodFactsProduct::toArray()`) |
 * | GET | /openfoodfacts/{barcode}/image?size=full | Vorderseitenbild (400 px, mit `size=full` volle Größe) |
 *
 * Fehler: `400 invalid_barcode` (mit `reason`), `404 off_not_found`,
 * `404 off_no_image`, `429 off_busy`, `503 off_unavailable`.
 */
class FoodFactsEndpoint
{
    /**
     * @param array<string, string> $args
     */
    public function lookup(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $refresh = ($Request->getQueryParams()['refresh'] ?? '') === '1';

        return self::run($Response, static function () use ($args, $refresh, $Response): ResponseInterface {
            $Product = self::lookupService()->lookup($args['barcode'] ?? '', $refresh);

            return Json::respond($Response, [
                'result' => $Product->toArray()
            ]);
        });
    }

    /**
     * Bild über den eigenen Server: Die App lädt es in den Zuschnitt und
     * danach wie ein eigenes Foto hoch. Nur das Vorderseitenbild des
     * gespeicherten Treffers, keine beliebigen Adressen.
     *
     * @param array<string, string> $args
     */
    public function image(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $full = ($Request->getQueryParams()['size'] ?? '') === 'full';

        return self::run($Response, static function () use ($args, $full, $Response): ResponseInterface {
            $image = self::lookupService()->image($args['barcode'] ?? '', $full);

            $Response->getBody()->write($image['body']);

            return $Response
                ->withStatus(200)
                ->withHeader('Content-Type', $image['contentType'])
                ->withHeader('Cache-Control', 'private, max-age=86400');
        });
    }

    public static function lookupService(): FoodFactsLookup
    {
        return new FoodFactsLookup(
            new FoodFactsCacheRepository(),
            new OpenFoodFactsClient(new CurlHttpClient())
        );
    }

    /**
     * @param callable(): ResponseInterface $action
     */
    private static function run(ResponseInterface $Response, callable $action): ResponseInterface
    {
        try {
            return $action();
        } catch (InvalidBarcodeException $Exception) {
            return self::invalidBarcode($Response, $Exception);
        } catch (FoodFactsException $Exception) {
            return self::foodFactsError($Response, $Exception);
        } catch (DBALException | JsonException | QUI\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'load_failed', 'Open Food Facts data could not be loaded.', 500);
        }
    }

    public static function invalidBarcode(ResponseInterface $Response, InvalidBarcodeException $Exception): ResponseInterface
    {
        return Json::respond(
            $Response,
            [
                'error' => 'invalid_barcode',
                'message' => $Exception->getMessage(),
                'field' => 'barcode',
                'reason' => $Exception->getReason()
            ],
            400
        );
    }

    public static function foodFactsError(ResponseInterface $Response, FoodFactsException $Exception): ResponseInterface
    {
        if ($Exception->getHttpStatus() >= 500) {
            QUI\System\Log::addWarning('Piklis: Open Food Facts', ['message' => $Exception->getMessage()]);
        }

        return Json::error(
            $Response,
            $Exception->getErrorCode(),
            $Exception->getMessage(),
            $Exception->getHttpStatus()
        );
    }
}
