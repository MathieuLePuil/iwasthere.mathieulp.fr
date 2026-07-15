<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\Venue;
use App\Repository\EventParticipationRepository;
use App\Repository\FriendRepository;

/**
 * Construit le feed d'activité des amis, ordonné par date d'événement
 * (pas par date d'ajout) : une carte = un jour. Dans la carte, les événements
 * partageant les mêmes amis, le même type et le même lieu forment un groupe,
 * annoncé une fois (« X était à 3 concerts », le lieu) puis listé.
 *
 *  - days     : tout l'historique passé, du plus récent au plus ancien, avec
 *               les souvenirs des amis (note, commentaire, photo)
 *  - upcoming : bandeau « Bientôt » — événements à venir des amis, du plus
 *               proche au plus lointain
 *
 * Chaque jour porte `is_new` : contient-il au moins une participation ajoutée
 * depuis la dernière visite du feed ($seenBefore) ? Sert à placer la limite
 * « déjà vu / pas encore vu ».
 *
 * Respecte la confidentialité : amis in-app confirmés uniquement, profils
 * privés exclus, participations privées exclues.
 */
final class FeedService
{
    /** Taille max du bandeau « Bientôt » */
    private const UPCOMING_MAX = 12;

    private const MONTHS_FR = [
        1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];

    public function __construct(
        private readonly FriendRepository $friendRepo,
        private readonly EventParticipationRepository $participationRepo,
    ) {}

    /**
     * @param \DateTimeImmutable|null $seenBefore dernière visite du feed ;
     *     null = première visite, tout est considéré comme nouveau
     *
     * @return array{
     *     friend_count: int,
     *     upcoming: list<array>,
     *     days: list<array{
     *         date: \DateTimeImmutable,
     *         date_label: string,
     *         is_new: bool,
     *         groups: list<array{users: User[], type: string, venue: ?Venue, events: list<array>}>,
     *     }>,
     * }
     */
    public function buildFeed(User $user, ?\DateTimeImmutable $seenBefore = null): array
    {
        $friends = $this->resolveVisibleFriends($user);
        if ($friends === []) {
            return ['friend_count' => 0, 'upcoming' => [], 'days' => []];
        }

        // Tout l'historique : le feed remonte à l'infini, page par page de jours
        $participations = $this->participationRepo->findForFeed($friends);

        // Un groupe par événement, tous les amis présents regroupés dessus
        $groups = [];
        foreach ($participations as $p) {
            $event = $p->getEvent();
            $key = (string) $event->getId();
            $groups[$key] ??= ['event' => $event, 'participations' => []];
            $groups[$key]['participations'][] = $p;
        }

        $today = new \DateTimeImmutable('today');

        // Bandeau « Bientôt » : à venir, du plus proche au plus lointain
        $upcoming = array_values(array_filter($groups, fn (array $g) => $g['event']->getDate() >= $today));
        usort($upcoming, fn (array $a, array $b) => $a['event']->getStartDateTime() <=> $b['event']->getStartDateTime());
        $upcoming = array_slice($upcoming, 0, self::UPCOMING_MAX);
        foreach ($upcoming as &$g) {
            $g['days_until'] = (int) $today->diff($g['event']->getDate())->days;
        }
        unset($g);

        // Feed principal : événements passés, du plus récent au plus ancien
        $items = array_values(array_filter($groups, fn (array $g) => $g['event']->getDate() < $today));
        usort($items, fn (array $a, array $b) => $b['event']->getStartDateTime() <=> $a['event']->getStartDateTime());

        // Participation de l'utilisateur sur ces événements (« Tu y étais aussi »)
        $events = [];
        foreach ([...$items, ...$upcoming] as $g) {
            $events[(string) $g['event']->getId()] = $g['event'];
        }
        $mine = $this->participationRepo->findByUserForEvents($user, array_values($events));
        foreach ($items as &$g) {
            $g['my_participation'] = $mine[(string) $g['event']->getId()] ?? null;
        }
        unset($g);
        foreach ($upcoming as &$g) {
            $g['my_participation'] = $mine[(string) $g['event']->getId()] ?? null;
        }
        unset($g);

        // Une carte par jour : les événements d'un même jour partagent la carte.
        // $items étant trié du plus récent au plus ancien, les jours le sont aussi.
        // Dans un jour, les événements qui partagent les mêmes amis, le même
        // type et le même lieu forment un groupe : « X était à 3 concerts »
        // puis la liste, au lieu de répéter la phrase pour chaque événement.
        $days = [];
        foreach ($items as $g) {
            $date = $g['event']->getDate();
            $dayKey = $date->format('Y-m-d');
            $days[$dayKey] ??= [
                'date' => $date,
                'date_label' => $this->dateLabel($date),
                'groups' => [],
                'is_new' => false,
            ];

            if (!$days[$dayKey]['is_new']) {
                foreach ($g['participations'] as $p) {
                    if ($seenBefore === null || $p->getCreatedAt() > $seenBefore) {
                        $days[$dayKey]['is_new'] = true;
                        break;
                    }
                }
            }

            $users = [];
            foreach ($g['participations'] as $p) {
                $users[(string) $p->getUser()->getId()] ??= $p->getUser();
            }
            $venue = $g['event']->getVenue();

            $g['companions'] = $this->companions(
                $g['participations'],
                [...array_keys($users), (string) $user->getId()],
            );

            $ids = array_keys($users);
            sort($ids);
            $groupKey = implode(',', $ids)
                . '|' . $g['event']->getType()
                . '|' . ($venue?->getId() ?? '-');

            $days[$dayKey]['groups'][$groupKey] ??= [
                'users' => array_values($users),
                'type' => $g['event']->getType(),
                'venue' => $venue,
                'events' => [],
            ];
            $days[$dayKey]['groups'][$groupKey]['events'][] = $g;
        }

        // Au sein d'un groupe puis d'un jour, dans l'ordre de la journée
        foreach ($days as &$d) {
            foreach ($d['groups'] as &$grp) {
                usort($grp['events'], fn (array $a, array $b) => $a['event']->getStartDateTime() <=> $b['event']->getStartDateTime());
            }
            unset($grp);

            $d['groups'] = array_values($d['groups']);
            usort($d['groups'], fn (array $a, array $b) => $a['events'][0]['event']->getStartDateTime() <=> $b['events'][0]['event']->getStartDateTime());
        }
        unset($d);
        $days = array_values($days);

        return [
            'friend_count' => count($friends),
            'upcoming' => $upcoming,
            'days' => $days,
        ];
    }

    /**
     * Les accompagnants tagués par les amis sur un événement, dédoublonnés.
     * On retire ceux que la carte nomme déjà — les amis de l'en-tête et
     * l'utilisateur lui-même, annoncé par « Toi aussi » — pour ne garder que
     * les personnes qu'on ne verrait nulle part ailleurs.
     *
     * @param \App\Entity\EventParticipation[] $participations
     * @param list<string>                     $skipUserIds
     *
     * @return list<string>
     */
    private function companions(array $participations, array $skipUserIds): array
    {
        $skip = array_fill_keys($skipUserIds, true);

        $names = [];
        foreach ($participations as $p) {
            foreach ($p->getFriends() as $f) {
                if (($f['type'] ?? '') === 'app') {
                    if (isset($skip[(string) ($f['userId'] ?? '')])) {
                        continue;
                    }
                    $name = $f['displayName'] ?? null;
                } else {
                    $name = $f['name'] ?? null;
                }

                if (is_string($name) && $name !== '') {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /** « 12 juillet », avec l'année si ce n'est pas celle en cours */
    private function dateLabel(\DateTimeImmutable $date): string
    {
        $label = $date->format('j') . ' ' . self::MONTHS_FR[(int) $date->format('n')];
        if ($date->format('Y') !== (new \DateTimeImmutable())->format('Y')) {
            $label .= ' ' . $date->format('Y');
        }

        return $label;
    }

    /**
     * Amis in-app confirmés dont le profil n'est pas privé.
     *
     * @return User[]
     */
    private function resolveVisibleFriends(User $user): array
    {
        $friends = [];
        foreach ($this->friendRepo->findConfirmedFriends($user) as $rel) {
            $other = $rel->getOwner()->getId()->equals($user->getId())
                ? $rel->getFriendUser()
                : $rel->getOwner();
            if ($other !== null && $other->getProfileVisibility() !== 'private') {
                $friends[(string) $other->getId()] = $other;
            }
        }

        return array_values($friends);
    }
}
