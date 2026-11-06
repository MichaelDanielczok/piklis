<?php

namespace MichaelDanielczok\Piklis\Products;

use DateTimeImmutable;

/**
 * Wie ein Listenartikel gerade genutzt wird. Es zählen sichtbare Einträge
 * nicht gelöschter Listen und Einträge nicht gelöschter Vorlagen. Ein
 * entfernter Eintrag bleibt als Zeile stehen, nutzt den Artikel aber nicht
 * mehr; ebenso alles auf gelöschten Listen.
 */
final class ListItemUsage
{
    public function __construct(
        public readonly Product $Product,
        public readonly int $activeLists,
        public readonly int $completedLists,
        public readonly ?DateTimeImmutable $lastCompletedAt,
        public readonly bool $inTemplate
    ) {
    }

    public function isUnused(): bool
    {
        return $this->activeLists === 0 && $this->completedLists === 0 && !$this->inTemplate;
    }

    /**
     * Darf das Bild weg? Nur, wenn alle nutzenden Listen abgeschlossen sind,
     * die letzte spätestens zu `$limit`, und keine Vorlage es braucht.
     */
    public function isImageExpired(DateTimeImmutable $limit): bool
    {
        return $this->Product->imageId !== null
            && $this->activeLists === 0
            && !$this->inTemplate
            && $this->lastCompletedAt !== null
            && $this->lastCompletedAt <= $limit;
    }
}
