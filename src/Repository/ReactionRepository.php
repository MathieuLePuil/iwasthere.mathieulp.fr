<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EventParticipation;
use App\Entity\Reaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reaction>
 */
class ReactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reaction::class);
    }

    /** La réaction que $user a posée sur $participation avec cet emoji, s'il l'a fait. */
    public function findOneFor(EventParticipation $participation, User $user, string $emoji): ?Reaction
    {
        return $this->createQueryBuilder('r')
            ->where('r.participation = :p')
            ->andWhere('r.user = :u')
            ->andWhere('r.emoji = :e')
            ->setParameter('p', $participation->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('u', $user->getId()->toBinary(), ParameterType::BINARY)
            ->setParameter('e', $emoji)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * L'état des réactions d'un lot de participations, du point de vue de $viewer :
     * combien pour chaque emoji, et lesquels le lecteur a lui-même posés.
     *
     * Le regroupement est fait par la base et non en PHP, pour qu'il suive la même
     * règle que l'index unique : la collation de la colonne tient '❤️' et '❤' pour
     * un seul emoji, là où PHP y verrait deux chaînes — donc deux pastilles pour le
     * même cœur. La forme rendue est celle qu'une des lignes portait, elles sont
     * équivalentes par construction.
     *
     * Tout est agrégé en une requête : le feed affiche plusieurs dizaines de
     * participations par page, une requête chacune se verrait.
     *
     * Les ids passent par une jointure plutôt que par IDENTITY() : cette dernière
     * rend la valeur brute de la clé étrangère — du binaire, ici — là où `p.id`
     * est reconstruit en Uuid par le type Doctrine.
     *
     * @param EventParticipation[] $participations
     *
     * @return array<string, array<string, array{count: int, mine: bool}>>
     *     id de participation => emoji => état, du plus posé au moins posé
     */
    public function stateFor(array $participations, User $viewer): array
    {
        if ($participations === []) {
            return [];
        }

        $ids = array_map(fn (EventParticipation $p) => $p->getId()->toBinary(), $participations);

        $rows = $this->createQueryBuilder('r')
            ->select(
                'p.id AS pid',
                'r.emoji AS emoji',
                'COUNT(r.id) AS total',
                'MAX(CASE WHEN u.id = :viewer THEN 1 ELSE 0 END) AS mine',
            )
            ->join('r.participation', 'p')
            ->join('r.user', 'u')
            ->where('r.participation IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::BINARY)
            ->setParameter('viewer', $viewer->getId()->toBinary(), ParameterType::BINARY)
            ->groupBy('p.id')
            ->addGroupBy('r.emoji')
            // À égalité, l'emoji départage : sans second critère, deux pastilles
            // au même compte pourraient permuter d'un affichage à l'autre.
            ->orderBy('total', 'DESC')
            ->addOrderBy('r.emoji', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $state = [];
        foreach ($rows as $row) {
            $state[(string) $row['pid']][$row['emoji']] = [
                'count' => (int) $row['total'],
                'mine' => (bool) $row['mine'],
            ];
        }

        return $state;
    }

    /**
     * L'état d'une seule participation, dans la forme rendue par stateFor().
     *
     * @return array<string, array{count: int, mine: bool}>
     */
    public function stateForOne(EventParticipation $participation, User $viewer): array
    {
        return $this->stateFor([$participation], $viewer)[(string) $participation->getId()] ?? [];
    }

    /** Toutes les réactions d'un compte, dans les deux sens : celles qu'il a posées et celles qu'il a reçues. */
    public function deleteAllForUser(User $user): void
    {
        $id = $user->getId()->toBinary();

        $this->getEntityManager()->createQuery(
            'DELETE FROM App\Entity\Reaction r
             WHERE r.user = :id
                OR r.participation IN (
                    SELECT p.id FROM App\Entity\EventParticipation p WHERE p.user = :id
                )'
        )
            ->setParameter('id', $id, ParameterType::BINARY)
            ->execute();
    }
}
