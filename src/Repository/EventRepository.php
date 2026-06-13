<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    /**
     * @return Event[]
     */
    public function findByCategory(string $category): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.category = :category')
            ->setParameter('category', $category)
            ->orderBy('e.date', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Event[]
     */
    public function findByArtistName(string $artistName): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.artistName = :artistName')
            ->setParameter('artistName', $artistName)
            ->orderBy('e.date', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Event[]
     */
    public function findPendingSetlistImports(): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.category = :category')
            ->andWhere('e.setlistSource IS NULL OR e.setlistSource = :source')
            ->andWhere('e.setlistRetryCount < :maxRetry')
            ->setParameter('category', 'music')
            ->setParameter('source', 'setlist_fm')
            ->setParameter('maxRetry', 3)
            ->orderBy('e.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Event[]
     */
    public function findPendingSetlistImport(): array
    {
        $threshold = new \DateTimeImmutable('-1 hour');

        return $this->createQueryBuilder('e')
            ->where('e.category = :cat')
            ->andWhere('e.date < :today')
            ->andWhere('(e.setlist IS NULL OR e.setlist = :emptyArr)')
            ->andWhere('e.setlistRetryCount < :max')
            ->andWhere('(e.setlistLastAttemptAt IS NULL OR e.setlistLastAttemptAt < :threshold)')
            ->setParameter('cat', 'music')
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->setParameter('emptyArr', '[]')
            ->setParameter('max', 24)
            ->setParameter('threshold', $threshold)
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();
    }

    /** @return Event[] Already imported setlists that need re-syncing */
    public function findImportedSetlists(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.category = :cat')
            ->andWhere('e.date < :today')
            ->andWhere('e.setlistSource = :source')
            ->setParameter('cat', 'music')
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->setParameter('source', 'setlist_fm')
            ->orderBy('e.date', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return string[] */
    public function searchTeams(string $query): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $results = $conn->fetchAllAssociative(
            'SELECT DISTINCT teams FROM event WHERE teams LIKE :q AND teams IS NOT NULL AND teams != "" LIMIT 30',
            ['q' => '%' . $query . '%']
        );

        $names = [];
        foreach ($results as $row) {
            foreach (explode(' vs ', $row['teams']) as $name) {
                $name = trim($name);
                if ($name !== '' && mb_stripos($name, $query) !== false) {
                    $names[$name] = true;
                }
            }
        }
        ksort($names);

        return array_keys(array_slice($names, 0, 10, true));
    }

    /** @return string[] */
    public function searchArtists(string $query): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $results = $conn->fetchAllAssociative(
            'SELECT DISTINCT artist_name FROM event WHERE artist_name LIKE :q AND artist_name IS NOT NULL AND artist_name != "" ORDER BY artist_name LIMIT 10',
            ['q' => '%' . $query . '%']
        );

        return array_column($results, 'artist_name');
    }

    /** @return string[] */
    public function searchTournaments(string $query): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $results = $conn->fetchAllAssociative(
            'SELECT DISTINCT tournament_name FROM event WHERE tournament_name LIKE :q AND tournament_name IS NOT NULL AND tournament_name != "" ORDER BY tournament_name LIMIT 10',
            ['q' => '%' . $query . '%']
        );

        return array_column($results, 'tournament_name');
    }

    /**
     * @return Event[]
     */
    public function search(string $query): array
    {
        $words = array_values(array_filter(array_map('trim', explode(' ', $query))));
        if (empty($words)) {
            return [];
        }

        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.venue', 'v');

        foreach ($words as $i => $word) {
            $p = 'w' . $i;
            $qb->andWhere("e.artistName LIKE :$p OR e.tournamentName LIKE :$p OR v.name LIKE :$p OR v.city LIKE :$p OR e.teams LIKE :$p")
               ->setParameter($p, '%' . $word . '%');
        }

        return $qb->orderBy('e.date', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
    }
}
