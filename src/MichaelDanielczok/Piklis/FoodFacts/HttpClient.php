<?php

namespace MichaelDanielczok\Piklis\FoodFacts;

/**
 * Minimaler HTTP-Abruf für Open Food Facts. In der App `CurlHttpClient`,
 * in Tests gespeicherte Antworten (Fixtures).
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @param int $maxBytes größere Antworten brechen ab
     * @throws FoodFactsException `off_unavailable` bei Netzfehler oder Zeitüberschreitung
     */
    public function get(string $url, array $headers, int $maxBytes): HttpResponse;
}
