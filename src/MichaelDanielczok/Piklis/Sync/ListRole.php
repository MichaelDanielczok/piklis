<?php

namespace MichaelDanielczok\Piklis\Sync;

/**
 * Rolle eines Benutzers an einer Liste.
 *
 * - `owner`: Besitzer einer persönlichen Liste
 * - `member`: Mitglied des Haushalts, dem die Liste gehört
 * - `guest`: angenommene Einladung zu dieser einen Liste (Außenstehender)
 *
 * Gibt es mehrere, gilt die stärkste (owner vor member vor guest).
 */
enum ListRole: string
{
    case Owner = 'owner';
    case Member = 'member';
    case Guest = 'guest';
}
