<?php

namespace MichaelDanielczok\Piklis\Products;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Images\ImageMedia;

/**
 * Aufräumen der Listenartikel (täglicher Cron, Konzept „Listenartikel“):
 *
 * - Das Bild verschwindet 90 Tage nach dem Abschluss der letzten Liste, die
 *   den Artikel nutzt, außer eine Vorlage nutzt ihn. Name und Ersatzicon
 *   bleiben im Archiv sichtbar.
 * - Die Zeile verschwindet samt Bild, sobald weder Liste noch Vorlage den
 *   Artikel nutzen. Ein Tag Wartezeit seit der letzten Änderung schützt
 *   Artikel, deren `entry.add` gerade erst unterwegs ist.
 *
 * Erst das Produkt, dann die Medien, wie `ProductImageService`: Scheitert
 * danach etwas, bleibt höchstens ein Medium übrig, nie ein Verweis ins Leere.
 */
final class ListItemCleanup
{
    public const IMAGE_DAYS = 90;

    public const UNUSED_DAYS = 1;

    /** `updated_by` beim Löschen des Bildes, nie eine Benutzer-ID */
    public const USER = 'cleanup';

    public function __construct(
        private readonly ProductStore $Store,
        private readonly ImageMedia $Media
    ) {
    }

    /**
     * @return array{images: int, rows: int}
     */
    public function run(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $imageLimit = $now->modify('-' . self::IMAGE_DAYS . ' days');
        $unusedLimit = $now->modify('-' . self::UNUSED_DAYS . ' days');
        $images = 0;
        $rows = 0;

        foreach ($this->Store->listItemUsages() as $Usage) {
            $Product = $Usage->Product;

            if ($Usage->isUnused()) {
                if ($Product->updatedAt <= $unusedLimit) {
                    $this->deleteRow($Product);
                    $rows++;
                }

                continue;
            }

            if ($Usage->isImageExpired($imageLimit)) {
                $this->deleteImage($Product, $now);
                $images++;
            }
        }

        return ['images' => $images, 'rows' => $rows];
    }

    private function deleteRow(Product $Product): void
    {
        $this->Store->delete($Product->id);
        $this->deleteMedia($Product->imageId, $Product->imageOriginalId);
    }

    private function deleteImage(Product $Product, DateTimeImmutable $now): void
    {
        $imageId = $Product->imageId;
        $originalId = $Product->imageOriginalId;

        $Product->setImage(null, null, self::USER, $now);
        $this->Store->update($Product);
        $this->deleteMedia($imageId, $originalId);
    }

    private function deleteMedia(?int ...$ids): void
    {
        foreach ($ids as $id) {
            if ($id !== null) {
                $this->Media->delete($id);
            }
        }
    }
}
