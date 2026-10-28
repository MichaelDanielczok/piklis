<?php

namespace MichaelDanielczok\Piklis\Setup;

use DateTimeImmutable;
use MichaelDanielczok\Piklis\Products\InvalidProductException;
use MichaelDanielczok\Piklis\Products\Product;
use MichaelDanielczok\Piklis\Products\ProductInput;
use MichaelDanielczok\Piklis\Products\ProductStore;
use MichaelDanielczok\Piklis\Products\SearchText;
use RuntimeException;

use function count;
use function dirname;
use function file_get_contents;
use function is_array;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Grundkatalog (`data/catalog.json`): rund 150 übliche Produkte mit Kategorie
 * und Ersatzicon, einmalig beim Paket-Setup angelegt (Obsidian „Piklis
 * Konzept“ › „Schnelles Anlegen und vorhandene Produkte“).
 *
 * - **Einmalig:** Gibt es schon ein Produkt des Grundkatalogs (auch
 *   ausgeblendet), legt das Setup nichts mehr an. Produkte werden nie
 *   gelöscht, nur ausgeblendet; was Produktpfleger ausblenden oder umbenennen,
 *   kommt also nicht wieder.
 * - **Nie überschreiben:** Ein Eintrag, dessen ID oder Name (normalisiert wie
 *   die Suche) schon im Katalog steht, wird übersprungen; vorhandene Produkte
 *   bleiben unverändert, es entsteht kein zweites „Milch“.
 * - **Ohne Ersteller:** `created_by` ist `CREATOR`, keine Benutzer-ID. Damit
 *   bearbeiten nur Produktpfleger diese Produkte (`ProductPermissions`), und
 *   sie stehen bei niemandem unter „Von mir“.
 */
final class CatalogSeeder
{
    /** `created_by` der Produkte des Grundkatalogs, nie eine Benutzer-ID */
    public const CREATOR = 'catalog';

    public function __construct(private readonly ProductStore $Store)
    {
    }

    /**
     * Einträge aus `data/catalog.json` des Pakets.
     *
     * @return list<array<array-key, mixed>>
     */
    public static function catalog(?string $file = null): array
    {
        $file ??= dirname(__DIR__, 4) . '/data/catalog.json';
        $json = file_get_contents($file);

        if ($json === false) {
            throw new RuntimeException('Catalog file not readable: ' . $file);
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $products = is_array($data) && is_array($data['products'] ?? null) ? $data['products'] : [];
        $items = [];

        foreach ($products as $product) {
            if (is_array($product)) {
                $items[] = $product;
            }
        }

        return $items;
    }

    /**
     * Legt die Einträge an, die es noch nicht gibt, aber nur, solange noch
     * kein Produkt des Grundkatalogs existiert.
     *
     * @param list<array<array-key, mixed>> $items je `id`, `name`, `category`, `icon`
     * @return array{seeded: bool, created: int, skipped: int}
     * @throws InvalidProductException bei einem ungültigen Eintrag (vor dem ersten Schreiben)
     */
    public function seed(array $items, ?DateTimeImmutable $now = null): array
    {
        $existing = $this->Store->all();

        foreach ($existing as $Product) {
            if ($Product->createdBy === self::CREATOR) {
                return ['seeded' => false, 'created' => 0, 'skipped' => 0];
            }
        }

        $ids = [];
        $names = [];

        foreach ($existing as $Product) {
            $ids[$Product->id] = true;
            $names[$Product->searchName()] = true;
        }

        $now ??= new DateTimeImmutable();
        $products = [];
        $skipped = 0;

        // erst alles prüfen, dann schreiben: ein fehlerhafter Datensatz legt nichts halb an
        foreach ($items as $item) {
            $id = is_string($item['id'] ?? null) ? $item['id'] : '';

            if ($id === '') {
                throw new InvalidProductException('id', 'required');
            }

            $Input = ProductInput::forCreate([
                'name' => $item['name'] ?? null,
                'category' => $item['category'] ?? null,
                'icon' => $item['icon'] ?? null
            ]);
            $searchName = SearchText::normalize((string)$Input->name);

            if (isset($ids[$id]) || isset($names[$searchName])) {
                $skipped++;
                continue;
            }

            $ids[$id] = true;
            $names[$searchName] = true;
            $products[] = Product::create($id, $Input, self::CREATOR, $now);
        }

        foreach ($products as $Product) {
            $this->Store->insert($Product);
        }

        return ['seeded' => true, 'created' => count($products), 'skipped' => $skipped];
    }
}
