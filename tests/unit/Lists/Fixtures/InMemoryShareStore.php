<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Lists\Fixtures;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Lists\ListInvite;
use MichaelDanielczok\Piklis\Lists\ListShare;
use MichaelDanielczok\Piklis\Lists\ShareStore;
use MichaelDanielczok\Piklis\Tests\Unit\Sync\Fixtures\InMemoryListStore;

use function array_filter;
use function array_values;

/**
 * Freigaben und Links im Arbeitsspeicher. Hält die Gäste des
 * `InMemoryListStore` aktuell, damit Rollen und Operationen wie in der App
 * von den Freigaben abhängen.
 */
final class InMemoryShareStore implements ShareStore
{
    /** @var array<string, ListInvite> Hash => Link */
    public array $invites = [];

    /** @var list<ListShare> */
    public array $shares = [];

    public function __construct(private readonly InMemoryListStore $Lists)
    {
    }

    public function findInvite(string $tokenHash, bool $forUpdate = false): ?ListInvite
    {
        return $this->invites[$tokenHash] ?? null;
    }

    public function insertInvite(ListInvite $Invite): void
    {
        $this->invites[$Invite->tokenHash] = $Invite;
    }

    public function markInviteUsed(ListInvite $Invite): void
    {
        $this->invites[$Invite->tokenHash] = $Invite;
    }

    public function deleteInvite(string $tokenHash): void
    {
        unset($this->invites[$tokenHash]);
    }

    public function openInvites(string $listId, DateTimeImmutable $now): array
    {
        return array_values(array_filter(
            $this->invites,
            static fn(ListInvite $Invite): bool => $Invite->listId === $listId && $Invite->isOpen($now)
        ));
    }

    public function shares(string $listId): array
    {
        return array_values(array_filter(
            $this->shares,
            static fn(ListShare $Share): bool => $Share->listId === $listId
        ));
    }

    public function findShare(string $listId, string $userId): ?ListShare
    {
        foreach ($this->shares as $Share) {
            if ($Share->listId === $listId && $Share->userId === $userId) {
                return $Share;
            }
        }

        return null;
    }

    public function insertShare(ListShare $Share): void
    {
        $this->shares[] = $Share;
        $this->Lists->guests[$Share->listId][] = $Share->userId;
    }

    public function deleteShare(string $listId, string $userId): void
    {
        $this->shares = array_values(array_filter(
            $this->shares,
            static fn(ListShare $Share): bool => $Share->listId !== $listId || $Share->userId !== $userId
        ));

        $this->Lists->guests[$listId] = array_values(array_filter(
            $this->Lists->guests[$listId] ?? [],
            static fn(string $guest): bool => $guest !== $userId
        ));
    }
}
