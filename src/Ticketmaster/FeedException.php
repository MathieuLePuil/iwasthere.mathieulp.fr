<?php

declare(strict_types=1);

namespace App\Ticketmaster;

/** La synchronisation s'arrête net, sans rien écrire : flux indisponible, tronqué, disque plein… */
final class FeedException extends \RuntimeException
{
}
