<?php

namespace MichaelDanielczok\Piklis\Database;

use QUI;

/**
 * Tabellen aus database.xml (Modell: docs/architecture.md, „Data model“).
 */
final class Tables
{
    public const PRODUCTS = 'pk_products';
    public const LISTS = 'pk_lists';
    public const LIST_ENTRIES = 'pk_list_entries';
    public const LIST_CHANGES = 'pk_list_changes';

    public const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * Tabellenname mit Präfix der Instanz.
     */
    public static function name(string $table): string
    {
        return QUI::getDBTableName($table);
    }
}
