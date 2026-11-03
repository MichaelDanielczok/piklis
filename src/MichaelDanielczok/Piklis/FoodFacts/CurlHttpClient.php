<?php

namespace MichaelDanielczok\Piklis\FoodFacts;

use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function is_string;
use function strlen;

use const CURLE_WRITE_ERROR;
use const CURLINFO_CONTENT_TYPE;
use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_PROTOCOLS;
use const CURLOPT_TIMEOUT;
use const CURLOPT_WRITEFUNCTION;
use const CURLPROTO_HTTPS;

/**
 * HTTP-Abruf mit cURL: nur HTTPS, keine Weiterleitungen (eine Weiterleitung
 * zu Open Beauty Facts o. Ä. gilt als „nicht gefunden“), kurze Zeitgrenzen,
 * Größenbegrenzung schon beim Lesen.
 */
final class CurlHttpClient implements HttpClient
{
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT = 12;

    public function get(string $url, array $headers, int $maxBytes): HttpResponse
    {
        $Handle = curl_init($url);

        if ($Handle === false) {
            throw FoodFactsException::unavailable('cURL could not be started.');
        }

        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $body = '';
        $tooLarge = false;

        curl_setopt_array($Handle, [
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_WRITEFUNCTION => static function ($Handle, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;

                    return 0;
                }

                $body .= $chunk;

                return strlen($chunk);
            }
        ]);

        $ok = curl_exec($Handle);
        $errorNumber = curl_errno($Handle);
        $error = curl_error($Handle);
        $status = (int)curl_getinfo($Handle, CURLINFO_RESPONSE_CODE);
        $contentType = curl_getinfo($Handle, CURLINFO_CONTENT_TYPE);

        if ($tooLarge && $errorNumber === CURLE_WRITE_ERROR) {
            throw FoodFactsException::unavailable('Response too large.');
        }

        if ($ok === false || $errorNumber !== 0) {
            throw FoodFactsException::unavailable($error !== '' ? $error : 'Request failed.');
        }

        return new HttpResponse($status, $body, is_string($contentType) ? $contentType : '');
    }
}
