<?php

namespace MichaelDanielczok\Piklis\Rest;

use Doctrine\DBAL\Exception as DBALException;
use MichaelDanielczok\Piklis\Templates\TemplateException;
use MichaelDanielczok\Piklis\Templates\TemplateRepository;
use MichaelDanielczok\Piklis\Templates\TemplateService;
use MichaelDanielczok\Piklis\Templates\TemplateState;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;

use function array_map;
use function is_array;
use function preg_match;
use function trim;

/**
 * Vorlagen (Schritt 12): Übersicht, eine Vorlage, anlegen, ganz speichern
 * (`If-Match` mit der bearbeiteten Revision, sonst `409 template_changed`),
 * löschen, in den Haushalt verschieben und kopieren. Regeln in
 * `Templates\TemplateService`; nur Besitzer und Haushaltsmitglieder, keine
 * Gäste.
 */
class TemplatesEndpoint
{
    /**
     * `GET /templates`: zugängliche Vorlagen ohne Einträge, mit Rolle, Anzahl
     * und den ersten Produkten (`preview`). ETag.
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
            $templates = (new TemplateRepository())->templatesForUser($userId);
        } catch (DBALException $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'load_failed', 'Templates could not be loaded.', 500);
        }

        $data = array_map(
            static fn(array $item): array => [
                ...$item['template']->toArray(),
                'role' => $item['role']->value,
                'count' => $item['count'],
                'preview' => $item['preview']
            ],
            $templates
        );

        return Json::respondWithEtag($Request, $Response, ['templates' => $data]);
    }

    /**
     * `GET /templates/{id}`: `{ template }` mit allen Einträgen, Rolle und dem
     * Namen dessen, der zuletzt gespeichert hat. ETag.
     *
     * @param array<string, string> $args
     */
    public function get(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $userId = SessionUser::id();
        $Repository = new TemplateRepository();

        try {
            $Template = (new TemplateService($Repository))->get((string)($args['id'] ?? ''), $userId);
        } catch (TemplateException $Exception) {
            return self::templateError($Response, $Exception, $userId);
        } catch (DBALException $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'load_failed', 'The template could not be loaded.', 500);
        }

        return Json::respondWithEtag($Request, $Response, [
            'template' => self::full($Template, $userId, $Repository)
        ]);
    }

    /**
     * `POST /templates` `{ id, name, householdId, entries: [{ productId,
     * quantity, note }] }`: `201` `{ status: 'created', template }`, bei
     * Wiederholung `200` `unchanged`.
     *
     * @param array<string, string> $args
     */
    public function create(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $userId = SessionUser::id();
        $Repository = new TemplateRepository();

        try {
            $result = (new TemplateService($Repository))->create(self::body($Request), $userId);
        } catch (TemplateException $Exception) {
            return self::templateError($Response, $Exception, $userId);
        } catch (DBALException $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'save_failed', 'The template could not be saved.', 500);
        }

        return Json::respond(
            $Response,
            [
                'status' => $result['status'],
                'template' => self::full($result['template'], $userId, $Repository)
            ],
            $result['status'] === 'created' ? 201 : 200
        );
    }

    /**
     * `PUT /templates/{id}` mit `If-Match: "<revision>"` und `{ name,
     * entries }`: ganz speichern, Antwort `{ template }`. Inzwischen von
     * jemand anderem gespeichert: `409 template_changed` mit `template`
     * (aktueller Stand).
     *
     * @param array<string, string> $args
     */
    public function update(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $userId = SessionUser::id();
        $Repository = new TemplateRepository();

        try {
            $Template = (new TemplateService($Repository))->save(
                (string)($args['id'] ?? ''),
                self::body($Request),
                self::ifMatch($Request->getHeaderLine('If-Match')),
                $userId
            );
        } catch (TemplateException $Exception) {
            return self::templateError($Response, $Exception, $userId, $Repository);
        } catch (DBALException $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'save_failed', 'The template could not be saved.', 500);
        }

        return Json::respond($Response, ['template' => self::full($Template, $userId, $Repository)]);
    }

    /**
     * `DELETE /templates/{id}`: `{ status: 'deleted' }`.
     *
     * @param array<string, string> $args
     */
    public function delete(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $userId = SessionUser::id();

        try {
            (new TemplateService(new TemplateRepository()))->delete((string)($args['id'] ?? ''), $userId);
        } catch (TemplateException $Exception) {
            return self::templateError($Response, $Exception, $userId);
        } catch (DBALException $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'save_failed', 'The template could not be deleted.', 500);
        }

        return Json::respond($Response, ['status' => 'deleted']);
    }

    /**
     * `POST /templates/{id}/move` `{ to: "household" }`: persönliche Vorlage in
     * den Haushalt (ID bleibt). Antwort `{ template }`.
     *
     * @param array<string, string> $args
     */
    public function move(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $input = self::body($Request);

        if (($input['to'] ?? null) !== TemplateService::TO_HOUSEHOLD) {
            return Json::error($Response, 'invalid_request', 'Expected { "to": "household" }.', 400);
        }

        $userId = SessionUser::id();
        $Repository = new TemplateRepository();

        try {
            $Template = (new TemplateService($Repository))->moveToHousehold((string)($args['id'] ?? ''), $userId);
        } catch (TemplateException $Exception) {
            return self::templateError($Response, $Exception, $userId);
        } catch (DBALException $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'save_failed', 'The template could not be moved.', 500);
        }

        return Json::respond($Response, ['template' => self::full($Template, $userId, $Repository)]);
    }

    /**
     * `POST /templates/{id}/copy` `{ id, to: "household" | "personal" }`:
     * `201` `{ status: 'created', template }`, bei Wiederholung `200`
     * `unchanged`.
     *
     * @param array<string, string> $args
     */
    public function copy(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $args
    ): ResponseInterface {
        $input = self::body($Request);
        $userId = SessionUser::id();
        $Repository = new TemplateRepository();

        try {
            $result = (new TemplateService($Repository))->copy(
                (string)($args['id'] ?? ''),
                $input['id'] ?? null,
                $input['to'] ?? null,
                $userId
            );
        } catch (TemplateException $Exception) {
            return self::templateError($Response, $Exception, $userId);
        } catch (DBALException $Exception) {
            QUI\System\Log::writeException($Exception);

            return Json::error($Response, 'save_failed', 'The template could not be copied.', 500);
        }

        return Json::respond(
            $Response,
            [
                'status' => $result['status'],
                'template' => self::full($result['template'], $userId, $Repository)
            ],
            $result['status'] === 'created' ? 201 : 200
        );
    }

    /**
     * Revision aus `If-Match` (`"3"`, auch ohne Anführungszeichen oder mit
     * `W/`); null, wenn sie fehlt oder keine Zahl ist.
     */
    public static function ifMatch(string $header): ?int
    {
        if (preg_match('#^\s*(?:W/)?"?(\d{1,18})"?\s*$#', trim($header), $matches) !== 1) {
            return null;
        }

        return (int)$matches[1];
    }

    /**
     * Vorlage mit Einträgen, Rolle und Namen dessen, der zuletzt gespeichert hat.
     *
     * @return array<string, mixed>
     * @throws DBALException
     */
    private static function full(TemplateState $Template, string $userId, TemplateRepository $Repository): array
    {
        $householdId = $Template->householdId === null ? null : $Repository->householdIdOf($userId);
        $Role = TemplateService::roleOf($Template, $userId, $householdId);
        $profile = SharesEndpoint::profile($Template->updatedBy);

        return [
            ...$Template->toArrayWithEntries(),
            'role' => $Role?->value,
            'updatedByName' => (string)$profile['name']
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function body(ServerRequestInterface $Request): array
    {
        $input = $Request->getParsedBody();

        return is_array($input) ? $input : [];
    }

    private static function templateError(
        ResponseInterface $Response,
        TemplateException $Exception,
        string $userId,
        ?TemplateRepository $Repository = null
    ): ResponseInterface {
        $data = [
            'error' => $Exception->getErrorCode(),
            'message' => $Exception->getMessage()
        ];

        if ($Exception->getField() !== null) {
            $data['field'] = $Exception->getField();
        }

        $Current = $Exception->getCurrent();

        if ($Current !== null && $Repository !== null) {
            try {
                $data['template'] = self::full($Current, $userId, $Repository);
            } catch (DBALException $DbException) {
                QUI\System\Log::writeException($DbException);
            }
        }

        return Json::respond($Response, $data, $Exception->getHttpStatus());
    }
}
