<?php

namespace MichaelDanielczok\Piklis\FoodFacts;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly string $contentType = ''
    ) {
    }
}
