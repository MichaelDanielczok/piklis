<?php

namespace MichaelDanielczok\Piklis\Tests\Unit\Images\Fixtures;

use MichaelDanielczok\Piklis\Images\ImageMedia;
use RuntimeException;

use function array_key_exists;
use function sprintf;

/**
 * Bildablage im Arbeitsspeicher wie `QuiqqerImageMedia`: Zuschnitte im
 * Ordner „Produktbilder“, Originale in „Produktbilder/Originale“, fortlaufende
 * Namen statt UUIDs. `failOnStore` simuliert eine volle Platte.
 */
final class InMemoryImageMedia implements ImageMedia
{
    /** @var array<int, array{file: string, content: string}> */
    public array $media = [];

    /** @var list<int> */
    public array $deleted = [];

    public bool $failOnStore = false;

    private int $nextId = 100;

    public function store(string $jpeg, string $kind): int
    {
        if ($this->failOnStore) {
            throw new RuntimeException('disk full');
        }

        $id = $this->nextId++;
        $folder = $kind === self::KIND_ORIGINAL ? 'Produktbilder/Originale/' : 'Produktbilder/';

        $this->media[$id] = [
            'file' => $folder . sprintf('bild-%d.jpg', $id),
            'content' => $jpeg
        ];

        return $id;
    }

    public function delete(int $id): void
    {
        if (array_key_exists($id, $this->media)) {
            unset($this->media[$id]);
            $this->deleted[] = $id;
        }
    }

    public function files(array $ids): array
    {
        $files = [];

        foreach ($ids as $id) {
            if (array_key_exists($id, $this->media)) {
                $files[$id] = $this->media[$id]['file'];
            }
        }

        return $files;
    }

    public function cacheUrl(): string
    {
        return '/media/cache/piklis/';
    }
}
