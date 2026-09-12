<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Demande la photo d'artiste d'un événement musical à Deezer. Traité par le
 * worker : deux appels HTTP (recherche, téléchargement) n'ont pas à retarder
 * la réponse qui suit la création d'un événement.
 */
final class FetchArtistImageMessage
{
    public function __construct(
        public readonly Uuid $eventId,
    ) {}
}
