<?php

namespace MichaelDanielczok\Piklis\Rest;

use Doctrine\DBAL\Exception as DBALException;
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
use function is_string;

/**
 * Lesen der Listen: Übersicht und eine Liste („alles seit Revision N“).
 * Geschrieben wird nur über `POST /ops`.
 */
class ListsEndpoint
{
    /**
     * `GET /lists`: zugängliche Listen ohne Einträge, mit Rolle und Zählern.
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
        } catch (DBALException $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'load_failed', 'Lists could not be loaded.', 500);
        }

        $data = array_map(
            static fn(array $item): array => [
                ...$item['list']->toArray(),
                'role' => $item['role']->value,
                'counts' => $item['counts']
            ],
            $lists
        );

        return Json::respondWithEtag($Request, $Response, ['lists' => $data]);
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

        $Role = $Repository->roleOf($List, $userId);

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
