<?php

declare(strict_types=1);

namespace App\Participation;

use App\Entity\User;
use App\Repository\EventParticipationRepository;

/**
 * Tient à jour les « Avec qui » qui citent un utilisateur.
 *
 * La liste des accompagnants d'une participation est un JSON figé (userId,
 * username, displayName) : un pseudo renommé y restait sous son ancien nom et
 * ses liens de profil renvoyaient 404 ; un compte supprimé y restait comme un
 * fantôme. Rien n'est flushé ici, l'appelant le fait avec le reste.
 */
final class CompanionSync
{
    public function __construct(private readonly EventParticipationRepository $participations) {}

    /** Réécrit pseudo et nom affiché partout où l'utilisateur est tagué. */
    public function rename(User $user): void
    {
        $id = (string) $user->getId();
        foreach ($this->participations->findTagging($id) as $participation) {
            $friends = $participation->getFriends();
            $changed = false;
            foreach ($friends as $i => $f) {
                if (($f['type'] ?? '') === 'app' && ($f['userId'] ?? '') === $id) {
                    $friends[$i]['username'] = $user->getUsername();
                    $friends[$i]['displayName'] = $user->getDisplayName();
                    $changed = true;
                }
            }
            if ($changed) {
                $participation->setFriends(array_values($friends));
            }
        }
    }

    /** Retire l'utilisateur de tous les « Avec qui » — pour la suppression du compte. */
    public function forget(User $user): void
    {
        $id = (string) $user->getId();
        foreach ($this->participations->findTagging($id) as $participation) {
            $participation->setFriends(array_values(array_filter(
                $participation->getFriends(),
                fn (array $f) => !((($f['type'] ?? '') === 'app') && ($f['userId'] ?? '') === $id),
            )));
        }
    }
}
