<?php

namespace MichaelDanielczok\Piklis\Rest;

use Doctrine\DBAL\Exception as DBALException;
use MichaelDanielczok\Piklis\Lists\ListException;
use MichaelDanielczok\Piklis\Lists\ListOwnership;
use MichaelDanielczok\Piklis\Lists\ListRepository;
use MichaelDanielczok\Piklis\Sync\EntryState;
use MichaelDanielczok\Piklis\Sync\ListState;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;

use function array_map;
use function array_values;
use function array_filter;
use function ctype_digit;
use function is_array;
use function is_string;

/**
 * Lesen der Listen: Übersicht und eine Liste („alles seit Revision N“).
 * Geschrieben wird über `POST /ops`; nur die Zugehörigkeit (in den Haushalt
 * verschieben, kopieren) läuft über eigene Routen (`Lists\ListOwnership`).
 * Jede Route prüft die Rolle des Benutzers an der Liste.
 */
class ListsEndpoint
{
    /**
     * `GET /lists`: zugängliche Listen ohne Einträge, mit Rolle, Zählern und
     * den Produkt-IDs der ersten offenen Einträge (`preview`), dazu der
     * Haushalt in Kurzform (`household: { id, name, role } | null` wie bei
     * `GET /me`): Die App fragt die Übersicht regelmäßig ab und merkt so auch,
     * wenn sie aus dem Haushalt entfernt oder er aufgelöst wurde.
     *
     * @param array<string, string> $args
     */
    public function index(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $userId = SessionUser::id();

        try {
            $lists = (new ListRepository())->listsForUser($userId);
            $household = HouseholdEndpoint::summary($userId);
        } catch (DBALException $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'load_failed', 'Lists could not be loaded.', 500);
        }

        $data = array_map(
            static fn(array $item): array => [
                ...$item['list']->toArray(),
                'role' => $item['role']->value,
                'counts' => $item['counts'],
                'preview' => $item['preview']
            ],
            $lists
        );

        return Json::respondWithEtag($Request, $Response, ['lists' => $data, 'household' => $household]);
    }

    /**
     * `GET /lists/{id}?since=N`: ohne `since` (oder bei unbekannter Revision)
     * alle sichtbaren Einträge (`full: true`), sonst nur Einträge mit einer
     * Revision über N, auch entfernte und Verweise (`full: false`).
     *
     * @param array<string, string> $args
     */
    public function get(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $userId = SessionUser::id();
        $Repository = new ListRepository();

        try {
            $List = $Repository->loadList((string)($args['id'] ?? ''), false);
        } catch (DBALException $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'load_failed', 'The list could not be loaded.', 500);
        }

        if ($List === null) {
            return Json::error($Response, 'list_not_found', 'The list does not exist.', 404);
        }

        if ($List->isDeleted()) {
            return Json::error($Response, 'list_deleted', 'The list has been deleted.', 410);
        }

        try {
            $Role = $Repository->roleOf($List, $userId);
        } catch (DBALException $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'load_failed', 'The list could not be loaded.', 500);
        }

        if ($Role === null) {
            return Json::error($Response, 'no_access', 'No access to this list.', 403);
        }

        $since = self::since($Request->getQueryParams()['since'] ?? null, $List);
        $entries = $since === null ? $List->visibleEntries() : self::changedSince($List, $since);

        return Json::respondWithEtag($Request, $Response, [
            'list' => [...$List->toArray(), 'role' => $Role->value],
            'revision' => $List->revision,
            'full' => $since === null,
            'entries' => array_map(static fn(EntryState $Entry): array => $Entry->toArray(), $entries)
        ]);
    }

    /**
     * `POST /lists/{id}/move` `{ to: "household" }`: persönliche Liste in den
     * Haushalt des Besitzers verschieben (ID bleibt). Antwort `{ list }`.
     *
     * @param array<string, string> $args
     */
    public function move(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $input = $Request->getParsedBody();
        $to = is_array($input) ? ($input['to'] ?? null) : null;

        if ($to !== ListOwnership::TO_HOUSEHOLD) {
            return Json::error($Response, 'invalid_request', 'Expected { "to": "household" }.', 400);
        }

        $userId = SessionUser::id();
        $Repository = new ListRepository();

        try {
            $List = (new ListOwnership($Repository))->moveToHousehold((string)($args['id'] ?? ''), $userId);
            $Role = $Repository->roleOf($List, $userId);
        } catch (ListException $Exception) {
            return self::listError($Response, $Exception);
        } catch (DBALException $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'save_failed', 'The list could not be moved.', 500);
        }

        return Json::respond($Response, ['list' => [...$List->toArray(), 'role' => $Role?->value]]);
    }

    /**
     * `POST /lists/{id}/copy` `{ id, to: "household" | "personal" }`: neue
     * Liste mit der ID aus dem Browser. Antwort `{ status, list }`, `201`
     * beim Anlegen, `200` mit `unchanged` bei Wiederholung.
     *
     * @param array<string, string> $args
     */
    public function copy(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $input = $Request->getParsedBody();
        $input = is_array($input) ? $input : [];
        $userId = SessionUser::id();
        $Repository = new ListRepository();

        try {
            $result = (new ListOwnership($Repository))->copy(
                (string)($args['id'] ?? ''),
                $input['id'] ?? null,
                $input['to'] ?? null,
                $userId
            );
            $Role = $Repository->roleOf($result['list'], $userId);
        } catch (ListException $Exception) {
            return self::listError($Response, $Exception);
        } catch (DBALException $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'save_failed', 'The list could not be copied.', 500);
        }

        return Json::respond(
            $Response,
            ['status' => $result['status'], 'list' => [...$result['list']->toArray(), 'role' => $Role?->value]],
            $result['status'] === 'created' ? 201 : 200
        );
    }

    private static function listError(ResponseInterface $Response, ListException $Exception): ResponseInterface
    {
        return Json::error(
            $Response,
            $Exception->getErrorCode(),
            $Exception->getMessage(),
            $Exception->getHttpStatus()
        );
    }

    /**
     * Gültige Revision aus `?since=`, null für „alles“.
     */
    private static function since(mixed $value, ListState $List): ?int
    {
        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }

        $since = (int)$value;

        return $since > $List->revision ? null : $since;
    }

    /**
     * @return list<EntryState>
     */
    private static function changedSince(ListState $List, int $since): array
    {
        return array_values(array_filter(
            $List->entries(),
            static fn(EntryState $Entry): bool => $Entry->revision > $since
        ));
    }
}
