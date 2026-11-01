<?php

namespace MichaelDanielczok\Piklis\Templates;

/**
 * Speicher für Vorlagen. In der App `TemplateRepository` (Datenbank), in
 * Tests ein Speicher im Arbeitsspeicher.
 */
interface TemplateStore
{
    /**
     * Führt `$callback` in einer Transaktion aus.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transactional(callable $callback): mixed;

    /**
     * Vorlage mit Einträgen, auch gelöschte (Grabstein). `$forUpdate` sperrt
     * die Zeile bis zum Ende der Transaktion (gleichzeitiges Speichern).
     */
    public function load(string $templateId, bool $forUpdate = false): ?TemplateState;

    /**
     * Haushalt des Benutzers, null ohne.
     */
    public function householdIdOf(string $userId): ?string;

    /**
     * Welche der Produkte gibt es nicht im Katalog?
     *
     * @param list<string> $productIds
     * @return list<string>
     */
    public function missingProducts(array $productIds): array;

    /**
     * Neue Vorlage samt Einträgen schreiben.
     */
    public function insert(TemplateState $Template): void;

    /**
     * Kopf und alle Einträge ersetzen (Einträge, die fehlen, verschwinden).
     */
    public function update(TemplateState $Template): void;
}
