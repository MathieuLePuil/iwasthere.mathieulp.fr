<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\UtcDateTimeImmutableType;
use App\Entity\TmSaleWindow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TmSaleWindow>
 */
class TmSaleWindowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TmSaleWindow::class);
    }

    /**
     * Les fenêtres dont l'ouverture tombe dans l'intervalle — le contrôle de
     * cohérence après un changement d'heure (voir app:ticketmaster:check-dst).
     *
     * @return list<TmSaleWindow>
     */
    public function findStartingBetween(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 200): array
    {
        /** @var list<TmSaleWindow> $windows */
        $windows = $this->createQueryBuilder('w')
            ->addSelect('e')
            ->join('w.event', 'e')
            ->where('w.startsAtUtc >= :from')
            ->andWhere('w.startsAtUtc < :to')
            ->setParameter('from', $from, UtcDateTimeImmutableType::NAME)
            ->setParameter('to', $to, UtcDateTimeImmutableType::NAME)
            ->orderBy('w.startsAtUtc', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $windows;
    }
}
