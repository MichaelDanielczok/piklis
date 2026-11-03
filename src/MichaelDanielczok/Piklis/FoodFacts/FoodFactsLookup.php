<?php

namespace MichaelDanielczok\Piklis\FoodFacts;

use DateInterval;
use DateTimeImmutable;

/**
 * Barcode bei Open Food Facts nachschlagen, mit Cache (`FoodFactsCache`).
 *
 * - Treffer gelten 7 Tage, „nicht gefunden“ 1 Tag; danach wird neu gefragt.
 * - „Neu abrufen“ (`$refresh`) fragt sofort neu, außer der letzte Abruf ist
 *   jünger als 10 Minuten (doppeltes Tippen kostet keinen Abruf).
 * - Bremse: höchstens 10 Abrufe je Minute für alle Benutzer zusammen
 *   (Open Food Facts erlaubt 15 je IP). Darüber hilft ein älterer Stand aus
 *   dem Cache, sonst `off_busy`.
 * - Ist Open Food Facts nicht erreichbar, hilft ebenfalls ein älterer Stand,
 *   außer bei „Neu abrufen“ (dort soll der Fehler sichtbar sein).
 */
final class FoodFactsLookup
{
    public const FOUND_TTL = 'P7D';
    public const NOT_FOUND_TTL = 'P1D';
    public const REFRESH_MIN_AGE = 'PT10M';
    public const MAX_FETCHES_PER_MINUTE = 10;

    public function __construct(
        private readonly FoodFactsCache $Cache,
        private readonly OpenFoodFactsClient $Client
    ) {
    }

    /**
     * @throws InvalidBarcodeException
     * @throws FoodFactsException `off_not_found`, `off_busy`, `off_unavailable`
     */
    public function lookup(mixed $barcode, bool $refresh = false, ?DateTimeImmutable $now = null): FoodFactsProduct
    {
        $barcode = Barcode::normalize($barcode);
        $now = $now ?? new DateTimeImmutable();
        $Entry = $this->Cache->find($barcode);

        if ($Entry !== null && !$this->needsFetch($Entry, $refresh, $now)) {
            return self::fromEntry($Entry);
        }

        if ($this->Cache->countFetchedSince($now->sub(new DateInterval('PT1M'))) >= self::MAX_FETCHES_PER_MINUTE) {
            if ($Entry !== null) {
                return self::fromEntry($Entry);
            }

            throw FoodFactsException::busy();
        }

        try {
            $product = $this->Client->fetch($barcode);
        } catch (FoodFactsException $Exception) {
            if ($Entry !== null && !$refresh) {
                return self::fromEntry($Entry);
            }

            throw $Exception;
        }

        $Entry = new CacheEntry($barcode, $product, $now);
        $this->Cache->save($Entry);

        return self::fromEntry($Entry);
    }

    /**
     * Vorderseitenbild des Treffers aus dem Cache (oder neu nachgeschlagen).
     * `$full`: volle Größe zum Übernehmen, sonst 400 px zur Vorschau.
     *
     * @return array{body: string, contentType: string}
     * @throws InvalidBarcodeException
     * @throws FoodFactsException
     */
    public function image(mixed $barcode, bool $full, ?DateTimeImmutable $now = null): array
    {
        $Product = $this->lookup($barcode, false, $now);
        $url = $full ? $Product->imageFullUrl() : $Product->imageUrl;

        if ($url === null) {
            throw FoodFactsException::noImage();
        }

        return $this->Client->fetchImage($url);
    }

    private function needsFetch(CacheEntry $Entry, bool $refresh, DateTimeImmutable $now): bool
    {
        if ($refresh) {
            return $Entry->fetchedAt->add(new DateInterval(self::REFRESH_MIN_AGE)) <= $now;
        }

        $ttl = new DateInterval($Entry->found() ? self::FOUND_TTL : self::NOT_FOUND_TTL);

        return $Entry->fetchedAt->add($ttl) <= $now;
    }

    /**
     * @throws FoodFactsException `off_not_found`
     */
    private static function fromEntry(CacheEntry $Entry): FoodFactsProduct
    {
        if ($Entry->product === null) {
            throw FoodFactsException::notFound();
        }

        return FoodFactsProduct::fromApi($Entry->product, $Entry->barcode, $Entry->fetchedAt);
    }
}
