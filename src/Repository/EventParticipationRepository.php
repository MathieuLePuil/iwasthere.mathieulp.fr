<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use App\Entity\EventParticipation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventParticipation>
 */
class EventParticipationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventParticipation::class);
    }

    public function findOneByEventAndUser(Event $event, User $user): ?EventParticipation
    {
        return $this->findOneBy(['event' => $event, 'user' => $user]);
    }

    public function findByUserAndEvent(User $user, Event $event): ?EventParticipation
    {
        return $this->findOneBy(['user' => $user, 'event' => $event]);
    }

    /**
     * @return EventParticipation[]
     */
    public function findByUser(User $user, int $limit = 0): array
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.event', 'e')
            ->where('p.user = :user')
            ->andWhere('p.status = :status')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('status', 'past')
            ->orderBy('e.date', 'DESC');

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return EventParticipation[]
     */
    public function findByEvent(Event $event): array
    {
        return $this->createQueryBuilder('ep')
            ->andWhere('ep.event = :event')
            ->setParameter('event', $event->getId()->toBinary(), ParameterType::BINARY)
            ->orderBy('ep.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return EventParticipation[]
     */
    public function findByUserAndStatus(User $user, string $status): array
    {
        return $this->createQueryBuilder('ep')
            ->andWhere('ep.user = :user')
            ->andWhere('ep.status = :status')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('status', $status)
            ->orderBy('ep.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findNextUpcoming(User $user): ?EventParticipation
    {
        return $this->createQueryBuilder('p')
            ->join('p.event', 'e')
            ->where('p.user = :user')
            ->andWhere('p.status = :status')
            ->andWhere('e.date >= :today')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('status', 'upcoming')
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->orderBy('e.date', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return EventParticipation[]
     */
    public function findRecentPast(User $user, int $limit = 3): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.event', 'e')
            ->where('p.user = :user')
            ->andWhere('p.status = :status')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('status', 'past')
            ->orderBy('e.date', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return EventParticipation[]
     */
    public function findPendingReminders(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.event', 'e')
            ->where('p.user = :user')
            ->andWhere('p.status = :status')
            ->andWhere('p.rating IS NULL')
            ->andWhere('e.date < :today')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('status', 'past')
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->orderBy('e.date', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return EventParticipation[]
     */
    public function findVisibleForEvent(Event $event, User $currentUser): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.event = :event')
            ->setParameter('event', $event->getId()->toBinary(), ParameterType::BINARY)
            ->getQuery()
            ->getResult();
    }

    public function updateStaleUpcoming(User $user): void
    {
        $this->getEntityManager()->createQuery(
            'UPDATE App\Entity\EventParticipation p
             SET p.status = :past
             WHERE p.user = :user
               AND p.status = :upcoming
               AND p.event IN (
                   SELECT e.id FROM App\Entity\Event e WHERE e.date < :today
               )'
        )
        ->setParameter('past', 'past')
        ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
        ->setParameter('upcoming', 'upcoming')
        ->setParameter('today', new \DateTimeImmutable('today'))
        ->execute();
    }

    public function getAvgRating(User $user): ?float
    {
        $result = $this->createQueryBuilder('p')
            ->select('AVG(p.rating)')
            ->where('p.user = :user')
            ->andWhere('p.rating IS NOT NULL')
            ->setParameter('user', $user->getId()->toBinary(), \Doctrine\DBAL\ParameterType::BINARY)
            ->getQuery()
            ->getSingleScalarResult();

        return $result ? round((float) $result, 1) : null;
    }

    public function countByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.user = :user')
            ->andWhere('p.status = :status')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('status', 'past')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return EventParticipation[] */
    public function findMusicUpcoming(User $user, string $type = ''): array
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.event', 'e')
            ->leftJoin('e.venue', 'v')
            ->where('p.user = :user')
            ->andWhere('e.date >= :today')
            ->andWhere('e.category = :cat')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->setParameter('cat', 'music')
            ->orderBy('e.date', 'ASC');
        if ($type) {
            $qb->andWhere('e.type = :type')->setParameter('type', $type);
        }
        return $qb->getQuery()->getResult();
    }

    /** @return EventParticipation[] */
    public function findMusicPast(User $user, string $type = '', string $year = '', string $sortBy = 'date'): array
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.event', 'e')
            ->leftJoin('e.venue', 'v')
            ->where('p.user = :user')
            ->andWhere('e.date < :today')
            ->andWhere('e.category = :cat')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->setParameter('cat', 'music');
        if ($type) {
            $qb->andWhere('e.type = :type')->setParameter('type', $type);
        }
        if ($year) {
            $qb->andWhere('YEAR(e.date) = :year')->setParameter('year', (int) $year);
        }
        match ($sortBy) {
            'rating'   => $qb->orderBy('p.rating', 'DESC')->addOrderBy('e.date', 'DESC'),
            'duration' => $qb->orderBy('p.duration', 'DESC')->addOrderBy('e.date', 'DESC'),
            default    => $qb->orderBy('e.date', 'DESC'),
        };
        return $qb->getQuery()->getResult();
    }

    public function findMusicYears(User $user): array
    {
        $result = $this->createQueryBuilder('p')
            ->select('DISTINCT YEAR(e.date) as year')
            ->join('p.event', 'e')
            ->where('p.user = :user')
            ->andWhere('e.category = :cat')
            ->andWhere('e.date < :today')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('cat', 'music')
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->orderBy('year', 'DESC')
            ->getQuery()
            ->getResult();
        return array_column($result, 'year');
    }

    /** @return EventParticipation[] */
    public function findSportUpcoming(User $user, string $sport = ''): array
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.event', 'e')
            ->leftJoin('e.venue', 'v')
            ->where('p.user = :user')
            ->andWhere('e.date >= :today')
            ->andWhere('e.category = :cat')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->setParameter('cat', 'sport')
            ->orderBy('e.date', 'ASC');
        if ($sport) {
            $qb->andWhere('e.type = :sport')->setParameter('sport', $sport);
        }
        return $qb->getQuery()->getResult();
    }

    /** @return EventParticipation[] */
    public function findSportPast(User $user, string $sport = '', string $year = ''): array
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.event', 'e')
            ->leftJoin('e.venue', 'v')
            ->where('p.user = :user')
            ->andWhere('e.date < :today')
            ->andWhere('e.category = :cat')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->setParameter('cat', 'sport')
            ->orderBy('e.date', 'DESC');
        if ($sport) {
            $qb->andWhere('e.type = :sport')->setParameter('sport', $sport);
        }
        if ($year) {
            $qb->andWhere('YEAR(e.date) = :year')->setParameter('year', (int) $year);
        }
        return $qb->getQuery()->getResult();
    }

    public function findSportYears(User $user): array
    {
        $result = $this->createQueryBuilder('p')
            ->select('DISTINCT YEAR(e.date) as year')
            ->join('p.event', 'e')
            ->where('p.user = :user')
            ->andWhere('e.category = :cat')
            ->andWhere('e.date < :today')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('cat', 'sport')
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->orderBy('year', 'DESC')
            ->getQuery()
            ->getResult();
        return array_column($result, 'year');
    }

    public function computeStats(User $user): array
    {
        $pastParts = $this->createQueryBuilder('p')
            ->join('p.event', 'e')
            ->leftJoin('e.venue', 'v')
            ->where('p.user = :user')
            ->andWhere('e.date < :today')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->orderBy('e.date', 'ASC')
            ->getQuery()
            ->getResult();

        $total = count($pastParts);
        if ($total === 0) {
            return ['total' => 0, 'has_data' => false];
        }

        $concerts = 0; $festivals = 0; $sports = 0;
        $totalDuration = 0; $totalRating = 0; $ratingCount = 0;
        $artists = []; $venues = []; $cities = []; $years = []; $months = []; $weekdays = [];
        $sportTypes = ['football' => 0, 'rugby' => 0, 'tennis' => 0];
        $friends = [];
        $byYear = []; $heatmap = [];
        $firstDate = null; $lastDate = null;
        $festivalGroups = [];

        foreach ($pastParts as $p) {
            $e = $p->getEvent();
            $date = $e->getDate();
            $year = $date->format('Y');
            $month = $date->format('n');
            $day = $date->format('N');
            $dateKey = $date->format('Y-m-d');

            if (!$firstDate || $date < $firstDate) {
                $firstDate = $date;
            }
            if (!$lastDate || $date > $lastDate) {
                $lastDate = $date;
            }

            $byYear[$year] = ($byYear[$year] ?? 0) + 1;
            $months[$month] = ($months[$month] ?? 0) + 1;
            $weekdays[$day] = ($weekdays[$day] ?? 0) + 1;
            $heatmap[$dateKey] = ($heatmap[$dateKey] ?? 0) + 1;

            if ($e->getCategory() === 'music') {
                if ($e->getType() === 'festival') {
                    $festivals++;
                    $festivalVenue = $e->getVenue()?->getName() ?? 'Inconnu';
                    $festKey = $festivalVenue . '|' . $year;
                    if (!isset($festivalGroups[$festKey])) {
                        $festivalGroups[$festKey] = [
                            'name'         => $festivalVenue,
                            'year'         => (int) $year,
                            'count'        => 0,
                            'total_rating' => 0,
                            'rating_count' => 0,
                        ];
                    }
                    $festivalGroups[$festKey]['count']++;
                    if ($p->getRating()) {
                        $festivalGroups[$festKey]['total_rating'] += $p->getRating();
                        $festivalGroups[$festKey]['rating_count']++;
                    }
                } else {
                    $concerts++;
                }
                if ($e->getArtistName()) {
                    $artists[$e->getArtistName()] = ($artists[$e->getArtistName()] ?? 0) + 1;
                }
            } else {
                $sports++;
                $t = $e->getType();
                if (isset($sportTypes[$t])) {
                    $sportTypes[$t]++;
                }
            }

            if ($p->getDuration()) {
                $totalDuration += $p->getDuration();
            }
            if ($p->getRating()) {
                $totalRating += $p->getRating();
                $ratingCount++;
            }

            if ($e->getVenue()) {
                $vName = $e->getVenue()->getName();
                $venues[$vName] = ($venues[$vName] ?? 0) + 1;
                $city = $e->getVenue()->getCity();
                $cities[$city] = ($cities[$city] ?? 0) + 1;
            }

            foreach ($p->getFriends() ?? [] as $f) {
                $name = $f['displayName'] ?? ($f['friendUserId'] ?? 'Inconnu');
                $friends[$name] = ($friends[$name] ?? 0) + 1;
            }
        }

        foreach ($festivalGroups as &$fg) {
            $fg['avg_rating'] = $fg['rating_count'] > 0
                ? round($fg['total_rating'] / $fg['rating_count'], 1)
                : null;
            unset($fg['total_rating'], $fg['rating_count']);
        }
        unset($fg);
        usort($festivalGroups, fn($a, $b) => $b['count'] <=> $a['count']);

        arsort($artists); arsort($venues); arsort($cities); arsort($friends);
        arsort($byYear);

        $topYear = $byYear ? array_key_first($byYear) : null;
        $topArtistCount = $artists ? max($artists) : 0;
        $topArtists = $topArtistCount > 0 ? array_keys(array_filter($artists, fn($c) => $c === $topArtistCount)) : [];
        $topArtist = $topArtists ? implode(', ', $topArtists) : null;
        $topVenueCount = $venues ? max($venues) : 0;
        $topVenues = $topVenueCount > 0 ? array_keys(array_filter($venues, fn($c) => $c === $topVenueCount)) : [];
        $topVenue = $topVenues ? implode(', ', $topVenues) : null;
        $topCity = $cities ? array_key_first($cities) : null;
        $topFriend = $friends ? array_key_first($friends) : null;

        ksort($heatmap);
        $streak = 0; $maxStreak = 0; $currentStreak = 0;
        $allDates = array_keys($heatmap);
        if ($allDates) {
            $prev = new \DateTimeImmutable($allDates[0]);
            $currentStreak = 1; $maxStreak = 1;
            for ($i = 1; $i < count($allDates); $i++) {
                $curr = new \DateTimeImmutable($allDates[$i]);
                $diff = (int) $prev->diff($curr)->days;
                if ($diff === 1) {
                    $currentStreak++;
                    if ($currentStreak > $maxStreak) {
                        $maxStreak = $currentStreak;
                    }
                } else {
                    $currentStreak = 1;
                }
                $prev = $curr;
            }
            $lastEventDate = new \DateTimeImmutable(end($allDates));
            $today = new \DateTimeImmutable('today');
            $daysSinceLast = (int) $lastEventDate->diff($today)->days;
            $streak = $daysSinceLast <= 1 ? $currentStreak : 0;
        }

        $heatmapFormatted = [];
        $yearAgo = (new \DateTimeImmutable())->modify('-365 days');
        foreach ($heatmap as $d => $count) {
            if (new \DateTimeImmutable($d) >= $yearAgo) {
                $heatmapFormatted[$d] = $count;
            }
        }

        return [
            'has_data' => true,
            'total' => $total,
            'concerts' => $concerts,
            'festivals' => $festivals,
            'sports' => $sports,
            'sport_types' => $sportTypes,
            'total_duration_h' => round($totalDuration / 60, 1),
            'avg_duration_min' => $concerts + $festivals > 0 ? round($totalDuration / ($concerts + $festivals)) : 0,
'avg_rating' => $ratingCount > 0 ? round($totalRating / $ratingCount, 1) : null,
            'first_date' => $firstDate,
            'last_date' => $lastDate,
            'top_year' => $topYear,
            'by_year' => $byYear,
            'by_month' => $months,
            'by_weekday' => $weekdays,
            'artists' => array_slice($artists, 0, 10, true),
            'artists_count' => count($artists),
            'top_artist' => $topArtist,
            'top_artist_count' => $topArtistCount,
            'venues' => array_slice($venues, 0, 5, true),
            'venues_count' => count($venues),
            'top_venue' => $topVenue,
            'cities' => array_slice($cities, 0, 5, true),
            'cities_count' => count($cities),
            'top_city' => $topCity,
            'friends_stats' => array_slice($friends, 0, 5, true),
            'top_friend' => $topFriend,
            'streak' => $streak,
            'max_streak' => $maxStreak,
            'heatmap' => $heatmapFormatted,
            'festival_count' => count($festivalGroups),
            'festival_groups' => array_slice($festivalGroups, 0, 5),
            'top_festival' => $festivalGroups[0] ?? null,
        ];
    }
}
