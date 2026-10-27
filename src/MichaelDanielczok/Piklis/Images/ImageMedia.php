<?php

namespace MichaelDanielczok\Piklis\Images;

/**
 * Ablage der Produktbilder. In der App die QUIQQER-Medien
 * (`QuiqqerImageMedia`), in Tests ein Speicher im Arbeitsspeicher.
 */
interface ImageMedia
{
    /** Quadratischer Zuschnitt, Ordner „Produktbilder“ */
    public const KIND_CROP = 'crop';

    /** Verkleinertes Original zum neuen Zuschneiden, Unterordner „Originale“ */
    public const KIND_ORIGINAL = 'original';

    /**
     * Legt ein JPEG mit zufälligem Dateinamen ab.
     *
     * @param self::KIND_* $kind
     * @return int ID des Mediums
     */
    public function store(string $jpeg, string $kind): int;

    /**
     * Löscht ein Medium ganz (mit seinen Cache-Dateien). Unbekannte IDs und
     * Medien außerhalb der Produktbilder bleiben unberührt.
     */
    public function delete(int $id): void;

    /**
     * Dateipfade unterhalb des Medienbereichs, z. B.
     * `Produktbilder/0f3c….jpg`. Fehlende oder inaktive Medien fehlen.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public function files(array $ids): array;

    /**
     * Adresse des Media-Caches des Projekts, z. B. `/media/cache/piklis/`.
     */
    public function cacheUrl(): string;
}
