<?php

namespace MichaelDanielczok\Piklis\Sync;

/**
 * Antwort auf die Frage beim Übernehmen, wenn das Produkt in der Zielliste
 * schon mit einer anderen Einheit steht (`onUnitMismatch` in
 * `entry.takeOver`).
 *
 * - `keep`: Menge der Zielliste behalten.
 * - `replace`: durch die übernommene Menge ersetzen.
 * - `note`: Menge der Zielliste behalten, die übernommene Menge kommt in die
 *   Notiz („+ 400 g“); so stehen beide Angaben in der Zeile.
 */
enum UnitMismatch: string
{
    case Keep = 'keep';
    case Replace = 'replace';
    case Note = 'note';
}
