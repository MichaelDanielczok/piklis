<?php

namespace MichaelDanielczok\Piklis\Sync;

/**
 * Zustand einer Liste: aktiv oder abgeschlossen (Archiv).
 */
enum ListStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
}
