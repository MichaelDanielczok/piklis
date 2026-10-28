<?php

namespace MichaelDanielczok\Piklis\Lists;

use DateTimeImmutable;

/**
 * Einladungslink zu einer Liste (`pk_list_invites`): einmal verwendbar,
 * 7 Tage gültig. Die ID nach außen ist der Hash des Tokens.
 */
final class ListInvite
{
    public function __construct(
        public readonly string $tokenHash,
        public readonly string $listId,
        public readonly string $createdBy,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $usedAt = null,
        public ?string $usedBy = null
    ) {
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    /**
     * Noch nicht benutzt und nicht abgelaufen.
     */
    public function isOpen(DateTimeImmutable $now): bool
    {
        return !$this->isUsed() && !$this->isExpired($now);
    }
}
