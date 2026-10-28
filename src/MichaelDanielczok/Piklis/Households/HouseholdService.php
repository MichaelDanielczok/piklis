<?php

namespace MichaelDanielczok\Piklis\Households;

use Closure;
use DateTimeImmutable;
use MichaelDanielczok\Piklis\Support\Uuid;

use function count;
use function is_string;
use function mb_strlen;
use function preg_replace;
use function trim;

/**
 * Regeln des Haushalts (Konzept „Persönlicher Start und Haushalt“ und
 * „Haushaltsrollen“), ohne HTTP und ohne Datenbank:
 *
 * - Höchstens ein Haushalt je Person. Anlegen macht zum Administrator.
 * - Beitritt mit Einladungscode: 7 Tage gültig, mehrfach verwendbar,
 *   Administratoren erzeugen ihn neu (der alte verfällt).
 * - Wer schon in einem anderen Haushalt ist, wechselt nur ausdrücklich
 *   (`switch`): Er verlässt den alten, dessen Listen bleiben dort.
 * - Administratoren ernennen weitere Administratoren, entfernen Mitglieder
 *   und lösen den Haushalt auf.
 * - Der letzte Administrator geht erst, wenn ein anderer übernimmt
 *   (`last_admin`). Geht das letzte Mitglied, wird der Haushalt mit seinen
 *   Listen gelöscht; das verlangt die Bestätigung `confirmDissolve`.
 *
 * Das Angebot, Listen vorher als persönliche Kopie mitzunehmen, macht die
 * App (`Lists\ListOwnership::copy`), bevor sie geht.
 */
final class HouseholdService
{
    public const NAME_MAX_LENGTH = 100;

    /** @var Closure(): string */
    private Closure $newCode;

    /** @var Closure(): string */
    private Closure $newId;

    /**
     * @param (Closure(): string)|null $newCode Einladungscode (Tests: fest)
     * @param (Closure(): string)|null $newId ID eines neuen Haushalts
     */
    public function __construct(
        private readonly HouseholdStore $Store,
        ?Closure $newCode = null,
        ?Closure $newId = null
    ) {
        $this->newCode = $newCode ?? static fn(): string => InviteCode::generate();
        $this->newId = $newId ?? static fn(): string => Uuid::random();
    }

    public function membershipOf(string $userId): ?Membership
    {
        return $this->Store->membershipOf($userId);
    }

    /**
     * Legt einen Haushalt an; der Benutzer wird Administrator, ein
     * Einladungscode ist gleich da.
     *
     * @throws HouseholdException
     */
    public function create(string $userId, mixed $name, ?DateTimeImmutable $now = null): Household
    {
        $now ??= new DateTimeImmutable();
        $name = self::name($name);

        return $this->Store->transactional(function () use ($userId, $name, $now): Household {
            if ($this->Store->membershipOf($userId) !== null) {
                throw self::alreadyMember();
            }

            $Household = new Household(($this->newId)(), $name, null, null, $userId, $now);
            $this->renewCode($Household, $now);

            $this->Store->insert($Household);
            $this->Store->addMember(new Membership($userId, $Household->id, HouseholdRole::Admin, $now));

            return $Household;
        });
    }

    /**
     * Beitritt mit Einladungscode als Mitglied. Ist der Benutzer schon in
     * einem anderen Haushalt, nur mit `$switch` (Wechsel: er verlässt den
     * alten nach den Regeln von `leave()`).
     *
     * @throws HouseholdException
     */
    public function join(
        string $userId,
        mixed $code,
        bool $switch = false,
        bool $confirmDissolve = false,
        ?DateTimeImmutable $now = null
    ): Household {
        $now ??= new DateTimeImmutable();
        $code = InviteCode::normalize(is_string($code) ? $code : '');

        return $this->Store->transactional(function () use ($userId, $code, $switch, $confirmDissolve, $now): Household {
            $Household = $code === '' ? null : $this->Store->findByInviteCode($code);

            if ($Household === null || $Household->activeInviteCode($now) === null) {
                throw new HouseholdException('invalid_code', 'The invite code is unknown or expired.', 404);
            }

            $Membership = $this->Store->membershipOf($userId);

            if ($Membership !== null) {
                if ($Membership->householdId === $Household->id) {
                    throw new HouseholdException('same_household', 'Already a member of this household.', 409);
                }

                if (!$switch) {
                    throw self::alreadyMember();
                }

                $this->leaveHousehold($Membership, $confirmDissolve, $now);
            }

            $this->Store->addMember(new Membership($userId, $Household->id, HouseholdRole::Member, $now));

            return $Household;
        });
    }

    /**
     * Neuer Einladungscode, 7 Tage gültig; der alte verfällt.
     *
     * @throws HouseholdException
     */
    public function renewInvite(string $userId, ?DateTimeImmutable $now = null): Household
    {
        $now ??= new DateTimeImmutable();

        return $this->Store->transactional(function () use ($userId, $now): Household {
            $Household = $this->adminHousehold($userId);
            $this->renewCode($Household, $now);
            $this->Store->updateInvite($Household);

            return $Household;
        });
    }

    /**
     * Rolle eines anderen Mitglieds ändern (ernennen oder zurückstufen) oder
     * die eigene abgeben; ein Administrator bleibt immer.
     *
     * @throws HouseholdException
     */
    public function setRole(string $userId, string $memberId, mixed $role): void
    {
        $Role = is_string($role) ? HouseholdRole::tryFrom($role) : null;

        if ($Role === null) {
            throw new HouseholdException('invalid_request', 'Role must be admin or member.', 400);
        }

        $this->Store->transactional(function () use ($userId, $memberId, $Role): void {
            $Household = $this->adminHousehold($userId);
            $Target = $this->memberOf($Household, $memberId);

            if ($Target->role === $Role) {
                return;
            }

            if ($Role === HouseholdRole::Member && $this->adminCount($Household->id) <= 1) {
                throw self::lastAdmin();
            }

            $this->Store->setRole($memberId, $Role);
        });
    }

    /**
     * Ein anderes Mitglied entfernen (auch einen Administrator); sich selbst
     * entfernt man mit `leave()`. Die Listen bleiben im Haushalt.
     *
     * @throws HouseholdException
     */
    public function removeMember(string $userId, string $memberId): void
    {
        if ($userId === $memberId) {
            throw new HouseholdException('invalid_request', 'Use leave to remove yourself.', 400);
        }

        $this->Store->transactional(function () use ($userId, $memberId): void {
            $Household = $this->adminHousehold($userId);
            $this->memberOf($Household, $memberId);
            $this->Store->removeMember($memberId);
        });
    }

    /**
     * Austritt. Die Listen bleiben im Haushalt. Der letzte Administrator
     * neben anderen Mitgliedern muss erst übergeben (`last_admin`); das
     * letzte Mitglied löst den Haushalt mit `$confirmDissolve` auf.
     *
     * @return bool true, wenn der Haushalt dabei aufgelöst wurde
     * @throws HouseholdException
     */
    public function leave(string $userId, bool $confirmDissolve = false, ?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();

        return $this->Store->transactional(function () use ($userId, $confirmDissolve, $now): bool {
            $Membership = $this->Store->membershipOf($userId);

            if ($Membership === null) {
                throw self::notMember();
            }

            return $this->leaveHousehold($Membership, $confirmDissolve, $now);
        });
    }

    /**
     * Auflösen (nur Administratoren): Haushalt, Mitgliedschaften und seine
     * Listen werden gelöscht.
     *
     * @throws HouseholdException
     */
    public function dissolve(string $userId, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();

        $this->Store->transactional(function () use ($userId, $now): void {
            $Household = $this->adminHousehold($userId);
            $this->Store->delete($Household->id, $userId, $now);
        });
    }

    /**
     * @return bool true, wenn der Haushalt aufgelöst wurde
     * @throws HouseholdException
     */
    private function leaveHousehold(Membership $Membership, bool $confirmDissolve, DateTimeImmutable $now): bool
    {
        // sperrt den Haushalt: zwei gleichzeitig gehende Administratoren
        $this->Store->find($Membership->householdId, true);
        $members = $this->Store->members($Membership->householdId);

        if (count($members) <= 1) {
            if (!$confirmDissolve) {
                throw new HouseholdException(
                    'confirm_dissolve',
                    'The last member leaving deletes the household and its lists; confirm first.',
                    409
                );
            }

            $this->Store->delete($Membership->householdId, $Membership->userId, $now);

            return true;
        }

        if ($Membership->isAdmin() && $this->adminCount($Membership->householdId) <= 1) {
            throw self::lastAdmin();
        }

        $this->Store->removeMember($Membership->userId);

        return false;
    }

    /**
     * Haushalt, in dem der Benutzer Administrator ist (gesperrt).
     *
     * @throws HouseholdException
     */
    private function adminHousehold(string $userId): Household
    {
        $Membership = $this->Store->membershipOf($userId);

        if ($Membership === null) {
            throw self::notMember();
        }

        if (!$Membership->isAdmin()) {
            throw new HouseholdException('forbidden', 'Only administrators may do this.', 403);
        }

        $Household = $this->Store->find($Membership->householdId, true);

        if ($Household === null) {
            throw self::notMember();
        }

        return $Household;
    }

    /**
     * @throws HouseholdException
     */
    private function memberOf(Household $Household, string $memberId): Membership
    {
        $Target = $this->Store->membershipOf($memberId);

        if ($Target === null || $Target->householdId !== $Household->id) {
            throw new HouseholdException('member_not_found', 'This person is not a member of the household.', 404);
        }

        return $Target;
    }

    private function adminCount(string $householdId): int
    {
        $count = 0;

        foreach ($this->Store->members($householdId) as $Member) {
            if ($Member->isAdmin()) {
                $count++;
            }
        }

        return $count;
    }

    private function renewCode(Household $Household, DateTimeImmutable $now): void
    {
        $Household->inviteCode = ($this->newCode)();
        $Household->inviteExpiresAt = $now->modify('+' . InviteCode::VALID_DAYS . ' days');
    }

    /**
     * @throws HouseholdException
     */
    private static function name(mixed $name): string
    {
        $name = is_string($name) ? trim(preg_replace('/\s+/u', ' ', $name) ?? '') : '';

        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new HouseholdException('invalid_name', 'The name must have 1 to 100 characters.', 400);
        }

        return $name;
    }

    private static function alreadyMember(): HouseholdException
    {
        return new HouseholdException('already_member', 'Already a member of another household.', 409);
    }

    private static function notMember(): HouseholdException
    {
        return new HouseholdException('not_member', 'Not a member of a household.', 404);
    }

    private static function lastAdmin(): HouseholdException
    {
        return new HouseholdException(
            'last_admin',
            'The last administrator must appoint another administrator or dissolve the household.',
            409
        );
    }
}
