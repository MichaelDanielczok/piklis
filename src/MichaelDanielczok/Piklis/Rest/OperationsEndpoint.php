<?php

namespace MichaelDanielczok\Piklis\Rest;

use MichaelDanielczok\Piklis\Images\QuiqqerImageMedia;
use MichaelDanielczok\Piklis\Lists\ListRepository;
use MichaelDanielczok\Piklis\Products\ProductPresenter;
use MichaelDanielczok\Piklis\Products\ProductRepository;
use MichaelDanielczok\Piklis\Products\ProductService;
use MichaelDanielczok\Piklis\Sync\InvalidOperationException;
use MichaelDanielczok\Piklis\Sync\Operation;
use MichaelDanielczok\Piklis\Sync\OperationProcessor;
use MichaelDanielczok\Piklis\Sync\OperationResult;
use MichaelDanielczok\Piklis\Sync\ProductOperation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use Throwable;

use function array_is_list;
use function count;
use function is_array;

/**
 * `POST /ops`: Operationen der App, in Reihenfolge verarbeitet.
 *
 * Anfrage `{ "ops": [ … ] }` (höchstens 200), Antwort `{ "results": [ … ] }`
 * mit einem Ergebnis je Operation in derselben Reihenfolge. Abgelehnte
 * Operationen stehen als `rejected` in den Ergebnissen, nicht als HTTP-Fehler.
 * Bei einem Serverfehler bricht die Verarbeitung mit 500 ab; die App schickt
 * den Rest erneut (bereits verarbeitete Operationen kommen als `duplicate`).
 *
 * `product.create` (ohne Liste) geht an `ProductOperation`, alle anderen an
 * den `OperationProcessor` der Listen.
 */
class OperationsEndpoint
{
    public const MAX_OPERATIONS = 200;

    /**
     * @param array<string, string> $args
     */
    public function post(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $input = $Request->getParsedBody();
        $operations = is_array($input) ? ($input['ops'] ?? null) : null;

        if (!is_array($operations) || !array_is_list($operations)) {
            return Json::error($Response, 'invalid_request', 'Expected { "ops": [ … ] }.', 400);
        }

        if (count($operations) > self::MAX_OPERATIONS) {
            return Json::error($Response, 'too_many_operations', 'At most 200 operations per request.', 413);
        }

        $userId = SessionUser::id();
        $Processor = new OperationProcessor(new ListRepository());
        $Products = new ProductOperation(
            new ProductService(new ProductRepository()),
            new ProductPresenter(new QuiqqerImageMedia())
        );
        $results = [];

        foreach ($operations as $raw) {
            if (ProductOperation::isProductOperation($raw)) {
                try {
                    $results[] = $Products->process($raw, $userId)->toArray();
                } catch (Throwable $Exception) {
                    QUI\System\Log::writeException($Exception);

                    return Json::error($Response, 'save_failed', 'Operations could not be saved.', 500);
                }

                continue;
            }

            try {
                $Operation = Operation::fromArray($raw);
            } catch (InvalidOperationException $Exception) {
                $Result = OperationResult::rejected(
                    (string)Operation::idOf($raw),
                    'invalid_operation',
                    $Exception->getMessage()
                );

                $results[] = $Result->toArray();
                continue;
            }

            try {
                $results[] = $Processor->process($Operation, $userId)->toArray();
            } catch (Throwable $Exception) {
                QUI\System\Log::writeException($Exception);

                return Json::error($Response, 'save_failed', 'Operations could not be saved.', 500);
            }
        }

        return Json::respond($Response, ['results' => $results]);
    }
}
