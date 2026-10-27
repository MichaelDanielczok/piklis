<?php

namespace MichaelDanielczok\Piklis\Rest;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function array_map;
use function explode;
use function in_array;
use function json_encode;
use function md5;
use function preg_replace;
use function trim;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * JSON-Antworten der API. Fehler immer als `{ "error": "code", "message": "…" }`.
 */
class Json
{
    /**
     * @param array<array-key, mixed> $data
     */
    public static function respond(ResponseInterface $Response, array $data, int $status = 200): ResponseInterface
    {
        $body = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $Response->getBody()->write($body);

        return $Response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    public static function error(
        ResponseInterface $Response,
        string $code,
        string $message,
        int $status
    ): ResponseInterface {
        return self::respond(
            $Response,
            [
                'error' => $code,
                'message' => $message
            ],
            $status
        );
    }

    /**
     * Wie `respond()`, aber mit `ETag`: Der Browser fragt bedingt nach und bekommt
     * bei unveränderten Daten `304` ohne Inhalt. Für Listen gedacht.
     *
     * @param array<array-key, mixed> $data
     */
    public static function respondWithEtag(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $data
    ): ResponseInterface {
        $body = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $etag = '"' . md5($body) . '"';

        $Response = $Response
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', 'private, no-cache');

        if (in_array($etag, self::parseIfNoneMatch($Request->getHeaderLine('If-None-Match')), true)) {
            return $Response->withStatus(304);
        }

        $Response->getBody()->write($body);

        return $Response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * ETags aus `If-None-Match`, ohne `W/` und ohne Zusatz von mod_deflate (`-gzip`).
     *
     * @return string[]
     */
    private static function parseIfNoneMatch(string $header): array
    {
        if ($header === '') {
            return [];
        }

        return array_map(
            static function (string $tag): string {
                $tag = trim($tag);
                $tag = preg_replace('#^W/#', '', $tag) ?? $tag;

                return preg_replace('#-(gzip|br|deflate)"$#', '"', $tag) ?? $tag;
            },
            explode(',', $header)
        );
    }
}
