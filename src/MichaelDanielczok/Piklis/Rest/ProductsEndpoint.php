<?php

namespace MichaelDanielczok\Piklis\Rest;

use Doctrine\DBAL\Exception as DBALException;
use MichaelDanielczok\Piklis\Products\InvalidProductException;
use MichaelDanielczok\Piklis\Products\Product;
use MichaelDanielczok\Piklis\Products\ProductException;
use MichaelDanielczok\Piklis\Products\ProductRepository;
use MichaelDanielczok\Piklis\Products\ProductSearch;
use MichaelDanielczok\Piklis\Products\ProductService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;

use function array_map;
use function is_array;
use function is_string;

/**
 * Produktkatalog: lesen, suchen, anlegen, bearbeiten, ausblenden.
 * Rechte siehe `Products\ProductPermissions`.
 *
 * | Methode | Pfad | Antwort |
 * | --- | --- | --- |
 * | GET | /products | `{ products }`, ganzer Katalog inkl. ausgeblendeter (ETag) |
 * | GET | /products/search?q=&limit= | `{ products }`, Treffer ohne ausgeblendete |
 * | GET | /products/{id} | `{ product }` |
 * | POST | /products | `{ status, product }`, 201 angelegt, 200 schon vorhanden |
 * | PATCH | /products/{id} | `{ product }` |
 * | POST | /products/{id}/hide, /unhide | `{ product }` |
 *
 * Fehler: `400 invalid_product` (mit `field`, `reason`), `403 forbidden`,
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
            $products = (new ProductRepository())->all();
        } catch (DBALException $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'load_failed', 'Products could not be loaded.', 500);
        }

        return Json::respondWithEtag($Request, $Response, [
            'products' => self::toArrays($products)
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
            $products = self::service()->search($query, ProductSearch::limit($params['limit'] ?? null), $includeHidden);
        } catch (DBALException $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'load_failed', 'Products could not be loaded.', 500);
        }

        return Json::respond($Response, [
            'products' => self::toArrays($products)
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
                'product' => self::service()->load((string)($args['id'] ?? ''))->toArray()
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
                    'product' => $result['product']->toArray()
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
                'product' => $Product->toArray()
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
                'product' => $Product->toArray()
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
        } catch (ProductException $Exception) {
            return Json::error(
                $Response,
                $Exception->getErrorCode(),
                $Exception->getMessage(),
                $Exception->getHttpStatus()
            );
        } catch (DBALException $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'save_failed', 'The product could not be saved.', 500);
        }
    }

    private static function service(): ProductService
    {
        return new ProductService(new ProductRepository());
    }

    /**
     * @param list<Product> $products
     * @return list<array<string, mixed>>
     */
    private static function toArrays(array $products): array
    {
        return array_map(static fn(Product $Product): array => $Product->toArray(), $products);
    }
}
