<?php

namespace MichaelDanielczok\Piklis\Lists;

use DateTimeImmutable;

/**
 * Angenommene Einladung (`pk_list_shares`): Der Benutzer ist Gast an genau
 * dieser Liste.
 */
final class ListShare
{
    public function __construct(
        public readonly string $listId,
        public readonly string $userId,
        public readonly string $invitedBy,
        public readonly DateTimeImmutable $acceptedAt
    ) {
    }
}
