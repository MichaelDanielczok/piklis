<?php

namespace MichaelDanielczok\Piklis\Sync;

/**
 * Zustand eines Listeneintrags. Gleiche Werte in Datenbank und JSON.
 */
enum EntryStatus: string
{
    case Open = 'open';
    case Done = 'done';
    case NotGot = 'notGot';
}
