<?php

declare(strict_types=1);

namespace App\Ticketmaster;

/**
 * Une fenêtre de vente : la mise en vente générale, ou une prévente.
 *
 * Le marché français n'expose aucune prévente (presales[] vide sur tout le
 * catalogue, voir docs/ticketmaster.md) : seule `Public` est alimentée
 * aujourd'hui. La table existe quand même pour que le jour où Ticketmaster
 * remplit presales[], l'ingestion écrive des lignes en plus et que les alertes
 * suivent sans refonte.
 */
enum SaleWindowType: string
{
    case Public = 'public';
    case Presale = 'presale';
}
