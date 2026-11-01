<?php

namespace MichaelDanielczok\Piklis\Templates;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Lists\SortKey;
use MichaelDanielczok\Piklis\Support\Uuid;
use MichaelDanielczok\Piklis\Sync\ListRole;

use function array_column;
use function array_map;
use function count;

/**
 * Regeln der Vorlagen (Konzept „Vorlagen“), ohne HTTP und ohne Datenbank:
 *
 * - Persönlich (Besitzer) oder für den Haushalt (alle Mitglieder sehen,
 *   verwenden und bearbeiten sie). Keine Freigabe an Außenstehende: Gäste
 *   einer Liste sehen keine Vorlagen.
 * - Nur online und immer ganz gespeichert: Name und alle Produkte mit
 *   Menge, Reihenfolge und Notiz. Wer speichert, nennt die Revision, die er
 *   bearbeitet hat (`If-Match`); hat inzwischen jemand anderes gespeichert,
 *   lehnt der Server mit `template_changed` ab und schickt den aktuellen
 *   Stand mit. Nichts wird still überschrieben.
 * - Die Reihenfolge kommt aus der Reihenfolge der gesendeten Produkte; der
 *   Server vergibt frische Sortierschlüssel.
 * - Löschen hinterlässt einen Grabstein (`template_deleted`).
 * - Persönliche Vorlagen lassen sich in den Haushalt verschieben (Besitzer)
 *   oder kopieren; Haushaltsvorlagen als persönliche Kopie mitnehmen
 *   (Mitglieder, z. B. vor dem Austritt).
 *
 * Listen aus einer Vorlage legt die App selbst an (Operationen wie beim
 * früheren Einkauf); eine Liste ändert die Vorlage nie.
 */
final class TemplateService
{
    public const TO_HOUSEHOLD = 'household';
    public const TO_PERSONAL = 'personal';

    public function __construct(private readonly TemplateStore $Store)
    {
    }

    /**
     * Rolle an einer Vorlage: Besitzer einer persönlichen, Mitglied des
     * Haushalts einer Haushaltsvorlage, sonst keine.
     */
    public static function roleOf(TemplateState $Template, string $userId, ?string $userHouseholdId): ?ListRole
    {
        if ($Template->ownerUser !== null && $Template->ownerUser === $userId) {
            return ListRole::Owner;
        }

        if (
            $Template->householdId !== null
            && $userHouseholdId !== null
            && $Template->householdId === $userHouseholdId
        ) {
            return ListRole::Member;
        }

        return null;
    }

    /**
     * @throws TemplateException
     */
    public function get(string $templateId, string $userId): TemplateState
    {
        return $this->accessible($templateId, $userId, false);
    }

    /**
     * Neue Vorlage mit der ID aus dem Browser. `$householdId` null =
     * persönlich, sonst muss es der eigene Haushalt sein.
     *
     * @param array<array-key, mixed> $input `id`, `name`, `householdId`, `entries`
     * @return array{status: string, template: TemplateState} `created` oder
     *         `unchanged` (dieselbe ID noch einmal vom selben Benutzer)
     * @throws TemplateException
     */
    public function create(array $input, string $userId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $templateId = $input['id'] ?? null;

        if (!Uuid::isValid($templateId)) {
            throw new TemplateException('invalid_request', 'The template needs a UUID.', 400, 'id');
        }

        /** @var string $templateId */
        $name = TemplateInput::name($input['name'] ?? null);
        $entries = TemplateInput::entries($input['entries'] ?? []);
        $householdId = $input['householdId'] ?? null;

        return $this->Store->transactional(
            function () use ($templateId, $name, $entries, $householdId, $userId, $now): array {
                $Existing = $this->Store->load($templateId, true);

                if ($Existing !== null) {
                    if ($Existing->createdBy === $userId && !$Existing->isDeleted()) {
                        return ['status' => 'unchanged', 'template' => $Existing];
                    }

                    throw new TemplateException('template_exists', 'A template with this id already exists.', 409);
                }

                if ($householdId !== null && $householdId !== $this->Store->householdIdOf($userId)) {
                    throw new TemplateException('forbidden', 'Only for the own household.', 403, 'householdId');
                }

                /** @var string|null $householdId */
                $Template = TemplateState::create($templateId, $name, $householdId, $userId, $now);
                $this->assertProductsExist($entries);
                $Template->setEntries(self::entriesOf($entries, []));

                $this->Store->insert($Template);

                return ['status' => 'created', 'template' => $Template];
            }
        );
    }

    /**
     * Ganz speichern: Name und alle Produkte. `$expectedRevision` ist die
     * Revision, die bearbeitet wurde (`If-Match`); null heißt „nicht
     * angegeben“ und wird abgelehnt.
     *
     * @param array<array-key, mixed> $input `name`, `entries`
     * @throws TemplateException
     */
    public function save(
        string $templateId,
        array $input,
        ?int $expectedRevision,
        string $userId,
        ?DateTimeImmutable $now = null
    ): TemplateState {
        $now ??= new DateTimeImmutable();

        if ($expectedRevision === null) {
            throw new TemplateException(
                'precondition_required',
                'Send the edited revision in If-Match.',
                428
            );
        }

        $name = TemplateInput::name($input['name'] ?? null);
        $entries = TemplateInput::entries($input['entries'] ?? null);

        return $this->Store->transactional(
            function () use ($templateId, $name, $entries, $expectedRevision, $userId, $now): TemplateState {
                $Template = $this->accessible($templateId, $userId, true);

                if ($Template->revision !== $expectedRevision) {
                    throw new TemplateException(
                        'template_changed',
                        'The template was changed in the meantime.',
                        409,
                        null,
                        $Template
                    );
                }

                $this->assertProductsExist($entries);

                $Template->name = $name;
                $Template->setEntries(self::entriesOf($entries, $Template->entryIdsByProduct()));
                $Template->revision++;
                $Template->updatedAt = $now;
                $Template->updatedBy = $userId;

                $this->Store->update($Template);

                return $Template;
            }
        );
    }

    /**
     * Löschen (Besitzer, Mitglieder): Grabstein, Einträge bleiben.
     *
     * @throws TemplateException
     */
    public function delete(string $templateId, string $userId, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();

        $this->Store->transactional(function () use ($templateId, $userId, $now): void {
            $Template = $this->accessible($templateId, $userId, true);
            $Template->deletedAt = $now;
            $Template->deletedBy = $userId;
            $Template->revision++;

            $this->Store->update($Template);
        });
    }

    /**
     * Persönliche Vorlage in den Haushalt verschieben (nur der Besitzer); die
     * ID bleibt.
     *
     * @throws TemplateException
     */
    public function moveToHousehold(string $templateId, string $userId, ?DateTimeImmutable $now = null): TemplateState
    {
        $now ??= new DateTimeImmutable();

        return $this->Store->transactional(function () use ($templateId, $userId, $now): TemplateState {
            $Template = $this->accessible($templateId, $userId, true);

            if ($Template->ownerUser !== $userId) {
                throw new TemplateException(
                    'forbidden',
                    'Only the owner of a personal template may move it.',
                    403
                );
            }

            $householdId = $this->Store->householdIdOf($userId);

            if ($householdId === null) {
                throw self::noHousehold();
            }

            $Template->householdId = $householdId;
            $Template->ownerUser = null;
            $Template->revision++;
            $Template->updatedAt = $now;
            $Template->updatedBy = $userId;

            $this->Store->update($Template);

            return $Template;
        });
    }

    /**
     * Kopie mit der ID aus dem Browser (idempotent): persönliche Vorlagen in
     * den Haushalt (Besitzer), Haushaltsvorlagen als persönliche Kopie
     * (Mitglieder). Name, Produkte, Mengen, Notizen und Reihenfolge.
     *
     * @return array{status: string, template: TemplateState}
     * @throws TemplateException
     */
    public function copy(
        string $templateId,
        mixed $newTemplateId,
        mixed $to,
        string $userId,
        ?DateTimeImmutable $now = null
    ): array {
        $now ??= new DateTimeImmutable();

        if (!Uuid::isValid($newTemplateId)) {
            throw new TemplateException('invalid_request', 'The new template needs a UUID.', 400, 'id');
        }

        if ($to !== self::TO_HOUSEHOLD && $to !== self::TO_PERSONAL) {
            throw new TemplateException('invalid_request', 'Copy to "household" or "personal".', 400, 'to');
        }

        /** @var string $newTemplateId */
        return $this->Store->transactional(
            function () use ($templateId, $newTemplateId, $to, $userId, $now): array {
                $Existing = $this->Store->load($newTemplateId, true);

                if ($Existing !== null) {
                    if ($Existing->createdBy === $userId && !$Existing->isDeleted()) {
                        return ['status' => 'unchanged', 'template' => $Existing];
                    }

                    throw new TemplateException('template_exists', 'A template with this id already exists.', 409);
                }

                $Source = $this->accessible($templateId, $userId, false);
                $householdId = null;

                if ($to === self::TO_HOUSEHOLD) {
                    if ($Source->ownerUser !== $userId) {
                        throw new TemplateException(
                            'invalid_request',
                            'Only personal templates can be copied into the household.',
                            400,
                            'to'
                        );
                    }

                    $householdId = $this->Store->householdIdOf($userId);

                    if ($householdId === null) {
                        throw self::noHousehold();
                    }
                } elseif ($Source->householdId === null) {
                    throw new TemplateException(
                        'invalid_request',
                        'Only household templates can be copied as a personal template.',
                        400,
                        'to'
                    );
                }

                $Copy = TemplateState::create($newTemplateId, $Source->name, $householdId, $userId, $now);
                $Copy->setEntries(array_map(
                    static fn(TemplateEntry $Entry): TemplateEntry => new TemplateEntry(
                        Uuid::random(),
                        $Entry->productId,
                        $Entry->quantity,
                        $Entry->note,
                        $Entry->sortKey
                    ),
                    $Source->entries()
                ));

                $this->Store->insert($Copy);

                return ['status' => 'created', 'template' => $Copy];
            }
        );
    }

    /**
     * Zugängliche, nicht gelöschte Vorlage.
     *
     * @throws TemplateException
     */
    private function accessible(string $templateId, string $userId, bool $forUpdate): TemplateState
    {
        $Template = $this->Store->load($templateId, $forUpdate);

        if ($Template === null) {
            throw new TemplateException('template_not_found', 'The template does not exist.', 404);
        }

        $householdId = $Template->householdId === null ? null : $this->Store->householdIdOf($userId);

        if (self::roleOf($Template, $userId, $householdId) === null) {
            throw new TemplateException('no_access', 'No access to this template.', 403);
        }

        if ($Template->isDeleted()) {
            throw new TemplateException('template_deleted', 'The template has been deleted.', 410);
        }

        return $Template;
    }

    /**
     * @param list<array{productId: string, quantity: \MichaelDanielczok\Piklis\Lists\Quantity, note: string}> $entries
     * @throws TemplateException
     */
    private function assertProductsExist(array $entries): void
    {
        if (count($entries) === 0) {
            return;
        }

        $missing = $this->Store->missingProducts(array_column($entries, 'productId'));

        if (count($missing) > 0) {
            throw new TemplateException('unknown_product', 'Unknown product.', 400, $missing[0]);
        }
    }

    /**
     * Einträge in der gesendeten Reihenfolge mit frischen Sortierschlüsseln;
     * vorhandene Produkte behalten ihre Eintrags-ID.
     *
     * @param list<array{productId: string, quantity: \MichaelDanielczok\Piklis\Lists\Quantity, note: string}> $entries
     * @param array<string, string> $idsByProduct
     * @return list<TemplateEntry>
     */
    private static function entriesOf(array $entries, array $idsByProduct): array
    {
        $keys = SortKey::manyBetween(null, null, count($entries));
        $result = [];

        foreach ($entries as $index => $entry) {
            $result[] = new TemplateEntry(
                $idsByProduct[$entry['productId']] ?? Uuid::random(),
                $entry['productId'],
                $entry['quantity'],
                $entry['note'],
                $keys[$index]
            );
        }

        return $result;
    }

    private static function noHousehold(): TemplateException
    {
        return new TemplateException('no_household', 'Not a member of a household.', 409);
    }
}
