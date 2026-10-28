<?php

namespace MichaelDanielczok\Piklis\Households;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use MichaelDanielczok\Piklis\Database\Tables;
use QUI;

use function is_string;

/**
 * Haushalte in `pk_households` und `pk_household_members`.
 */
class HouseholdRepository implements HouseholdStore
{
    private Connection $Connection;

    public function __construct(?Connection $Connection = null)
    {
        $this->Connection = $Connection ?? QUI::getDataBaseConnection();
    }

    public function transactional(callable $callback): mixed
    {
        return $this->Connection->transactional(static fn(): mixed => $callback());
    }

    /**
     * @throws DBALException
     */
    public function membershipOf(string $userId): ?Membership
    {
        $row = $this->Connection->createQueryBuilder()
            ->select('*')
            ->from(Tables::name(Tables::HOUSEHOLD_MEMBERS))
            ->where('user_id = :user')
            ->setParameter('user', $userId)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : self::membershipFromRow($row);
    }

    /**
     * @throws DBALException
     */
    public function find(string $householdId, bool $forUpdate = false): ?Household
    {
        $Query = $this->Connection->createQueryBuilder()
            ->select('*')
            ->from(Tables::name(Tables::HOUSEHOLDS))
            ->where('id = :id')
            ->setParameter('id', $householdId);

        if ($forUpdate) {
            $Query->forUpdate();
        }

        $row = $Query->executeQuery()->fetchAssociative();

        return $row === false ? null : self::householdFromRow($row);
    }

    /**
     * @throws DBALException
     */
    public function findByInviteCode(string $code): ?Household
    {
        $row = $this->Connection->createQueryBuilder()
            ->select('*')
            ->from(Tables::name(Tables::HOUSEHOLDS))
            ->where('invite_code = :code')
            ->setParameter('code', $code)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : self::householdFromRow($row);
    }

    /**
     * @throws DBALException
     */
    public function members(string $householdId): array
    {
        $rows = $this->Connection->createQueryBuilder()
            ->select('*')
            ->from(Tables::name(Tables::HOUSEHOLD_MEMBERS))
            ->where('household_id = :household')
            ->setParameter('household', $householdId)
            ->orderBy('joined_at')
            ->addOrderBy('user_id')
            ->executeQuery()
            ->fetchAllAssociative();

        $members = [];

        foreach ($rows as $row) {
            $members[] = self::membershipFromRow($row);
        }

        return $members;
    }

    /**
     * @throws DBALException
     */
    public function insert(Household $Household): void
    {
        $this->Connection->insert(Tables::name(Tables::HOUSEHOLDS), [
            'id' => $Household->id,
            'name' => $Household->name,
            'invite_code' => $Household->inviteCode,
            'invite_expires_at' => $Household->inviteExpiresAt?->format(Tables::DATETIME_FORMAT),
            'created_by' => $Household->createdBy,
            'created_at' => $Household->createdAt->format(Tables::DATETIME_FORMAT)
        ]);
    }

    /**
     * @throws DBALException
     */
    public function updateInvite(Household $Household): void
    {
        $this->Connection->update(
            Tables::name(Tables::HOUSEHOLDS),
            [
                'invite_code' => $Household->inviteCode,
                'invite_expires_at' => $Household->inviteExpiresAt?->format(Tables::DATETIME_FORMAT)
            ],
            ['id' => $Household->id]
        );
    }

    /**
     * @throws DBALException
     */
    public function addMember(Membership $Membership): void
    {
        $this->Connection->insert(Tables::name(Tables::HOUSEHOLD_MEMBERS), [
            'user_id' => $Membership->userId,
            'household_id' => $Membership->householdId,
            'role' => $Membership->role->value,
            'joined_at' => $Membership->joinedAt->format(Tables::DATETIME_FORMAT)
        ]);
    }

    /**
     * @throws DBALException
     */
    public function setRole(string $userId, HouseholdRole $role): void
    {
        $this->Connection->update(
            Tables::name(Tables::HOUSEHOLD_MEMBERS),
            ['role' => $role->value],
            ['user_id' => $userId]
        );
    }

    /**
     * @throws DBALException
     */
    public function removeMember(string $userId): void
    {
        $this->Connection->delete(Tables::name(Tables::HOUSEHOLD_MEMBERS), ['user_id' => $userId]);
    }

    /**
     * @throws DBALException
     */
    public function delete(string $householdId, string $userId, DateTimeImmutable $now): void
    {
        $this->Connection->createQueryBuilder()
            ->update(Tables::name(Tables::LISTS))
            ->set('deleted_at', ':now')
            ->set('deleted_by', ':user')
            ->where('household_id = :household')
            ->andWhere('deleted_at IS NULL')
            ->setParameter('now', $now->format(Tables::DATETIME_FORMAT))
            ->setParameter('user', $userId)
            ->setParameter('household', $householdId)
            ->executeStatement();

        $this->Connection->delete(Tables::name(Tables::HOUSEHOLD_MEMBERS), ['household_id' => $householdId]);
        $this->Connection->delete(Tables::name(Tables::HOUSEHOLDS), ['id' => $householdId]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function householdFromRow(array $row): Household
    {
        return new Household(
            (string)$row['id'],
            (string)$row['name'],
            self::nullableString($row['invite_code'] ?? null),
            self::date($row['invite_expires_at'] ?? null),
            (string)$row['created_by'],
            self::date($row['created_at']) ?? new DateTimeImmutable()
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function membershipFromRow(array $row): Membership
    {
        return new Membership(
            (string)$row['user_id'],
            (string)$row['household_id'],
            HouseholdRole::tryFrom((string)$row['role']) ?? HouseholdRole::Member,
            self::date($row['joined_at']) ?? new DateTimeImmutable()
        );
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return new DateTimeImmutable($value);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
