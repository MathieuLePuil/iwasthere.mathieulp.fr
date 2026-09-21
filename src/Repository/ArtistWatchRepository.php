<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ArtistWatch;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ArtistWatch>
 */
class ArtistWatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtistWatch::class);
    }

    /** @return list<ArtistWatch> par ordre alphabétique */
    public function findForUser(User $user): array
    {
        /** @var list<ArtistWatch> $rows */
        $rows = $this->createQueryBuilder('a')
            ->where('a.user = :user')
            ->setParameter('user', $user->getId()->toBinary(), ParameterType::BINARY)
            ->orderBy('a.artistNormalized', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function findOneForUserAndArtist(User $user, string $artistNormalized): ?ArtistWatch
    {
        return $this->findOneBy(['user' => $user, 'artistNormalized' => $artistNormalized]);
    }

    /**
     * Qui veut être prévenu pour ces artistes — la wishlist seulement.
     *
     * @param list<string> $artistsNormalized
     *
     * @return array<string, array<string, string>> user_id → artiste normalisé → nom affiché
     */
    public function findInterests(array $artistsNormalized): array
    {
        if ($artistsNormalized === []) {
            return [];
        }

        /** @var list<array{user_id: string, artist_name: string, artist_normalized: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT user_id, artist_name, artist_normalized FROM artist_watch WHERE artist_normalized IN (?)',
            [$artistsNormalized],
            [ArrayParameterType::STRING],
        );

        $interests = [];
        foreach ($rows as $row) {
            $userId = Uuid::fromBinary($row['user_id'])->toRfc4122();
            $interests[$userId][$row['artist_normalized']] = $row['artist_name'];
        }

        return $interests;
    }
}
