<?php

namespace MichaelDanielczok\Piklis\Households;

/**
 * Rolle im Haushalt. Alle Mitglieder bearbeiten die gemeinsamen Listen;
 * Administratoren verwalten außerdem Einladungen und Mitglieder und dürfen
 * den Haushalt auflösen. Der Ersteller ist zunächst Administrator.
 */
enum HouseholdRole: string
{
    case Admin = 'admin';
    case Member = 'member';
}
