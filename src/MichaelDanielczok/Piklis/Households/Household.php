<?php

namespace MichaelDanielczok\Piklis\Households;

use DateTimeImmutable;

/**
 * Ein Haushalt (Zeile von `pk_households`).
 */
final class Household
{
    public function __construct(
        public readonly string $id,
        public string $name,
        public ?string $inviteCode,
        public ?DateTimeImmutable $inviteExpiresAt,
        public readonly string $createdBy,
        public readonly DateTimeImmutable $createdAt
    ) {
    }

    /**
     * Gültiger Einladungscode zum Zeitpunkt `$now`, sonst null.
     */
    public function activeInviteCode(DateTimeImmutable $now): ?string
    {
        if ($this->inviteCode === null || $this->inviteExpiresAt === null || $this->inviteExpiresAt <= $now) {
            return null;
        }

        return $this->inviteCode;
    }
}
