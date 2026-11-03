<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\FoodFacts\Fixtures;

use MichaelDanielczok\Piklis\FoodFacts\FoodFactsException;
use MichaelDanielczok\Piklis\FoodFacts\HttpClient;
use MichaelDanielczok\Piklis\FoodFacts\HttpResponse;

use function array_key_exists;
use function file_get_contents;
use function parse_url;

use const PHP_URL_PATH;

/**
 * Antwortet mit gespeicherten Antworten von Open Food Facts
 * (`tests/fixtures/openfoodfacts/`) statt live zu fragen. Merkt sich jede
 * Anfrage samt Kopfzeilen.
 */
final class FixtureHttpClient implements HttpClient
{
    public const DIRECTORY = __DIR__ . '/../../../fixtures/openfoodfacts/';

    /** @var array<string, HttpResponse> Pfad der Adresse → Antwort */
    private array $responses = [];

    /** @var list<array{url: string, headers: array<string, string>}> */
    public array $requests = [];

    public bool $offline = false;

    public static function fixture(string $file): string
    {
        return (string)file_get_contents(self::DIRECTORY . $file);
    }

    /**
     * Produktabruf mit gespeicherter Antwort (200 bzw. 404 für „nicht gefunden“).
     */
    public function product(string $barcode, string $file, int $status = 200): self
    {
        $this->responses['/api/v3.4/product/' . $barcode] = new HttpResponse(
            $status,
            self::fixture($file),
            'application/json; charset=utf-8'
        );

        return $this;
    }

    public function respond(string $path, HttpResponse $Response): self
    {
        $this->responses[$path] = $Response;

        return $this;
    }

    public function get(string $url, array $headers, int $maxBytes): HttpResponse
    {
        $this->requests[] = [
            'url' => $url,
            'headers' => $headers
        ];

        if ($this->offline) {
            throw FoodFactsException::unavailable('Offline in test.');
        }

        $path = (string)parse_url($url, PHP_URL_PATH);

        if (!array_key_exists($path, $this->responses)) {
            return new HttpResponse(500, '', 'text/plain');
        }

        return $this->responses[$path];
    }
}
