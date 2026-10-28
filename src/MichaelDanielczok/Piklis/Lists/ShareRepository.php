<?php

namespace MichaelDanielczok\Piklis\Lists;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use MichaelDanielczok\Piklis\Database\Tables;
use QUI;

use function array_map;
use function is_string;

/**
 * Freigaben und Einladungslinks in der Datenbank (`pk_list_shares`,
 * `pk_list_invites`).
 */
class ShareRepository implements ShareStore
{
    private Connection $Connection;

    public function __construct(?Connection $Connection = null)
    {
        $this->Connection = $Connection ?? QUI::getDataBaseConnection();
    }

    /**
     * @throws DBALException
     */
    public function findInvite(string $tokenHash, bool $forUpdate = false): ?ListInvite
    {
        $Query = $this->Connection->createQueryBuilder()
            ->select('*')
            ->from(Tables::name(Tables::LIST_INVITES))
            ->where('token_hash = :hash')
            ->setParameter('hash', $tokenHash);

        if ($forUpdate) {
            $Query->forUpdate();
        }

        $row = $Query->executeQuery()->fetchAssociative();

        return $row === false ? null : self::inviteFromRow($row);
    }

    /**
     * @throws DBALException
     */
    public function insertInvite(ListInvite $Invite): void
    {
        $this->Connection->insert(Tables::name(Tables::LIST_INVITES), [
            'token_hash' => $Invite->tokenHash,
            'list_id' => $Invite->listId,
            'created_by' => $Invite->createdBy,
            'created_at' => $Invite->createdAt->format(Tables::DATETIME_FORMAT),
            'expires_at' => $Invite->expiresAt->format(Tables::DATETIME_FORMAT),
            'used_at' => $Invite->usedAt?->format(Tables::DATETIME_FORMAT),
            'used_by' => $Invite->usedBy
        ]);
    }

    /**
     * @throws DBALException
     */
    public function markInviteUsed(ListInvite $Invite): void
    {
        $this->Connection->update(
            Tables::name(Tables::LIST_INVITES),
            [
                'used_at' => $Invite->usedAt?->format(Tables::DATETIME_FORMAT),
                'used_by' => $Invite->usedBy
            ],
            ['token_hash' => $Invite->tokenHash]
        );
    }

    /**
     * @throws DBALException
     */
    public function deleteInvite(string $tokenHash): void
    {
        $this->Connection->delete(Tables::name(Tables::LIST_INVITES), ['token_hash' => $tokenHash]);
    }

    /**
     * @throws DBALException
     */
    public function openInvites(string $listId, DateTimeImmutable $now): array
    {
        $rows = $this->Connection->createQueryBuilder()
            ->select('*')
            ->from(Tables::name(Tables::LIST_INVITES))
            ->where('list_id = :list')
            ->andWhere('used_at IS NULL')
            ->andWhere('expires_at > :now')
            ->setParameter('list', $listId)
            ->setParameter('now', $now->format(Tables::DATETIME_FORMAT))
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('token_hash', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(self::inviteFromRow(...), $rows);
    }

    /**
     * @throws DBALException
     */
    public function shares(string $listId): array
    {
        $rows = $this->Connection->createQueryBuilder()
            ->select('*')
            ->from(Tables::name(Tables::LIST_SHARES))
            ->where('list_id = :list')
            ->setParameter('list', $listId)
            ->orderBy('accepted_at', 'ASC')
            ->addOrderBy('user_id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(self::shareFromRow(...), $rows);
    }

    /**
     * @throws DBALException
     */
    public function findShare(string $listId, string $userId): ?ListShare
    {
        $row = $this->Connection->createQueryBuilder()
            ->select('*')
            ->from(Tables::name(Tables::LIST_SHARES))
            ->where('list_id = :list')
            ->andWhere('user_id = :user')
            ->setParameter('list', $listId)
            ->setParameter('user', $userId)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : self::shareFromRow($row);
    }

    /**
     * @throws DBALException
     */
    public function insertShare(ListShare $Share): void
    {
        $this->Connection->insert(Tables::name(Tables::LIST_SHARES), [
            'list_id' => $Share->listId,
            'user_id' => $Share->userId,
            'invited_by' => $Share->invitedBy,
            'accepted_at' => $Share->acceptedAt->format(Tables::DATETIME_FORMAT)
        ]);
    }

    /**
     * @throws DBALException
     */
    public function deleteShare(string $listId, string $userId): void
    {
        $this->Connection->delete(Tables::name(Tables::LIST_SHARES), [
            'list_id' => $listId,
            'user_id' => $userId
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function inviteFromRow(array $row): ListInvite
    {
        return new ListInvite(
            (string)$row['token_hash'],
            (string)$row['list_id'],
            (string)$row['created_by'],
            new DateTimeImmutable((string)$row['created_at']),
            new DateTimeImmutable((string)$row['expires_at']),
            self::date($row['used_at'] ?? null),
            is_string($row['used_by'] ?? null) && $row['used_by'] !== '' ? $row['used_by'] : null
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function shareFromRow(array $row): ListShare
    {
        return new ListShare(
            (string)$row['list_id'],
            (string)$row['user_id'],
            (string)$row['invited_by'],
            new DateTimeImmutable((string)$row['accepted_at'])
        );
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }
}
