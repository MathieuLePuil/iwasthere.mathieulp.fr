<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;

/**
 * Génère le fichier .ics d'un événement, pour le bouton « Ajouter au calendrier ».
 *
 * Le format (RFC 5545) est pointilleux sur trois choses, d'où les méthodes plus bas :
 * les sauts de ligne sont des CRLF, les valeurs texte s'échappent, et une ligne ne
 * dépasse pas 75 octets. Un fichier qui ignore l'une des trois s'ouvre chez les uns
 * et pas chez les autres — d'où le choix de tout écrire à la main plutôt que de
 * dépendre d'une bibliothèque pour trois douzaines de lignes.
 */
final class IcsExporter
{
    /** Durée posée quand l'événement a une heure mais qu'on ignore sa durée réelle. */
    private const DEFAULT_DURATION_HOURS = 3;

    /** @param string $url lien absolu vers la fiche, construit par le routeur appelant */
    public function export(Event $event, string $url): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            // Identifie le producteur du fichier ; libre, mais attendu par certains clients.
            'PRODID:-//IWasThere//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            ...$this->timezone(),
            'BEGIN:VEVENT',
            // Doit être stable dans le temps : réimporter le même événement met à jour
            // l'entrée au lieu d'en créer une seconde.
            'UID:' . $event->getId() . '@iwasthereapp.app',
            'DTSTAMP:' . (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd\THis\Z'),
            ...$this->when($event),
            'SUMMARY:' . $this->escape($this->title($event)),
            'URL:' . $this->escape($url),
        ];

        if ($venue = $event->getVenue()) {
            $lines[] = 'LOCATION:' . $this->escape($venue->getName());
        }
        if ($description = $this->description($event, $url)) {
            $lines[] = 'DESCRIPTION:' . $this->escape($description);
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines)) . "\r\n";
    }

    public function filename(Event $event): string
    {
        // Ramené à l'ASCII : un nom de fichier accentué ou ponctué se négocie mal
        // entre navigateurs et systèmes de fichiers.
        $slug = strtolower((string) preg_replace(
            ['/[^A-Za-z0-9]+/', '/^-+|-+$/'],
            ['-', ''],
            $this->transliterate($this->title($event)),
        ));

        return ($slug !== '' ? $slug : 'evenement') . '.ics';
    }

    /**
     * Un événement sans heure saisie devient une journée entière plutôt que d'hériter
     * du défaut 21h/16h de getStartDateTime() : ce défaut sert à ordonner et à relancer,
     * en faire un horaire dans l'agenda de quelqu'un serait le présenter comme un fait.
     * Sur une journée entière, DTEND est exclusif — d'où le +1 jour.
     *
     * @return list<string>
     */
    private function when(Event $event): array
    {
        if ($event->getStartTime() === null) {
            return [
                'DTSTART;VALUE=DATE:' . $event->getDate()->format('Ymd'),
                'DTEND;VALUE=DATE:' . $event->getDate()->modify('+1 day')->format('Ymd'),
            ];
        }

        $start = $event->getStartDateTime();

        return [
            'DTSTART;TZID=Europe/Paris:' . $start->format('Ymd\THis'),
            'DTEND;TZID=Europe/Paris:' . $start->modify('+' . self::DEFAULT_DURATION_HOURS . ' hours')->format('Ymd\THis'),
        ];
    }

    /**
     * Les heures sont écrites en TZID=Europe/Paris, ce qui oblige à embarquer la
     * définition du fuseau : sans elle, un client qui ne connaît pas l'identifiant
     * retombe sur l'heure locale de la machine et décale l'événement.
     *
     * @return list<string>
     */
    private function timezone(): array
    {
        return [
            'BEGIN:VTIMEZONE',
            'TZID:Europe/Paris',
            'BEGIN:DAYLIGHT',
            'TZOFFSETFROM:+0100',
            'TZOFFSETTO:+0200',
            'TZNAME:CEST',
            'DTSTART:19700329T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
            'END:DAYLIGHT',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:+0200',
            'TZOFFSETTO:+0100',
            'TZNAME:CET',
            'DTSTART:19701025T030000',
            'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
            'END:STANDARD',
            'END:VTIMEZONE',
        ];
    }

    /** « Ligue 1 — PSG vs OM » pour un match, le nom de l'artiste pour un concert. */
    private function title(Event $event): string
    {
        if ($event->getTournamentName() && $event->getTeams()) {
            return $event->getTournamentName() . ' — ' . $event->getTeams();
        }

        return $event->getArtistName()
            ?? $event->getTournamentName()
            ?? $event->getTeams()
            ?? 'Événement';
    }

    private function description(Event $event, string $url): ?string
    {
        $parts = array_filter([
            $event->getTourName(),
            'Ajouté depuis IWasThere : ' . $url,
        ]);

        return $parts === [] ? null : implode("\n", $parts);
    }

    /**
     * RFC 5545 §3.3.11 : antislash, point-virgule et virgule sont réservés dans une
     * valeur texte, et un vrai saut de ligne y couperait la propriété en deux.
     */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n", "\r"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'],
            $value,
        );
    }

    /**
     * RFC 5545 §3.1 : 75 octets par ligne, la suite sur une ligne commençant par une
     * espace. On compte en octets et on coupe sur les frontières de caractères — un
     * « é » scindé en deux lignes produirait un fichier invalide.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = '';
        $current = '';
        // Découpe en caractères et non en octets : mb_str_split respecte l'UTF-8.
        foreach (mb_str_split($line, 1, 'UTF-8') as $char) {
            // 75 octets pour la première ligne, 74 pour les suivantes : l'espace de
            // continuation en tête compte dans la limite.
            $limit = $out === '' ? 75 : 74;
            if (strlen($current) + strlen($char) > $limit) {
                $out .= ($out === '' ? '' : "\r\n ") . $current;
                $current = '';
            }
            $current .= $char;
        }

        return $out . ($out === '' ? '' : "\r\n ") . $current;
    }

    private function transliterate(string $value): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return $ascii === false ? $value : $ascii;
    }
}
