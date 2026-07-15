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
 *
 * Une seule évaluation nourrit les deux vues : le profil ne montre que `highlights`
 * et le premier de `next`, la page Succès déplie `families`.
 */
final class BadgeService
{
    /** Nombre de pastilles mises en avant sur le profil : une ligne, pas plus. */
    private const HIGHLIGHTS = 4;

    public function __construct(private readonly EventParticipationRepository $repo) {}

    /**
     * @return array{
     *     families: list<array{name: string, icon: string, earned_count: int, total: int, tiers: list<array{badge: Badge, value: int, target: int, percent: int, earned: bool, tier: int, locked_ahead: bool}>}>,
     *     highlights: list<array{badge: Badge, value: int, target: int, percent: int, earned: bool, tier: int, locked_ahead: bool}>,
     *     next: list<array{badge: Badge, value: int, target: int, percent: int, earned: bool, tier: int, locked_ahead: bool}>,
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
     *     families: list<array{name: string, icon: string, earned_count: int, total: int, tiers: list<array{badge: Badge, value: int, target: int, percent: int, earned: bool, tier: int, locked_ahead: bool}>}>,
     *     highlights: list<array{badge: Badge, value: int, target: int, percent: int, earned: bool, tier: int, locked_ahead: bool}>,
     *     next: list<array{badge: Badge, value: int, target: int, percent: int, earned: bool, tier: int, locked_ahead: bool}>,
     *     earned_count: int,
     *     total_count: int
     * }
     */
    public function evaluate(array $stats): array
    {
        $families = [];
        $next = [];
        $sommets = [];
        $earnedCount = 0;

        foreach (Badge::families() as $nom => $paliers) {
            $tiers = [];
            $familleEarned = 0;
            $prochainTrouve = false;

            // Les paliers sont déclarés dans l'ordre croissant : le premier non atteint
            // est celui à viser, ceux d'après sont hors de portée pour l'instant
            // (locked_ahead) et n'affichent pas de progression — montrer « 3/100 »
            // à quelqu'un qui vise 10 concerts n'aide personne.
            foreach ($paliers as $index => $badge) {
                $value = $this->value($stats, $badge);
                $target = $badge->target();
                $earned = $value >= $target;

                $tier = [
                    'badge' => $badge,
                    'value' => $value,
                    'target' => $target,
                    'percent' => min(100, (int) floor($value / $target * 100)),
                    'earned' => $earned,
                    'tier' => $index + 1,
                    'locked_ahead' => false,
                ];

                if ($earned) {
                    $familleEarned++;
                    $earnedCount++;
                    // Les paliers montent : le dernier décroché de la famille est le plus haut.
                    $sommets[$nom] = $tier;
                } elseif (!$prochainTrouve) {
                    $next[] = $tier;
                    $prochainTrouve = true;
                } else {
                    $tier['locked_ahead'] = true;
                }

                $tiers[] = $tier;
            }

            $families[] = [
                'name' => $nom,
                'icon' => $paliers[0]->icon(),
                'earned_count' => $familleEarned,
                'total' => count($paliers),
                'tiers' => $tiers,
            ];
        }

        // Les plus proches du but en tête : c'est le prochain effort qui motive.
        usort($next, fn(array $a, array $b) => $b['percent'] <=> $a['percent']);

        return [
            'families' => $families,
            'highlights' => $this->highlights($sommets),
            'next' => $next,
            'earned_count' => $earnedCount,
            'total_count' => count(Badge::cases()),
        ];
    }

    /**
     * Le plus haut palier décroché de chaque famille, les plus hauts d'abord : sur le
     * profil il n'y a de place que pour quelques pastilles, autant que ce soient celles
     * qui se sont fait attendre. À égalité de palier, l'ordre des familles tranche.
     *
     * @param array<string, array{badge: Badge, value: int, target: int, percent: int, earned: bool, tier: int, locked_ahead: bool}> $sommets
     *
     * @return list<array{badge: Badge, value: int, target: int, percent: int, earned: bool, tier: int, locked_ahead: bool}>
     */
    private function highlights(array $sommets): array
    {
        $sommets = array_values($sommets);
        usort($sommets, fn(array $a, array $b) => $b['tier'] <=> $a['tier']);

        return array_slice($sommets, 0, self::HIGHLIGHTS);
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
