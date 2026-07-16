<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\EventParticipationRepository;
use App\Repository\FriendRepository;

/**
 * Le classement de l'année : qui, de l'utilisateur et de ses amis, a vécu le plus
 * d'événements depuis le 1er janvier.
 *
 * Même périmètre que le feed : amis in-app confirmés, profils privés exclus — on
 * ne classe que des gens dont on voit déjà l'activité. L'utilisateur y figure
 * toujours, même sans ami : un classement d'une personne reste un compteur, et
 * c'est le cas de départ de tout le monde.
 *
 * Les ex æquo partagent leur rang, et le suivant reprend au nombre de personnes
 * déjà classées (1, 2, 2, 4) : deux personnes à égalité sont deuxièmes, pas
 * deuxième et troisième.
 */
final class LeaderboardService
{
    public function __construct(
        private readonly FriendRepository $friendRepo,
        private readonly EventParticipationRepository $participationRepo,
    ) {}

    /**
     * @return list<array{user: User, count: int, rank: int, is_me: bool}>
     *     du plus fourni au moins fourni ; à égalité, par ordre alphabétique
     */
    public function forUser(User $user, int $year): array
    {
        $people = [(string) $user->getId() => $user];
        foreach ($this->friendRepo->findConfirmedFriends($user) as $rel) {
            $other = $rel->getOwner()->getId()->equals($user->getId())
                ? $rel->getFriendUser()
                : $rel->getOwner();

            // Un ami compte, qu'il soit public ou privé : privé ne l'est jamais
            // vis-à-vis de ses amis.
            if ($other !== null) {
                $people[(string) $other->getId()] = $other;
            }
        }

        $counts = $this->participationRepo->countForYearByUsers(array_values($people), $year);

        $rows = [];
        foreach ($people as $id => $person) {
            $rows[] = [
                'user' => $person,
                'count' => $counts[$id] ?? 0,
                'rank' => 0,
                'is_me' => $id === (string) $user->getId(),
            ];
        }

        // À égalité, l'ordre alphabétique plutôt que celui de la base : sans second
        // critère, deux ex æquo pourraient permuter d'un affichage à l'autre.
        usort($rows, fn (array $a, array $b) => $b['count'] <=> $a['count']
            ?: strcasecmp($a['user']->getDisplayName(), $b['user']->getDisplayName()));

        $rank = 0;
        $previousCount = null;
        foreach ($rows as $i => &$row) {
            if ($row['count'] !== $previousCount) {
                $rank = $i + 1;
                $previousCount = $row['count'];
            }
            $row['rank'] = $rank;
        }
        unset($row);

        return $rows;
    }
}
