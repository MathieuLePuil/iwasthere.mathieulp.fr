<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use App\Entity\EventParticipation;
use App\Entity\User;
use App\Stats\FestivalEditions;
use App\Stats\LuckyTeam;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\QueryBuilder;
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
    public function findAllByUser(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.event', 'e')
            ->where('p.user = :user')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->orderBy('e.date', 'DESC')
            ->getQuery()
            ->getResult();
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
     * Les événements tombant un jour comme aujourd'hui, les années précédentes —
     * matière de la carte et du rappel « il y a un an ».
     *
     * On compare le jour et le mois plutôt qu'une date calculée : un « -1 an »
     * sur le 29 février n'existe pas trois années sur quatre, et cadrer sur une
     * seule année ferait passer à la trappe les anniversaires plus anciens. Un
     * 29 février ne ressort donc que les 29 février, ce qui est le comportement
     * voulu — c'est le jour où l'on y était.
     *
     * `e.date < :today` écarte l'édition de cette année s'il y en a une : sur un
     * festival annuel, l'événement du jour n'est pas son propre anniversaire.
     *
     * @return EventParticipation[] du plus récent au plus ancien
     */
    public function findAnniversaries(User $user, \DateTimeImmutable $today): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.event', 'e')->addSelect('e')
            ->leftJoin('e.venue', 'v')->addSelect('v')
            ->where('p.user = :user')
            ->andWhere('MONTH(e.date) = :month')
            ->andWhere('DAY(e.date) = :day')
            ->andWhere('e.date < :today')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('month', (int) $today->format('n'))
            ->setParameter('day', (int) $today->format('j'))
            ->setParameter('today', $today->setTime(0, 0))
            ->orderBy('e.date', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Les événements de l'utilisateur qui ont lieu aujourd'hui — matière du
     * rappel « jour J ».
     *
     * @return EventParticipation[]
     */
    public function findToday(User $user): array
    {
        $today = new \DateTimeImmutable('today');

        return $this->createQueryBuilder('p')
            ->join('p.event', 'e')->addSelect('e')
            ->leftJoin('e.venue', 'v')->addSelect('v')
            ->where('p.user = :user')
            ->andWhere('e.date >= :today')
            ->andWhere('e.date < :tomorrow')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('today', $today)
            ->setParameter('tomorrow', $today->modify('+1 day'))
            ->orderBy('e.date', 'ASC')
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

    /**
     * Participations visibles des amis pour le feed, tout l'historique par
     * défaut : le feed remonte à l'infini, la pagination se fait par jours.
     *
     * @param User[] $users
     * @param \DateTimeImmutable|null $since borne basse optionnelle
     * @return EventParticipation[]
     */
    public function findForFeed(array $users, ?\DateTimeImmutable $since = null): array
    {
        if ($users === []) {
            return [];
        }

        $ids = array_map(fn (User $u) => $u->getId()->toBinary(), $users);

        $qb = $this->createQueryBuilder('p')
            ->join('p.user', 'u')->addSelect('u')
            ->join('p.event', 'e')->addSelect('e')
            ->leftJoin('e.venue', 'v')->addSelect('v')
            // Aucun filtre de visibilité : un ami voit tout. C'est l'amitié qui fait
            // l'accès, et l'appelant ne passe ici que des amis confirmés.
            ->where('p.user IN (:users)')
            ->setParameter('users', $ids, ArrayParameterType::BINARY);

        if ($since !== null) {
            $qb->andWhere('e.date >= :since')->setParameter('since', $since);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Participations de l'utilisateur pour un lot d'événements, indexées par id d'événement.
     *
     * @param Event[] $events
     * @return array<string, EventParticipation>
     */
    public function findByUserForEvents(User $user, array $events): array
    {
        if ($events === []) {
            return [];
        }

        $ids = array_map(fn (Event $e) => $e->getId()->toBinary(), $events);

        $parts = $this->createQueryBuilder('p')
            ->where('p.user = :user')
            ->andWhere('p.event IN (:events)')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('events', $ids, ArrayParameterType::BINARY)
            ->getQuery()
            ->getResult();

        $byEvent = [];
        foreach ($parts as $p) {
            $byEvent[(string) $p->getEvent()->getId()] = $p;
        }

        return $byEvent;
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

    private function buildHistoryQb(User $user, string $tab, string $type, string $year): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.event', 'e')
            ->where('p.user = :user')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('today', new \DateTimeImmutable('today'));

        if ($tab === 'upcoming') {
            $qb->andWhere('e.date >= :today');
        } else {
            $qb->andWhere('e.date < :today');
            if ($year) {
                $qb->andWhere('YEAR(e.date) = :year')->setParameter('year', (int) $year);
            }
        }

        if ($type === 'sport') {
            $qb->andWhere('e.category = :cat')->setParameter('cat', 'sport');
        } elseif ($type) {
            $qb->andWhere('e.type = :type')->setParameter('type', $type);
        }

        return $qb;
    }

    public function countHistory(User $user, string $tab, string $type = '', string $year = ''): int
    {
        return (int) $this->buildHistoryQb($user, $tab, $type, $year)
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return EventParticipation[] */
    public function findHistoryPage(User $user, string $tab, string $type = '', string $year = '', int $page = 1, int $perPage = 20): array
    {
        return $this->buildHistoryQb($user, $tab, $type, $year)
            ->orderBy('e.date', $tab === 'upcoming' ? 'ASC' : 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    /** @return int[] */
    public function findHistoryYears(User $user): array
    {
        $result = $this->createQueryBuilder('p')
            ->select('DISTINCT YEAR(e.date) as year')
            ->join('p.event', 'e')
            ->where('p.user = :user')
            ->andWhere('e.date < :today')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->orderBy('year', 'DESC')
            ->getQuery()
            ->getResult();
        return array_column($result, 'year');
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

    /**
     * @return EventParticipation[] participations passées, triées par date croissante
     */
    public function findPastParticipations(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.event', 'e')
            ->leftJoin('e.venue', 'v')
            ->addSelect('e', 'v')
            ->where('p.user = :user')
            ->andWhere('e.date < :today')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->orderBy('e.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Les événements déjà vécus, pour la page /p/{pseudo}.
     *
     * La date fait foi plutôt que `p.status`, qui n'est recalé que par
     * updateStaleUpcoming() — une page servie à un visiteur anonyme ne déclenche
     * aucun recalage, elle ne peut pas s'y fier.
     *
     * Rien n'est filtré ici : c'est au contrôleur de décider s'il a le droit
     * d'afficher cette page, selon que le compte est public ou qu'on en est l'ami.
     *
     * @return EventParticipation[] les plus récents d'abord
     */
    public function findRecentPastForProfile(User $user, int $limit = 0): array
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.event', 'e')
            ->leftJoin('e.venue', 'v')
            ->addSelect('e', 'v')
            ->where('p.user = :user')
            ->andWhere('e.date < :today')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->orderBy('e.date', 'DESC');

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /** Le compteur de la page /p/{pseudo} ; même règle de date que ci-dessus. */
    public function countPastForProfile(User $user): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->join('p.event', 'e')
            ->where('p.user = :user')
            ->andWhere('e.date < :today')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Les événements d'une année auxquels l'utilisateur est allé — matière du
     * Rewind. On s'arrête à aujourd'hui : un concert de décembre pas encore
     * passé ne fait pas partie du bilan.
     *
     * @return EventParticipation[]
     */
    public function findForYear(User $user, int $year): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.event', 'e')->addSelect('e')
            ->leftJoin('e.venue', 'v')->addSelect('v')
            ->where('p.user = :user')
            ->andWhere('YEAR(e.date) = :year')
            ->andWhere('e.date < :today')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('year', $year)
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->orderBy('e.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Le nombre d'événements déjà vécus dans l'année, pour un lot d'utilisateurs.
     *
     * Tous les événements comptent : entre amis, il n'y a rien de caché, et le
     * classement ne mélange que des amis.
     *
     * On s'arrête à aujourd'hui — un concert de décembre déjà noté n'est pas encore
     * vécu, et le compter ferait mentir « qui a fait le plus » (même règle que le Rewind).
     *
     * @param User[] $users
     *
     * @return array<string, int> id d'utilisateur => compte ; un utilisateur sans
     *     événement cette année est absent, à l'appelant de retomber sur zéro
     */
    public function countForYearByUsers(array $users, int $year): array
    {
        if ($users === []) {
            return [];
        }

        $ids = array_map(fn (User $u) => $u->getId()->toBinary(), $users);

        $rows = $this->createQueryBuilder('p')
            ->select('u.id AS uid', 'COUNT(p.id) AS total')
            ->join('p.user', 'u')
            ->join('p.event', 'e')
            ->where('p.user IN (:users)')
            ->andWhere('YEAR(e.date) = :year')
            ->andWhere('e.date < :today')
            ->setParameter('users', $ids, ArrayParameterType::BINARY)
            ->setParameter('year', $year)
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->groupBy('u.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['uid']] = (int) $row['total'];
        }

        return $counts;
    }

    public function computeStats(User $user): array
    {
        $pastParts = $this->findPastParticipations($user);

        $total = count($pastParts);
        if ($total === 0) {
            return ['total' => 0, 'has_data' => false];
        }

        $concerts = 0; $festivals = 0; $sports = 0;
        $totalDuration = 0; $totalRating = 0; $ratingCount = 0;
        $artistVariants = []; $venues = []; $years = []; $months = []; $weekdays = [];
        $sportTypes = ['football' => 0, 'rugby' => 0, 'tennis' => 0];
        $friends = [];
        $byYear = []; $heatmap = [];
        $firstDate = null; $lastDate = null;
        $festivalGroups = [];
        $songVariants = [];

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
                    // Un concert vu en festival : les éditions se regroupent après la
                    // boucle, une fois toutes les dates connues (FestivalEditions).
                    $festivals++;
                } else {
                    $concerts++;
                }
                if ($e->getArtistName()) {
                    $artistName = trim($e->getArtistName());
                    $artistKey = mb_strtolower($artistName);
                    $artistVariants[$artistKey][$artistName] = ($artistVariants[$artistKey][$artistName] ?? 0) + 1;
                }
                $songArtist = trim((string) $e->getArtistName());
                foreach (array_merge($e->getSetlistNormalized(), $e->getSetlistEncoresNormalized()) as $s) {
                    if (!empty($s['tape'])) {
                        continue;
                    }
                    $songName = trim((string) ($s['name'] ?? ''));
                    if ($songName === '') {
                        continue;
                    }
                    $songKey = mb_strtolower($songArtist) . '|' . mb_strtolower($songName);
                    $songVariants[$songKey] ??= ['names' => [], 'artist' => $songArtist];
                    $songVariants[$songKey]['names'][$songName] = ($songVariants[$songKey]['names'][$songName] ?? 0) + 1;
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
            }

            foreach ($p->getFriends() ?? [] as $f) {
                $name = $f['displayName'] ?? ($f['friendUserId'] ?? 'Inconnu');
                $friends[$name] = ($friends[$name] ?? 0) + 1;
            }
        }

        foreach (FestivalEditions::group($pastParts) as $edition) {
            $notes = array_filter(array_map(fn($p) => $p->getRating(), $edition));
            $festivalGroups[] = [
                'name'       => FestivalEditions::name($edition),
                'year'       => FestivalEditions::year($edition),
                'count'      => count($edition),
                'avg_rating' => $notes !== [] ? round(array_sum($notes) / count($notes), 1) : null,
            ];
        }
        usort($festivalGroups, fn($a, $b) => $b['count'] <=> $a['count']);

        // Regroupe les graphies d'un même artiste (casse, espaces) sous la variante la plus fréquente
        $artists = [];
        foreach ($artistVariants as $variants) {
            arsort($variants);
            $artists[array_key_first($variants)] = array_sum($variants);
        }

        $songs = [];
        foreach ($songVariants as $v) {
            arsort($v['names']);
            $songs[] = [
                // (string) : un titre purement numérique devient une clé int en PHP
                'name' => (string) array_key_first($v['names']),
                'artist' => $v['artist'],
                'count' => array_sum($v['names']),
            ];
        }
        usort($songs, fn($a, $b) => [$b['count'], mb_strtolower($a['name'])] <=> [$a['count'], mb_strtolower($b['name'])]);

        arsort($artists); arsort($venues); arsort($friends);
        arsort($byYear);

        $topYear = $byYear ? array_key_first($byYear) : null;
        $topArtistCount = $artists ? max($artists) : 0;
        $topArtists = $topArtistCount > 0 ? array_keys(array_filter($artists, fn($c) => $c === $topArtistCount)) : [];
        $topArtist = $topArtists ? implode(', ', $topArtists) : null;
        $topVenueCount = $venues ? max($venues) : 0;
        $topVenues = $topVenueCount > 0 ? array_keys(array_filter($venues, fn($c) => $c === $topVenueCount)) : [];
        $topVenue = $topVenues ? implode(', ', $topVenues) : null;
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

        // Séries de mois consécutifs, en plus des jours ci-dessus : un festival donne
        // quelques jours d'affilée et rien de plus, alors qu'« un événement par mois,
        // douze mois de suite » récompense une assiduité que les jours ne voient pas.
        // Deux événements le même mois ne comptent qu'une fois, d'où le dédoublonnage
        // par clé Y-m.
        $monthKeys = [];
        foreach ($allDates as $d) {
            $monthKeys[substr($d, 0, 7)] = true;
        }
        $months_ = array_keys($monthKeys);
        sort($months_);
        $monthStreak = 0; $maxMonthStreak = 0; $currentMonthStreak = 0;
        if ($months_) {
            $prevMonth = \DateTimeImmutable::createFromFormat('Y-m-d|', $months_[0] . '-01');
            $currentMonthStreak = 1; $maxMonthStreak = 1;
            for ($i = 1; $i < count($months_); $i++) {
                $currMonth = \DateTimeImmutable::createFromFormat('Y-m-d|', $months_[$i] . '-01');
                // Comparaison sur le mois suivant plutôt que sur un écart de jours :
                // les mois n'ont pas tous la même longueur.
                if ($prevMonth->modify('+1 month')->format('Y-m') === $months_[$i]) {
                    $currentMonthStreak++;
                    if ($currentMonthStreak > $maxMonthStreak) {
                        $maxMonthStreak = $currentMonthStreak;
                    }
                } else {
                    $currentMonthStreak = 1;
                }
                $prevMonth = $currMonth;
            }
            // La série court encore si le dernier mois est celui-ci ou le précédent :
            // en cours de mois, on n'a pas encore « raté » le mois courant.
            $now = new \DateTimeImmutable('today');
            $lastMonth = end($months_);
            $monthStreak = in_array($lastMonth, [$now->format('Y-m'), $now->modify('-1 month')->format('Y-m')], true)
                ? $currentMonthStreak
                : 0;
        }

        $heatmapFormatted = [];
        $yearAgo = (new \DateTimeImmutable())->modify('-365 days');
        foreach ($heatmap as $d => $count) {
            if (new \DateTimeImmutable($d) >= $yearAgo) {
                $heatmapFormatted[$d] = $count;
            }
        }

        // Bilan porte-bonheur : seulement pour les équipes choisies qui figurent dans
        // au moins un match vu. La liste des matchs vit sur la page détail, on ne
        // garde ici que le résumé chiffré de chaque équipe.
        $favoriteTeams = $user->getFavoriteTeams();
        $luckyTeams = null;
        if ($favoriteTeams !== []) {
            $lucky = LuckyTeam::compute($pastParts, $favoriteTeams);
            if ($lucky['teams'] !== []) {
                foreach ($lucky['teams'] as &$t) {
                    unset($t['matches']);
                }
                unset($t);
                $luckyTeams = $lucky;
            }
        }

        return [
            'lucky_teams' => $luckyTeams,
            'has_data' => true,
            'total' => $total,
            // 'concerts' et 'festivals' partitionnent les événements musicaux : ils se
            // somment à 'total' avec 'sports', d'où la Répartition. 'concerts_total' est
            // l'autre lecture, celle du spectateur — un concert reste un concert, qu'il
            // ait été donné en tête d'affiche ou sur la scène d'un festival.
            'concerts' => $concerts,
            'festivals' => $festivals,
            'concerts_total' => $concerts + $festivals,
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
            'songs' => array_slice($songs, 0, 5),
            'songs_count' => count($songs),
            'top_artist' => $topArtist,
            'top_artist_count' => $topArtistCount,
            'venues' => array_slice($venues, 0, 5, true),
            'venues_count' => count($venues),
            'top_venue' => $topVenue,
            'friends_stats' => array_slice($friends, 0, 5, true),
            'top_friend' => $topFriend,
            'streak' => $streak,
            'max_streak' => $maxStreak,
            'month_streak' => $monthStreak,
            'max_month_streak' => $maxMonthStreak,
            'heatmap' => $heatmapFormatted,
            'festival_count' => count($festivalGroups),
            'festival_groups' => array_slice($festivalGroups, 0, 5),
            'top_festival' => $festivalGroups[0] ?? null,
        ];
    }
}
