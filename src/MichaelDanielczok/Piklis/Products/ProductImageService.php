<?php

namespace MichaelDanielczok\Piklis\Products;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Images\ImageLimits;
use MichaelDanielczok\Piklis\Images\ImageMedia;
use MichaelDanielczok\Piklis\Images\InvalidImageException;
use MichaelDanielczok\Piklis\Images\UploadedImage;
use Throwable;

/**
 * Produktbild setzen, ersetzen, neu zuschneiden und entfernen, mit den
 * Rechten der Produktdaten (`ProductPermissions::canChangeImage`).
 *
 * - Setzen/Ersetzen: Zuschnitt und Original kommen zusammen; das alte Bild
 *   und das alte Original werden gelöscht.
 * - Neu zuschneiden: nur ein neuer Zuschnitt; das Original bleibt.
 * - Entfernen: beide Medien werden gelöscht, die App zeigt wieder das
 *   Ersatzicon.
 *
 * Reihenfolge: erst prüfen, dann die neuen Medien ablegen, dann das Produkt
 * speichern, zuletzt die alten Medien löschen. Scheitert das Speichern,
 * werden die neuen Medien wieder gelöscht.
 */
final class ProductImageService
{
    public function __construct(
        private readonly ProductStore $Store,
        private readonly ImageMedia $Media,
        private readonly ImageLimits $Limits
    ) {
    }

    /**
     * @param string $crop JPEG, quadratisch mit `ImageLimits::CROP_SIZE` px
     * @param string|null $original JPEG, verkleinertes Original; null behält das bisherige (neu zuschneiden)
     * @throws InvalidImageException
     * @throws ProductException
     */
    public function setImage(
        string $productId,
        string $crop,
        ?string $original,
        string $userId,
        bool $isManager,
        ?DateTimeImmutable $now = null
    ): Product {
        $Product = $this->loadEditable($productId, $userId, $isManager);

        UploadedImage::checkCrop($crop, $this->Limits);

        if ($original !== null) {
            UploadedImage::checkOriginal($original, $this->Limits);
        }

        $oldImageId = $Product->imageId;
        $oldOriginalId = $Product->imageOriginalId;
        $originalId = $oldOriginalId;
        $stored = [];

        try {
            $imageId = $this->Media->store($crop, ImageMedia::KIND_CROP);
            $stored[] = $imageId;

            if ($original !== null) {
                $originalId = $this->Media->store($original, ImageMedia::KIND_ORIGINAL);
                $stored[] = $originalId;
            }

            $Product->setImage($imageId, $originalId, $userId, $now ?? new DateTimeImmutable());
            $this->Store->update($Product);
        } catch (Throwable $Exception) {
            foreach ($stored as $id) {
                $this->Media->delete($id);
            }

            throw $Exception;
        }

        if ($oldImageId !== null) {
            $this->Media->delete($oldImageId);
        }

        if ($oldOriginalId !== null && $oldOriginalId !== $originalId) {
            $this->Media->delete($oldOriginalId);
        }

        return $Product;
    }

    /**
     * @throws ProductException
     */
    public function removeImage(
        string $productId,
        string $userId,
        bool $isManager,
        ?DateTimeImmutable $now = null
    ): Product {
        $Product = $this->loadEditable($productId, $userId, $isManager);

        $oldImageId = $Product->imageId;
        $oldOriginalId = $Product->imageOriginalId;

        if (!$Product->setImage(null, null, $userId, $now ?? new DateTimeImmutable())) {
            return $Product;
        }

        $this->Store->update($Product);

        if ($oldImageId !== null) {
            $this->Media->delete($oldImageId);
        }

        if ($oldOriginalId !== null) {
            $this->Media->delete($oldOriginalId);
        }

        return $Product;
    }

    /**
     * @throws ProductException
     */
    private function loadEditable(string $productId, string $userId, bool $isManager): Product
    {
        $Product = (new ProductService($this->Store))->load($productId);

        if (!ProductPermissions::canChangeImage($Product, $userId, $isManager)) {
            throw ProductException::forbidden('Only the creator or a product maintainer may change this image.');
        }

        return $Product;
    }
}
