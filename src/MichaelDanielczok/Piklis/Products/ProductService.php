<?php

namespace MichaelDanielczok\Piklis\Products;

use DateTimeImmutable;

use function array_key_exists;
use function is_string;
use function preg_match;
use function strtolower;

/**
 * Anlegen, Bearbeiten, Ausblenden und Suchen im Katalog, mit den Rechten aus
 * `ProductPermissions`. Ohne HTTP: genutzt von `Rest\ProductsEndpoint` und
 * von der Operation `product.create` (`POST /ops`).
 */
final class ProductService
{
    public const CREATED = 'created';
    public const UNCHANGED = 'unchanged';

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private ProductSuggestion $Suggestion;

    public function __construct(private readonly ProductStore $Store, ?ProductSuggestion $Suggestion = null)
    {
        $this->Suggestion = $Suggestion ?? ProductSuggestion::default();
    }

    /**
     * Legt ein Produkt mit der ID aus dem Browser an. Idempotent: Dieselbe ID
     * vom selben Benutzer noch einmal gibt das vorhandene Produkt zurück
     * (`unchanged`, nichts überschrieben), von einem anderen `product_exists`.
     * Ein gleichnamiges Produkt verhindert nichts: ähnliche oder gleiche Namen
     * werden nie zusammengeführt.
     *
     * Fehlen `category` oder `icon` ganz, ergänzt der Vorschlag aus dem Namen
     * (`ProductSuggestion`) sie; ein gesendetes null bleibt null.
     *
     * @param array<array-key, mixed> $input
     * @return array{status: string, product: Product}
     * @throws InvalidProductException
     * @throws ProductException
     */
    public function create(mixed $id, array $input, string $userId, ?DateTimeImmutable $now = null): array
    {
        $id = self::uuid($id);
        $Input = ProductInput::forCreate($this->withSuggestion($input));
        $Existing = $this->Store->find($id);

        if ($Existing !== null) {
            if ($Existing->createdBy !== $userId) {
                throw ProductException::exists();
            }

            return [
                'status' => self::UNCHANGED,
                'product' => $Existing
            ];
        }

        $Product = Product::create($id, $Input, $userId, $now ?? new DateTimeImmutable());
        $this->Store->insert($Product);

        return [
            'status' => self::CREATED,
            'product' => $Product
        ];
    }

    /**
     * Ändert die gesendeten Felder. Eigene Produkte darf jeder bearbeiten,
     * Produktpfleger alle.
     *
     * @param array<array-key, mixed> $input
     * @throws InvalidProductException
     * @throws ProductException
     */
    public function update(
        string $id,
        array $input,
        string $userId,
        bool $isManager,
        ?DateTimeImmutable $now = null
    ): Product {
        $Product = $this->load($id);

        if (!ProductPermissions::canEdit($Product, $userId, $isManager)) {
            throw ProductException::forbidden('Only the creator or a product maintainer may edit this product.');
        }

        $Input = ProductInput::forUpdate($input);

        if ($Product->apply($Input, $userId, $now ?? new DateTimeImmutable())) {
            $this->Store->update($Product);
        }

        return $Product;
    }

    /**
     * Blendet ein Produkt aus Katalog und Vorschlägen aus oder wieder ein.
     * Nur Produktpfleger.
     *
     * @throws ProductException
     */
    public function setHidden(
        string $id,
        bool $hidden,
        string $userId,
        bool $isManager,
        ?DateTimeImmutable $now = null
    ): Product {
        $Product = $this->load($id);

        if (!ProductPermissions::canHide($isManager)) {
            throw ProductException::forbidden('Only product maintainers may hide products.');
        }

        if ($Product->setHidden($hidden, $userId, $now ?? new DateTimeImmutable())) {
            $this->Store->update($Product);
        }

        return $Product;
    }

    /**
     * Vorschläge beim Tippen, ohne ausgeblendete Produkte (außer
     * `$includeHidden`, nur für Produktpfleger gedacht).
     *
     * @return list<Product>
     */
    public function search(string $query, int $limit, bool $includeHidden = false): array
    {
        $tokens = SearchText::tokens($query);

        if ($tokens === []) {
            return [];
        }

        $candidates = $this->Store->findContainingAll($tokens, $includeHidden);

        return ProductSearch::search($query, $candidates, $limit, $includeHidden);
    }

    /**
     * @throws ProductException
     */
    public function load(string $id): Product
    {
        $Product = $this->Store->find(strtolower($id));

        if ($Product === null) {
            throw ProductException::notFound();
        }

        return $Product;
    }

    /**
     * @param array<array-key, mixed> $input
     * @return array<array-key, mixed>
     */
    private function withSuggestion(array $input): array
    {
        $missing = !array_key_exists('category', $input) || !array_key_exists('icon', $input);

        if (!$missing || !is_string($input['name'] ?? null)) {
            return $input;
        }

        $suggestion = $this->Suggestion->suggest($input['name']);

        if ($suggestion === null) {
            return $input;
        }

        foreach ($suggestion as $field => $value) {
            if (!array_key_exists($field, $input)) {
                $input[$field] = $value;
            }
        }

        return $input;
    }

    /**
     * @throws InvalidProductException
     */
    private static function uuid(mixed $id): string
    {
        if (!is_string($id)) {
            throw new InvalidProductException('id', 'required');
        }

        $id = strtolower($id);

        if (preg_match(self::UUID_PATTERN, $id) !== 1) {
            throw new InvalidProductException('id', 'invalid');
        }

        return $id;
    }
}
