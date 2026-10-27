<?php

namespace MichaelDanielczok\Piklis\Sync;

use function str_starts_with;

/**
 * Arten von Operationen auf Listen und Einträgen (`type` im JSON).
 * Form der Daten je Art: docs/architecture.md, „Sync“.
 */
enum OperationType: string
{
    case ListCreate = 'list.create';
    case ListUpdate = 'list.update';
    case ListComplete = 'list.complete';
    case ListReopen = 'list.reopen';
    case ListDelete = 'list.delete';
    case EntryAdd = 'entry.add';
    case EntrySet = 'entry.set';
    case EntryMove = 'entry.move';
    case EntrySetStatus = 'entry.setStatus';
    case EntryRemove = 'entry.remove';
    case EntryRestore = 'entry.restore';
    case EntryTransfer = 'entry.transfer';

    public function isEntryOperation(): bool
    {
        return str_starts_with($this->value, 'entry.');
    }
}
