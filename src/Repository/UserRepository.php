<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * Les utilisateurs de ces ids, en une requête. Les ids invalides sont ignorés.
     *
     * @param list<string> $ids
     * @return User[]
     */
    public function findByIds(array $ids): array
    {
        $binary = [];
        foreach ($ids as $id) {
            if (is_string($id) && Uuid::isValid($id)) {
                $binary[] = Uuid::fromString($id)->toBinary();
            }
        }
        if ($binary === []) {
            return [];
        }

        return $this->createQueryBuilder('u')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', $binary, ArrayParameterType::BINARY)
            ->getQuery()
            ->getResult();
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    public function findOneByUsername(string $username): ?User
    {
        return $this->findOneBy(['username' => $username]);
    }

    public function findOneByGoogleId(string $googleId): ?User
    {
        return $this->findOneBy(['googleId' => $googleId]);
    }

    /**
     * @return User[]
     */
    public function adminSearch(string $query): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.username LIKE :q OR u.email LIKE :q OR u.displayName LIKE :q')
            ->setParameter('q', LikeEscaper::contains($query))
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return User[]
     */
    public function searchByUsername(string $query, User $currentUser): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.username LIKE :q OR u.displayName LIKE :q')
            ->andWhere('u != :current')
            ->setParameter('q', LikeEscaper::contains($query))
            ->setParameter('current', $currentUser)
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
    }

    /**
     * Les utilisateurs dont l'heure de rappel tombe dans l'une des minutes
     * données (heure française, format « H:i »).
     *
     * La commande de rappels balaie une fenêtre de quelques minutes plutôt que
     * la minute courante seule : un cron sauté ne doit pas faire perdre le
     * rappel de la journée. Le dédoublonnage en aval évite le second envoi.
     *
     * @param list<string> $times
     * @return User[]
     */
    public function findDueForReminders(array $times): array
    {
        if ($times === []) {
            return [];
        }

        return $this->createQueryBuilder('u')
            // COALESCE : la colonne est nullable, l'absence vaut 08:00 comme partout
            ->where('COALESCE(u.notifCompletionTime, :default) IN (:times)')
            ->setParameter('default', '08:00')
            ->setParameter('times', $times)
            ->getQuery()
            ->getResult();
    }
}
