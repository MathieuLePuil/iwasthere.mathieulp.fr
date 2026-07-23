<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\AuditLog;
use App\Entity\Event;
use App\Entity\EventParticipation;
use App\Entity\Friend;
use App\Entity\Notification;
use App\Entity\Reaction;
use App\Entity\User;
use App\Entity\Venue;
use App\Notification\NotificationType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Jeu de données de démonstration : peuple toutes les entités avec des données
 * fictives et cohérentes. Aucune donnée réelle — tous les comptes utilisent le
 * mot de passe « iwasthere » et une adresse @iwasthereapp.app.
 *
 * `doctrine:fixtures:load` purge d'abord chaque table mappée, ce qui efface les
 * données existantes avant de recharger celle-ci.
 */
class AppFixtures extends Fixture
{
    private const PASSWORD = 'iwasthere';

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Venue> */
    private array $venues = [];

    /** @var list<Event> */
    private array $events = [];

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {}

    public function load(ObjectManager $manager): void
    {
        $this->loadUsers($manager);
        $this->loadFriends($manager);
        $this->loadVenues($manager);
        $this->loadEvents($manager);
        $this->loadParticipationsReactionsAndNotifications($manager);
        $this->loadAuditLogs($manager);

        $manager->flush();
    }

    private function loadUsers(ObjectManager $manager): void
    {
        // handle => [displayName, role, theme, bio, publicité du profil, équipes, avatar]
        $defs = [
            'admin' => [
                'Admin Démo', 'superAdmin', 'dark',
                'Compte d\'administration de la démo. Accès au back-office.',
                ['events' => 'public', 'stats' => 'public', 'friends' => 'friends'],
                ['football' => 'Olympique de Marseille', 'rugby' => 'Stade Toulousain'],
                'unlockRewind',
            ],
            'lucas' => [
                'Lucas Martin', 'user', 'dark',
                'Concerts de metal et matchs de l\'OM. Toujours dans la fosse.',
                ['events' => 'public', 'stats' => 'friends', 'friends' => 'friends'],
                ['football' => 'Olympique de Marseille', 'rugby' => 'Stade Français'],
                null,
            ],
            'emma' => [
                'Emma Bernard', 'user', 'light',
                'Festivalière invétérée. Rock en Seine chaque année.',
                ['events' => 'public', 'stats' => 'public', 'friends' => 'public'],
                ['football' => 'Paris Saint-Germain'],
                null,
            ],
            'hugo' => [
                'Hugo Petit', 'user', 'auto',
                'Rugby et rap. Team Stade Toulousain.',
                ['events' => 'friends', 'stats' => 'friends', 'friends' => 'friends'],
                ['rugby' => 'Stade Toulousain', 'football' => 'FC Nantes'],
                null,
            ],
            'lea' => [
                'Léa Dubois', 'user', 'dark',
                null,
                ['events' => 'private', 'stats' => 'private', 'friends' => 'private'],
                [],
                null,
            ],
            'nathan' => [
                'Nathan Moreau', 'user', 'dark',
                'Tennis à Roland-Garros tous les ans. Fan de Djokovic.',
                ['events' => 'friends', 'stats' => 'public', 'friends' => 'friends'],
                ['football' => 'Olympique Lyonnais'],
                null,
            ],
            'chloe' => [
                'Chloé Laurent', 'user', 'light',
                'Pop, variété, un peu de tout. J\'y étais !',
                ['events' => 'public', 'stats' => 'friends', 'friends' => 'public'],
                [],
                null,
            ],
            'tom' => [
                'Tom Girard', 'user', 'dark',
                'Ultra du Vélodrome depuis 2010.',
                ['events' => 'friends', 'stats' => 'friends', 'friends' => 'friends'],
                ['football' => 'Olympique de Marseille'],
                'unlockRewind',
            ],
        ];

        foreach ($defs as $handle => [$displayName, $role, $theme, $bio, $privacy, $teams, $extra]) {
            $user = new User();
            $user->setUsername($handle);
            $user->setDisplayName($displayName);
            $user->setEmail($handle . '@iwasthereapp.app');
            $user->setPassword($this->hasher->hashPassword($user, self::PASSWORD));
            $user->setRole($role);
            $user->setTheme($theme);
            $user->setBio($bio);
            $user->setFavoriteTeams($teams);
            foreach ($privacy as $category => $level) {
                $user->setPrivacyLevel($category, $level);
            }
            // Quelques préférences de notification personnalisées, le reste par défaut (activé).
            $prefs = NotificationType::defaults();
            if ($handle === 'lea') {
                $prefs[NotificationType::FriendActivity->value] = false;
                $prefs[NotificationType::EventAnniversary->value] = false;
            }
            $user->setNotifPrefs($prefs);
            $user->setNotifCompletionTime('09:00');
            $user->setFeedLastSeenAt(new \DateTimeImmutable('-2 days'));
            $user->setCreatedAt(new \DateTimeImmutable('-14 months'));
            if ($extra === 'unlockRewind') {
                $user->unlockRewind(2025, new \DateTimeImmutable('-10 days'));
            }

            $manager->persist($user);
            $this->users[$handle] = $user;
        }
    }

    private function loadFriends(ObjectManager $manager): void
    {
        // Amitiés confirmées (réciproques : une entrée dans chaque sens).
        $confirmed = [
            ['admin', 'lucas'],
            ['admin', 'emma'],
            ['admin', 'tom'],
            ['lucas', 'emma'],
            ['lucas', 'tom'],
            ['emma', 'chloe'],
            ['hugo', 'nathan'],
            ['nathan', 'chloe'],
            ['lea', 'emma'],
        ];
        foreach ($confirmed as [$a, $b]) {
            $manager->persist($this->makeFriend($this->users[$a], 'inApp', 'confirmed', $this->users[$b]));
            $manager->persist($this->makeFriend($this->users[$b], 'inApp', 'confirmed', $this->users[$a]));
        }

        // Demandes en attente (un seul sens : le demandeur -> le destinataire).
        $pending = [
            ['hugo', 'admin'],
            ['tom', 'nathan'],
        ];
        foreach ($pending as [$from, $to]) {
            $manager->persist($this->makeFriend($this->users[$from], 'inApp', 'pending', $this->users[$to]));
        }

        // Amis « manuels » / externes : pas de compte lié, juste un nom saisi à la main.
        $manual = [
            ['lucas', 'Papa'],
            ['emma', 'Sarah (du boulot)'],
            ['tom', 'Kevin'],
        ];
        foreach ($manual as [$owner, $name]) {
            $friend = $this->makeFriend($this->users[$owner], 'manual', null, null);
            $friend->setDisplayName($name);
            $manager->persist($friend);
        }
    }

    private function makeFriend(User $owner, string $type, ?string $status, ?User $friendUser): Friend
    {
        $friend = new Friend();
        $friend->setOwner($owner);
        $friend->setFriendType($type);
        $friend->setStatus($status);
        $friend->setFriendUser($friendUser);
        $friend->setCreatedAt(new \DateTimeImmutable('-6 months'));

        return $friend;
    }

    private function loadVenues(ObjectManager $manager): void
    {
        // handle => [name, address, lat, long, capacity, type]
        $defs = [
            'accor'      => ['Accor Arena', '8 Boulevard de Bercy, 75012 Paris', 48.8388, 2.3789, 20300, 'arena'],
            'sdf'        => ['Stade de France', 'ZAC du Cornillon Nord, 93200 Saint-Denis', 48.9245, 2.3601, 80698, 'stadium'],
            'velodrome'  => ['Orange Vélodrome', '3 Boulevard Michelet, 13008 Marseille', 43.2699, 5.3958, 67394, 'stadium'],
            'zenith'     => ['Zénith Paris - La Villette', '211 Avenue Jean Jaurès, 75019 Paris', 48.8938, 2.3903, 6293, 'concert_hall'],
            'olympia'    => ['L\'Olympia', '28 Boulevard des Capucines, 75009 Paris', 48.8702, 2.3281, 1996, 'concert_hall'],
            'roland'     => ['Stade Roland-Garros', '2 Avenue Gordon Bennett, 75016 Paris', 48.8470, 2.2530, 15225, 'stadium'],
            'groupama'   => ['Groupama Stadium', '10 Avenue Simone Veil, 69150 Décines-Charpieu', 45.7653, 4.9822, 59186, 'stadium'],
            'cigale'     => ['La Cigale', '120 Boulevard de Rochechouart, 75018 Paris', 48.8823, 2.3403, 1389, 'concert_hall'],
            'stcloud'    => ['Domaine national de Saint-Cloud', '92210 Saint-Cloud', 48.8407, 2.2186, 40000, 'festival_ground'],
            'defense'    => ['Paris La Défense Arena', '99 Jardins de l\'Arche, 92000 Nanterre', 48.8957, 2.2295, 40000, 'arena'],
        ];

        $creators = array_keys($this->users);
        $i = 0;
        foreach ($defs as $handle => [$name, $address, $lat, $lng, $capacity, $type]) {
            $venue = new Venue();
            $venue->setName($name);
            $venue->setAddress($address);
            $venue->setLatitude($lat);
            $venue->setLongitude($lng);
            $venue->setCapacity($capacity);
            $venue->setVenueType($type);
            $venue->setCreatedByUserId($this->users[$creators[$i % count($creators)]]->getId());
            $venue->setCreatedAt(new \DateTimeImmutable('-12 months'));
            $venue->setUpdatedAt(new \DateTime('-12 months'));

            $manager->persist($venue);
            $this->venues[$handle] = $venue;
            $i++;
        }
    }

    private function loadEvents(ObjectManager $manager): void
    {
        // --- Concerts / festivals (musique) ---
        $this->events[] = $this->makeMusicEvent($manager, 'concert', 'accor', '-8 months', 'Metallica', 'M72 World Tour',
            ['Whiplash', 'For Whom the Bell Tolls', 'Enter Sandman', 'Master of Puppets'],
            ['Nothing Else Matters', 'One'], 'admin', '20:30');

        $this->events[] = $this->makeMusicEvent($manager, 'concert', 'defense', '-5 months', 'Coldplay', 'Music of the Spheres',
            ['Yellow', 'Paradise', 'The Scientist', 'Viva la Vida', 'Clocks'],
            ['Fix You', 'A Sky Full of Stars'], 'emma', '21:00');

        $this->events[] = $this->makeMusicEvent($manager, 'concert', 'olympia', '-3 months', 'Angèle', 'Nonante-Cinq Tour',
            ['Balance ton quoi', 'Bruxelles je t\'aime', 'Tout oublier', 'Fever'],
            ['Ta reine'], 'chloe', '20:00');

        $this->events[] = $this->makeMusicEvent($manager, 'concert', 'cigale', '-1 month', 'Phoenix', 'Alpha Zulu Tour',
            ['Lisztomania', 'Entertainment', '1901', 'If I Ever Feel Better'],
            ['Ti Amo'], 'lucas', '20:30');

        $this->events[] = $this->makeMusicEvent($manager, 'festival', 'stcloud', '-11 months', null, 'Rock en Seine 2025',
            ['Set du festival — têtes d\'affiche multiples'], [], 'emma', '14:00');

        // Concert à venir
        $this->events[] = $this->makeMusicEvent($manager, 'concert', 'zenith', '+2 months', 'Justice', 'Hyperdrama Tour',
            null, null, 'admin', '20:00');

        // --- Sport ---
        $this->events[] = $this->makeSportEvent($manager, 'football', 'velodrome', '-7 months',
            'Olympique de Marseille vs Paris Saint-Germain', '3 - 1', '1', 'Ligue 1', 'tom', '21:00',
            [['label' => 'Mi-temps', 'score' => '1 - 0']]);

        $this->events[] = $this->makeSportEvent($manager, 'football', 'groupama', '-4 months',
            'Olympique Lyonnais vs AS Monaco', '2 - 2', null, 'Ligue 1', 'nathan', '17:00',
            [['label' => 'Mi-temps', 'score' => '1 - 1']]);

        $this->events[] = $this->makeSportEvent($manager, 'rugby', 'sdf', '-6 months',
            'Stade Toulousain vs Stade Français', '27 - 18', '1', 'Top 14', 'hugo', '16:45', null);

        $this->events[] = $this->makeSportEvent($manager, 'tennis', 'roland', '-2 months',
            'Novak Djokovic vs Carlos Alcaraz', '6/4 6/2 7/5', '2', 'Roland-Garros', 'nathan', '15:00', null);

        // Match à venir
        $this->events[] = $this->makeSportEvent($manager, 'football', 'velodrome', '+1 month',
            'Olympique de Marseille vs AS Monaco', null, null, 'Ligue 1', 'tom', '21:00', null);
    }

    private function makeMusicEvent(
        ObjectManager $manager, string $type, string $venue, string $dateExpr,
        ?string $artist, ?string $tour, ?array $setlist, ?array $encores, string $creator, string $startTime,
    ): Event {
        $event = new Event();
        $event->setCategory('music');
        $event->setType($type);
        $event->setDate(new \DateTimeImmutable($dateExpr));
        $event->setStartTime(new \DateTimeImmutable($startTime));
        $event->setVenue($this->venues[$venue]);
        $event->setArtistName($artist);
        $event->setTourName($tour);
        if ($setlist !== null) {
            $event->setSetlist($setlist);
            $event->setSetlistEncores($encores);
            $event->setSetlistSource('setlistfm');
            $event->setSetlistUrl('https://www.setlist.fm/');
            $event->setSetlistImportedAt(new \DateTimeImmutable($dateExpr));
        }
        $this->stampEvent($event, $creator, $dateExpr);
        $manager->persist($event);

        return $event;
    }

    private function makeSportEvent(
        ObjectManager $manager, string $type, string $venue, string $dateExpr,
        string $teams, ?string $finalScore, ?string $winner, string $tournament, string $creator, string $startTime,
        ?array $intermediate,
    ): Event {
        $event = new Event();
        $event->setCategory('sport');
        $event->setType($type);
        $event->setDate(new \DateTimeImmutable($dateExpr));
        $event->setStartTime(new \DateTimeImmutable($startTime));
        $event->setVenue($this->venues[$venue]);
        $event->setTeams($teams);
        $event->setTournamentName($tournament);
        $event->setFinalScore($finalScore);
        $event->setWinner($winner);
        $event->setIntermediateScores($intermediate);
        $this->stampEvent($event, $creator, $dateExpr);
        $manager->persist($event);

        return $event;
    }

    private function stampEvent(Event $event, string $creator, string $dateExpr): void
    {
        $event->setCreatedByUserId($this->users[$creator]->getId());
        $created = new \DateTimeImmutable($dateExpr);
        $event->setCreatedAt($created->modify('-1 month'));
        $event->setUpdatedAt(\DateTime::createFromInterface($created));
    }

    private function loadParticipationsReactionsAndNotifications(ObjectManager $manager): void
    {
        // Pour chaque événement : une liste d'utilisateurs présents, le premier étant
        // le « propriétaire » du souvenir sur lequel les autres réagissent.
        $handles = array_keys($this->users);

        $ratings = [5, 4, 5, 3, 4, 5, 4];
        $comments = [
            'Soirée incroyable, ambiance de folie 🔥',
            'Un grand moment, à refaire !',
            'La setlist était parfaite.',
            'Un peu déçu par le son mais super moment quand même.',
            'Inoubliable, j\'y étais et je m\'en souviendrai.',
            null,
        ];
        $emojis = ['🔥', '❤️', '🎉', '🤩', '👏', '🙌', '🎸', '🏆'];

        // Qui participe à quel événement (par index dans $this->events).
        $lineup = [
            0 => ['lucas', 'admin', 'tom'],          // Metallica
            1 => ['emma', 'admin', 'chloe', 'lea'],  // Coldplay
            2 => ['chloe', 'emma', 'nathan'],        // Angèle
            3 => ['lucas', 'emma'],                  // Phoenix
            4 => ['emma', 'lucas', 'chloe'],         // Rock en Seine
            5 => ['admin', 'lucas'],                 // Justice (à venir)
            6 => ['tom', 'lucas', 'admin'],          // OM-PSG
            7 => ['nathan', 'hugo'],                 // OL-Monaco
            8 => ['hugo', 'nathan'],                 // rugby
            9 => ['nathan', 'chloe'],                // tennis
            10 => ['tom', 'lucas', 'admin'],         // OM-Monaco (à venir)
        ];

        $ci = 0;
        foreach ($lineup as $eventIndex => $participants) {
            $event = $this->events[$eventIndex];
            $isPast = $event->getDate() < new \DateTimeImmutable('today');

            /** @var list<EventParticipation> $created */
            $created = [];
            foreach ($participants as $pos => $handle) {
                $user = $this->users[$handle];
                $participation = new EventParticipation();
                $participation->setEvent($event);
                $participation->setUser($user);
                $participation->setStatus($isPast ? 'past' : 'upcoming');

                if ($isPast) {
                    $participation->setRating($ratings[($ci) % count($ratings)]);
                    $participation->setComment($comments[($ci) % count($comments)]);
                    $participation->setDuration($event->getCategory() === 'music' ? 150 : 105);
                }

                // Les accompagnants : les autres participants « app » + un externe pour le premier.
                $friendsData = [];
                foreach ($participants as $otherPos => $otherHandle) {
                    if ($otherPos === $pos) {
                        continue;
                    }
                    $other = $this->users[$otherHandle];
                    $friendsData[] = [
                        'type' => 'app',
                        'userId' => (string) $other->getId(),
                        'username' => $other->getUsername(),
                        'displayName' => $other->getDisplayName(),
                    ];
                }
                if ($pos === 0) {
                    $friendsData[] = ['type' => 'external', 'name' => 'Alex'];
                }
                $participation->setFriends($friendsData);
                $participation->setPhotos([]);
                $participation->setCreatedAt($event->getDate());
                $participation->setUpdatedAt(\DateTime::createFromInterface($event->getDate()));

                $manager->persist($participation);
                $created[] = $participation;
                $ci++;
            }

            $event->setParticipantCount(count($created));

            // Réactions : sur le souvenir du propriétaire (position 0), par les autres. Passé uniquement.
            if ($isPast && count($created) > 1) {
                $owner = $created[0];
                foreach (array_slice($created, 1) as $k => $p) {
                    $reaction = new Reaction();
                    $reaction->setParticipation($owner);
                    $reaction->setUser($p->getUser());
                    $reaction->setEmoji($emojis[($eventIndex + $k) % count($emojis)]);
                    $manager->persist($reaction);
                }
            }
        }

        $this->loadNotifications($manager);
    }

    private function loadNotifications(ObjectManager $manager): void
    {
        // [destinataire, type, titre, corps, lu ?]
        $defs = [
            ['admin', NotificationType::FriendRequest, 'Nouvelle demande d\'ami', 'Hugo Petit veut t\'ajouter en ami', false],
            ['admin', NotificationType::FriendReaction, 'Nouvelle réaction 🔥', 'Lucas Martin a réagi à ton souvenir Metallica', false],
            ['lucas', NotificationType::FriendAccepted, 'Demande acceptée', 'Emma Bernard a accepté ta demande d\'ami', true],
            ['lucas', NotificationType::FriendTaggedInEvent, 'Tu as été tagué', 'Tom Girard t\'a ajouté à OM - PSG', true],
            ['emma', NotificationType::FriendActivity, 'Activité d\'un ami', 'Chloé Laurent a ajouté un souvenir : Angèle', false],
            ['nathan', NotificationType::EventAnniversary, 'Il y a un an…', 'Roland-Garros : Djokovic vs Alcaraz', false],
            ['tom', NotificationType::RewindAvailable, 'Ton Rewind 2025 est prêt 🎁', 'Découvre ton année en concerts et en matchs', false],
            ['chloe', NotificationType::EventDay, 'C\'est aujourd\'hui !', 'Tu as un événement prévu aujourd\'hui', true],
            ['hugo', NotificationType::FriendSameEvent, 'Un ami au même match ?', 'Nathan Moreau y va aussi — vous y allez ensemble ?', false],
        ];

        foreach ($defs as $i => [$recipient, $type, $title, $body, $isRead]) {
            $notif = new Notification();
            $notif->setRecipient($this->users[$recipient]);
            $notif->setType($type->value);
            $notif->setTitle($title);
            $notif->setBody($body);
            $notif->setData(['icon' => $type->icon()]);
            $notif->setIsRead($isRead);
            $notif->setCreatedAt(new \DateTimeImmutable(sprintf('-%d days', $i + 1)));

            $manager->persist($notif);
        }
    }

    private function loadAuditLogs(ObjectManager $manager): void
    {
        $adminId = $this->users['admin']->getId();

        $defs = [
            ['update', 'Event', (string) $this->events[0]->getId(), 'artistName', 'Metalica', 'Metallica'],
            ['update', 'Venue', (string) $this->venues['accor']->getId(), 'capacity', '20000', '20300'],
            ['delete', 'User', (string) $this->users['lea']->getId(), null, null, null],
        ];

        foreach ($defs as $i => [$action, $entityType, $entityId, $field, $old, $new]) {
            $log = new AuditLog();
            $log->setSuperAdminUserId($adminId);
            $log->setAction($action);
            $log->setEntityType($entityType);
            $log->setEntityId($entityId);
            $log->setFieldChanged($field);
            $log->setOldValue($old);
            $log->setNewValue($new);
            $log->setPerformedAt(new \DateTimeImmutable(sprintf('-%d days', ($i + 1) * 2)));

            $manager->persist($log);
        }
    }
}
