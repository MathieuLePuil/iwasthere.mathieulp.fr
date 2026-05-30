<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
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
            ->setParameter('q', '%' . $query . '%')
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
            ->setParameter('q', '%' . $query . '%')
            ->setParameter('current', $currentUser)
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
    }
}
