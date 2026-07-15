<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Venue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Venue>
 */
class VenueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Venue::class);
    }

    /**
     * Find a venue by name, ignoring case and surrounding whitespace,
     * so we don't create identical duplicates ("Graspop", "graspop", "Graspop ").
     */
    public function findOneByName(string $name): ?Venue
    {
        return $this->createQueryBuilder('v')
            ->where('LOWER(TRIM(v.name)) = LOWER(TRIM(:name))')
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Venue[]
     */
    public function search(string $query): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.name LIKE :q')
            ->setParameter('q', '%' . $query . '%')
            ->orderBy('v.name', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
    }
}
