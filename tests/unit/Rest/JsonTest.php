<?php

namespace MichaelDanielczok\PwaStarter\Tests\Unit\Rest;

use GuzzleHttp\Psr7\ServerRequest;
use MichaelDanielczok\PwaStarter\Rest\Json;
use PHPUnit\Framework\TestCase;
use QUI\REST\Response;

/**
 * Beispiel für einen Unit-Test ohne Datenbank: Antwortformat der API.
 */
class JsonTest extends TestCase
{
    public function testErrorHasCodeMessageAndStatus(): void
    {
        $Response = Json::error(new Response(), 'invalid_setting', 'Invalid value.', 400);

        $this->assertSame(400, $Response->getStatusCode());
        $this->assertSame('application/json; charset=utf-8', $Response->getHeaderLine('Content-Type'));
        $this->assertSame(
            ['error' => 'invalid_setting', 'message' => 'Invalid value.'],
            json_decode((string)$Response->getBody(), true)
        );
    }

    public function testEtagAnswers304WhenUnchanged(): void
    {
        $data = ['items' => [1, 2, 3]];
        $First = Json::respondWithEtag(new ServerRequest('GET', '/'), new Response(), $data);
        $etag = $First->getHeaderLine('ETag');

        $this->assertSame(200, $First->getStatusCode());
        $this->assertNotSame('', $etag);

        // mod_deflate hängt -gzip an das ETag an
        $Request = new ServerRequest('GET', '/', ['If-None-Match' => substr($etag, 0, -1) . '-gzip"']);
        $Second = Json::respondWithEtag($Request, new Response(), $data);

        $this->assertSame(304, $Second->getStatusCode());
        $this->assertSame('', (string)$Second->getBody());
    }
}
