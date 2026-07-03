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
     * @return Venue[]
     */
    public function findByCity(string $city): array
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.city = :city')
            ->setParameter('city', $city)
            ->orderBy('v.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Venue[]
     */
    public function findByCountry(string $country): array
    {
        return $this->createQueryBuilder('v')
            ->andWhere('v.country = :country')
            ->setParameter('country', $country)
            ->orderBy('v.name', 'ASC')
            ->getQuery()
            ->getResult();
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
            ->where('v.name LIKE :q OR v.city LIKE :q')
            ->setParameter('q', '%' . $query . '%')
            ->orderBy('v.name', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
    }
}
