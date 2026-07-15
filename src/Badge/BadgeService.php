<?php

declare(strict_types=1);

namespace App\Badge;

use App\Entity\User;
use App\Repository\EventParticipationRepository;

/**
 * Confronte les statistiques d'un utilisateur au catalogue de Badge.
 *
 * Tout part de computeStats(), qui balaie déjà l'historique complet : les badges
 * n'ajoutent aucune requête, d'où le passage des stats en argument plutôt qu'un
 * calcul interne — la page profil les a parfois déjà sous la main.
 */
final class BadgeService
{
    public function __construct(private readonly EventParticipationRepository $repo) {}

    /**
     * @return array{
     *     earned: list<array{badge: Badge, value: int}>,
     *     next: list<array{badge: Badge, value: int, target: int, percent: int}>,
     *     earned_count: int,
     *     total_count: int
     * }
     */
    public function forUser(User $user): array
    {
        return $this->evaluate($this->repo->computeStats($user));
    }

    /**
     * @param array<string, mixed> $stats sortie de computeStats()
     *
     * @return array{
     *     earned: list<array{badge: Badge, value: int}>,
     *     next: list<array{badge: Badge, value: int, target: int, percent: int}>,
     *     earned_count: int,
     *     total_count: int
     * }
     */
    public function evaluate(array $stats): array
    {
        $earned = [];
        $next = [];

        foreach (Badge::families() as $paliers) {
            // Les paliers sont déclarés dans l'ordre croissant : le premier non atteint
            // est celui à viser, et les suivants de la famille ne sont pas montrés —
            // afficher « 100 concerts » à quelqu'un qui en a 3 n'aide personne.
            $prochainTrouve = false;
            foreach ($paliers as $badge) {
                $value = $this->value($stats, $badge);
                if ($value >= $badge->target()) {
                    $earned[] = ['badge' => $badge, 'value' => $value];
                    continue;
                }
                if (!$prochainTrouve) {
                    $next[] = [
                        'badge' => $badge,
                        'value' => $value,
                        'target' => $badge->target(),
                        'percent' => (int) floor($value / $badge->target() * 100),
                    ];
                    $prochainTrouve = true;
                }
            }
        }

        // Les plus proches du but en tête : c'est le prochain effort qui motive.
        usort($next, fn(array $a, array $b) => $b['percent'] <=> $a['percent']);

        return [
            'earned' => $earned,
            'next' => $next,
            'earned_count' => count($earned),
            'total_count' => count(Badge::cases()),
        ];
    }

    /**
     * Un historique vide renvoie ['total' => 0, 'has_data' => false] et rien d'autre :
     * toutes les mesures valent alors zéro plutôt que de faire échouer la lecture.
     *
     * @param array<string, mixed> $stats
     */
    private function value(array $stats, Badge $badge): int
    {
        return (int) ($stats[$badge->metric()] ?? 0);
    }
}
