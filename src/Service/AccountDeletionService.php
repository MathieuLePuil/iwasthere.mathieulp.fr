<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\EventParticipationRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Supprime un compte et tout ce qui le référence.
 *
 * User ne mappe aucune association et toutes les clés étrangères vers `user` sont
 * en RESTRICT : un `remove($user)` seul part en violation de contrainte dès que le
 * compte a la moindre participation, notification ou relation d'amitié — donc pour
 * tout compte réel. Le ménage est fait ici, explicitement.
 *
 * Passer ces clés en ON DELETE CASCADE aurait été plus court mais faux :
 * event.participant_count est dénormalisé et maintenu à la main
 * (cf. EventController::delete), la base l'aurait laissé gonflé sur chaque
 * événement fréquenté par le compte supprimé.
 */
class AccountDeletionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventParticipationRepository $participationRepo,
    ) {}

    public function delete(User $user): void
    {
        // Tout ou rien : si une contrainte oubliée saute sur le dernier DELETE, les
        // compteurs déjà décrémentés ne doivent pas rester faux.
        $this->em->wrapInTransaction(function () use ($user): void {
            foreach ($this->participationRepo->findAllByUser($user) as $participation) {
                $event = $participation->getEvent();
                $event->setParticipantCount(max(0, $event->getParticipantCount() - 1));
            }
            $this->em->flush();

            $id = $user->getId()->toBinary();

            // DQL et non remove() ligne à ligne : l'UnitOfWork ne garantit pas que les
            // enfants partent avant le parent, et c'est précisément l'ordre qui compte ici.
            $this->deleteAll('App\Entity\EventParticipation p', 'p.user = :id', $id);
            $this->deleteAll('App\Entity\Notification n', 'n.recipient = :id', $id);
            // Les deux sens : la liste d'amis du compte, et les entrées des autres qui pointent vers lui.
            $this->deleteAll('App\Entity\Friend f', 'f.owner = :id OR f.friendUser = :id', $id);
            // push_subscription n'a pas d'entité, mais sa FK est déjà en ON DELETE CASCADE.

            $this->em->remove($user);
            $this->em->flush();
        });
    }

    private function deleteAll(string $from, string $where, string $id): void
    {
        $this->em->createQuery("DELETE FROM {$from} WHERE {$where}")
            ->setParameter('id', $id, ParameterType::BINARY)
            ->execute();
    }
}
