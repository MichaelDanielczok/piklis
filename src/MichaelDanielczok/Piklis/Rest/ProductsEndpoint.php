<?php

namespace MichaelDanielczok\Piklis\Rest;

use Doctrine\DBAL\Exception as DBALException;
use MichaelDanielczok\Piklis\Images\ImageLimits;
use MichaelDanielczok\Piklis\Images\InvalidImageException;
use MichaelDanielczok\Piklis\Images\QuiqqerImageMedia;
use MichaelDanielczok\Piklis\Images\UploadedImage;
use MichaelDanielczok\Piklis\Products\InvalidProductException;
use MichaelDanielczok\Piklis\Products\ProductException;
use MichaelDanielczok\Piklis\Products\ProductImageService;
use MichaelDanielczok\Piklis\Products\ProductPresenter;
use MichaelDanielczok\Piklis\Products\ProductRepository;
use MichaelDanielczok\Piklis\Products\ProductSearch;
use MichaelDanielczok\Piklis\Products\ProductService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use QUI;

use function is_array;
use function is_string;

use const UPLOAD_ERR_FORM_SIZE;
use const UPLOAD_ERR_INI_SIZE;
use const UPLOAD_ERR_NO_FILE;
use const UPLOAD_ERR_OK;

/**
 * Produktkatalog: lesen, suchen, anlegen, bearbeiten, ausblenden, Bild.
 * Rechte siehe `Products\ProductPermissions`. Produkte tragen ihre
 * Bildadressen (`image`, `imageUrl`, siehe `Images\ProductImageUrls`).
 *
 * | Methode | Pfad | Antwort |
 * | --- | --- | --- |
 * | GET | /products | `{ products }`, ganzer Katalog inkl. ausgeblendeter (ETag) |
 * | GET | /products/search?q=&limit= | `{ products }`, Treffer ohne ausgeblendete |
 * | GET | /products/{id} | `{ product }` |
 * | POST | /products | `{ status, product }`, 201 angelegt, 200 schon vorhanden |
 * | PATCH | /products/{id} | `{ product }` |
 * | POST | /products/{id}/hide, /unhide | `{ product }` |
 * | POST | /products/{id}/image | `{ product }`, multipart: `image` (Zuschnitt), `original` (optional) |
 * | DELETE | /products/{id}/image | `{ product }` |
 *
 * Fehler: `400 invalid_product` (mit `field`, `reason`), `400 invalid_image`
 * und `413 too_large` (mit `field`, `reason`), `403 forbidden`,
 * `404 product_not_found`, `409 product_exists`, `500 save_failed`.
 */
class ProductsEndpoint
{
    /**
     * @param array<string, string> $args
     */
    public function index(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        try {
            $products = self::presenter()->many((new ProductRepository())->all());
        } catch (DBALException | QUI\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'load_failed', 'Products could not be loaded.', 500);
        }

        return Json::respondWithEtag($Request, $Response, [
            'products' => $products
        ]);
    }

    /**
     * `?q=` Suchtext, `?limit=` (Standard 20, höchstens 100), `?hidden=1`
     * zeigt Produktpflegern auch ausgeblendete.
     *
     * @param array<string, string> $args
     */
    public function search(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $params = $Request->getQueryParams();
        $query = is_string($params['q'] ?? null) ? $params['q'] : '';
        $includeHidden = ($params['hidden'] ?? '') === '1' && SessionUser::canManageProducts();

        try {
            $products = self::presenter()->many(
                self::service()->search($query, ProductSearch::limit($params['limit'] ?? null), $includeHidden)
            );
        } catch (DBALException | QUI\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'load_failed', 'Products could not be loaded.', 500);
        }

        return Json::respond($Response, [
            'products' => $products
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function get(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        return self::run($Response, static function () use ($args): array {
            return [
                'product' => self::presenter()->one(self::service()->load((string)($args['id'] ?? '')))
            ];
        });
    }

    /**
     * Erwartet `{ id, name, category?, icon?, description?, packageSize? }`;
     * die ID erzeugt der Browser (`crypto.randomUUID()`).
     *
     * @param array<string, string> $args
     */
    public function create(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $input = $Request->getParsedBody();

        if (!is_array($input)) {
            return Json::error($Response, 'invalid_request', 'Expected a JSON object.', 400);
        }

        return self::run(
            $Response,
            static function () use ($input): array {
                $result = self::service()->create($input['id'] ?? null, $input, SessionUser::id());

                return [
                    'status' => $result['status'],
                    'product' => self::presenter()->one($result['product'])
                ];
            },
            static fn(array $data): int => $data['status'] === ProductService::CREATED ? 201 : 200
        );
    }

    /**
     * Erwartet die geänderten Felder, z. B. `{ "description": "…" }`.
     *
     * @param array<string, string> $args
     */
    public function update(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $input = $Request->getParsedBody();

        if (!is_array($input)) {
            return Json::error($Response, 'invalid_request', 'Expected a JSON object.', 400);
        }

        return self::run($Response, static function () use ($args, $input): array {
            $Product = self::service()->update(
                (string)($args['id'] ?? ''),
                $input,
                SessionUser::id(),
                SessionUser::canManageProducts()
            );

            return [
                'product' => self::presenter()->one($Product)
            ];
        });
    }

    /**
     * @param array<string, string> $args
     */
    public function hide(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        return self::setHidden($Response, (string)($args['id'] ?? ''), true);
    }

    /**
     * @param array<string, string> $args
     */
    public function unhide(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        return self::setHidden($Response, (string)($args['id'] ?? ''), false);
    }

    /**
     * Setzt oder ersetzt das Bild (multipart/form-data): `image` ist der
     * quadratische Zuschnitt, `original` das verkleinerte Original. Ohne
     * `original` bleibt das bisherige (neu zuschneiden). Beide JPEG, im
     * Browser neu kodiert.
     *
     * @param array<string, string> $args
     */
    public function setImage(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $files = $Request->getUploadedFiles();

        try {
            $crop = self::uploadedContent($files[UploadedImage::FIELD_CROP] ?? null, UploadedImage::FIELD_CROP);
            $original = self::uploadedContent(
                $files[UploadedImage::FIELD_ORIGINAL] ?? null,
                UploadedImage::FIELD_ORIGINAL
            );
        } catch (InvalidImageException $Exception) {
            return self::imageError($Response, $Exception);
        }

        if ($crop === null) {
            return self::imageError($Response, new InvalidImageException(UploadedImage::FIELD_CROP, 'required'));
        }

        return self::run($Response, static function () use ($args, $crop, $original): array {
            $Product = self::imageService()->setImage(
                (string)($args['id'] ?? ''),
                $crop,
                $original,
                SessionUser::id(),
                SessionUser::canManageProducts()
            );

            return [
                'product' => self::presenter()->one($Product)
            ];
        });
    }

    /**
     * Entfernt das Bild (die App zeigt wieder das Ersatzicon).
     *
     * @param array<string, string> $args
     */
    public function removeImage(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        return self::run($Response, static function () use ($args): array {
            $Product = self::imageService()->removeImage(
                (string)($args['id'] ?? ''),
                SessionUser::id(),
                SessionUser::canManageProducts()
            );

            return [
                'product' => self::presenter()->one($Product)
            ];
        });
    }

    /**
     * Inhalt einer hochgeladenen Datei; null, wenn das Feld fehlt.
     *
     * @throws InvalidImageException
     */
    private static function uploadedContent(mixed $File, string $field): ?string
    {
        if ($File === null) {
            return null;
        }

        if (!($File instanceof UploadedFileInterface)) {
            throw new InvalidImageException($field, 'not_jpeg');
        }

        $error = $File->getError();

        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidImageException($field, 'too_large');
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidImageException($field, 'required');
        }

        return (string)$File->getStream();
    }

    private static function imageError(ResponseInterface $Response, InvalidImageException $Exception): ResponseInterface
    {
        return Json::respond(
            $Response,
            [
                'error' => $Exception->getHttpStatus() === 413 ? 'too_large' : 'invalid_image',
                'message' => $Exception->getMessage(),
                'field' => $Exception->getField(),
                'reason' => $Exception->getReason()
            ],
            $Exception->getHttpStatus()
        );
    }

    private static function setHidden(ResponseInterface $Response, string $id, bool $hidden): ResponseInterface
    {
        return self::run($Response, static function () use ($id, $hidden): array {
            $Product = self::service()->setHidden(
                $id,
                $hidden,
                SessionUser::id(),
                SessionUser::canManageProducts()
            );

            return [
                'product' => self::presenter()->one($Product)
            ];
        });
    }

    /**
     * Führt eine Aktion aus und übersetzt ihre Ausnahmen in API-Fehler.
     *
     * @param callable(): array<string, mixed> $action
     * @param (callable(array<string, mixed>): int)|null $statusOf HTTP-Status je nach Ergebnis (Standard 200)
     */
    private static function run(
        ResponseInterface $Response,
        callable $action,
        ?callable $statusOf = null
    ): ResponseInterface {
        try {
            $data = $action();
            $status = $statusOf === null ? 200 : $statusOf($data);

            return Json::respond($Response, $data, $status);
        } catch (InvalidProductException $Exception) {
            return Json::respond(
                $Response,
                [
                    'error' => 'invalid_product',
                    'message' => $Exception->getMessage(),
                    'field' => $Exception->getField(),
                    'reason' => $Exception->getReason()
                ],
                400
            );
        } catch (InvalidImageException $Exception) {
            return self::imageError($Response, $Exception);
        } catch (ProductException $Exception) {
            return Json::error(
                $Response,
                $Exception->getErrorCode(),
                $Exception->getMessage(),
                $Exception->getHttpStatus()
            );
        } catch (DBALException | QUI\Exception $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'save_failed', 'The product could not be saved.', 500);
        }
    }

    private static function service(): ProductService
    {
        return new ProductService(new ProductRepository());
    }

    /**
     * @throws QUI\Exception
     */
    private static function imageService(): ProductImageService
    {
        return new ProductImageService(new ProductRepository(), new QuiqqerImageMedia(), ImageLimits::fromProject());
    }

    /**
     * @throws QUI\Exception
     */
    private static function presenter(): ProductPresenter
    {
        return new ProductPresenter(new QuiqqerImageMedia());
    }
}
