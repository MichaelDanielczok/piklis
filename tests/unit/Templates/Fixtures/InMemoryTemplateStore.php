<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Templates\Fixtures;

use MichaelDanielczok\Piklis\Templates\TemplateState;
use MichaelDanielczok\Piklis\Templates\TemplateStore;

use function array_values;
use function in_array;

/**
 * Vorlagen im Arbeitsspeicher für Tests ohne Datenbank. Gespeichert wird
 * eine Kopie, damit Tests sehen, was wirklich geschrieben wurde.
 */
final class InMemoryTemplateStore implements TemplateStore
{
    /** @var array<string, TemplateState> */
    public array $templates = [];

    /** @var array<string, list<string>> Haushalt => Mitglieder */
    public array $householdMembers = [];

    /** @var list<string> bekannte Produkte */
    public array $products = [];

    public int $writes = 0;

    public function transactional(callable $callback): mixed
    {
        return $callback();
    }

    public function load(string $templateId, bool $forUpdate = false): ?TemplateState
    {
        return isset($this->templates[$templateId]) ? clone $this->templates[$templateId] : null;
    }

    public function householdIdOf(string $userId): ?string
    {
        foreach ($this->householdMembers as $householdId => $members) {
            if (in_array($userId, $members, true)) {
                return $householdId;
            }
        }

        return null;
    }

    public function missingProducts(array $productIds): array
    {
        $missing = [];

        foreach ($productIds as $productId) {
            if (!in_array($productId, $this->products, true)) {
                $missing[] = $productId;
            }
        }

        return array_values($missing);
    }

    public function insert(TemplateState $Template): void
    {
        $this->templates[$Template->id] = clone $Template;
        $this->writes++;
    }

    public function update(TemplateState $Template): void
    {
        $this->templates[$Template->id] = clone $Template;
        $this->writes++;
    }
}
