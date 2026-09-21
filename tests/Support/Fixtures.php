<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Event;
use App\Entity\EventParticipation;
use App\Entity\EventWatch;
use App\Entity\TmEvent;
use App\Entity\TmSaleWindow;
use App\Entity\User;
use App\Entity\Venue;
use App\Ticketmaster\NameNormalizer;
use App\Ticketmaster\SaleWindowType;

/** Constructeurs courts pour les tests unitaires — pas de base, pas de conteneur. */
final class Fixtures
{
    public static function user(string $username = 'alice'): User
    {
        return (new User())
            ->setUsername($username)
            ->setDisplayName(ucfirst($username))
            ->setEmail($username . '@example.test');
    }

    public static function venue(string $name): Venue
    {
        return (new Venue())->setName($name)->setAddress('')->setLatitude(0.0)->setLongitude(0.0);
    }

    public static function event(string $type, string $date, ?string $name = null, ?Venue $venue = null): Event
    {
        $event = (new Event())
            ->setType($type)
            ->setCategory(in_array($type, ['concert', 'festival'], true) ? 'music' : 'sport')
            ->setDate(new \DateTimeImmutable($date));
        if ($venue !== null) {
            $event->setVenue($venue);
        }
        if ($name !== null) {
            $event->getCategory() === 'music' ? $event->setArtistName($name) : $event->setTeams($name);
        }

        return $event;
    }

    public static function participation(Event $event, ?User $user = null): EventParticipation
    {
        return (new EventParticipation())->setEvent($event)->setUser($user ?? self::user());
    }

    /** Un événement Ticketmaster avec sa fenêtre de vente générale (instants UTC en « Y-m-d H:i:s »). */
    public static function tmEvent(string $id, string $name, string $onsaleUtc, ?string $url = 'https://www.ticketmaster.fr/fr/manifestation/x', ?string $eventStartUtc = null): TmEvent
    {
        $utc = new \DateTimeZone('UTC');
        $event = (new TmEvent($id))
            ->setName($name)
            ->setNameNormalized(NameNormalizer::normalize($name))
            ->setStatus('onsale')
            ->setVenueName('Accor Arena')
            ->setVenueCity('Paris')
            ->setVenueTimezone('Europe/Paris')
            ->setSegment('Music')
            ->setUrl($url)
            ->setEventStartUtc($eventStartUtc === null ? null : new \DateTimeImmutable($eventStartUtc, $utc));
        new TmSaleWindow($event, SaleWindowType::Public, new \DateTimeImmutable($onsaleUtc, $utc));

        return $event;
    }

    public static function watch(User $user, TmEvent $event): EventWatch
    {
        return new EventWatch($user, $event);
    }
}
