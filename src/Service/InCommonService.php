<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Entity\EventParticipation;
use App\Entity\User;
use App\Repository\EventParticipationRepository;

/**
 * Croise les historiques de deux utilisateurs : ce qu'ils ont vécu ensemble,
 * et les artistes qu'ils ont vus chacun de leur côté.
 */
class InCommonService
{
    public function __construct(private readonly EventParticipationRepository $repo)
    {
    }

    /**
     * Un événement compte comme « ensemble » si les deux l'ont enregistré, ou si
     * l'un a taggué l'autre dans ses amis — un ami taggué n'a pas forcément
     * ajouté l'événement de son côté.
     */
    public function forUsers(User $me, User $other): array
    {
        $mine = $this->repo->findAllByUser($me);
        // Rien à filtrer : la page n'est ouverte qu'entre amis, et un ami voit tout.
        $theirs = $this->repo->findAllByUser($other);

        $myId = (string) $me->getId();
        $otherId = (string) $other->getId();

        $theirsByEvent = [];
        foreach ($theirs as $p) {
            $theirsByEvent[(string) $p->getEvent()->getId()] = $p;
        }

        $together = [];
        foreach ($mine as $p) {
            $eventId = (string) $p->getEvent()->getId();
            $theirPart = $theirsByEvent[$eventId] ?? null;
            if ($theirPart === null && !$this->isTagged($p, $otherId)) {
                continue;
            }
            $together[$eventId] = [
                'event' => $p->getEvent(),
                'mine' => $p,
                'theirs' => $theirPart,
                'both' => $theirPart !== null,
            ];
        }
        // Ce qu'il a ajouté en me tagguant sans que je l'aie enregistré.
        foreach ($theirs as $p) {
            $eventId = (string) $p->getEvent()->getId();
            if (isset($together[$eventId]) || !$this->isTagged($p, $myId)) {
                continue;
            }
            $together[$eventId] = [
                'event' => $p->getEvent(),
                'mine' => null,
                'theirs' => $p,
                'both' => false,
            ];
        }

        usort($together, fn ($a, $b) => $b['event']->getDate() <=> $a['event']->getDate());

        $today = new \DateTimeImmutable('today');
        $past = array_values(array_filter($together, fn ($t) => $t['event']->getDate() < $today));
        $upcoming = array_reverse(array_values(array_filter($together, fn ($t) => $t['event']->getDate() >= $today)));

        return [
            'together_past' => $past,
            'together_upcoming' => $upcoming,
            'counts' => $this->counts($past),
            'ratings' => $this->ratings($past),
            'first' => $past === [] ? null : $past[count($past) - 1]['event'],
            'last' => $past === [] ? null : $past[0]['event'],
            'artists' => $this->commonArtists($mine, $theirs, $past, $today),
        ];
    }

    /**
     * @param array<int, array{event: Event}> $past
     * @return array{total: int, concert: int, festival: int, sport: int}
     */
    private function counts(array $past): array
    {
        $counts = ['total' => count($past), 'concert' => 0, 'festival' => 0, 'sport' => 0];
        foreach ($past as $t) {
            $event = $t['event'];
            if ($event->getCategory() === 'sport') {
                $counts['sport']++;
            } elseif ($event->getType() === 'festival') {
                $counts['festival']++;
            } else {
                $counts['concert']++;
            }
        }

        return $counts;
    }

    /**
     * Comparaison des notes, sur les seuls événements que les deux ont notés.
     *
     * @param array<int, array{mine: ?EventParticipation, theirs: ?EventParticipation}> $past
     * @return array{both: int, agree: int, mine_higher: int, theirs_higher: int, avg_gap: ?float}
     */
    private function ratings(array $past): array
    {
        $stats = ['both' => 0, 'agree' => 0, 'mine_higher' => 0, 'theirs_higher' => 0, 'avg_gap' => null];
        $gap = 0;

        foreach ($past as $t) {
            $mine = $t['mine']?->getRating();
            $theirs = $t['theirs']?->getRating();
            if ($mine === null || $theirs === null) {
                continue;
            }

            $stats['both']++;
            $gap += abs($mine - $theirs);
            if ($mine === $theirs) {
                $stats['agree']++;
            } elseif ($mine > $theirs) {
                $stats['mine_higher']++;
            } else {
                $stats['theirs_higher']++;
            }
        }

        if ($stats['both'] > 0) {
            $stats['avg_gap'] = round($gap / $stats['both'], 1);
        }

        return $stats;
    }

    /**
     * Artistes que les deux ont vus, ensemble ou chacun de son côté.
     *
     * @param EventParticipation[] $mine
     * @param EventParticipation[] $theirs
     * @param array<int, array{event: Event}> $past
     * @return array<int, array{name: string, mine: int, theirs: int, together: int}>
     */
    private function commonArtists(array $mine, array $theirs, array $past, \DateTimeImmutable $today): array
    {
        $mineByArtist = $this->countArtists($mine, $today);
        $theirsByArtist = $this->countArtists($theirs, $today);

        $togetherByArtist = [];
        foreach ($past as $t) {
            $key = $this->artistKey($t['event']);
            if ($key !== null) {
                $togetherByArtist[$key] = ($togetherByArtist[$key] ?? 0) + 1;
            }
        }

        $artists = [];
        foreach ($mineByArtist as $key => $data) {
            if (!isset($theirsByArtist[$key])) {
                continue;
            }
            // Sur la casse, on affiche la graphie la plus fréquente chez les deux.
            $names = $data['names'];
            foreach ($theirsByArtist[$key]['names'] as $name => $count) {
                $names[$name] = ($names[$name] ?? 0) + $count;
            }
            arsort($names);

            $artists[] = [
                'name' => array_key_first($names),
                'mine' => $data['count'],
                'theirs' => $theirsByArtist[$key]['count'],
                'together' => $togetherByArtist[$key] ?? 0,
            ];
        }

        usort($artists, fn ($a, $b) => [$b['together'], $b['mine'] + $b['theirs'], mb_strtolower($a['name'])]
            <=> [$a['together'], $a['mine'] + $a['theirs'], mb_strtolower($b['name'])]);

        return $artists;
    }

    /**
     * @param EventParticipation[] $parts
     * @return array<string, array{count: int, names: array<string, int>}>
     */
    private function countArtists(array $parts, \DateTimeImmutable $today): array
    {
        $counts = [];
        foreach ($parts as $p) {
            $event = $p->getEvent();
            $key = $this->artistKey($event);
            if ($key === null || $event->getDate() >= $today) {
                continue;
            }
            $name = trim($event->getArtistName());
            $counts[$key] ??= ['count' => 0, 'names' => []];
            $counts[$key]['count']++;
            $counts[$key]['names'][$name] = ($counts[$key]['names'][$name] ?? 0) + 1;
        }

        return $counts;
    }

    private function artistKey(Event $event): ?string
    {
        if ($event->getCategory() !== 'music' || !$event->getArtistName()) {
            return null;
        }

        return mb_strtolower(trim($event->getArtistName()));
    }

    private function isTagged(EventParticipation $p, string $userId): bool
    {
        foreach ($p->getFriends() as $friend) {
            if (($friend['type'] ?? '') === 'app' && ($friend['userId'] ?? '') === $userId) {
                return true;
            }
        }

        return false;
    }
}
