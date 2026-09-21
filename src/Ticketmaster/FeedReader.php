<?php

declare(strict_types=1);

namespace App\Ticketmaster;

use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;

/**
 * Lit le flux Discovery Feed en continu : ~400 Mo de JSON en un seul objet
 * `{"events":[…]}`, gzippé. `json_decode` sur la chaîne entière est exclu ;
 * json-machine itère sur le pointeur `/events` en lisant directement le gzip
 * (`compress.zlib://`), sans jamais décompresser sur disque.
 *
 * Un fichier tronqué se termine par une exception de syntaxe (fin inattendue)
 * au lieu d'une fin propre : c'est le signal que rien ne doit être écrit.
 */
final class FeedReader
{
    /**
     * @return \Generator<int, array<string, mixed>> les événements bruts, un par un
     *
     * @throws \JsonMachine\Exception\JsonMachineException flux illisible ou tronqué
     */
    public function events(string $gzPath): \Generator
    {
        $items = Items::fromFile('compress.zlib://' . $gzPath, [
            'pointer' => '/events',
            'decoder' => new ExtJsonDecoder(true),
        ]);

        foreach ($items as $event) {
            if (is_array($event)) {
                yield $event;
            }
        }
    }
}
